const CACHE_NAME = 'restocloud-v2';
const STATIC_ASSETS = [
    '/',
    '/index.html',
    '/styles.css',
    '/app.js',
    '/css/_variables.css',
    '/css/_base.css',
    '/css/_sidebar.css',
    '/css/_layout.css',
    '/css/_pos.css',
    '/css/_floor-orders.css',
    '/css/_config-reports.css',
    '/css/_modals-tickets.css',
    '/css/_responsive-mobile.css',
    '/css/_dashboard-extras.css',
    '/js/vendor/chart.min.js',
    '/js/core/store.js',
    '/js/core/api-client.js',
    '/js/core/view-controller.js',
    '/js/core/notifications.js',
    '/js/core/auto-animate.js',
    '/js/core/report-renderer.js',
    '/js/core/report-builders.js',
    '/js/core/websocket.js',
    '/js/core/offline-queue.js',
    '/js/app/helpers.js',
    '/js/app/state.js',
    '/js/app/auth.js',
    '/js/app/stock.js',
    '/js/app/cart.js',
    '/js/app/navigation.js',
    '/js/app/header.js',
    '/js/ui/toast.js',
    '/js/ui/modal.js',
    '/js/views/pos-view.js',
    '/js/views/orders-view.js',
    '/js/views/menu-config-view.js',
    '/js/views/inventory-view.js',
    '/js/views/reports-view.js',
    '/js/views/dashboard-view.js',
    '/js/views/reservations-view.js',
    '/js/views/tenant-users-view.js',
    '/js/views/saas-admin-view.js',
    '/js/controllers/pos-controller.js',
    '/js/controllers/orders-controller.js',
    '/js/controllers/menu-config-controller.js',
    '/js/controllers/inventory-controller.js',
    '/js/controllers/reports-controller.js',
    '/js/controllers/dashboard-controller.js',
    '/js/controllers/reservations-controller.js',
    '/js/controllers/tenant-users-controller.js',
    '/js/controllers/saas-admin-controller.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch(() => {
                console.log('Some assets failed to cache, continuing...');
            });
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (url.pathname === '/api.php' || url.pathname.includes('/api.php')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                if (event.request.method === 'POST') {
                    event.waitUntil(
                        self.registration.sync.register('replay-offline-orders').catch(() => {})
                    );
                }
                return new Response(JSON.stringify({ status: 'error', message: 'Sin conexión', offline: true }), {
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            return cached || fetch(event.request).then((response) => {
                if (response.ok && event.request.method === 'GET') {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            });
        }).catch(() => {
            if (event.request.destination === 'document') {
                return caches.match('/index.html');
            }
        })
    );
});

self.addEventListener('sync', (event) => {
    if (event.tag === 'replay-offline-orders') {
        event.waitUntil(
            self.clients.matchAll().then(clients => {
                clients.forEach(client => {
                    client.postMessage({ type: 'replay-offline-orders' });
                });
            })
        );
    }
});

self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : { title: 'RestoCloud', body: 'Nueva notificación' };
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/favicon.ico',
            badge: '/favicon.ico',
            data: data.url || '/'
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data)
    );
});
