globalThis.SicratPwaStatic = (() => {
    const name = 'sicrat-static-' + SICRAT_STATIC_VERSION;
    const core = ['/offline.html', '/manifest.webmanifest', '/assets/pwa/icon-192.png', '/assets/pwa/icon-512.png',
        '/assets/images/favicon/apple-180x180-touch-icon.png', '/assets/css/pwa.css', '/assets/js/pwa/register.js',
        '/assets/js/pwa/offline.js'];
    async function install() {
        const cache = await caches.open(name);
        // A missing core asset leaves the previous worker in service.
        await cache.addAll(core.map(url => new Request(url, { cache: 'reload' })));
    }
    async function activate() {
        for (const key of await caches.keys()) {
            if (key.startsWith('sicrat-static-') && key !== name) await caches.delete(key);
        }
    }
    function accepts(url) {
        return url.origin === self.location.origin && Object.hasOwn(SICRAT_STATIC_ASSETS, url.pathname);
    }
    async function read(request) {
        const url = new URL(request.url);
        const cache = await caches.open(name);
        const cached = await cache.match(url.pathname);
        if (cached) return cached;
        const response = await fetch(request);
        if (response.ok && !response.redirected && response.type === 'basic'
            && !response.headers.get('Content-Type')?.includes('text/html')) {
            await cache.put(url.pathname, response.clone());
        }
        return response;
    }
    async function shell() { return (await caches.open(name)).match('/offline.html'); }
    return { install, activate, accepts, read, shell, name };
})();
