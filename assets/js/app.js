/* GSMaRT Construction System - front-end helpers (no build step) */
(function () {
  'use strict';

  // number helpers come from fmt.js (loaded in <head> so inline page scripts can use them)
  const { num, r2, fmt } = window.AppFmt;

  // ------------------------------------------------------------------ line grids
  function initGrid(table) {
    const items = JSON.parse(table.dataset.items || '{}');
    const taxes = JSON.parse(table.dataset.taxes || '{}');
    const unitsByItem = JSON.parse(table.dataset.units || '{}'); // item id -> [{id, code, factor, base, sd, pd}]
    const mode = table.dataset.mode || 'sales'; // sales | purchase | expense | journal
    const tbody = table.querySelector('tbody');
    const tpl = document.getElementById(table.dataset.template);
    const form = table.closest('form');
    let counter = tbody.querySelectorAll('tr').length + 1000;

    const field = (tr, name) => tr.querySelector('[data-f="' + name + '"]');

    function priceFor(itemId, cb) {
      const it = items[itemId];
      if (!it) return cb(0);
      if (mode !== 'sales') return cb(it.cost);
      const cust = form && form.querySelector('[name="customer_id"]');
      if (cust && cust.value && table.dataset.priceUrl) {
        fetch(table.dataset.priceUrl + '&item=' + itemId + '&customer=' + cust.value)
          .then((r) => r.json()).then((d) => cb(num(d.price))).catch(() => cb(it.price));
      } else {
        cb(it.price);
      }
    }

    const r4 = (v) => Math.round((v + Number.EPSILON) * 10000) / 10000;

    /** base units in one of the unit the row is entered in */
    function rowFactor(tr) {
      const u = field(tr, 'uom_id');
      if (!u || !u.value) return 1;
      const o = u.options[u.selectedIndex];
      return num(o && o.dataset.factor) || 1;
    }

    /** fills the U/M select for the row's item and returns the unit picked by default */
    function fillUnits(tr, keep) {
      const sel = field(tr, 'uom_id');
      if (!sel) return null;
      const itemSel = field(tr, 'item_id');
      const list = (itemSel && unitsByItem[itemSel.value]) || [];
      sel.innerHTML = '<option value=""></option>';
      let kept = null, preferred = null, base = null;
      list.forEach((u) => {
        const o = document.createElement('option');
        o.value = u.id;
        o.textContent = u.code;
        o.dataset.factor = u.factor;
        sel.appendChild(o);
        if (String(u.id) === String(keep)) kept = u;
        if (!preferred && (mode === 'purchase' ? u.pd : u.sd)) preferred = u;
        if (!base && u.base) base = u;
      });
      const picked = kept || preferred || base;
      if (picked) sel.value = String(picked.id);
      return picked;
    }

    function recalcRow(tr, source) {
      const itemSel = field(tr, 'item_id');
      const it = itemSel ? items[itemSel.value] : null;
      const qtyF = field(tr, 'qty'), rateF = field(tr, 'rate'), amtF = field(tr, 'amount'), discF = field(tr, 'discount_val') || field(tr, 'discount_pct');
      const kindF = field(tr, 'discount_kind');
      const taxSel = field(tr, 'tax_code_id'), taxF = field(tr, 'tax_amount');
      let amount = amtF ? num(amtF.value) : 0;
      if (it && it.type === 'subtotal') {
        let sum = 0, prev = tr.previousElementSibling;
        while (prev) {
          const ps = field(prev, 'item_id');
          const pit = ps ? items[ps.value] : null;
          if (pit && pit.type === 'subtotal') break;
          sum += num(field(prev, 'amount') ? field(prev, 'amount').value : 0);
          prev = prev.previousElementSibling;
        }
        if (amtF) amtF.value = '0.00';
        const lbl = tr.querySelector('[data-subtotal]');
        if (lbl) lbl.textContent = fmt(sum);
        if (taxF) taxF.value = '0.00';
        return;
      }
      if (it && it.type === 'discount') {
        const prev = tr.previousElementSibling;
        const prevAmt = prev && field(prev, 'amount') ? num(field(prev, 'amount').value) : 0;
        amount = it.pct > 0 ? -r2(prevAmt * it.pct / 100) : -Math.abs(num(rateF ? rateF.value : it.price));
        if (amtF) amtF.value = amount.toFixed(2);
      } else if (it && it.type === 'payment') {
        amount = -Math.abs(num(rateF ? rateF.value : 0));
        if (amtF) amtF.value = amount.toFixed(2);
      } else if (qtyF && rateF && source !== 'amount') {
        const q = num(qtyF.value), rt = num(rateF.value), d = discF ? num(discF.value) : 0;
        const kind = kindF ? kindF.value : 'pct';
        if (qtyF.value !== '' || rateF.value !== '') {
          const gross = r2(q * rt);
          amount = kind === 'amt' ? r2(gross - Math.min(Math.abs(d), Math.abs(gross)) * (gross < 0 ? -1 : 1)) : r2(gross * (1 - d / 100));
          if (amtF) amtF.value = amount.toFixed(2);
        }
      } else if (source === 'amount' && qtyF && rateF && num(qtyF.value) !== 0 && !(discF && num(discF.value))) {
        rateF.value = (amount / num(qtyF.value)).toFixed(4).replace(/\.?0+$/, '');
      }
      if (taxSel && taxF) {
        const rate = num(taxes[taxSel.value] || 0);
        taxF.value = r2(amount * rate / 100).toFixed(2);
      }
    }

    function recalcTotals() {
      let sub = 0, tax = 0, dr = 0, cr = 0;
      tbody.querySelectorAll('tr').forEach((tr) => {
        const a = field(tr, 'amount'), t = field(tr, 'tax_amount');
        const d = field(tr, 'debit'), c = field(tr, 'credit');
        if (a) sub += num(a.value);
        if (t) tax += num(t.value);
        if (d) dr += num(d.value);
        if (c) cr += num(c.value);
      });
      const group = table.dataset.totalGroup || 'main';
      let grandSub = 0, grandTax = 0;
      document.querySelectorAll('table.line-grid[data-total-group="' + group + '"]').forEach((tb) => {
        tb.querySelectorAll('tbody tr').forEach((tr) => {
          const a = tr.querySelector('[data-f="amount"]'), t = tr.querySelector('[data-f="tax_amount"]');
          if (a) grandSub += num(a.value);
          if (t) grandTax += num(t.value);
        });
      });
      const set = (k, v) => document.querySelectorAll('[data-total="' + group + '-' + k + '"]').forEach((el) => { el.textContent = fmt(v); });
      set('subtotal', grandSub);
      set('tax', grandTax);
      set('total', grandSub + grandTax);
      set('debit', dr);
      set('credit', cr);
      set('diff', dr - cr);
      set('grid-' + (table.id || ''), sub);
      document.dispatchEvent(new CustomEvent('grid:totals', { detail: { group, subtotal: grandSub, tax: grandTax, total: grandSub + grandTax } }));
    }

    function onItemChange(tr) {
      const sel = field(tr, 'item_id');
      const it = items[sel.value];
      if (!it) { fillUnits(tr, ''); recalcRow(tr); recalcTotals(); return; }
      const desc = field(tr, 'description');
      if (desc) desc.value = it.desc || it.name;
      const unit = field(tr, 'unit');
      if (unit) unit.value = it.unit || '';
      const taxSel = field(tr, 'tax_code_id');
      if (taxSel && it.tax) taxSel.value = String(it.tax);
      const qtyF = field(tr, 'qty');
      if (qtyF && (qtyF.value === '' || num(qtyF.value) === 0) && ['subtotal', 'discount', 'payment'].indexOf(it.type) < 0) qtyF.value = '1';
      const picked = fillUnits(tr, '');
      priceFor(sel.value, (price) => {
        const rateF = field(tr, 'rate');
        // the price is per base unit; the line is entered in the chosen unit
        const perEntered = picked ? r4(num(price) * num(picked.factor)) : price;
        if (rateF) rateF.value = (it.type === 'discount' && it.pct > 0) ? '' : perEntered;
        recalcRow(tr);
        recalcTotals();
      });
    }

    /** switching the unit re-prices the line for the new unit */
    function onUnitChange(tr, oldFactor) {
      const rateF = field(tr, 'rate');
      const f = rowFactor(tr);
      if (rateF && rateF.value !== '' && oldFactor > 0 && f > 0 && f !== oldFactor) {
        rateF.value = String(r4(num(rateF.value) / oldFactor * f));
      }
      recalcRow(tr);
      recalcTotals();
    }

    function bindRow(tr) {
      let unitFactor = rowFactor(tr);
      tr.addEventListener('change', (e) => {
        const f = e.target.dataset.f;
        if (f === 'item_id') { onItemChange(tr); unitFactor = rowFactor(tr); return; }
        if (f === 'uom_id') { const old = unitFactor; unitFactor = rowFactor(tr); return onUnitChange(tr, old); }
        recalcRow(tr, f);
        recalcTotals();
      });
      tr.addEventListener('input', (e) => {
        const f = e.target.dataset.f;
        if (['qty', 'rate', 'discount_pct', 'discount_val', 'debit', 'credit'].indexOf(f) >= 0) { recalcRow(tr, f); recalcTotals(); }
      });
      const rm = tr.querySelector('[data-remove-row]');
      if (rm) rm.addEventListener('click', () => { tr.remove(); recalcTotals(); });
      // a new line straight above this one, for an item forgotten in the middle of a long list
      const ins = tr.querySelector('[data-insert-row]');
      if (ins) ins.addEventListener('click', () => { const nr = addRow(tr); if (nr) markNew([nr]); });
    }

    // ---- the default site for items: copied onto the lines, so nobody has to pick it on every line.
    // A line whose site was changed by hand keeps its own; changing the default moves the others along.
    const headSite = form ? form.querySelector('select[name="site_id"]') : null;
    const rowSite = (tr) => tr.querySelector('select[name$="[site_id]"]');
    let lastSite = headSite ? headSite.value : '';
    const setSite = (sel, v) => { if (sel.value !== v && Array.prototype.some.call(sel.options, (o) => o.value === v)) { sel.value = v; if (sel.comboSync) sel.comboSync(); } };
    function defaultSite(tr) {
      const sel = rowSite(tr);
      if (headSite && sel && sel.value === '' && headSite.value !== '') setSite(sel, headSite.value);
    }
    if (headSite) {
      headSite.addEventListener('change', () => {
        tbody.querySelectorAll('tr').forEach((tr) => { const sel = rowSite(tr); if (sel && (sel.value === '' || sel.value === lastSite)) setSite(sel, headSite.value); });
        lastSite = headSite.value;
      });
    }

    // new lines are shown, lit up for a moment; the cursor is not put in them, because the item box would drop its
    // list over the buttons below and a second click on "Add line" would pick an item instead
    function markNew(rows) {
      rows.forEach((r) => { r.classList.remove('row-new'); void r.offsetWidth; r.classList.add('row-new'); setTimeout(() => r.classList.remove('row-new'), 1300); });
      if (rows[0]) { try { rows[0].scrollIntoView({ block: 'nearest' }); } catch (e) { /* ignore */ } }
    }

    /** a new line at the end, or straight above the line given */
    function addRow(before) {
      if (!tpl) return null;
      const html = tpl.innerHTML.replace(/__i__/g, 'n' + (counter++));
      const tmp = document.createElement('tbody');
      tmp.innerHTML = html.trim();
      const tr = tmp.firstElementChild;
      if (before && before.parentNode === tbody) tbody.insertBefore(tr, before); else tbody.appendChild(tr);
      bindRow(tr);
      defaultSite(tr);
      if (window.AppCombo) window.AppCombo.init(tr);
      tr.dispatchEvent(new CustomEvent('grid:row-added', { bubbles: true }));
      if (before) { tbody.querySelectorAll('tr').forEach((r) => recalcRow(r)); recalcTotals(); }
      return tr;
    }

    tbody.querySelectorAll('tr').forEach(bindRow);
    tbody.querySelectorAll('tr').forEach(defaultSite);
    // "Add line", "Add 5 lines" and "Add 10 lines"
    document.querySelectorAll('[data-add-row="' + table.id + '"]').forEach((b) => b.addEventListener('click', () => {
      const n = Math.max(1, Math.min(50, num(b.dataset.addCount) || 1));
      const added = [];
      for (let i = 0; i < n; i++) { const tr = addRow(); if (tr) added.push(tr); }
      markNew(added);
    }));
    if (!tbody.querySelector('tr')) { for (let i = 0; i < (num(table.dataset.minRows) || 3); i++) addRow(); }
    table.addRow = addRow;
    table.recalc = () => { tbody.querySelectorAll('tr').forEach((tr) => recalcRow(tr)); recalcTotals(); };
    // The tax of a line is worked out again when the form is saved, so the figure shown in the totals
    // has to be the same one. A line that carries a tax code has its tax put in step here, which keeps a
    // form filled from another document (a sales order from an estimate, an invoice from a sales order,
    // a bill from a purchase order) showing the tax and the total the source document showed.
    // Only the tax is touched: the amount of a saved line is left exactly as it was entered.
    tbody.querySelectorAll('tr').forEach((tr) => {
      const taxSel = field(tr, 'tax_code_id'), taxF = field(tr, 'tax_amount'), amtF = field(tr, 'amount');
      if (!taxSel || !taxF || !amtF) return;
      const rate = num(taxes[taxSel.value] || 0);
      const want = r2(num(amtF.value) * rate / 100);
      if (Math.abs(want - num(taxF.value)) > 0.004) taxF.value = want.toFixed(2);
    });
    recalcTotals();
  }

  document.querySelectorAll('table.line-grid').forEach(initGrid);

  // ------------------------------------------------------------------ journal grids: class of the entry and class of the lines
  // The Class chosen for the whole entry is shown on every line that has an account, and a line gets it as soon as
  // its account is chosen. A line whose class was changed by hand keeps its own. With no class on the entry yet,
  // the first class picked on a line becomes the class of the entry.
  document.querySelectorAll('table.line-grid[data-mode="journal"]').forEach((table) => {
    const form = table.closest('form');
    const head = form && form.querySelector('select[name="class_id"]');
    if (!head) return;
    let last = head.value;
    const lineClass = (tr) => tr.querySelector('select[name$="[class_id]"]');
    const hasAccount = (tr) => { const a = tr.querySelector('select[name$="[account_id]"]'); return !!a && a.value !== ''; };
    const spread = () => {
      Array.prototype.forEach.call(table.tBodies[0].rows, (tr) => {
        const ls = lineClass(tr);
        if (ls && hasAccount(tr) && (ls.value === '' || ls.value === last)) ls.value = head.value;
      });
      last = head.value;
    };
    head.addEventListener('change', spread);
    table.addEventListener('change', (e) => {
      const el = e.target;
      const tr = el.closest && el.closest('tr');
      if (!tr || !el.name) return;
      const ls = lineClass(tr);
      if (!ls) return;
      if (/\[account_id\]$/.test(el.name)) {
        if (el.value !== '' && ls.value === '') ls.value = head.value;
        if (el.value === '' && ls.value === head.value) ls.value = '';
      } else if (el === ls && head.value === '' && ls.value !== '') {
        head.value = ls.value;
        head.classList.remove('class-missing');
        spread();
      }
    });
  });

  // ------------------------------------------------------------------ searchable drop-downs
  // <select data-combo> becomes a box you type in: the list narrows with every letter (incremental search) and
  // matches any part of the text, so an account is found by its description and a name by any word of it.
  // The <select> stays in the form (hidden) and keeps posting the value; "change" still fires on it.
  const Combo = (function () {
    let open = null;   // the combo whose list is showing
    const list = document.createElement('div');
    list.className = 'combo-list';
    list.setAttribute('role', 'listbox');
    list.style.display = 'none';
    document.addEventListener('DOMContentLoaded', () => document.body.appendChild(list));
    if (document.body) document.body.appendChild(list);

    const norm = (t) => String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function labelOf(sel) {
      const o = sel.options[sel.selectedIndex];
      return o && o.value !== '' ? o.text : '';
    }

    // The list is put in the part of the window the on-screen keyboard is NOT covering. On a phone held
    // on its side the keyboard takes most of the screen; visualViewport says where the visible band is,
    // so the list is placed and sized to fit inside it instead of ending up behind the keys.
    function place(c) {
      const vv = window.visualViewport;
      const seenTop = vv ? vv.offsetTop : 0;
      const seenH = vv ? vv.height : window.innerHeight;
      const seenW = vv ? vv.width : window.innerWidth;
      const seenLeft = vv ? vv.offsetLeft : 0;
      const seenBottom = seenTop + seenH;
      const r = c.input.getBoundingClientRect();
      // the box itself can be behind the keyboard; the list is hung off the edge of the visible band then
      const from = Math.min(Math.max(r.top, seenTop), seenBottom);
      const to = Math.min(Math.max(r.bottom, seenTop), seenBottom);
      const below = seenBottom - to - 12;
      const above = from - seenTop - 12;
      const under = below >= 150 || below >= above;
      const want = Math.min(Math.max(r.width, 260), seenW - 16);
      list.style.maxHeight = Math.max(90, Math.min(300, under ? below : above)) + 'px';
      list.style.minWidth = want + 'px';
      list.style.left = Math.max(seenLeft + 8, Math.min(r.left, seenLeft + seenW - want - 8)) + 'px';
      if (under) { list.style.top = (to + 2) + 'px'; list.style.bottom = 'auto'; }
      else { list.style.top = 'auto'; list.style.bottom = (window.innerHeight - from + 2) + 'px'; }
    }

    function render(c) {
      const q = norm(c.input.value.trim());
      const words = c.typed && q ? q.split(/\s+/) : [];
      const starts = [], rest = [];
      Array.prototype.forEach.call(c.sel.options, (o) => {
        if (o.value === '' || o.disabled) return;
        const t = norm(o.text);
        if (!words.every((w) => t.indexOf(w) >= 0)) return;
        (words.length && t.indexOf(words[0]) === 0 ? starts : rest).push(o);
      });
      c.items = starts.concat(rest).slice(0, 200);
      const mark = (text) => {
        if (!words.length) return esc(text);
        const t = norm(text); let out = '', i = 0;
        const hits = [];
        words.forEach((w) => { const at = t.indexOf(w); if (at >= 0) hits.push([at, at + w.length]); });
        hits.sort((a, b) => a[0] - b[0]);
        hits.forEach(([a, b]) => { if (a < i) return; out += esc(text.slice(i, a)) + '<mark>' + esc(text.slice(a, b)) + '</mark>'; i = b; });
        return out + esc(text.slice(i));
      };
      // the part in brackets at the end (account code, kind of name) is shown lighter
      const split = (text) => { const m = /^(.*\S)\s+\(([^()]*)\)$/.exec(text); return m ? [m[1], m[2]] : [text, '']; };
      let html = c.blank && !words.length && c.sel.value !== '' ? '<div class="combo-opt combo-clear" data-i="-1">' + esc(c.blankText || 'Clear this box') + '</div>' : '';
      c.items.forEach((o, i) => {
        const [main, tail] = split(o.text);
        html += '<div class="combo-opt' + (o.value === c.sel.value ? ' is-current' : '') + '" role="option" data-i="' + i + '"><span>' + mark(main) + '</span>'
          + (tail ? '<span class="combo-tail">' + mark(tail) + '</span>' : '') + '</div>';
      });
      if (!c.items.length) html += '<div class="combo-none">Nothing matches "' + esc(c.input.value.trim()) + '"</div>';
      list.innerHTML = html;
      c.hi = c.items.length ? 0 : -1;
      if (!words.length) { const cur = c.items.findIndex((o) => o.value === c.sel.value); if (cur >= 0) c.hi = cur; }
      paintHi(c, true);
    }

    function paintHi(c, scroll) {
      list.querySelectorAll('.combo-opt').forEach((el) => el.classList.toggle('is-hi', +el.dataset.i === c.hi));
      const el = list.querySelector('.combo-opt.is-hi');
      if (el && scroll) { const top = el.offsetTop, bot = top + el.offsetHeight; if (top < list.scrollTop) list.scrollTop = top; else if (bot > list.scrollTop + list.clientHeight) list.scrollTop = bot - list.clientHeight; }
    }

    function show(c) {
      if (c.sel.disabled) return;
      open = c;
      list.style.display = 'block';
      place(c);
      render(c);
      // taking the focus makes the browser scroll the box into view, which moves it: measure again once
      // that has happened, so the list is not left where the box used to be
      requestAnimationFrame(() => { if (open === c) place(c); });
    }

    function hide(revert) {
      if (!open) return;
      const c = open;
      open = null;
      list.style.display = 'none';
      c.typed = false;
      if (revert) c.input.value = labelOf(c.sel);
    }

    function choose(c, i) {
      const val = i < 0 ? '' : (c.items[i] ? c.items[i].value : null);
      if (val === null) return;
      const changed = c.sel.value !== val;
      c.sel.value = val;
      hide(true);
      if (changed) c.sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    list.addEventListener('mousedown', (e) => {
      const el = e.target.closest('.combo-opt');
      e.preventDefault();   // keep the focus in the box
      if (el && open) choose(open, +el.dataset.i);
    });
    window.addEventListener('scroll', (e) => { if (open && e.target !== list && !list.contains(e.target)) place(open); }, true);
    window.addEventListener('resize', () => { if (open) place(open); });
    // the keyboard coming up or going down, and the phone being turned
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', () => { if (open) place(open); });
      window.visualViewport.addEventListener('scroll', () => { if (open) place(open); });
    }
    window.addEventListener('orientationchange', () => setTimeout(() => { if (open) place(open); }, 250));

    function enhance(sel) {
      if (sel.dataset.comboReady === '1' || sel.closest('template')) return;
      sel.dataset.comboReady = '1';
      const first = sel.options[0];
      const c = { sel, items: [], hi: -1, typed: false, blank: !!(first && first.value === ''), blankText: first && first.value === '' ? first.text.trim() : '' };
      const wrap = document.createElement('div');
      wrap.className = 'combo';
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-control form-control-sm combo-input';
      input.autocomplete = 'off';
      input.spellcheck = false;
      input.placeholder = sel.dataset.comboPlaceholder || 'Type to search';
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      if (sel.title) input.title = sel.title;
      if (sel.id) { const lab = document.querySelector('label[for="' + sel.id + '"]'); if (lab) { input.id = sel.id + '_combo'; lab.htmlFor = input.id; } }
      c.input = input;
      sel.parentNode.insertBefore(wrap, sel);
      wrap.appendChild(input);
      wrap.appendChild(sel);
      sel.classList.add('combo-src');
      sel.tabIndex = -1;
      if (sel.required) { sel.required = false; input.required = true; }
      input.disabled = sel.disabled;
      input.value = labelOf(sel);

      input.addEventListener('focus', () => { input.select(); show(c); });
      input.addEventListener('click', () => { if (open !== c) { input.select(); show(c); } });
      input.addEventListener('input', () => { c.typed = true; if (open !== c) show(c); else render(c); });
      input.addEventListener('blur', () => {
        if (open !== c) return;
        // leaving with text typed: take the highlighted match; leaving it empty clears the choice
        if (c.typed && input.value.trim() === '' && c.blank) choose(c, -1);
        else if (c.typed && c.hi >= 0) choose(c, c.hi);
        else hide(true);
      });
      input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          if (open !== c) { show(c); return; }
          const n = c.items.length; if (!n) return;
          c.hi = e.key === 'ArrowDown' ? Math.min(n - 1, c.hi + 1) : Math.max(c.blank ? -1 : 0, c.hi - 1);
          paintHi(c, true);
        } else if (e.key === 'Enter') {
          if (open === c) { e.preventDefault(); if (c.typed && input.value.trim() === '' && c.blank) choose(c, -1); else if (c.hi >= -1) choose(c, c.hi); }
        } else if (e.key === 'Escape') {
          if (open === c) { e.preventDefault(); e.stopPropagation(); hide(true); }
        }
      });
      // value changed by other code (clear button, defaults): show it
      sel.addEventListener('change', () => { if (open !== c) input.value = labelOf(sel); });
      sel.comboSync = () => { input.value = labelOf(sel); input.disabled = sel.disabled; };
    }

    // Every long drop-down searches as you type: customers, vendors, employees and other names, items,
    // fixed assets, accounts ... Short lists (a status, a method, a yes/no) stay plain drop-downs.
    // A select can be kept plain with data-no-combo, or forced on with data-combo.
    const AUTO_MIN = 4;
    function auto(sel) {
      if (sel.multiple || sel.hasAttribute('data-no-combo') || sel.closest('[data-no-combo]')) return false;
      if (sel.hasAttribute('data-combo')) return true;
      let n = 0;
      for (let i = 0; i < sel.options.length; i++) { if (sel.options[i].value !== '') n++; if (n > AUTO_MIN) return true; }
      return false;
    }
    function init(root) {
      (root || document).querySelectorAll('select').forEach((sel) => { if (auto(sel)) enhance(sel); });
    }
    return { init, sync: (root) => (root || document).querySelectorAll('select[data-combo]').forEach((s) => s.comboSync && s.comboSync()) };
  })();
  window.AppCombo = Combo;
  Combo.init(document);
  document.addEventListener('reset', (e) => setTimeout(() => Combo.sync(e.target), 0));

  // ------------------------------------------------------------------ wide tables on a small screen
  // A table with more columns than the screen has room for used to push the whole page sideways, so on a
  // phone the menu bar and the buttons slid out of reach. Every table that is not already inside a box of
  // its own gets one here: the columns are swiped inside the box and the page itself stays where it is.
  // Left alone: tables whose heading is pinned to the window (a box of their own would stop that), the
  // totals block of a transaction, and anything already in a scrolling box.
  document.querySelectorAll('table.table').forEach((t) => {
    if (t.closest('.table-responsive') || t.classList.contains('totals-box') || t.querySelector('thead.sticky-top')) return;
    const par = t.parentNode;
    if (!par || par.nodeType !== 1) return;
    const box = document.createElement('div');
    box.className = 'table-responsive';
    par.insertBefore(box, t);
    box.appendChild(t);
  });

  // a soft shadow down the right-hand edge while there are still columns out of sight
  document.querySelectorAll('.table-responsive').forEach((box) => {
    const upd = () => box.classList.toggle('has-more', box.scrollWidth - box.clientWidth - box.scrollLeft > 2);
    box.addEventListener('scroll', upd, { passive: true });
    window.addEventListener('resize', upd);
    upd();
  });

  // ------------------------------------------------------------------ misc
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (e) => { if (!confirm(el.dataset.confirm)) e.preventDefault(); });
  });

  // ------------------------------------------------------------------ saving without a class
  // Any transaction form with a Class box asks for confirmation when it is saved with no class chosen.
  // Skipped for: forms or buttons marked data-no-class-check, forms whose data-class-check-when amounts are all empty, buttons that do not save (preview, load lines),
  // a Class box that is hidden or has no classes to choose from.
  (function () {
    const NOT_SAVING = ['preview', 'load', 'save_units', 'suspend'];
    let modalEl = null, modal = null, onContinue = null, focusBack = null;

    function build() {
      modalEl = document.createElement('div');
      modalEl.className = 'modal fade class-confirm';
      modalEl.id = 'classConfirmModal';
      modalEl.tabIndex = -1;
      modalEl.setAttribute('aria-labelledby', 'classConfirmTitle');
      modalEl.innerHTML =
        '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        + '<div class="modal-header"><span class="class-confirm-ico"><i class="bi bi-tag"></i></span>'
        + '<h5 class="modal-title" id="classConfirmTitle">No class selected</h5>'
        + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        + '<div class="modal-body"><p class="mb-2">This transaction has no class. It will be saved as <strong>Unclassified</strong> and will not appear under any class on the class reports.</p>'
        + '<p class="mb-0 text-muted small">Do you want to continue saving?</p></div>'
        + '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-act="back"><i class="bi bi-arrow-left"></i> Choose a class</button>'
        + '<button type="button" class="btn btn-primary" data-act="go"><i class="bi bi-check2"></i> Continue saving</button></div>'
        + '</div></div>';
      document.body.appendChild(modalEl);
      modal = new bootstrap.Modal(modalEl);
      modalEl.querySelector('[data-act="go"]').addEventListener('click', () => { const f = onContinue; onContinue = null; focusBack = null; modal.hide(); if (f) f(); });
      modalEl.querySelector('[data-act="back"]').addEventListener('click', () => modal.hide());
      modalEl.addEventListener('shown.bs.modal', () => modalEl.querySelector('[data-act="back"]').focus());
      modalEl.addEventListener('hidden.bs.modal', () => {
        onContinue = null;
        // after Bootstrap has handed the focus back to the Save button, move it to the Class box
        const sel = focusBack;
        focusBack = null;
        if (sel) { sel.classList.add('class-missing'); setTimeout(() => { try { sel.focus(); } catch (e) { /* ignore */ } }, 30); }
      });
    }

    document.addEventListener('change', (e) => { if (e.target.matches && e.target.matches('select.class-missing')) e.target.classList.remove('class-missing'); });

    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement) || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
      if (form.dataset.classConfirmed === '1' || form.hasAttribute('data-no-class-check')) return;
      const btn = e.submitter || null;
      if (btn && (btn.hasAttribute('data-no-class-check') || btn.hasAttribute('formnovalidate'))) return;
      if (btn && NOT_SAVING.indexOf(btn.value) >= 0) return;
      if (btn && NOT_SAVING.indexOf(btn.dataset.do || '') >= 0) return;
      const sel = form.querySelector('select[name="class_id"]');
      if (!sel || sel.disabled || sel.value !== '' || sel.offsetParent === null) return;
      if (!Array.prototype.some.call(sel.options, (o) => o.value !== '')) return;   // no classes set up yet
      // journal entries: nothing to ask when every line that is filled in carries its own class
      // (a cash voucher still asks, because the class at the top is the one its cash line gets)
      const lineSels = Array.prototype.filter.call(form.querySelectorAll('select[name$="[class_id]"]'), (ls) => {
        const tr = ls.closest('tr');
        const acc = tr && tr.querySelector('select[name$="[account_id]"]');
        return acc && acc.value !== '';
      });
      if (lineSels.length && !form.querySelector('select[name="account_id"]') && lineSels.every((ls) => ls.value !== '')) return;
      // forms where the class only matters when certain amounts are entered (bank reconciliation: service charge, interest)
      const when = form.getAttribute('data-class-check-when');
      if (when && !Array.prototype.some.call(form.querySelectorAll(when), (i) => num(i.value) > 0)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      const go = () => {
        form.dataset.classConfirmed = '1';
        if (typeof form.requestSubmit === 'function') { btn && btn.form === form ? form.requestSubmit(btn) : form.requestSubmit(); }
        else { form.submit(); }
        setTimeout(() => { delete form.dataset.classConfirmed; }, 0);   // ask again if the page stays open (validation stopped the save)
      };
      if (!window.bootstrap || !bootstrap.Modal) {
        if (window.confirm('No class was selected. Continue saving without a class?')) go(); else sel.focus();
        return;
      }
      if (!modalEl) build();
      onContinue = go;
      focusBack = sel;
      modal.show();
    }, true);
  })();

  // ------------------------------------------------------------------ Memorize / Load memorized (modules/txn/_memorize.php)
  // "Memorize" in the box sends the form with memorize=1: the server checks it as for saving and keeps it on the
  // Memorized Transactions list instead of recording it. Nothing else is asked (class, stock) because nothing is saved.
  document.addEventListener('click', (e) => {
    const go = e.target.closest('[data-memorize-go]');
    if (!go) return;
    const box = go.closest('.modal');
    const form = go.closest('form') || (box && box.closest('form'));
    if (!form) return;
    const name = form.querySelector('[data-mem-name]');
    if (name && name.value.trim() === '') { name.classList.add('is-invalid'); name.focus(); return; }
    form.elements.memorize.value = '1';
    form.dataset.classConfirmed = '1';
    if (form.elements.confirm_negative) form.elements.confirm_negative.value = '1';
    const nv = form.noValidate;
    form.noValidate = true;
    if (window.bootstrap && box) bootstrap.Modal.getOrCreateInstance(box).hide();
    if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    setTimeout(() => { form.noValidate = nv; form.elements.memorize.value = '0'; delete form.dataset.classConfirmed; if (form.elements.confirm_negative) form.elements.confirm_negative.value = '0'; }, 0);
  });
  document.addEventListener('input', (e) => { if (e.target.matches && e.target.matches('[data-mem-name]')) e.target.classList.remove('is-invalid'); });
  // loading a memorized transaction replaces what is on the form: ask first when something was typed
  let formTouched = false;
  document.addEventListener('input', (e) => { if (e.target.closest && e.target.closest('main form[method="post"]') && !e.target.closest('.modal')) formTouched = true; });
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-mem-load]');
    if (a && formTouched && !window.confirm('Replace what is on the form with the memorized transaction?')) e.preventDefault();
  });

  // ------------------------------------------------------------------ stock below zero: ask before saving
  // A form carrying confirm_negative (sales invoice, sales receipt, POS, inventory adjustment and transfer) is
  // not saved when it would take an item below zero at a site: the page comes back with the question
  // "Saving the transaction will lead the inventory to a negative balance. Do you want to continue?".
  // Yes sends the form again, with the button that was pressed the first time; No leaves the form as it is
  // to be changed or cancelled. window.AppNegStock.ask() is also used by the POS register before it sends.
  (function () {
    // the button pressed is remembered, so Yes can press the same one again
    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement) || !form.elements.confirm_submitter) return;
      const b = e.submitter;
      form.elements.confirm_submitter.value = b && b.name ? b.name + '=' + (b.value || '') : '';
    }, true);

    let modalEl = null, modal = null, onYes = null, onNo = null;
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    function build() {
      modalEl = document.createElement('div');
      modalEl.className = 'modal fade class-confirm neg-stock-confirm';
      modalEl.id = 'negStockModal';
      modalEl.tabIndex = -1;
      modalEl.setAttribute('aria-labelledby', 'negStockTitle');
      modalEl.setAttribute('data-bs-backdrop', 'static');
      modalEl.innerHTML =
        '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        + '<div class="modal-header"><span class="class-confirm-ico neg-ico"><i class="bi bi-exclamation-triangle"></i></span>'
        + '<h5 class="modal-title" id="negStockTitle">Stock will go below zero</h5></div>'
        + '<div class="modal-body"><p class="mb-2">Saving the transaction will lead the inventory to a negative balance.</p>'
        + '<div class="neg-stock-list"></div>'
        + '<p class="mb-0 fw-semibold">Do you want to continue?</p></div>'
        + '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-act="no"><i class="bi bi-x-lg"></i> No</button>'
        + '<button type="button" class="btn btn-warning" data-act="yes"><i class="bi bi-check2"></i> Yes</button></div>'
        + '</div></div>';
      document.body.appendChild(modalEl);
      modal = new bootstrap.Modal(modalEl, { backdrop: 'static' });
      modalEl.querySelector('[data-act="yes"]').addEventListener('click', () => { const f = onYes; onYes = null; onNo = null; modal.hide(); if (f) f(); });
      modalEl.querySelector('[data-act="no"]').addEventListener('click', () => modal.hide());
      modalEl.addEventListener('shown.bs.modal', () => modalEl.querySelector('[data-act="no"]').focus());
      modalEl.addEventListener('hidden.bs.modal', () => { const f = onNo; onYes = null; onNo = null; if (f) f(); });
    }
    /** rows: [{code, name?, site?, before, after}] */
    function ask(rows, yes, no) {
      const text = 'Saving the transaction will lead the inventory to a negative balance.\n\n'
        + rows.map((r) => r.code + (r.site ? ' at ' + r.site : '') + ': ' + r.before + ' on hand, would be left with ' + r.after).join('\n')
        + '\n\nDo you want to continue?';
      if (!window.bootstrap || !bootstrap.Modal) { if (window.confirm(text)) yes(); else if (no) no(); return; }
      if (!modalEl) build();
      modalEl.querySelector('.neg-stock-list').innerHTML = '<div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Item</th>'
        + (rows.some((r) => r.site) ? '<th>Site</th>' : '') + '<th class="num">On hand</th><th class="num">After saving</th></tr></thead><tbody>'
        + rows.map((r) => '<tr><td>' + esc(r.code) + (r.name ? '<div class="small text-muted">' + esc(r.name) + '</div>' : '') + '</td>'
          + (rows.some((x) => x.site) ? '<td>' + esc(r.site || '') + '</td>' : '')
          + '<td class="num">' + esc(r.before) + '</td><td class="num neg">' + esc(r.after) + '</td></tr>').join('')
        + '</tbody></table></div>';
      onYes = yes; onNo = no || null;
      modal.show();
    }
    /** send the form again with the answer Yes */
    function resend(form, btn) {
      form.elements.confirm_negative.value = '1';
      form.dataset.classConfirmed = '1';
      if (typeof form.requestSubmit === 'function') { btn && btn.form === form ? form.requestSubmit(btn) : form.requestSubmit(); }
      else {
        if (btn && btn.name) { const h = document.createElement('input'); h.type = 'hidden'; h.name = btn.name; h.value = btn.value; form.appendChild(h); }
        form.submit();
      }
      setTimeout(() => { delete form.dataset.classConfirmed; }, 0);
    }
    window.AppNegStock = { ask, resend };

    // the page came back with the question
    const alertEl = document.querySelector('[data-negative-stock]');
    const field = document.querySelector('form input[name="confirm_negative"]');
    if (!alertEl || !field) return;
    const form = field.form;
    let rows = [];
    try { rows = JSON.parse(alertEl.dataset.negativeStock || '[]'); } catch (e) { rows = []; }
    const pressed = (form.elements.confirm_submitter ? form.elements.confirm_submitter.value : '').split('=');
    const btn = pressed[0] ? Array.prototype.find.call(form.querySelectorAll('button[name], input[type="submit"][name]'),
      (b) => b.name === pressed[0] && String(b.value || '') === pressed.slice(1).join('=')) || null : null;
    const show = () => ask(rows, () => resend(form, btn));
    if (document.readyState === 'complete') setTimeout(show, 50); else window.addEventListener('load', () => setTimeout(show, 50));
  })();

  // ------------------------------------------------------------------ long forms travel in one field
  // PHP reads only the first max_input_vars fields of a form (1000 as installed) and silently drops the rest:
  // a bill of 122 lines has more than 1,400 fields and would be saved without its last 40 lines. A long form
  // is therefore sent as one field, _packed, holding every [name, value] pair; the server unfolds it again
  // (unpack_posted_form in app/helpers.php). This runs last, after every other submit handler has had its say.
  const PACK_ABOVE = 300;
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if ((form.getAttribute('enctype') || '').toLowerCase() === 'multipart/form-data') return;
    const named = Array.prototype.filter.call(form.elements, (el) => el.name && !el.disabled);
    if (named.length <= PACK_ABOVE) return;
    let fd;
    try { fd = new FormData(form, e.submitter || undefined); } catch (err) { fd = new FormData(form); if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value); }
    const pairs = [];
    let csrf = '';
    fd.forEach((v, k) => { if (k === '_csrf') { csrf = v; } else if (typeof v === 'string') { pairs.push([k, v]); } });
    // everything else is left out of this submission; the boxes are given back at once, so a form that opens in
    // another tab, or a page the browser keeps, still works
    const off = named.filter((el) => el.name !== '_csrf');
    off.forEach((el) => { el.disabled = true; });
    if (e.submitter && e.submitter.name) { e.submitter.dataset.packName = e.submitter.name; e.submitter.removeAttribute('name'); }
    let box = form.querySelector('input[name="_packed"]');
    if (!box) { box = document.createElement('input'); box.type = 'hidden'; box.name = '_packed'; form.appendChild(box); }
    box.value = JSON.stringify(pairs);
    if (!form.querySelector('input[name="_csrf"]') && csrf) { const c = document.createElement('input'); c.type = 'hidden'; c.name = '_csrf'; c.value = csrf; form.appendChild(c); }
    setTimeout(() => {
      off.forEach((el) => { el.disabled = false; });
      if (e.submitter && e.submitter.dataset.packName) { e.submitter.name = e.submitter.dataset.packName; delete e.submitter.dataset.packName; }
      box.remove();
    }, 0);
  });

  document.querySelectorAll('[data-check-all]').forEach((master) => {
    master.addEventListener('change', () => {
      document.querySelectorAll(master.dataset.checkAll).forEach((cb) => { cb.checked = master.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); });
    });
  });

  // auto-submit selects
  document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

  // A drop-down list of check boxes (multi_check_field): the button shows what is ticked; a filter form is sent
  // when the list is closed after a change, or by its Show button.
  document.querySelectorAll('[data-multi-check]').forEach((box) => {
    const btn = box.querySelector('.multi-check-btn');
    const text = box.querySelector('.multi-check-text');
    const boxes = () => Array.from(box.querySelectorAll('.multi-check-list input[type=checkbox]'));
    const state = () => boxes().filter((c) => c.checked).map((c) => c.value).join(',');
    const label = () => {
      const names = boxes().filter((c) => c.checked).map((c) => c.closest('label').textContent.trim());
      text.textContent = names.length === 0 ? box.dataset.all
        : names.length <= 2 ? names.join(', ') : names[0] + ', ' + names[1] + ' and ' + (names.length - 2) + ' more ' + box.dataset.noun;
      btn.classList.toggle('has-picks', names.length > 0);
      if (names.length) btn.classList.remove('is-invalid');
    };
    let before = state();
    box.addEventListener('change', (e) => { if (e.target.matches('.multi-check-list input')) label(); });
    const all = box.querySelector('[data-mc-all]');
    const none = box.querySelector('[data-mc-none]');
    const fire = () => box.dispatchEvent(new Event('change', { bubbles: true }));
    if (all) all.addEventListener('click', () => { boxes().forEach((c) => { if (!c.closest('.multi-check-item').hidden) c.checked = true; }); label(); fire(); });
    if (none) none.addEventListener('click', () => { boxes().forEach((c) => { c.checked = false; }); label(); fire(); });
    const find = box.querySelector('[data-mc-find]');
    if (find) {
      find.addEventListener('input', () => {
        const words = find.value.toLowerCase().split(/\s+/).filter(Boolean);
        box.querySelectorAll('.multi-check-item').forEach((it) => {
          const t = it.textContent.toLowerCase();
          it.hidden = !words.every((w) => t.indexOf(w) >= 0);
        });
        // a group heading shows while one of its choices does
        box.querySelectorAll('[data-mc-group]').forEach((g) => {
          let n = g.nextElementSibling; let any = false;
          while (n && !n.hasAttribute('data-mc-group')) { if (!n.hidden) any = true; n = n.nextElementSibling; }
          g.hidden = !any;
        });
      });
      find.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    }
    btn.addEventListener('shown.bs.dropdown', () => { before = state(); if (find) find.focus(); });
    if (box.hasAttribute('data-multi-submit')) {
      btn.addEventListener('hidden.bs.dropdown', () => { if (state() !== before && box.closest('form')) box.closest('form').submit(); });
    }
    label();
  });

  // Supervisor approval box: by the supervisor's password, or by a one-time approval code
  document.querySelectorAll('[data-approval]').forEach((box) => {
    const show = (way) => {
      box.querySelectorAll('.approval-tab').forEach((b) => b.classList.toggle('is-on', b.dataset.way === way));
      box.querySelectorAll('[data-way-panel]').forEach((p) => { p.hidden = p.dataset.wayPanel !== way; p.querySelectorAll('input').forEach((i) => { if (p.hidden) i.value = ''; }); });
    };
    box.querySelectorAll('.approval-tab').forEach((b) => b.addEventListener('click', () => show(b.dataset.way)));
    const code = box.querySelector('.approval-code');
    if (code) code.addEventListener('input', () => {
      const v = code.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
      code.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4) : v;
    });
  });

  // Incremental search: as the user types in an input with data-live-filter="<selector of the rows>", the rows
  // whose text does not contain every typed word are hidden at once, with no reload. Enter still sends the form
  // (a barcode scan, or a search the server should run). The matching count is shown in [data-live-count].
  document.querySelectorAll('input[data-live-filter]').forEach((input) => {
    const count = document.querySelector(input.dataset.liveCount || '#__none');
    const empty = document.querySelector(input.dataset.liveEmpty || '#__none');
    const rows = [...document.querySelectorAll(input.dataset.liveFilter)].filter((r) => r !== empty);
    if (!rows.length) return;
    const texts = rows.map((r) => (r.dataset.filterText || r.textContent).toLowerCase().replace(/\s+/g, ' '));
    const apply = () => {
      const words = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
      let shown = 0;
      // a class, not the hidden attribute: Bootstrap's d-flex (display: flex !important) would win over hidden
      rows.forEach((r, i) => { const hit = words.every((w) => texts[i].includes(w)); r.classList.toggle('live-hide', !hit); if (hit) shown++; });
      if (count) count.textContent = words.length ? shown + ' of ' + rows.length : rows.length + '';
      if (empty) empty.classList.toggle('live-hide', shown > 0);
      const first = rows.find((r) => !r.classList.contains('live-hide'));
      rows.forEach((r) => r.classList.remove('live-first'));
      if (first && words.length) first.classList.add('live-first');
    };
    if (empty) empty.classList.add('live-hide');
    input.addEventListener('input', apply);
    // Enter opens the first match instead of reloading the page, unless nothing matched (then the server searches)
    input.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      const first = rows.find((r) => !r.classList.contains('live-hide'));
      const link = first && input.value.trim() ? (first.matches('a[href]') ? first : first.querySelector('a[href]')) : null;
      if (link && !input.hasAttribute('data-live-enter-submits')) { e.preventDefault(); window.location.href = link.href; }
    });
    if (input.value) apply();
  });

  // Sum of inputs matching a selector into a target
  document.querySelectorAll('[data-sum-into]').forEach((container) => {
    const target = document.querySelector(container.dataset.sumInto);
    const sel = container.dataset.sumSelector || 'input[data-sum]';
    const update = () => {
      let s = 0;
      container.querySelectorAll(sel).forEach((i) => { s += num(i.value); });
      if (target) target.textContent = fmt(s);
      document.dispatchEvent(new CustomEvent('sum:update', { detail: { id: container.id, sum: s } }));
    };
    container.addEventListener('input', update);
    container.addEventListener('change', update);
    update();
  });

  // Checkbox that fills an amount input with its data-amount (payments, deposits, bills)
  document.querySelectorAll('input[type=checkbox][data-fill]').forEach((cb) => {
    cb.addEventListener('change', () => {
      const target = document.querySelector(cb.dataset.fill);
      if (!target) return;
      target.value = cb.checked ? num(cb.dataset.amount).toFixed(2) : '';
      target.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  // toggle sections by select value: data-toggle-by="#selectId" data-show-when="a,b"
  document.querySelectorAll('[data-toggle-by]').forEach((el) => {
    const src = document.querySelector(el.dataset.toggleBy);
    if (!src) return;
    const upd = () => {
      const vals = el.dataset.showWhen.split(',');
      const v = src.type === 'checkbox' ? (src.checked ? '1' : '0') : src.value;
      el.style.display = vals.indexOf(v) >= 0 ? '' : 'none';
    };
    src.addEventListener('change', upd);
    upd();
  });

  // Tree toggles for page access
  document.querySelectorAll('[data-module-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const mod = btn.dataset.moduleToggle;
      const val = btn.dataset.value;
      document.querySelectorAll('select[data-module="' + mod + '"]').forEach((s) => { s.value = val; s.dispatchEvent(new Event('change', { bubbles: true })); });
      document.querySelectorAll('input[type=checkbox][data-module="' + mod + '"][data-kind="' + (btn.dataset.kind || 'allowed') + '"]').forEach((c) => { c.checked = val === '1'; });
    });
  });
})();
