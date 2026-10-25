/* GSMaRT Construction System - behaviour shared by the construction forms (no library needed).
 *
 *  Line grids: <table data-cs-lines id="x"> with a <template data-cs-row="x"> row whose names carry __i__.
 *    [data-cs-add="x"]         adds a row             [data-cs-del] (in a row) removes it
 *    [data-q] [data-r] [data-a] quantity, rate, amount: the amount follows quantity x rate
 *    [data-cs-sum="name"]       shows the total of every [data-a] of the table, or of [data-sum="name"]
 *  Offline drafts: <form data-cs-offline="key"> keeps what is typed on this device until it is sent.
 */
(function () {
  'use strict';
  var num = function (v) { v = String(v == null ? '' : v).replace(/[, ]/g, ''); var n = parseFloat(v); return isNaN(n) ? 0 : n; };
  var fmt = function (n) { return (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  window.csNum = num;
  window.csFmt = fmt;

  function sums(table) {
    var form = table.closest('form') || document;
    var totals = {};
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      tr.querySelectorAll('[data-sum]').forEach(function (el) {
        var k = el.getAttribute('data-sum');
        totals[k] = (totals[k] || 0) + num(el.value !== undefined && el.tagName !== 'SPAN' && el.tagName !== 'TD' ? el.value : el.textContent);
      });
      var a = tr.querySelector('[data-a]');
      if (a) totals.amount = (totals.amount || 0) + num(a.value !== undefined && a.tagName === 'INPUT' ? a.value : a.textContent);
    });
    form.querySelectorAll('[data-cs-sum]').forEach(function (out) {
      if (out.getAttribute('data-cs-table') && out.getAttribute('data-cs-table') !== table.id) return;
      var k = out.getAttribute('data-cs-sum');
      var v = totals[k] || 0;
      if (out.tagName === 'INPUT') out.value = v.toFixed(2); else out.textContent = fmt(v);
    });
    table.dispatchEvent(new CustomEvent('cs:totals', { bubbles: true, detail: totals }));
  }

  function bindRow(table, tr) {
    var q = tr.querySelector('[data-q]'), r = tr.querySelector('[data-r]'), a = tr.querySelector('[data-a]');
    var calc = function () {
      if (q && r && a) {
        var v = Math.round(num(q.value) * num(r.value) * 100) / 100;
        if (a.tagName === 'INPUT') a.value = v ? v.toFixed(2) : ''; else a.textContent = fmt(v);
      }
      sums(table);
    };
    [q, r].forEach(function (el) { if (el) el.addEventListener('input', calc); });
    tr.querySelectorAll('[data-sum], [data-a]').forEach(function (el) { el.addEventListener('input', function () { sums(table); }); });
    var del = tr.querySelector('[data-cs-del]');
    if (del) del.addEventListener('click', function () {
      var body = tr.parentNode;
      tr.remove();
      if (body && !body.querySelector('tr')) addRow(table);
      sums(table);
    });
  }

  var counter = 0;
  function addRow(table) {
    var tpl = document.querySelector('template[data-cs-row="' + table.id + '"]');
    if (!tpl) return null;
    var html = tpl.innerHTML.replace(/__i__/g, 'n' + (counter++) + '_' + Date.now().toString(36));
    var tmp = document.createElement('tbody');
    tmp.innerHTML = html.trim();
    var tr = tmp.firstElementChild;
    table.tBodies[0].appendChild(tr);
    bindRow(table, tr);
    if (window.AppCombo) window.AppCombo.init(tr);
    table.dispatchEvent(new CustomEvent('cs:row', { bubbles: true, detail: tr }));
    return tr;
  }

  document.querySelectorAll('table[data-cs-lines]').forEach(function (table) {
    if (!table.tBodies.length) table.appendChild(document.createElement('tbody'));
    table.querySelectorAll('tbody tr').forEach(function (tr) { bindRow(table, tr); });
    var min = parseInt(table.getAttribute('data-min-rows') || '1', 10);
    for (var i = table.tBodies[0].rows.length; i < min; i++) addRow(table);
    document.querySelectorAll('[data-cs-add="' + table.id + '"]').forEach(function (b) {
      b.addEventListener('click', function () {
        var n = parseInt(b.getAttribute('data-count') || '1', 10);
        var last = null;
        for (var k = 0; k < n; k++) last = addRow(table);
        var f = last && last.querySelector('input, select');
        if (f) f.focus();
      });
    });
    table.csAddRow = function () { return addRow(table); };
    table.csRecalc = function () { sums(table); };
    sums(table);
  });

  // ---- a select that fills other fields of its row from data- attributes of the chosen option (unit, cost)
  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel.matches || !sel.matches('select[data-fill]')) return;
    var opt = sel.options[sel.selectedIndex];
    var tr = sel.closest('tr') || sel.closest('form');
    if (!opt || !tr) return;
    sel.getAttribute('data-fill').split(',').forEach(function (pair) {
      var bits = pair.split(':'), attr = bits[0], target = bits[1];
      var el = tr.querySelector('[name$="[' + target + ']"], [name="' + target + '"]');
      var val = opt.getAttribute('data-' + attr);
      if (el && val !== null && (el.value === '' || el.hasAttribute('data-fill-always'))) {
        el.value = val;
        el.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  });

  // ---- offline drafts (site reports and timesheets typed where the signal comes and goes)
  document.querySelectorAll('form[data-cs-offline]').forEach(function (form) {
    var key = 'gs.draft.' + form.getAttribute('data-cs-offline');
    var note = document.createElement('div');
    note.className = 'alert alert-warning py-2 small';
    note.hidden = true;
    form.prepend(note);
    var read = function () { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } };
    var save = function () {
      var data = [];
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.type === 'file' || el.type === 'hidden' && el.name === '_csrf') return;
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
        data.push([el.name, el.value]);
      });
      try { localStorage.setItem(key, JSON.stringify({ at: Date.now(), data: data })); } catch (e) { /* storage full or blocked */ }
    };
    var draft = read();
    if (draft && draft.data && form.hasAttribute('data-cs-new')) {
      note.hidden = false;
      note.innerHTML = 'A draft typed on this device on ' + new Date(draft.at).toLocaleString() + ' was not sent yet. <button type="button" class="btn btn-link btn-sm p-0" data-restore>Put it back in the form</button> or <button type="button" class="btn btn-link btn-sm p-0" data-discard>discard it</button>.';
      note.querySelector('[data-restore]').addEventListener('click', function () {
        draft.data.forEach(function (pair) {
          var el = form.querySelector('[name="' + pair[0].replace(/"/g, '\\"') + '"]');
          if (el && el.type !== 'checkbox') el.value = pair[1]; else if (el) el.checked = true;
        });
        note.hidden = true;
        if (window.AppCombo) window.AppCombo.sync(form);
      });
      note.querySelector('[data-discard]').addEventListener('click', function () { try { localStorage.removeItem(key); } catch (e) {} note.hidden = true; });
    }
    form.addEventListener('input', save);
    form.addEventListener('submit', function (e) {
      if (navigator.onLine === false) {
        e.preventDefault();
        save();
        note.hidden = false;
        note.textContent = 'You are offline. The report is kept on this device; send it again when the signal is back.';
        return;
      }
      try { localStorage.removeItem(key); } catch (err) {}
    });
  });

  // ---- percent fields that keep an amount in step: data-pct-of="#base" data-pct-out="#amount"
  document.querySelectorAll('[data-pct-of]').forEach(function (el) {
    var base = document.querySelector(el.getAttribute('data-pct-of'));
    var out = document.querySelector(el.getAttribute('data-pct-out'));
    if (!base || !out) return;
    el.addEventListener('input', function () { out.value = (Math.round(num(base.value) * num(el.value)) / 100).toFixed(2); out.dispatchEvent(new Event('input', { bubbles: true })); });
  });

  // ---- schedule rows: start [data-ds], finish [data-df] and duration in days [data-dd] keep each other in step
  var dayMs = 86400000;
  var parseDay = function (v) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : null; };
  var fmtDay = function (t) { return new Date(t).toISOString().slice(0, 10); };
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.matches || !el.matches('[data-ds], [data-df], [data-dd]')) return;
    var row = el.closest('tr');
    if (!row) return;
    var s = row.querySelector('[data-ds]'), f = row.querySelector('[data-df]'), d = row.querySelector('[data-dd]');
    if (!s || !f || !d) return;
    var st = parseDay(s.value), ft = parseDay(f.value), days = parseInt(d.value, 10);
    if (el === d && st !== null && days > 0) {
      f.value = fmtDay(st + (days - 1) * dayMs);
    } else if (st !== null && ft !== null && ft >= st) {
      d.value = Math.round((ft - st) / dayMs) + 1;
    } else if (el === s && st !== null && days > 0) {
      f.value = fmtDay(st + (days - 1) * dayMs);
    }
  });
})();
