const CACHE_VERSION = 'cashier-shell-v4';
const APP_SCOPE = new URL(self.registration.scope).pathname;
const APP_SHELL = APP_SCOPE;
const ASSET_ROOT = new URL('../cashier-build/', self.registration.scope).pathname;
const ICON_ROOT = new URL('../pwa/', self.registration.scope).pathname;

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache=await caches.open(CACHE_VERSION);
            await cache.add(new Request(APP_SHELL,{cache:'reload'}));
            const manifestUrl=new URL('../cashier-build/manifest.json',self.registration.scope);
            const response=await fetch(manifestUrl,{cache:'no-cache',credentials:'same-origin'});
            if(!response.ok) throw new Error('Cashier assets unavailable');
            const manifest=await response.json();
            const root=new URL('./',manifestUrl);
            const files=new Set();
            for(const entry of Object.values(manifest)) for(const file of [entry.file,...(entry.css || [])]) {
                const url=new URL(file,root);
                if(url.origin!==self.location.origin || !url.pathname.startsWith(root.pathname)) throw new Error('Invalid cashier asset');
                files.add(url.href);
            }
            await cache.addAll([...files].map(url=>new Request(url,{cache:'reload'})));
            await self.skipWaiting();
        })()
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('cashier-shell-') && key !== CACHE_VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname.includes('/api/')) return;

    if (request.mode === 'navigate' && url.pathname.startsWith(APP_SCOPE)) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) caches.open(CACHE_VERSION).then((cache) => cache.put(APP_SHELL, response.clone()));
                    return response;
                })
                .catch(() => caches.match(APP_SHELL))
        );
        return;
    }

    // Cache public application assets only. Tenant images and signed media must
    // never enter the shared shell cache used by another account or business.
    if ((url.pathname.startsWith(ASSET_ROOT) || url.pathname.startsWith(ICON_ROOT)) && ['script', 'style', 'font', 'image'].includes(request.destination)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const refreshed = fetch(request).then((response) => {
                    if (response.ok) caches.open(CACHE_VERSION).then((cache) => cache.put(request, response.clone()));
                    return response;
                }).catch(() => cached);
                return cached || refreshed;
            })
        );
    }
});
