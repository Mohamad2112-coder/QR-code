// QR Order Pager PWA Service Worker & Web Push Handler

const CACHE_NAME = 'qrpager-v1.0';
const STATIC_ASSETS = [
    '/',
    '/assets/css/app.css',
    '/assets/js/app.js',
    '/manifest.json'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch(err => console.log('Static cache warm error:', err));
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
            );
        })
    );
    self.clients.claim();
});

// Web Push Notification Listener
self.addEventListener('push', (event) => {
    let data = {
        title: '🔔 Order Update',
        body: 'Your order status has been updated!',
        icon: '/assets/icons/icon-192.png',
        badge: '/assets/icons/badge-72.png',
        url: '/'
    };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon || '/assets/icons/icon-192.png',
        badge: data.badge || '/assets/icons/badge-72.png',
        vibrate: [200, 100, 200, 100, 200],
        tag: data.tag || 'order-ready-notification',
        renotify: true,
        requireInteraction: true,
        data: {
            url: data.url || '/'
        }
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Notification Click Handler
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if (client.url === targetUrl && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
