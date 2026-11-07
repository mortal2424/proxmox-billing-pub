self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET' || request.mode !== 'navigate' ||
        new URL(request.url).origin !== self.location.origin) return;

    event.respondWith(fetch(request).catch(() => new Response(
        '<!doctype html><html lang="ru"><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>HomeVlad</title><main><h1>Нет подключения</h1>' +
        '<p>Подключитесь к интернету и обновите страницу.</p></main></html>',
        {status: 503, headers: {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'}}
    )));
});
