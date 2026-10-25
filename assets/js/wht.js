/* GSMaRT Construction System - the Withholding cell of Pay Bills and Receive Payments (Withholding::picker).
 *
 * A button that opens the types of the Withholding Tax Table; ticking a type works out its amount from the tax base
 * (net of VAT, or gross for a type set up that way) and the amount can be typed over. The total goes to the line's
 * hidden "withholding" field, which fires a bubbling "change" so the page can work out the amount to pay.
 * Loaded in <head>, so the page's own script can call AppWht before the page has finished loading.
 */
window.AppWht = (function () {
  'use strict';
  const num = (v) => { const n = parseFloat(String(v == null ? '' : v).replace(/,/g, '')); return isNaN(n) ? 0 : n; };
  const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
  const fmt = (v) => v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const rateText = (r) => String(r).replace(/\.?0+$/, '') + '%';

  const baseIn = (p) => p.querySelector('.wht-base-in');
  const rows = (p) => Array.from(p.querySelectorAll('.wht-row'));
  const ratio = (p) => num(p.dataset.ratio) || 1;

  /** the base of one type: the net base, or the gross amount it came from */
  const baseFor = (p, cb) => {
    const net = num(baseIn(p).value);
    return cb.dataset.basis === 'gross' ? r2(net / ratio(p)) : net;
  };

  /** the amounts of the ticked types that were not typed over, then the total */
  function recalc(p, silent) {
    rows(p).forEach((row) => {
      const cb = row.querySelector('.wht-on');
      const amt = row.querySelector('.wht-amt');
      row.classList.toggle('is-on', cb.checked);
      amt.disabled = !cb.checked;
      if (!cb.checked) { amt.value = ''; delete amt.dataset.typed; return; }
      if (amt.dataset.typed !== '1') {
        const v = r2(baseFor(p, cb) * num(cb.dataset.rate) / 100);
        amt.value = v > 0 ? v.toFixed(2) : '';
      }
    });
    total(p, silent);
  }

  function total(p, silent) {
    let s = 0;
    const rates = [];
    rows(p).forEach((row) => {
      const cb = row.querySelector('.wht-on');
      if (cb.checked) { s += num(row.querySelector('.wht-amt').value); rates.push(rateText(cb.dataset.rate)); }
    });
    s = r2(s);
    const hid = p.querySelector('.wht-total');
    const before = hid.value;
    hid.value = s > 0.004 ? s.toFixed(2) : '';
    p.querySelector('.wht-sum').textContent = s > 0.004 ? fmt(s) : '';
    p.querySelector('.wht-sum2').textContent = fmt(s);
    p.querySelector('.wht-rates').textContent = rates.join(' + ');
    p.classList.toggle('has-wht', s > 0.004);
    if (!silent && before !== hid.value) hid.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /** the amount the line settles (VAT included) changed: the base follows it until a base is typed */
  function setGross(p, gross) {
    const b = baseIn(p);
    if (b.dataset.typed !== '1') {
      const v = r2(Math.max(0, gross) * ratio(p));
      b.value = v > 0 ? v.toFixed(2) : '';
    }
    recalc(p);
  }

  /** the page tells the picker what its line would settle, so the base is there even before the line is ticked */
  function source(p, fn) { p.whtGross = fn; }

  /** the base follows the line's outstanding amount (from the page, or data-gross) until a base is typed */
  function follow(p) {
    const b = baseIn(p);
    if (!b || b.dataset.typed === '1') return;
    const g = typeof p.whtGross === 'function' ? num(p.whtGross()) : (p.dataset.gross !== undefined ? num(p.dataset.gross) : null);
    if (g === null) return;
    const v = r2(Math.max(0, g) * ratio(p));
    b.value = v > 0 ? v.toFixed(2) : '';
  }

  /** the supplier's (customer's) usual types are ticked when the line is chosen, unless the user already chose */
  function useDefaults(p) {
    if (p.dataset.touched === '1') return;
    const ids = String(p.dataset.default || '').split(',').filter(Boolean);
    rows(p).forEach((row) => { const cb = row.querySelector('.wht-on'); if (ids.indexOf(cb.dataset.id) >= 0) cb.checked = true; });
    recalc(p);
  }

  function clear(p) {
    rows(p).forEach((row) => { row.querySelector('.wht-on').checked = false; });
    delete baseIn(p).dataset.typed;
    delete p.dataset.touched;
    recalc(p);
  }

  const value = (p) => num(p.querySelector('.wht-total').value);

  function wire(p) {
    if (p.dataset.wired) return;
    p.dataset.wired = '1';
    const btn = p.querySelector('.wht-btn');
    const menu = p.querySelector('.wht-menu');
    let dd = null;
    if (window.bootstrap && btn && !btn.disabled) {
      // fixed, so the list is not cut off by the scrolling table around it
      dd = new bootstrap.Dropdown(btn, { autoClose: 'outside', popperConfig: (d) => Object.assign({}, d, { strategy: 'fixed' }) });
      btn.addEventListener('click', () => dd.toggle());
      // opening the list shows the base worked out from the outstanding balance, ready for a tick
      btn.addEventListener('show.bs.dropdown', () => { follow(p); recalc(p, true); });
    }
    menu.addEventListener('change', (e) => {
      if (e.target.matches('.wht-on')) { p.dataset.touched = '1'; follow(p); recalc(p); }
    });
    menu.addEventListener('input', (e) => {
      if (e.target.matches('.wht-amt')) { e.target.dataset.typed = '1'; p.dataset.touched = '1'; total(p); }
      if (e.target.matches('.wht-base-in')) { e.target.dataset.typed = '1'; recalc(p); }
    });
    menu.querySelector('.wht-clear')?.addEventListener('click', () => {
      rows(p).forEach((row) => { const a = row.querySelector('.wht-amt'); delete a.dataset.typed; row.querySelector('.wht-on').checked = false; });
      p.dataset.touched = '1';
      recalc(p);
    });
    menu.querySelector('.wht-done')?.addEventListener('click', () => { if (dd) dd.hide(); btn.focus(); });
    total(p, true);
  }

  function init(root) { (root || document).querySelectorAll('[data-wht]').forEach(wire); }
  document.addEventListener('DOMContentLoaded', () => init());

  return { init, setGross, useDefaults, clear, value, recalc, source };
})();
