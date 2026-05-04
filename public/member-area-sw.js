/* Service worker for member area PWA - scope is set at registration time */
self.addEventListener('install', function () {
  self.skipWaiting();
});
self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
  if (!event.data) return;
  let payload = { title: 'Notificação', body: '', url: null, icon: null, badge: null };
  try {
    const data = event.data.json();
    payload = {
      title: data.title ?? payload.title,
      body: data.body ?? payload.body,
      url: data.url ?? null,
      icon: data.icon ?? null,
      badge: data.badge ?? null,
    };
  } catch (_) {
    try {
      payload.body = event.data.text();
    } catch (_) {}
  }
  const fallbackIcon = new URL('/images/gateways/pix.svg', self.location.origin).href;
  const icon = payload.icon || payload.badge || fallbackIcon;
  const badge = payload.badge || payload.icon || icon;
  event.waitUntil(
    (async function () {
      try {
        const audio = new Audio(new URL('/cash.mp3', self.location.origin).href);
        audio.volume = 1;
        void audio.play().catch(function () {});
      } catch (_) {}
      await self.registration.showNotification(payload.title, {
        body: payload.body,
        icon: icon,
        badge: badge,
        tag: payload.url || 'member-area-push',
        data: { url: payload.url },
      });
    })()
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  const url = event.notification.data?.url;
  if (!url) return;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (let i = 0; i < clientList.length; i++) {
        const base = url.split('?')[0];
        if (clientList[i].url === url || clientList[i].url.startsWith(base)) {
          return clientList[i].focus();
        }
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
