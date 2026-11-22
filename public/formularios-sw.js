/*
 * Offline mode of the forms to fill in (assets/controllers/form_fill_controller.js registers it).
 *
 * - The page of a form (/actividades/<activity>/formulario/<slot>) is fetched from the network and the last copy is kept: without a
 *   connection that copy opens, and what is typed is kept by the page in the browser until it can be saved.
 * - The scripts, styles and icons the page needs (/assets/…) are kept the first time and refreshed in the background.
 * - Nothing else is touched, and nothing but GET is ever answered from here: saving and delivering always go to the server.
 * - Pages hold the centre's data: signing out clears them.
 */
const PAGES = 'atica-form-pages-v1';
const ASSETS = 'atica-form-assets-v1';
const FORM_PAGE = /^\/actividades\/[^/]+\/formulario\/[^/]+$/;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('atica-form-') && key !== PAGES && key !== ASSETS).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.endsWith('/logout')) {
        event.waitUntil(caches.delete(PAGES));
        return;
    }

    if (request.mode === 'navigate' && FORM_PAGE.test(url.pathname)) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    // Only a page that really is the form (a redirect to the login is not worth keeping).
                    if (response.ok && !response.redirected) {
                        const copy = response.clone();
                        event.waitUntil(caches.open(PAGES).then((cache) => cache.put(request, copy)));
                    }
                    return response;
                })
                .catch(() => caches.match(request, { cacheName: PAGES }).then((cached) => cached || Response.error())),
        );
        return;
    }

    if (url.pathname.startsWith('/assets/')) {
        event.respondWith(
            caches.open(ASSETS).then((cache) => cache.match(request).then((cached) => {
                const network = fetch(request).then((response) => {
                    if (response.ok) {
                        cache.put(request, response.clone());
                    }
                    return response;
                });
                return cached || network;
            })),
        );
    }
});
