const CACHE = 'nenial-shell-v6';
const SHELL = ['/', '/responsive.css?v=20260702-4', '/manifest.webmanifest', '/face-manifest.webmanifest', '/media/Nenial.jpg', '/media/Background.jpg'];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)));
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key)))));
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.pathname.startsWith('/api/')) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).then(response => {
            const copy = response.clone();
            caches.open(CACHE).then(cache => cache.put('/', copy));
            return response;
        }).catch(() => caches.match('/')));
        return;
    }

    if (url.pathname === '/responsive.css' || url.pathname.startsWith('/build/')) {
        event.respondWith(fetch(request).then(response => {
            const copy = response.clone();
            caches.open(CACHE).then(cache => cache.put(request, copy));
            return response;
        }).catch(() => caches.match(request)));
        return;
    }

    if (url.pathname.startsWith('/media/') || url.pathname.startsWith('/face-models/')) {
        event.respondWith(caches.match(request).then(cached => cached || fetch(request).then(response => {
            const copy = response.clone();
            caches.open(CACHE).then(cache => cache.put(request, copy));
            return response;
        })));
    }
});
