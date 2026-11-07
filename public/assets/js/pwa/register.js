/* One registration shared by login, PHP layouts and the public offline shell. */
(() => {
    const state = { online: null, unlocked: false, profiles: [] };
    let registration;
    let probe;
    let installPrompt;
    let statusText;
    let expiryTimer;
    let updateRequested = false;
    const offlineView = () => Boolean(document.querySelector('[data-offline-shell]'));
    const date = value => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Sin información';

    async function send(type, values = {}, worker = navigator.serviceWorker?.controller) {
        if (!worker) throw new Error('La aplicación se está preparando. Vuelve a abrir esta pantalla en unos segundos.');
        return new Promise((resolve, reject) => {
            const channel = new MessageChannel();
            const timer = setTimeout(() => { channel.port1.close(); reject(new Error('No fue posible completar la verificación. Inténtalo de nuevo.')); }, 120000);
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
        if (statusText) statusText.textContent = message;
        window.dispatchEvent(new CustomEvent('sicrat:pwa-message', { detail: message }));
    }
    function render() {
        clearTimeout(expiryTimer);
        if (state.unlocked && state.localDeadline) {
            expiryTimer = setTimeout(() => {
                clearPrivateView('Se requiere conexión a Internet. Han pasado más de 12 horas desde la última verificación de acceso.');
                send('LOCK').catch(() => {});
            }, Math.max(0, state.localDeadline - performance.now()));
        }
        const band = document.getElementById('sicrat-offline-band');
        band.hidden = state.online !== false;
        document.documentElement.style.setProperty('--sicrat-offline-height', `${band.offsetHeight}px`);
        document.documentElement.classList.toggle('sicrat-is-offline', state.online === false);
        const select = document.getElementById('pwa-profile');
        const previous = select.value;
        select.replaceChildren();
        state.profiles.forEach(profile => {
            const option = document.createElement('option');
            option.value = profile.id;
            option.textContent = `Cuenta ${profile.user} · Organización ${profile.organization ?? 'global'} · Elección ${profile.election ?? 'general'}`;
            select.append(option);
        });
        select.value = state.profile || previous || select.options[0]?.value || '';
        document.getElementById('pwa-unlock').disabled = !state.profiles.length;
        document.getElementById('pwa-delete').disabled = !state.profiles.length;
        document.getElementById('pwa-enable').disabled = state.online === false;
        document.getElementById('pwa-lock').hidden = !state.unlocked;
        document.getElementById('pwa-account').textContent = state.unlocked
            ? `${state.context.name} · ${state.context.election_name || 'Contexto general'}` : 'Perfil bloqueado';
        document.getElementById('pwa-storage').textContent = state.unlocked
            ? `Última validación: ${date(state.context.validated_at)}. Vigencia hasta: ${date(state.context.expires_at)}. ${state.count} consultas · ${(state.bytes / 1024).toFixed(0)} KB guardados.`
            : 'Desbloquea tu perfil para ver la información guardada. Tu frase local no inicia una sesión en el servidor.';
        window.dispatchEvent(new CustomEvent('sicrat:pwa-state', { detail: { ...state } }));
    }
    async function refresh(checkServer = false) {
        if (probe) return probe;
        probe = send(checkServer ? 'PROBE' : 'STATUS').then(result => {
            Object.assign(state, result, { localDeadline: performance.now() + result.remainingMs });
            render();
            return state;
        })
            .catch(error => { announce(error.message); }).finally(() => { probe = null; });
        return probe;
    }
    async function run(work) {
        try { await work(); await refresh(); }
        catch (error) { announce(error.message); }
    }
    function settings() { document.getElementById('sicrat-pwa-panel').showModal(); refresh(); }
    function clearPrivateView(message) {
        window.dispatchEvent(new CustomEvent('sicrat:pwa-locked', { detail: message }));
        state.unlocked = false;
        announce(message);
        // Do not leave already rendered private PHP/module content behind another account.
        if (!offlineView() && !location.pathname.startsWith('/autenticacion')) {
            const wrapper = document.querySelector('.wrapper');
            if (wrapper) { wrapper.replaceChildren(); wrapper.hidden = true; }
            location.replace('/offline.html');
        }
    }
    function initialize() {
        const band = document.createElement('div');
        band.id = 'sicrat-offline-band';
        band.setAttribute('role', 'status');
        band.hidden = true;
        band.textContent = 'Sin conexión · Estás consultando información guardada en este dispositivo. Solo lectura.';
        document.body.prepend(band);
        const toolbar = document.createElement('div');
        toolbar.className = 'sicrat-pwa-toolbar';
        const button = document.createElement('button');
        button.type = 'button'; button.textContent = 'Datos sin conexión'; button.addEventListener('click', settings);
        const install = document.createElement('button');
        install.type = 'button'; install.textContent = 'Instalar SICRAT'; install.hidden = true;
        install.id = 'sicrat-pwa-install';
        install.addEventListener('click', async () => { if (installPrompt) { await installPrompt.prompt(); installPrompt = null; install.hidden = true; } });
        statusText = document.createElement('span'); statusText.setAttribute('role', 'status');
        toolbar.append(statusText, install, button); document.body.append(toolbar);
        const panel = document.createElement('dialog');
        panel.id = 'sicrat-pwa-panel';
        panel.innerHTML = `<form method="dialog"><button class="pwa-close" aria-label="Cerrar">Cerrar</button></form>
            <h2>Disponible sin conexión</h2><p id="pwa-account"></p><p id="pwa-storage"></p>
            <p>Autoriza este dispositivo con conexión. Usa una frase local distinta de tu contraseña de SICRAT: al menos cuatro palabras y 14 caracteres. Tendrás que introducirla cuando el navegador bloquee el perfil.</p>
            <form id="pwa-local-form" data-pwa-local>
                <label for="pwa-profile">Perfil guardado</label><select id="pwa-profile"></select>
                <label for="pwa-phrase">Frase de desbloqueo local</label><input id="pwa-phrase" type="password" autocomplete="off" required>
                <div class="pwa-actions"><button id="pwa-unlock" type="submit">Desbloquear perfil</button><button id="pwa-enable" type="button">Autorizar cuenta actual</button></div>
            </form>
            <div class="pwa-actions"><button id="pwa-lock" type="button">Bloquear perfil</button><button id="pwa-delete" type="button">Eliminar datos guardados</button><a href="/offline.html">Consultar lo guardado</a></div>
            <p id="pwa-panel-message" role="status"></p>
            <p class="pwa-note">Solo se guardan consultas que abras con el perfil desbloqueado. No guardes datos en dispositivos ajenos. Si olvidas tu frase, elimina el perfil y autorízalo de nuevo con Internet.</p>
            <p class="pwa-note">En iPhone/iPad: abre SICRAT en Safari y elige Compartir → Añadir a pantalla de inicio. La instalación requiere HTTPS.</p>`;
        document.body.append(panel);
        window.addEventListener('sicrat:pwa-message', event => { document.getElementById('pwa-panel-message').textContent = event.detail; });
        const phrase = () => { const input = document.getElementById('pwa-phrase'); const value = input.value; input.value = ''; return value; };
        document.getElementById('pwa-local-form').addEventListener('submit', event => {
            event.preventDefault();
            run(async () => { await send('UNLOCK', { profile: document.getElementById('pwa-profile').value, phrase: phrase() }); announce('Perfil desbloqueado.'); panel.close(); });
        });
        document.getElementById('pwa-enable').addEventListener('click', () => run(async () => {
            await send('ENABLE', { phrase: phrase() }); announce('Dispositivo autorizado. Las consultas que abras ahora podrán guardarse.'); panel.close();
        }));
        document.getElementById('pwa-lock').addEventListener('click', () => run(() => send('LOCK')));
        document.getElementById('pwa-delete').addEventListener('click', () => run(async () => {
            await send('DELETE', { profile: document.getElementById('pwa-profile').value }); announce('Datos guardados eliminados del dispositivo.');
        }));
        document.querySelectorAll('[data-pwa-settings]').forEach(element => {
            const open = document.createElement('button'); open.type = 'button'; open.textContent = 'Disponible sin conexión · Administrar datos guardados'; open.addEventListener('click', settings); element.append(open);
        });
        document.addEventListener('submit', event => {
            if (state.online !== false || event.target.matches('[data-pwa-local]') || event.target.method === 'dialog') return;
            event.preventDefault(); event.stopImmediatePropagation(); announce('Esta operación requiere conexión a Internet.');
        }, true);
        if (!isSecureContext || !('serviceWorker' in navigator) || !crypto.subtle || !('indexedDB' in window)) {
            announce('El acceso sin conexión requiere HTTPS y un navegador compatible. Puedes seguir trabajando con conexión.');
            button.disabled = true; return;
        }
        navigator.serviceWorker.register('/service-worker.js', { scope: '/', updateViaCache: 'none' }).then(value => {
            registration = value;
            const update = () => {
                if (!registration.waiting) return;
                const control = document.createElement('button'); control.type = 'button'; control.textContent = 'Actualizar SICRAT';
                control.addEventListener('click', () => run(async () => {
                    const waiting = registration.waiting;
                    await send('LOCK', { quiet: true });
                    updateRequested = true;
                    await send('UPDATE', {}, waiting);
                }));
                toolbar.append(control);
            };
            update();
            value.addEventListener('updatefound', () => value.installing?.addEventListener('statechange', update));
            if (navigator.serviceWorker.controller) refresh(true);
        }).catch(() => announce('No fue posible preparar el acceso sin conexión. Puedes seguir trabajando con Internet.'));
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (updateRequested) location.reload();
            else refresh(true);
        });
        navigator.serviceWorker.addEventListener('message', event => {
            const message = event.data;
            if (message?.source !== 'sicrat-pwa') return;
            if (message.type === 'connection') { state.online = message.online; render(); }
            if (message.type === 'locked') clearPrivateView(message.reason);
            if (message.type === 'expired') clearPrivateView('Se requiere conexión a Internet. Han pasado más de 12 horas desde la última verificación de acceso. Conéctate para validar nuevamente tu cuenta y permisos.');
            if (message.type === 'local') announce(`Última actualización: ${date(message.updatedAt)}${message.updating ? ' · Actualizando…' : ''}`);
            if (message.type === 'storage-error') announce(message.reason);
            if (message.type === 'validating') { announce('Verificando cuenta y permisos…'); window.dispatchEvent(new Event('sicrat:pwa-validating')); }
            if (message.type === 'validated') { announce('Cuenta y permisos verificados.'); refresh(); }
            if (message.type === 'updated' || message.type === 'invalidated') window.dispatchEvent(new CustomEvent('sicrat:pwa-' + message.type, { detail: message }));
            if (message.type === 'invalidated' && message.revoked) clearPrivateView('El servidor retiró el acceso a información guardada. Abre nuevamente SICRAT con conexión.');
        });
        window.addEventListener('online', () => refresh(true));
        window.addEventListener('offline', () => refresh(true));
        window.addEventListener('pageshow', event => { if (event.persisted) { clearPrivateView('Verifica el acceso para volver a abrir esta pantalla.'); refresh(true); } });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(true); });
        setInterval(() => { if (!document.hidden && navigator.serviceWorker.controller) refresh(true); }, 30000);
    }
    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault(); installPrompt = event;
        const button = document.getElementById('sicrat-pwa-install'); if (button) button.hidden = false;
    });
    window.SicratPwa = { send, refresh, settings, date, state, announce };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
