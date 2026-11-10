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
