const CACHE_PREFIX = `restocloud-${encodeURIComponent(new URL(self.registration.scope).pathname)}-`;
const CACHE_NAME = `${CACHE_PREFIX}v11`;
const appUrl = (path) => new URL(path.replace(/^\//, ''), self.registration.scope).href;
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
            return Promise.all(STATIC_ASSETS.map(async (path) => {
                try {
                    const request = new Request(appUrl(path), { cache: 'reload' });
                    const response = await fetch(request);
                    if (response.ok) await cache.put(request, response);
                } catch (error) {
                    console.warn('Asset unavailable for offline use:', path);
                }
            }));
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME).map((name) => caches.delete(name))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin || !url.href.startsWith(self.registration.scope)) return;

    if (url.pathname === '/api.php' || url.pathname.includes('/api.php')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                if (event.request.method === 'POST') {
                    if (self.registration.sync) event.waitUntil(
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

    if (event.request.method !== 'GET') return;
    const isDocument = event.request.mode === 'navigate';
    const isCode = ['script', 'style'].includes(event.request.destination) || /\.(css|js)$/.test(url.pathname);
    const isStatic = ['image', 'font'].includes(event.request.destination);
    // Do not cache API responses or other dynamic/authenticated data.
    if (!isDocument && !isCode && !isStatic) return;

    event.respondWith((async () => {
        const cache = await caches.open(CACHE_NAME);
        const cached = () => cache.match(event.request);
        const fromNetwork = async () => {
            const response = await fetch(event.request, { cache: 'no-cache' });
            if (response.ok) {
                try { await cache.put(event.request, response.clone()); } catch (error) {
                    console.warn('Unable to cache resource:', url.pathname);
                }
            }
            return response;
        };
        try {
            if (isStatic) return (await cached()) || await fromNetwork();
            const response = await fromNetwork();
            if (response.status >= 500) return (await cached()) || response;
            return response;
        } catch (error) {
            const fallback = await cached();
            if (fallback) return fallback;
            if (isDocument) {
                const shell = await cache.match(appUrl('index.html'));
                if (shell) return shell;
            }
            return Response.error();
        }
    })());
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
