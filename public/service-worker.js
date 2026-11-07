/* Rebuild the asset version with: php bin/build-pwa.php */
importScripts('/pwa-assets.js', '/assets/js/pwa/policy.js', '/assets/js/pwa/database.js',
    '/assets/js/pwa/vault.js', '/assets/js/pwa/delta.js', '/assets/js/pwa/network.js', '/assets/js/pwa/static.js');

const network = SicratPwaNetwork;
const vault = SicratPwaVault;
const storage = SicratPwaDatabase;

self.addEventListener('install', event => event.waitUntil(SicratPwaStatic.install()));
self.addEventListener('activate', event => event.waitUntil((async () => {
    await SicratPwaStatic.activate();
    await self.clients.claim();
})()));

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method)) {
        event.respondWith(network.mutate(request, event.clientId));
        return;
    }
    if (request.method !== 'GET') return;
    if (request.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                const response = await fetch(request, { cache: 'no-store' });
                if (response.status >= 500) throw new Error('Servidor no disponible.');
                if (vault.active) {
                    await network.validate(true);
                    if (vault.active) vault.authorize(event.resultingClientId);
                }
                return response;
            } catch {
                await network.state(false);
                return await SicratPwaStatic.shell() || network.error('Se requiere conexión a Internet.');
            }
        })());
        return;
    }
    if (SicratPwaStatic.accepts(url)) {
        event.respondWith(SicratPwaStatic.read(request));
        return;
    }
    const rule = SicratPwaPolicy.match(url);
    if (rule) event.respondWith(network.read(event, rule));
    // Media, PDFs, private files, other APIs and dynamic HTML never enter a cache.
});

async function status(client) {
    if (vault.active && !vault.valid() && !network.validating) {
        vault.lock();
        await network.broadcast('expired');
    }
    const active = vault.authorized(client) ? vault.active : null;
    const rows = active ? await storage.records(active.profile.id) : [];
    return {
        online: network.online, unlocked: Boolean(active), validating: network.validating,
        profile: active?.profile.id, context: active?.grant.context,
        remainingMs: active ? Math.max(0, active.grant.expiresAt - active.grant.serverAt
            - Math.max(Date.now() - active.grant.localAt, performance.now() - active.started)) : 0,
        profiles: (await storage.profiles()).map(row => ({ id: row.id, user: row.user, organization: row.organization, election: row.election })),
        count: rows.length, bytes: rows.reduce((sum, row) => sum + row.bytes, 0)
    };
}

let unlockAttempt = 0;
let lastFailedUnlock = 0;
self.addEventListener('message', event => {
    const client = event.source;
    if (!client?.id || new URL(client.url).origin !== self.location.origin || !event.ports[0]) return;
    const data = event.data || {};
    event.waitUntil((async () => {
        try {
            let result;
            switch (data.type) {
                case 'STATUS':
                    result = await status(client.id);
                    break;
                case 'PROBE':
                    await network.probe();
                    if (vault.authorized(client.id)) await vault.checkpoint();
                    result = await status(client.id);
                    break;
                case 'ENABLE': {
                    const before = vault.epoch;
                    const context = await network.context();
                    if (before !== vault.epoch) throw new Error('El contexto cambió. Vuelve a autorizar el dispositivo.');
                    const version = await network.invalidate('Se está autorizando un perfil local.', client.id);
                    if (version !== vault.epoch) throw new Error('El contexto cambió. Vuelve a autorizar el dispositivo.');
                    for (const profile of await storage.profiles()) {
                        if (profile.user !== context.user) await storage.clear(profile.id, true);
                    }
                    if (version !== vault.epoch) throw new Error('El contexto cambió. Vuelve a autorizar el dispositivo.');
                    await vault.create(context, data.phrase, client.id);
                    await network.validate(true);
                    result = await status(client.id);
                    break;
                }
                case 'UNLOCK': {
                    if (Date.now() - lastFailedUnlock < Math.min(unlockAttempt, 10) * 1000) {
                        throw new Error('Espera unos segundos antes de volver a intentarlo.');
                    }
                    const version = await network.invalidate('Se está desbloqueando otro perfil local.', client.id);
                    if (version !== vault.epoch) throw new Error('El contexto cambió. Vuelve a desbloquear el perfil.');
                    await network.broadcast('validating');
                    if (version !== vault.epoch) throw new Error('El contexto cambió. Vuelve a desbloquear el perfil.');
                    try { await vault.unlock(data.profile, data.phrase, client.id); unlockAttempt = 0; }
                    catch (failure) { unlockAttempt++; lastFailedUnlock = Date.now(); throw failure; }
                    // Check real connectivity without silently discarding a new login's encrypted profile.
                    if (await network.reachable()) {
                        const context = await network.context();
                        if (context.profile !== data.profile) { vault.lock(); throw new Error('Este perfil no corresponde a la cuenta y elección conectadas.'); }
                        vault.active.grant.context.session = context.session;
                        await network.validate(true);
                    } else await network.state(false);
                    const active = vault.assert(client.id);
                    if (active.profile.revalidating) { vault.lock(); throw new Error('Se requiere conexión para terminar la verificación de permisos.'); }
                    await vault.checkpoint();
                    result = await status(client.id);
                    break;
                }
                case 'LIST':
                    if (network.validating) throw new Error('Verificando acceso. Inténtalo de nuevo en un momento.');
                    result = await vault.list(data.module, client.id);
                    break;
                case 'LOCK':
                    await network.invalidate('El perfil offline se bloqueó.', data.quiet ? client.id : null);
                    result = true;
                    break;
                case 'DELETE': {
                    // Deletion needs no decryption and never reveals data.
                    const profile = await storage.get('profiles', data.profile);
                    if (profile) {
                        if (vault.active?.profile.id === profile.id) await network.invalidate('Se eliminaron los datos guardados.');
                        await storage.clear(profile.id, true);
                    }
                    result = true;
                    break;
                }
                case 'LOGOUT': {
                    const user = vault.active?.profile.user;
                    if (user) await vault.forgetUser(user);
                    else vault.lock();
                    await network.broadcast('locked', { reason: 'Se cerró el perfil local.' });
                    result = true;
                    break;
                }
                case 'UPDATE':
                    await self.skipWaiting();
                    result = true;
                    break;
                default:
                    throw new Error('Acción offline no disponible.');
            }
            event.ports[0].postMessage({ ok: true, result });
        } catch (error) {
            let message = error.message || 'No fue posible acceder al perfil offline.';
            if (error instanceof DOMException) message = 'No fue posible usar el almacenamiento del dispositivo. Revisa el espacio disponible y los permisos del navegador.';
            if (error instanceof TypeError || error instanceof SyntaxError) message = 'Se requiere conexión a Internet para verificar el acceso.';
            event.ports[0].postMessage({ ok: false, message });
        }
    })());
});
