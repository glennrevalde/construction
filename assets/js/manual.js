/* GSMaRT Construction System - the User's Manual (manual.php): search, "on this page", enlarged screenshots. */
(function () {
  'use strict';

  // ---------------------------------------------------------------- search
  const searchUrl = document.body.dataset.search;
  let data = null, loading = null;
  const load = () => {
    if (data) return Promise.resolve(data);
    if (!loading) {
      loading = fetch(searchUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : []))
        .then((rows) => {
          data = rows.map((r) => Object.assign(r, { tl: r.t.toLowerCase(), xl: r.x.toLowerCase() }));
          return data;
        })
        .catch(() => (data = []));
    }
    return loading;
  };
  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const words = (q) => q.toLowerCase().split(/\s+/).map((w) => w.replace(/[^\p{L}\p{N}/&.-]/gu, '')).filter((w) => w.length > 1);

  function find(q) {
    const ws = words(q);
    if (!ws.length) return [];
    const phrase = q.trim().toLowerCase();
    const out = [];
    for (const r of data) {
      let score = 0, ok = true;
      for (const w of ws) {
        const inT = r.tl.includes(w), n = inT ? 1 : (r.xl.includes(w) ? 1 : 0);
        if (!n) { ok = false; break; }
        score += inT ? 12 : 0;
        let i = -1, c = 0;
        while (c < 8 && (i = r.xl.indexOf(w, i + 1)) !== -1) c++;
        score += c;
      }
      if (!ok) continue;
      if (ws.length > 1 && (r.tl.includes(phrase) || r.xl.includes(phrase))) score += r.tl.includes(phrase) ? 30 : 10;
      if (r.tl === phrase) score += 40;
      out.push([score, r]);
    }
    return out.sort((a, b) => b[0] - a[0]).slice(0, 30).map((x) => x[1]);
  }

  function snippet(r, ws) {
    const x = r.x;
    let at = -1;
    for (const w of ws) { const i = r.xl.indexOf(w); if (i !== -1 && (at === -1 || i < at)) at = i; }
    let s = at > 60 ? '...' + x.slice(at - 50, at + 150) : x.slice(0, 200);
    s = esc(s);
    for (const w of ws) s = s.replace(new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'), '<mark>$1</mark>');
    return s;
  }

  function hrefOf(r) { return 'manual.php?c=' + encodeURIComponent(r.c) + '#' + encodeURIComponent(r.id); }

  document.querySelectorAll('.man-search').forEach((box) => {
    const input = box.querySelector('input');
    const list = box.querySelector('.man-results');
    let active = -1;
    const close = () => { list.hidden = true; active = -1; };
    const show = () => {
      const q = input.value.trim();
      if (q.length < 2) { close(); return; }
      load().then(() => {
        const ws = words(q);
        const rows = find(q);
        list.innerHTML = rows.length
          ? rows.map((r) => '<a href="' + hrefOf(r) + '"><div class="r-t">' + esc(r.t) + '</div><div class="r-c">' + esc(r.ct) + '</div><div class="r-x">' + snippet(r, ws) + '</div></a>').join('')
          : '<div class="r-none">Nothing in the manual matches "' + esc(q) + '".</div>';
        list.hidden = false;
        active = -1;
      });
    };
    let timer = null;
    input.addEventListener('focus', () => { load(); if (input.value.trim().length > 1) show(); });
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(show, 120); });
    input.addEventListener('keydown', (e) => {
      const items = [...list.querySelectorAll('a')];
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!items.length) return;
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach((a, i) => a.classList.toggle('is-active', i === active));
        items[active].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const a = items[active >= 0 ? active : 0];
        if (a) window.location.href = a.href;
      } else if (e.key === 'Escape') {
        close();
      }
    });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) close(); });
  });

  // ---------------------------------------------------------------- where you are in the chapter
  const doc = document.getElementById('manDoc');
  if (doc) {
    const heads = [...doc.querySelectorAll('h2[id]')];
    const links = [...document.querySelectorAll('[data-sec]')];
    let last = null, queued = false;
    const update = () => {
      queued = false;
      let cur = heads.length ? heads[0].id : null;
      for (const h of heads) { if (h.getBoundingClientRect().top < 140) cur = h.id; else break; }
      if (cur === last) return;
      last = cur;
      links.forEach((a) => a.classList.toggle('is-here', a.dataset.sec === cur));
    };
    window.addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
    update();
  }

  // a section chosen in the phone menu closes the menu
  document.querySelectorAll('#sidebar .man-sec').forEach((a) => a.addEventListener('click', () => {
    const oc = window.bootstrap && bootstrap.Offcanvas.getInstance(document.getElementById('sidebar'));
    if (oc) oc.hide();
  }));

  // ---------------------------------------------------------------- enlarged screenshots
  const modalEl = document.getElementById('manZoom');
  if (modalEl && window.bootstrap) {
    const modal = new bootstrap.Modal(modalEl);
    const img = document.getElementById('manZoomImg');
    const cap = document.getElementById('manZoomT');
    document.querySelectorAll('.man-zoom').forEach((a) => a.addEventListener('click', (e) => {
      if (window.innerWidth < 768) return;   // phones open the picture itself, where it can be pinched
      e.preventDefault();
      img.src = a.getAttribute('href');
      img.alt = a.querySelector('img').alt;
      cap.textContent = a.querySelector('img').alt;
      modal.show();
    }));
  }
})();
