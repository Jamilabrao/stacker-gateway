/* Firebase Cloud Messaging — painel PWA (scope /painel/) */
importScripts('https://www.gstatic.com/firebasejs/11.6.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/11.6.0/firebase-messaging-compat.js');

let messaging = null;

function showNotificationFromPayload(payload) {
    const n = payload.notification || {};
    const data = payload.data || {};
    const title = n.title || data.title || 'Notificação';
    const body = n.body || data.body || '';
    const url = data.url || null;
    const icon = n.icon || data.icon || data.badge || new URL('/icons/icon-192x192.png', self.location.origin).href;
    const badge = data.badge || icon;
    const tag = data.tag || url || 'panel-fcm-push';

    return self.registration.showNotification(title, {
        body: body,
        icon: icon,
        badge: badge,
        tag: tag,
        data: { url: url },
    });
}

function initFirebase(config) {
    if (!config || !config.apiKey) return;
    if (!firebase.apps.length) {
        firebase.initializeApp(config);
    }
    messaging = firebase.messaging();
    messaging.onBackgroundMessage(function (payload) {
        return showNotificationFromPayload(payload);
    });
}

fetch(new URL('/painel/push/client-config.json', self.location.origin).href)
    .then(function (res) {
        return res.json();
    })
    .then(function (json) {
        if (json && json.enabled && json.firebase) {
            initFirebase(json.firebase);
        }
    })
    .catch(function () {});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data?.url;
    if (!url) return;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (let i = 0; i < clientList.length; i++) {
                const base = String(url).split('?')[0];
                if (clientList[i].url === url || clientList[i].url.startsWith(base)) {
                    return clientList[i].focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(url);
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
