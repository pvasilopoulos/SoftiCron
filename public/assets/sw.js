/* CronApp service worker: Web Push notifications + notification clicks. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'CronApp', body: event.data ? event.data.text() : '' }; }
  const title = data.title || 'CronApp';
  event.waitUntil(Promise.all([
    self.registration.showNotification(title, {
      body: data.body || '',
      icon: data.icon || '/assets/icons/icon-192.png',
      badge: data.badge || '/assets/icons/badge-72.png',
      tag: data.tag || undefined,
      renotify: !!data.tag,
      data: { url: data.url || '/', ackUrl: data.ack_url || null },
      actions: data.ack_url ? [{ action: 'ack', title: '✋ Το είδα' }, { action: 'open', title: 'Άνοιγμα' }] : [],
    }),
    // Open tabs refresh the bell (notification center) right away.
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((all) => all.forEach((c) => c.postMessage({ type: 'push' }))),
  ]));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const d = event.notification.data || {};
  const url = (event.action === 'ack' && d.ackUrl) ? d.ackUrl : (d.url || '/');
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if ('focus' in c) {
        await c.focus();
        if ('navigate' in c) return c.navigate(url);
        return;
      }
    }
    return self.clients.openWindow(url);
  })());
});
