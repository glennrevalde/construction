/* GSMaRT Construction System - the shell: rail sections, the pages panel, the phone drawer. No library needed. */
(function () {
  'use strict';
  var root = document.documentElement;
  var panel = document.getElementById('gsPanel');
  if (!panel) return;
  var mobile = window.matchMedia('(max-width: 991.98px)');

  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* private window: no memory, nothing breaks */ } }

  function showSection(code, all) {
    panel.querySelectorAll('[data-gs-panel]').forEach(function (s) {
      s.hidden = !all && s.getAttribute('data-gs-panel') !== code;
    });
    document.querySelectorAll('.gs-rail-btn').forEach(function (b) {
      var on = b.getAttribute('data-gs-section') === code;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function openDrawer() {
    root.classList.add('gs-drawer-on');
    var f = panel.querySelector('[data-gs-page-filter]');
    setTimeout(function () { (panel.querySelector('.gs-link.is-current') || f || panel).focus({ preventScroll: false }); }, 60);
  }
  function closeDrawer() { root.classList.remove('gs-drawer-on'); }

  // the rail: a section opens its pages in the panel (and shows the panel if it was hidden)
  document.querySelectorAll('.gs-rail-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var code = b.getAttribute('data-gs-section');
      var wasActive = b.classList.contains('is-active');
      if (wasActive && !root.classList.contains('gs-panel-off')) {
        root.classList.add('gs-panel-off');
        store('gs.panel', 'off');
        return;
      }
      showSection(code, false);
      root.classList.remove('gs-panel-off');
      store('gs.panel', null);
    });
  });

  // the phone bar: a section, or every section
  document.querySelectorAll('[data-gs-drawer-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.hasAttribute('data-gs-all')) showSection(null, true);
      else showSection(b.getAttribute('data-gs-section'), false);
      openDrawer();
    });
  });
  document.querySelectorAll('[data-gs-drawer-close]').forEach(function (b) { b.addEventListener('click', closeDrawer); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && root.classList.contains('gs-drawer-on')) closeDrawer(); });
  mobile.addEventListener && mobile.addEventListener('change', function () { if (!mobile.matches) closeDrawer(); });

  // the button beside the company name hides or shows the pages (desktop)
  var t = document.querySelector('[data-gs-panel-toggle]');
  if (t) t.addEventListener('click', function () {
    var off = root.classList.toggle('gs-panel-off');
    store('gs.panel', off ? 'off' : null);
  });

  // "Find a page": narrows every section of the panel as you type
  var filter = panel.querySelector('[data-gs-page-filter]');
  var active = (document.querySelector('.gs-rail-btn.is-active') || {}).getAttribute ? document.querySelector('.gs-rail-btn.is-active').getAttribute('data-gs-section') : null;
  if (filter) {
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      var words = q.split(/\s+/).filter(Boolean);
      if (!words.length) {
        panel.querySelectorAll('.is-filtered').forEach(function (el) { el.classList.remove('is-filtered'); });
        showSection(active, false);
        return;
      }
      showSection(null, true);
      panel.querySelectorAll('[data-gs-panel]').forEach(function (sec) {
        var any = false;
        sec.querySelectorAll('.gs-mod').forEach(function (mod) {
          var hits = 0;
          mod.querySelectorAll('.gs-link').forEach(function (a) {
            var text = a.textContent.toLowerCase();
            var ok = words.every(function (w) { return text.indexOf(w) !== -1; });
            a.classList.toggle('is-filtered', !ok);
            if (ok) hits++;
          });
          mod.querySelectorAll('.gs-group, .gs-sub').forEach(function (g) { g.classList.toggle('is-filtered', true); });
          mod.classList.toggle('is-filtered', hits === 0);
          if (hits) { mod.open = true; mod.querySelectorAll('details').forEach(function (d) { d.open = true; }); }
          any = any || hits > 0;
        });
        sec.classList.toggle('is-filtered', !any);
      });
    });
    filter.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        var first = panel.querySelector('.gs-link:not(.is-filtered)');
        if (first && !first.closest('.is-filtered')) { e.preventDefault(); window.location.href = first.href; }
      }
    });
  }

  // keep the current page in view inside the panel
  var cur = panel.querySelector('.gs-link.is-current');
  if (cur && cur.scrollIntoView && !mobile.matches) {
    var r = cur.getBoundingClientRect();
    if (r.bottom > window.innerHeight - 40) cur.scrollIntoView({ block: 'center' });
  }
})();
