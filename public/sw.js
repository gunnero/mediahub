/* Only the public application shell is cached here. Private pages are explicitly
   saved by the signed-in user through the offline library controls. */
const CACHE = 'mediahub-shell-__BUILD_ID__';
self.addEventListener('install', event => event.waitUntil((async () => {
  const response = await fetch('/', { cache: 'reload' });
  if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) throw new Error('Shell unavailable');
  const html = await response.clone().text();
  const assets = [...html.matchAll(/(?:src|href)="(\/assets\/[^"?#]+)"/g)].map(match => match[1]);
  if (!assets.some(asset => asset.endsWith('.js'))) return; // Development server: no offline shell.
  const cache = await caches.open(CACHE); await cache.addAll(assets); await cache.put('/', response);
})()));
self.addEventListener('activate', event => event.waitUntil((async () => {
  for (const name of await caches.keys()) if (name.startsWith('mediahub-shell-') && name !== CACHE) await caches.delete(name);
  await self.clients.claim();
})()));
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin')) return;
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request).catch(async () => (await caches.open(CACHE)).match('/') ));
  } else if (url.pathname.startsWith('/assets/')) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE); const cached = await cache.match(event.request); if (cached) return cached;
      const response = await fetch(event.request); if (response.ok) await cache.put(event.request, response.clone()); return response;
    })());
  }
});
self.addEventListener('push', event => {
  let data = {}; try { data = event.data?.json() || {}; } catch {}
  event.waitUntil(self.registration.showNotification('MediaHub', { body: data.body || 'Your alerts are ready.', icon: '/favicon.svg?v=75adff', tag: 'mediahub-alerts', data: { url: '/alerts' } }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(self.clients.openWindow('/alerts'));
});
