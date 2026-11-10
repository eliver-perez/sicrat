/* Registro compartido: la caché se prepara sin controles técnicos para el usuario. */
(() => {
    const state = { online: null, unlocked: false, profiles: [] };
    const blockedKey = 'sicrat-pwa-blocked';
    let blocked = false;
    let probe;
    let preparing;
    let expiryTimer;
    const offlineView = () => Boolean(document.querySelector('[data-offline-shell]'));
    const date = value => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Sin información';

    async function send(type, values = {}, worker = navigator.serviceWorker?.controller) {
        if (!worker) throw new Error('La aplicación se está preparando.');
        return new Promise((resolve, reject) => {
            const channel = new MessageChannel();
            const timer = setTimeout(() => { channel.port1.close(); reject(new Error('No fue posible verificar el acceso.')); }, 120000);
            channel.port1.onmessage = event => {
                clearTimeout(timer);
                channel.port1.close();
                if (event.data.ok) resolve(event.data.result);
                else reject(new Error(event.data.message));
            };
            worker.postMessage({ type, ...values }, [channel.port2]);
        });
    }
    function announce(message) {
        window.dispatchEvent(new CustomEvent('sicrat:pwa-message', { detail: message }));
    }
    function blockPreviousView() {
        blocked = true;
        // Solo marca una pantalla obsoleta; no contiene claves ni es necesaria para recuperar datos.
        try { sessionStorage.setItem(blockedKey, '1'); } catch { /* Almacenamiento restringido. */ }
    }
    function render() {
        clearTimeout(expiryTimer);
        if (state.unlocked && state.localDeadline) {
            expiryTimer = setTimeout(() => {
                clearPrivateView('Se requiere conexión. Han pasado más de 12 horas desde la última verificación de acceso.');
                send('LOCK').catch(() => {});
            }, Math.max(0, state.localDeadline - performance.now()));
        }
        const band = document.getElementById('sicrat-offline-band');
        band.hidden = state.online !== false;
        document.documentElement.style.setProperty('--sicrat-offline-height', `${band.offsetHeight}px`);
        document.documentElement.classList.toggle('sicrat-is-offline', state.online === false);
        window.dispatchEvent(new CustomEvent('sicrat:pwa-state', { detail: { ...state } }));
    }
    function apply(result) {
        Object.assign(state, result, { localDeadline: performance.now() + result.remainingMs });
        render();
        return state;
    }
    async function refresh(checkServer = false) {
        if (probe) return probe;
        probe = send(checkServer ? 'PROBE' : 'STATUS').then(apply)
            .catch(error => announce(error.message)).finally(() => { probe = null; });
        return probe;
    }
    async function prepare() {
        if (preparing || !navigator.serviceWorker.controller) return preparing;
        preparing = (async () => {
            if (!offlineView() && !document.getElementById('electoral-context')) { await refresh(true); return; }
            try {
                if (offlineView()) {
                    await refresh(true);
                    if (blocked) return;
                    apply(await send('RECOVER'));
                } else {
                    const result = await send('CONNECT');
                    blocked = false;
                    try { sessionStorage.removeItem(blockedKey); } catch { /* La recuperación no depende de sessionStorage. */ }
                    apply(result);
                    if (!result.persistent) announce('Este navegador no pudo conservar el acceso offline al cerrar la aplicación. Puedes seguir trabajando con conexión.');
                    // La retención reduce desalojos automáticos; no altera el plazo de autorización.
                    if (result.persistent && navigator.storage?.persist) navigator.storage.persist().catch(() => {});
                }
            } catch (error) { await refresh(true); announce(error.message); }
        })().finally(() => { preparing = null; });
        return preparing;
    }
    function clearPrivateView(message) {
        state.unlocked = false;
        window.dispatchEvent(new CustomEvent('sicrat:pwa-locked', { detail: message }));
        announce(message);
        if (!offlineView() && !location.pathname.startsWith('/autenticacion')) {
            const wrapper = document.querySelector('.wrapper');
            if (wrapper) { wrapper.replaceChildren(); wrapper.hidden = true; }
            location.replace('/offline.html');
        }
    }
    function initialize() {
        try {
            sessionStorage.removeItem('sicrat-pwa-session'); // Eliminar secretos del mecanismo anterior.
            blocked = sessionStorage.getItem(blockedKey) === '1';
        } catch { /* No se necesita almacenamiento de pestaña para recuperar el perfil. */ }
        const band = document.createElement('div');
        band.id = 'sicrat-offline-band';
        band.setAttribute('role', 'status');
        band.hidden = true;
        band.textContent = 'Sin conexión con el servidor · Solo puedes consultar información guardada.';
        document.body.prepend(band);
        const resize = () => document.documentElement.style.setProperty('--sicrat-offline-height', `${band.offsetHeight}px`);
        if ('ResizeObserver' in window) new ResizeObserver(resize).observe(band);
        document.addEventListener('submit', event => {
            if (state.online !== false || event.target.method === 'dialog') return;
            event.preventDefault(); event.stopImmediatePropagation(); announce('Esta operación requiere conexión a Internet.');
        }, true);
        if (!isSecureContext || !('serviceWorker' in navigator) || !crypto.subtle || !('indexedDB' in window)) return;
        navigator.serviceWorker.addEventListener('controllerchange', prepare);
        navigator.serviceWorker.addEventListener('message', event => {
            const message = event.data;
            if (message?.source !== 'sicrat-pwa') return;
            // Late lock/expiry notices must not replace an authentication redirect with the offline shell.
            if (window.SicratSession?.leaving) return;
            if (message.type === 'connection') { state.online = message.online; render(); }
            if (message.type === 'auth-required') {blockPreviousView();window.SicratSession?.expired(message.reason,true);return;}
            if (message.type === 'session-ended') {blockPreviousView();window.SicratSession?.expired(message.reason || 'logout',true);return;}
            if (message.type === 'locked') { blockPreviousView(); if(message.code==='session-ended'){window.SicratSession?.expired('logout');return;} if(['permissions','context-changed'].includes(message.code) && state.online!==false){location.replace('/?callBack=deniedAccess');return;} clearPrivateView(message.reason); }
            if (message.type === 'expired') clearPrivateView('Se requiere conexión. Han pasado más de 12 horas desde la última verificación de acceso.');
            if (message.type === 'storage-error') announce(message.reason);
            if (message.type === 'validating') window.dispatchEvent(new Event('sicrat:pwa-validating'));
            if (message.type === 'validated') refresh();
            if (message.type === 'updated' || message.type === 'invalidated') window.dispatchEvent(new CustomEvent('sicrat:pwa-' + message.type, { detail: message }));
            if (message.type === 'invalidated' && message.revoked) {if(state.online!==false)location.replace('/?callBack=deniedAccess');else clearPrivateView('El acceso a esta información cambió. Abre SICRAT con conexión.');}
        });
        navigator.serviceWorker.register('/service-worker.js', { scope: '/', updateViaCache: 'none' }).then(registration => {
            const update = () => {
                // Activar assets sin recargar ni perder formularios abiertos.
                if (registration.waiting) send('UPDATE', {}, registration.waiting).catch(() => {});
            };
            update();
            registration.addEventListener('updatefound', () => registration.installing?.addEventListener('statechange', update));
            prepare();
        }).catch(() => announce('No fue posible preparar las consultas sin conexión.'));
        window.addEventListener('online', async () => { await refresh(true); if (!state.unlocked) prepare(); });
        window.addEventListener('offline', () => refresh(true));
        window.addEventListener('pageshow', event => { if (event.persisted) { document.querySelector('.wrapper')?.replaceChildren(); location.reload(); } });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(true); });
        setInterval(() => { if (!document.hidden && navigator.serviceWorker.controller) refresh(true); }, 30000);
    }
    window.SicratPwa = { send, refresh, date, state, announce, prepare };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
