/* CronApp global UI behaviour (no framework). */
(function () {
  'use strict';
  const base = (window.APP && window.APP.base || '/').replace(/\/$/, '');

  window.toast = function (msg, type) {
    const wrap = document.getElementById('toasts');
    if (!wrap) return alert(msg);
    const t = document.createElement('div');
    t.className = 'toast' + (type === 'error' ? ' error' : '');
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.textContent = msg;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 4500);
  };

  window.api = async function (path, opts) {
    const o = Object.assign({ method: 'GET', headers: {} }, opts || {});
    o.headers = Object.assign({ Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': window.APP.csrf }, o.headers);
    if (o.json !== undefined) { o.body = JSON.stringify(o.json); o.headers['Content-Type'] = 'application/json'; delete o.json; }
    o.credentials = 'same-origin';
    const res = await fetch(base + path, o);
    let data = {};
    try { data = await res.json(); } catch (e) { /* not json */ }
    if (!res.ok) throw Object.assign(new Error(data.error || data.message || ('HTTP ' + res.status)), { data });
    return data;
  };

  /** Details of this device, sent with the push subscription. */
  window.deviceMeta = function () {
    let tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { /* old browser */ }
    return {
      pwa: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
      lang: navigator.language || '',
      tz,
      screen: Math.round(screen.width) + 'x' + Math.round(screen.height),
    };
  };

  /** Keeps "last seen" / IP / PWA state of this device fresh (at most every 6 hours). */
  async function deviceHeartbeat() {
    try {
      // Only inside the app (logged in), not on login/invite pages.
      if (!document.getElementById('sidebar') || !('serviceWorker' in navigator)) return;
      const key = 'deviceSeenAt';
      if (Date.now() - (+localStorage.getItem(key) || 0) < 6 * 3600 * 1000) return;
      const reg = await navigator.serviceWorker.getRegistration();
      const sub = reg && await reg.pushManager.getSubscription();
      if (!sub) return;
      await window.api('/notifications/subscribe', { method: 'POST', json: Object.assign(sub.toJSON(), { meta: window.deviceMeta() }) });
      localStorage.setItem(key, String(Date.now()));
    } catch (e) { /* storage blocked or offline */ }
  }

  document.addEventListener('DOMContentLoaded', () => {
    deviceHeartbeat();
    // Mobile drawer
    const sidebar = document.getElementById('sidebar');
    const openDrawer = () => {
      document.body.classList.add('drawer-open');
      document.querySelectorAll('[data-drawer-open]').forEach((b) => b.setAttribute('aria-expanded', 'true'));
      setTimeout(() => sidebar?.querySelector('.nav a.active, .nav a')?.focus({ preventScroll: true }), 220);
    };
    const closeDrawer = () => {
      if (!document.body.classList.contains('drawer-open')) return;
      document.body.classList.remove('drawer-open');
      document.querySelectorAll('[data-drawer-open]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    };
    document.querySelectorAll('[data-drawer-open]').forEach((b) => b.addEventListener('click', openDrawer));
    document.querySelectorAll('[data-drawer-close]').forEach((b) => b.addEventListener('click', closeDrawer));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });
    sidebar?.querySelectorAll('.nav a').forEach((a) => a.addEventListener('click', closeDrawer));
    // Swipe left on the drawer closes it.
    if (sidebar) {
      let sx = null, sy = 0;
      sidebar.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
      sidebar.addEventListener('touchend', (e) => {
        if (sx === null) return;
        const dx = e.changedTouches[0].clientX - sx, dy = Math.abs(e.changedTouches[0].clientY - sy);
        if (dx < -60 && dy < 50) closeDrawer();
        sx = null;
      }, { passive: true });
    }

    // Mobile header: tenant name until the page title scrolls away, then the page title; back arrow on sub-pages.
    const mh = document.querySelector('[data-mobile-header]');
    if (mh) {
      const h1 = document.querySelector('#content h1');
      if (h1 && 'IntersectionObserver' in window) {
        new IntersectionObserver(([en]) => mh.classList.toggle('scrolled', !en.isIntersecting), { rootMargin: '-56px 0px 0px 0px' }).observe(h1);
      } else {
        mh.classList.add('scrolled');
      }
      const crumbs = document.querySelectorAll('#content .breadcrumb a');
      const back = mh.querySelector('[data-mh-back]');
      if (crumbs.length && back) {
        const parent = crumbs[crumbs.length - 1];
        back.href = parent.href;
        back.setAttribute('aria-label', 'Πίσω: ' + parent.textContent.trim());
        back.hidden = false;
        mh.classList.add('has-back');
      }
    }

    // Filter forms: on phones only the search is shown, the rest behind a "Φίλτρα" button.
    document.querySelectorAll('form.filters').forEach((f) => {
      const main = f.querySelector('.filters-main');
      if (!main) return;
      const active = Array.from(f.querySelectorAll('select, input:not([type="hidden"]):not([name="q"])'))
        .filter((el) => (el.type === 'checkbox' ? el.checked : el.value !== '')).length;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn f-toggle';
      btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg><span>Φίλτρα</span>'
        + (active ? '<span class="jk-count">' + active + '</span>' : '');
      btn.addEventListener('click', () => {
        const open = f.classList.toggle('f-open');
        btn.setAttribute('aria-expanded', String(open));
        btn.classList.toggle('on', open);
      });
      const search = main.querySelector('.searchbar');
      search ? search.after(btn) : main.prepend(btn);
    });

    // Sticky save bar for long forms whose "Αποθήκευση" sits in the page header (phone + tablet).
    const headSave = document.querySelector('.page-head button.btn-primary[type="submit"][form]');
    const saveForm = headSave && document.getElementById(headSave.getAttribute('form'));
    if (headSave && saveForm && 'IntersectionObserver' in window) {
      const bar = document.createElement('div');
      bar.className = 'save-bar';
      bar.setAttribute('role', 'region');
      bar.setAttribute('aria-label', 'Αποθήκευση');
      const txt = document.createElement('span');
      txt.className = 'sb-text';
      txt.textContent = 'Καμία αλλαγή ακόμα';
      const b = headSave.cloneNode(true);
      b.removeAttribute('id');
      bar.append(txt, b);
      document.body.appendChild(bar);
      let headVisible = true, dirty = false;
      const sync = () => {
        const show = !headVisible && window.matchMedia('(max-width: 1180px)').matches;
        bar.classList.toggle('show', show);
        document.documentElement.classList.toggle('has-save-bar', show);
      };
      new IntersectionObserver(([en]) => { headVisible = en.isIntersecting; sync(); }).observe(headSave);
      const markDirty = () => {
        if (dirty) return;
        dirty = true;
        bar.classList.add('dirty');
        txt.textContent = '● Δεν αποθηκεύτηκαν';
      };
      saveForm.addEventListener('input', markDirty);
      saveForm.addEventListener('change', markDirty);
      addEventListener('resize', sync);
    }

    // Theme toggle (cycles light/dark, persisted on the server profile)
    document.querySelectorAll('[data-theme-toggle]').forEach((b) => b.addEventListener('click', async () => {
      const html = document.documentElement;
      const current = html.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      const next = current === 'dark' ? 'light' : 'dark';
      html.dataset.theme = next;
      try {
        const fd = new FormData();
        fd.append('_csrf', window.APP.csrf);
        fd.append('theme', next);
        fd.append('name', document.querySelector('.side-user .who strong')?.textContent || '');
        await fetch(base + '/profile', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
      } catch (e) { /* offline: keep local */ }
    }));

    // Confirm dialogs
    document.addEventListener('submit', (e) => {
      const f = e.target;
      if (f.dataset.confirm && !confirm(f.dataset.confirm)) { e.preventDefault(); return; }
      // Forms that must be sent once (login): a double tap would reuse a rotated session → 419.
      if (f.hasAttribute('data-once')) {
        if (f._sent) { e.preventDefault(); return; }
        f._sent = true;
      }
      if (f.hasAttribute('data-loading')) f.querySelectorAll('button[type="submit"]').forEach((b) => { b.classList.add('loading'); b.dataset.label = b.textContent; });
    }, true);
    document.querySelectorAll('[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));

    // Fresh CSRF token when a page comes back after a long time (mobile browsers keep tabs
    // in memory for hours, or restore them from the back/forward cache).
    const refreshCsrf = async () => {
      try {
        const res = await fetch(base + '/csrf-token', { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        const d = await res.json();
        if (!d.token) return;
        window.APP.csrf = d.token;
        document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', d.token);
        document.querySelectorAll('input[name="_csrf"]').forEach((i) => { i.value = d.token; });
      } catch (e) { /* offline */ }
    };
    let hiddenAt = 0;
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') hiddenAt = Date.now();
      else if (hiddenAt && Date.now() - hiddenAt > 120000) refreshCsrf();
    });
    addEventListener('pageshow', (e) => {
      if (!e.persisted) return;
      document.querySelectorAll('form[data-once]').forEach((f) => {
        f._sent = false;
        f.querySelectorAll('button.loading').forEach((b) => b.classList.remove('loading'));
      });
      refreshCsrf();
    });

    // Job enable switches (AJAX, falls back to normal submit)
    document.querySelectorAll('[data-toggle-form] [data-submit-on-change]').forEach((cb) => cb.addEventListener('change', async () => {
      const f = cb.closest('form');
      try {
        const res = await fetch(f.action, { method: 'POST', body: new FormData(f), headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
        const d = await res.json();
        if (!res.ok) throw new Error(d.error);
        window.toast(d.enabled ? 'Το job ενεργοποιήθηκε.' : 'Το job τέθηκε σε παύση.');
      } catch (e) {
        cb.checked = !cb.checked;
        window.toast(e.message || 'Αποτυχία.', 'error');
      }
    }));

    // Copy buttons
    document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', () => {
      navigator.clipboard?.writeText(b.dataset.copy).then(() => window.toast('Αντιγράφηκε.'));
    }));

    // "Αντιγραφή" button on every code block, including ones added later (test run results etc.).
    const copyText = async (text) => {
      try { await navigator.clipboard.writeText(text); return true; } catch (e) {
        // Fallback for non-secure contexts.
        const ta = Object.assign(document.createElement('textarea'), { value: text });
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta); ta.select();
        const ok = document.execCommand('copy'); ta.remove(); return ok;
      }
    };
    const enhanceCode = (root) => {
      (root.querySelectorAll ? root : document).querySelectorAll('pre.code:not([data-no-copy])').forEach((pre) => {
        if (pre.parentElement.classList.contains('code-wrap')) return;
        const wrap = document.createElement('div');
        wrap.className = 'code-wrap';
        wrap.style.marginTop = pre.style.marginTop;
        pre.style.marginTop = '0';
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'code-copy';
        btn.setAttribute('aria-label', 'Αντιγραφή κώδικα');
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span>Αντιγραφή</span>';
        btn.addEventListener('click', async () => {
          const ok = await copyText(pre.innerText.replace(/\s+$/, ''));
          btn.classList.toggle('done', ok);
          btn.querySelector('span').textContent = ok ? 'Αντιγράφηκε' : 'Αποτυχία';
          clearTimeout(btn._t);
          btn._t = setTimeout(() => { btn.classList.remove('done'); btn.querySelector('span').textContent = 'Αντιγραφή'; }, 1600);
        });
        wrap.appendChild(btn);
      });
    };
    enhanceCode(document);
    new MutationObserver((muts) => muts.forEach((m) => m.addedNodes.forEach((n) => {
      if (n.nodeType === 1 && (n.matches?.('pre.code') || n.querySelector?.('pre.code'))) enhanceCode(n.matches('pre.code') ? n.parentNode : n);
    }))).observe(document.body, { childList: true, subtree: true });

    // Ctrl/Cmd+K focuses search
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        const s = document.getElementById('global-search');
        if (s) { e.preventDefault(); s.focus(); s.select(); }
      }
    });

    // Auto refresh of live pages while visible and the user is idle
    const auto = document.querySelector('[data-autorefresh]');
    if (auto) {
      const secs = parseInt(auto.dataset.autorefresh, 10) || 30;
      let lastInput = Date.now();
      ['keydown', 'pointerdown', 'scroll'].forEach((ev) => addEventListener(ev, () => { lastInput = Date.now(); }, { passive: true }));
      setInterval(() => {
        if (document.visibilityState === 'visible' && Date.now() - lastInput > secs * 1000 && !document.querySelector('form :focus') && !document.documentElement.hasAttribute('data-hold-refresh') && !document.querySelector('details.feed-more[open]')) location.reload();
      }, secs * 1000);
    }

    // Register the service worker (needed for push + install)
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(() => {});
    }
  });
})();
