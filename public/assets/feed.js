/* In-app notification center (bell popup). */
(function () {
  'use strict';
  const panel = document.getElementById('feed-panel');
  if (!panel || !window.api || window.__feedReady) return;
  window.__feedReady = true;
  const list = panel.querySelector('[data-feed-list]');
  const backdrop = document.querySelector('.feed-backdrop');
  const toggles = [...document.querySelectorAll('[data-feed-toggle]')];
  const badges = [...document.querySelectorAll('[data-feed-badge]')];
  const countEl = panel.querySelector('[data-feed-count]');
  const qInput = panel.querySelector('[data-feed-q]');
  const baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
  const ICON = {
    error: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>',
    warning: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
    success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>',
    info: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16v-4M12 8h.01M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z"/></svg>',
  };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let filter = 'all';
  let q = '';
  let items = [];
  let more = false;
  let loading = false;
  let latest = 0;
  const isOpen = () => !panel.hidden;

  function setUnread(n) {
    n = Math.max(0, n | 0);
    const label = n > 99 ? '99+' : String(n);
    badges.forEach((b) => { b.hidden = n === 0; b.textContent = label; });
    toggles.forEach((t) => t.setAttribute('aria-label', 'Ειδοποιήσεις' + (n ? ' (' + n + ' νέες)' : '')));
    countEl.hidden = n === 0;
    countEl.textContent = n + ' νέες';
    document.title = (n ? '(' + label + ') ' : '') + baseTitle;
    if (navigator.setAppBadge) { (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {}); }
  }

  function render() {
    if (!items.length) {
      const msg = q ? 'Καμία ειδοποίηση για «' + esc(q) + '».'
        : filter === 'unread' ? 'Δεν έχεις μη αναγνωσμένες ειδοποιήσεις.'
        : filter !== 'all' ? 'Καμία ειδοποίηση σε αυτή την κατηγορία.'
        : 'Δεν υπάρχουν ειδοποιήσεις ακόμα. Εδώ θα βλέπεις αποτυχίες, ανακάμψεις και χαμένες εκτελέσεις των jobs.';
      list.innerHTML = '<div class="feed-empty">' + ICON.success + '<span>' + msg + '</span></div>';
      return;
    }
    let html = '';
    let day = null;
    items.forEach((it) => {
      if (it.day !== day) { day = it.day; html += '<div class="feed-day">' + esc(day) + '</div>'; }
      html += '<article class="feed-item lvl-' + esc(it.level) + (it.read ? '' : ' unread') + '" data-id="' + it.id + '">'
        + '<span class="feed-ic" aria-hidden="true">' + (ICON[it.level] || ICON.info) + '</span>'
        + '<a class="feed-main" href="' + esc(it.url || '#') + '" data-feed-open>'
        + '<span class="feed-title">' + esc(it.title) + '</span>'
        + (it.body ? '<span class="feed-body">' + esc(it.body) + '</span>' : '')
        + '<span class="feed-meta">' + (it.group ? '<span class="tag">' + esc(it.group) + '</span>' : '') + '<span title="' + esc(it.day + ' ' + it.time) + '">' + esc(it.time) + ' · ' + esc(it.ago) + '</span></span>'
        + '</a>'
        + '<span class="feed-acts">'
        + '<button type="button" class="feed-act" data-feed-mark title="' + (it.read ? 'Σήμανση ως μη αναγνωσμένη' : 'Σήμανση ως αναγνωσμένη') + '" aria-label="' + (it.read ? 'Σήμανση ως μη αναγνωσμένη' : 'Σήμανση ως αναγνωσμένη') + '"><span class="feed-dot' + (it.read ? ' hollow' : '') + '"></span></button>'
        + '<button type="button" class="feed-act" data-feed-del title="Διαγραφή" aria-label="Διαγραφή"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button>'
        + '</span></article>';
    });
    if (more) html += '<button type="button" class="feed-loadmore" data-feed-more>Περισσότερες…</button>';
    list.innerHTML = html;
  }

  async function load(append) {
    if (loading) return;
    loading = true;
    if (!append) list.innerHTML = '<div class="feed-empty"><span class="spinner" aria-hidden="true"></span><span>Φόρτωση…</span></div>';
    try {
      const params = new URLSearchParams({ filter, limit: '20' });
      if (q) params.set('q', q);
      if (append && items.length) params.set('before', String(items[items.length - 1].id));
      const d = await window.api('/feed?' + params.toString());
      items = append ? items.concat(d.items) : d.items;
      more = d.more;
      if (!append && d.items.length) latest = Math.max(latest, d.items[0].id);
      setUnread(d.unread);
      render();
    } catch (e) {
      list.innerHTML = '<div class="feed-empty"><span>Δεν φορτώθηκαν οι ειδοποιήσεις: ' + esc(e.message) + '</span></div>';
    } finally {
      loading = false;
    }
  }

  function show() {
    panel.hidden = false;
    if (backdrop) backdrop.hidden = false;
    toggles.forEach((t) => t.setAttribute('aria-expanded', 'true'));
    requestAnimationFrame(() => panel.classList.add('in'));
    panel.querySelector('#feed-title').focus({ preventScroll: true });
    load(false);
  }
  function hide() {
    panel.classList.remove('in');
    panel.hidden = true;
    if (backdrop) backdrop.hidden = true;
    toggles.forEach((t) => t.setAttribute('aria-expanded', 'false'));
    const more = panel.querySelector('.feed-more');
    if (more) more.open = false;
  }

  toggles.forEach((t) => t.addEventListener('click', (e) => { e.stopPropagation(); isOpen() ? hide() : show(); }));
  panel.querySelectorAll('[data-feed-close]').forEach((b) => b.addEventListener('click', hide));
  if (backdrop) backdrop.addEventListener('click', hide);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && isOpen()) { hide(); toggles.find((t) => t.offsetParent)?.focus(); } });
  document.addEventListener('click', (e) => { if (isOpen() && !panel.contains(e.target) && !toggles.some((t) => t.contains(e.target))) hide(); });

  panel.querySelectorAll('[data-feed-filter]').forEach((b) => b.addEventListener('click', () => {
    filter = b.dataset.feedFilter;
    panel.querySelectorAll('[data-feed-filter]').forEach((o) => o.setAttribute('aria-selected', o === b ? 'true' : 'false'));
    load(false);
  }));
  let qt = null;
  qInput.addEventListener('input', () => { clearTimeout(qt); qt = setTimeout(() => { q = qInput.value.trim(); load(false); }, 300); });

  list.addEventListener('click', async (e) => {
    const art = e.target.closest('.feed-item');
    if (e.target.closest('[data-feed-more]')) { load(true); return; }
    if (!art) return;
    const id = +art.dataset.id;
    const it = items.find((x) => x.id === id);
    if (e.target.closest('[data-feed-mark]')) {
      try {
        const d = await window.api('/feed/' + id + '/' + (it.read ? 'unread' : 'read'), { method: 'POST' });
        it.read = !it.read;
        if (filter === 'unread' && it.read) items = items.filter((x) => x.id !== id);
        setUnread(d.unread); render();
      } catch (err) { window.toast(err.message, 'error'); }
      return;
    }
    if (e.target.closest('[data-feed-del]')) {
      art.classList.add('leaving');
      try {
        const d = await window.api('/feed/' + id + '/delete', { method: 'POST' });
        items = items.filter((x) => x.id !== id);
        setUnread(d.unread); render();
      } catch (err) { art.classList.remove('leaving'); window.toast(err.message, 'error'); }
      return;
    }
    if (e.target.closest('[data-feed-open]') && it && !it.read) {
      // Mark as read, then follow the link (keepalive survives the navigation).
      e.preventDefault();
      const href = e.target.closest('a').href;
      try { await window.api('/feed/' + id + '/read', { method: 'POST', keepalive: true }); } catch (err) { /* navigate anyway */ }
      location.href = href;
    }
  });

  panel.querySelector('[data-feed-readall]').addEventListener('click', async () => {
    try {
      await window.api('/feed/read-all', { method: 'POST' });
      items.forEach((it) => { it.read = true; });
      if (filter === 'unread') items = [];
      setUnread(0); render();
    } catch (err) { window.toast(err.message, 'error'); }
  });
  panel.querySelector('[data-feed-clear]').addEventListener('click', async () => {
    if (!confirm('Να διαγραφούν όλες οι αναγνωσμένες ειδοποιήσεις;')) return;
    try {
      const d = await window.api('/feed/clear-read', { method: 'POST' });
      panel.querySelector('.feed-more').open = false;
      window.toast('Διαγράφηκαν ' + d.deleted + ' ειδοποιήσεις.');
      load(false);
    } catch (err) { window.toast(err.message, 'error'); }
  });

  // Live updates: poll the counter (cheap) and react to push messages from the service worker.
  async function poll(announce) {
    try {
      const d = await window.api('/feed/count');
      const fresh = latest && d.latest > latest;
      setUnread(d.unread);
      if (d.tenants) window.dispatchEvent(new CustomEvent('feed:counts', { detail: { tenants: d.tenants, tenant: d.tenant } }));
      if (fresh) {
        if (isOpen()) { load(false); }
        else if (announce) {
          const n = await window.api('/feed?limit=1');
          if (n.items[0] && !n.items[0].read) window.toast(n.items[0].title, n.items[0].level === 'error' ? 'error' : undefined);
        }
        toggles.forEach((t) => { t.classList.remove('ring'); void t.offsetWidth; t.classList.add('ring'); });
      }
      latest = Math.max(latest, d.latest);
    } catch (e) { /* offline or logged out: try again later */ }
  }
  poll(false);
  setInterval(() => { if (document.visibilityState === 'visible') poll(true); }, 30000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(true); });
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', (e) => { if (e.data && e.data.type === 'push') poll(true); });
  }
})();

