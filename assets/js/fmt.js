/* Number helpers shared by app.js and inline page scripts. Loaded in <head>. */
window.AppFmt = (function () {
  'use strict';
  const num = (v) => {
    if (v === null || v === undefined) return 0;
    const n = parseFloat(String(v).replace(/,/g, ''));
    return isNaN(n) ? 0 : n;
  };
  const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
  const fmt = (v) => {
    const s = Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return v < 0 ? '(' + s + ')' : s;
  };
  return { num, r2, fmt };
})();
