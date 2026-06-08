/* Service worker for panel PWA */
self.addEventListener('fetch', function (event) {
  // Necessário para o Chrome Android considerar o app instalável como PWA (não só atalho).
  if (event.request.method !== 'GET') return;
  let url;
  try {
    url = new URL(event.request.url);
  } catch (_) {
    return;
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
  // Não intercepte requisições cross-origin (pixels, CDNs, gateways). Isso pode mascarar erros e quebrar scripts.
  if (url.origin !== self.location.origin) return;
  // Service worker do painel só deve atuar no painel.
  if (!url.pathname.startsWith('/painel/')) return;
  event.respondWith(
    fetch(event.request).catch(function () {
      return Response.error();
    })
  );
});

self.addEventListener('install', function () {
  self.skipWaiting();
});
self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});
self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
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
  const fallbackIcon = new URL('/icons/icon-192x192.png', self.location.origin).href;
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
        tag: payload.url || 'panel-push',
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
