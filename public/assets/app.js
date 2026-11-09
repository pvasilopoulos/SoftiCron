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
    document.querySelectorAll('[data-drawer-open]').forEach((b) => b.addEventListener('click', () => document.body.classList.add('drawer-open')));
    document.querySelectorAll('[data-drawer-close]').forEach((b) => b.addEventListener('click', () => document.body.classList.remove('drawer-open')));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.body.classList.remove('drawer-open'); });

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
      if (f.hasAttribute('data-loading')) f.querySelectorAll('button[type="submit"]').forEach((b) => { b.classList.add('loading'); b.dataset.label = b.textContent; });
    }, true);
    document.querySelectorAll('[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));

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
        if (document.visibilityState === 'visible' && Date.now() - lastInput > secs * 1000 && !document.querySelector('form :focus')) location.reload();
      }, secs * 1000);
    }

    // Register the service worker (needed for push + install)
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(() => {});
    }
  });
})();
