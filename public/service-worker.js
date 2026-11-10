/* Rebuild the asset version with: php bin/build-pwa.php */
importScripts('/pwa-assets.js', '/assets/js/pwa/policy.js', '/assets/js/pwa/database.js',
    '/assets/js/pwa/vault.js', '/assets/js/pwa/delta.js', '/assets/js/pwa/network.js', '/assets/js/pwa/static.js');

const network = SicratPwaNetwork;
const vault = SicratPwaVault;
const storage = SicratPwaDatabase;
const preparingClients = new Map();

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
                if (url.pathname.startsWith('/autenticacion')) {
                    // El login crea un CSRF nuevo. Finalizar verificaciones anteriores antes de pedirlo
                    // evita que un 401 tardío destruya la nueva sesión anónima de autenticación.
                    vault.lock();
                    if (network.validating) {
                        try { await network.validate(); } catch { /* La pantalla permitirá autenticarse de nuevo. */ }
                    }
                }
                const response = await fetch(request, { cache: 'no-store' });
                if (response.status >= 500) throw new Error('Servidor no disponible.');
                // Un perfil recuperado puede sobrevivir a la cookie HTTP. Nunca impedir abrir el login.
                const loginPage = url.pathname.startsWith('/autenticacion')
                    || new URL(response.url).pathname.startsWith('/autenticacion');
                if (loginPage) {
                    vault.lock();
                    if(!url.pathname.startsWith('/autenticacion')) await network.authenticationLost();
                }
                if (vault.active && !loginPage) {
                    try {
                        await network.validate(true);
                        if (vault.active) vault.authorize(event.resultingClientId);
                    } catch (error) {
                        // Una denegación del servidor invalida el perfil, pero no convierte su HTML en un fallo de red.
                        if ([401, 403].includes(error.status)) await network.state(true);
                        else throw error;
                    }
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
    if (rule) event.respondWith((async () => {
        // Las consultas iniciales esperan la preparación de su propia pestaña.
        if (preparingClients.has(event.clientId)) await preparingClients.get(event.clientId);
        return network.read(event, rule);
    })());
    else if(url.pathname.startsWith('/api/'))event.respondWith(network.onlineRequest(request));
    // Media, PDFs, private files and dynamic HTML never enter a cache.
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
        remainingMs: active ? vault.remaining() : 0, persistent: Boolean(active?.persistent),
        profiles: (await storage.profiles()).map(row => ({ id: row.id, user: row.user, organization: row.organization, election: row.election })),
        count: rows.length, bytes: rows.reduce((sum, row) => sum + row.bytes, 0)
    };
}

self.addEventListener('message', event => {
    const client = event.source;
    if (!client?.id || new URL(client.url).origin !== self.location.origin || !event.ports[0]) return;
    const data = event.data || {};
    const work = (async () => {
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
                case 'CONNECT': {
                    // El perfil de recuperación solo se elige mediante un contexto real del servidor.
                    const before = vault.epoch;
                    const context = await network.context();
                    if (before !== vault.epoch) throw new Error('El contexto cambió. Abre nuevamente la pantalla.');
                    const version = await network.invalidate('La sesión se abrió en otra pantalla.', client.id);
                    for (const profile of await storage.profiles()) {
                        if (profile.user !== context.user || profile.schema !== 2) await storage.clear(profile.id, true);
                    }
                    if (version !== vault.epoch) throw new Error('El contexto cambió. Abre nuevamente la pantalla.');
                    const existing = await storage.get('profiles', context.profile);
                    if (existing) {
                        try { await vault.restore(context.profile, client.id); }
                        catch (error) {
                            if (vault.epoch !== version + 1) throw error;
                            await storage.clear(context.profile, true);
                        }
                    }
                    if (!vault.active) await vault.create(context, client.id);
                    if (vault.active.profile.id !== context.profile) throw new Error('El contexto cambió.');
                    vault.active.grant.context.session = context.session;
                    await network.validate(true);
                    vault.assert(client.id);
                    await vault.remember();
                    result = await status(client.id);
                    break;
                }
                case 'RECOVER': {
                    // No acepta una cuenta/elección indicada por la página ni enumera perfiles recuperables.
                    if (data.profile !== undefined || data.phrase !== undefined) throw new Error('No se permite elegir otro perfil offline.');
                    const windows = await self.clients.matchAll({ type: 'window' });
                    if (windows.some(window => window.id !== client.id && vault.authorized(window.id))) {
                        throw new Error('La información ya está abierta en otra pantalla.');
                    }
                    const before = vault.epoch;
                    const recovery = await storage.get('recovery', 'active');
                    if (!recovery || before !== vault.epoch) throw new Error('Se requiere conexión para verificar el acceso en este dispositivo.');
                    vault.lock();
                    const version = vault.epoch;
                    await network.broadcast('locked', { reason: 'La información se abrió en otra pantalla.' }, client.id);
                    if (version !== vault.epoch) throw new Error('El contexto cambió.');
                    await vault.restore(recovery.profile, client.id);
                    if (await network.reachable()) {
                        // También puede renovar un perfil vencido, pero únicamente tras validar con el servidor.
                        await network.validate(true);
                    } else await network.state(false);
                    const value = vault.assert(client.id);
                    if (value.profile.revalidating) { vault.lock(); throw new Error('Se requiere conexión para terminar la verificación de permisos.'); }
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
                    await vault.forgetAll();
                    await network.broadcast('session-ended');
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
    })();
    if (['CONNECT', 'RECOVER'].includes(data.type)) preparingClients.set(client.id, work);
    event.waitUntil(work.finally(() => {
        if (preparingClients.get(client.id) === work) preparingClients.delete(client.id);
    }));
});