/* Tenant switcher: keyboard, outside click, search, live unread badges. */
(function () {
  'use strict';
  const ts = document.querySelector('[data-tenant-switch]');
  if (!ts) return;
  const opts = () => [...ts.querySelectorAll('.ts-opt:not([hidden])')];
  const search = ts.querySelector('[data-ts-search]');
  ts.addEventListener('toggle', () => {
    if (!ts.open) return;
    const cur = ts.querySelector('.ts-opt.on') || opts()[0];
    (search || cur)?.focus();
  });
  document.addEventListener('click', (e) => { if (ts.open && !ts.contains(e.target)) ts.open = false; });
  ts.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && ts.open) { ts.open = false; ts.querySelector('summary').focus(); return; }
    if (!ts.open || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return;
    const list = opts();
    const i = list.indexOf(document.activeElement);
    const next = e.key === 'Home' ? 0 : e.key === 'End' ? list.length - 1 : e.key === 'ArrowDown' ? Math.min(list.length - 1, i + 1) : Math.max(0, i - 1);
    list[next]?.focus();
    e.preventDefault();
  });
  if (search) search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    ts.querySelectorAll('.ts-opt').forEach((o) => { o.hidden = q !== '' && !o.dataset.tsName.includes(q); });
  });
  // Live update from the bell's polling (/feed/count includes per-tenant counts).
  window.addEventListener('feed:counts', (e) => {
    const { tenants, tenant } = e.detail;
    let others = 0;
    ts.querySelectorAll('[data-ts-count]').forEach((b) => {
      const id = b.dataset.tsCount;
      const n = +(tenants[id] || 0);
      b.hidden = n === 0;
      b.textContent = n > 99 ? '99+' : String(n);
      if (+id !== +tenant) others += n;
    });
    const o = ts.querySelector('[data-ts-others]');
    if (o) { o.hidden = others === 0; o.textContent = others > 99 ? '99+' : String(others); }
  });
})();
