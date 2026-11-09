/* Private requests remain outside Cache Storage. Authorization always precedes renewal. */
globalThis.SicratPwaNetwork = (() => {
    const vault = SicratPwaVault;
    const db = SicratPwaDatabase;
    let online = null;
    let validation = null;
    let lastValidation = 0;
    let probing = null;
    const pending = new Map();

    async function broadcast(type, detail = {}, except = null) {
        for (const client of await self.clients.matchAll({ type: 'window' })) {
            if (client.id === except) continue;
            client.postMessage({ source: 'sicrat-pwa', type, ...detail });
        }
    }
    async function state(value) {
        if (online !== value) {
            online = value;
            await broadcast('connection', { online: value });
        }
    }
    async function fetchTimed(input, init = {}) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 10000);
        try { return await fetch(input, { ...init, cache: 'no-store', signal: controller.signal }); }
        finally { clearTimeout(timer); }
    }
    function error(message, status = 503) {
        return new Response(JSON.stringify({ success: false, message }), {
            status, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }
        });
    }
    async function context() {
        const requestedAt = Date.now();
        const response = await fetchTimed('/api/pwa/context', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if ([401, 403].includes(response.status)) {
            const user = vault.active?.profile.user;
            if (user) await vault.forgetUser(user);
            else await vault.forgetAll();
            await broadcast('locked', { reason: 'La sesión o los permisos cambiaron. Conéctate e inicia sesión nuevamente.' });
            const failure = new Error('La sesión no está autorizada. Inicia sesión con conexión.');
            failure.status = response.status;
            throw failure;
        }
        if (!response.ok) throw new Error('No fue posible verificar el acceso con el servidor.');
        const payload = await response.json();
        if (!payload.data?.profile || !payload.data?.validated_at) throw new Error('El servidor no pudo verificar el acceso.');
        // Anclar antes de la petición evita sumar latencia o duración del barrido a las 12 horas.
        Object.defineProperty(payload.data, 'localValidatedAt', { value: requestedAt });
        return payload.data;
    }
    async function invalidate(message, except = null) {
        vault.lock();
        const version = vault.epoch;
        await db.remove('recovery', 'active');
        await broadcast('locked', { reason: message }, except);
        return version;
    }
    function matches(response, value) {
        return response.headers.get('X-Sicrat-Profile') === value.profile.id
            && response.headers.get('X-Sicrat-Access') === value.grant.context.access
            && response.headers.get('X-Sicrat-Session') === value.grant.context.session;
    }
    async function saveSafely(record, version) {
        try { await vault.save(record, version); }
        catch {
            await broadcast('storage-error', { reason: 'No hay espacio disponible para guardar esta consulta. Puedes seguir trabajando con conexión.' });
        }
    }
    async function networkRecord(url, cached, rule, value, version) {
        const headers = { Accept: 'application/json', 'X-Sicrat-Offline': '1' };
        if (cached?.etag) headers['If-None-Match'] = cached.etag;
        const response = await fetchTimed(url, { credentials: 'same-origin', headers });
        if (vault.active !== value || vault.epoch !== version) return error('El contexto cambió. Abre nuevamente la pantalla.', 409);
        if ([401, 403, 404].includes(response.status)) {
            // The same row can occur in several saved lists/details. Drop the context's snapshots
            // rather than retaining an indirect copy after the server has denied a read.
            if (!validation) vault.barrier();
            await db.clear(value.profile.id);
            if (response.status === 401) {
                await vault.forgetUser(value.profile.user);
                await broadcast('locked', { reason: 'La sesión dejó de estar autorizada.' });
            } else await broadcast('invalidated', { url, revoked: true });
            return response;
        }
        if (response.status >= 500) throw new Error('El servidor no está disponible.');
        if (response.status !== 304 && !response.ok) return response;
        if (!matches(response, value)) {
            await vault.forgetUser(value.profile.user);
            await invalidate('La cuenta, elección o permisos cambiaron. Desbloquea el perfil correcto.');
            return error('La autorización cambió. Vuelve a abrir la pantalla.', 409);
        }
        const now = Number(response.headers.get('X-Sicrat-Validated'));
        if (response.status === 304 && cached) {
            await saveSafely({ ...cached, checkedAt: now }, version);
            return localResponse(cached, false);
        }
        if (!response.headers.get('Content-Type')?.includes('application/json')) return response;
        const body = await response.clone().json();
        if (body.success === false) return response;
        const record = { url, body, module: rule.module, etag: response.headers.get('ETag'), updatedAt: now, checkedAt: now };
        if (rule.module === 'mensajes' && url.split('?')[0].endsWith('/messages')) {
            const delta = body.data;
            if (delta?.message_updates?.length || delta?.attachment_updates?.length) {
                for (const row of await db.records(value.profile.id, 'mensajes')) {
                    const saved = await vault.readRecord(row, true);
                    if (saved && saved.url.split('?')[0] === url.split('?')[0] && SicratPwaDelta.messages(saved.body, delta)) {
                        // The old representation changed locally; the next check must get a fresh ETag.
                        await saveSafely({ ...saved, etag: null, updatedAt: now }, version);
                    }
                }
            }
            // Empty polls need no additional offline snapshot. Existing cursors stay on the wire.
            if (new URL(url, self.location.origin).searchParams.has('after') && !delta?.rows?.length) return response;
        }
        await saveSafely(record, version);
        if (cached && cached.etag !== record.etag) await broadcast('updated', { url, updatedAt: now });
        return response;
    }
    function localResponse(record, stale = true) {
        return new Response(JSON.stringify(record.body), { headers: {
            'Content-Type': 'application/json', 'Cache-Control': 'no-store',
            'X-Sicrat-Local': stale ? '1' : 'validated', 'X-Sicrat-Updated': String(record.updatedAt)
        } });
    }
    async function validate(force = false) {
        if (validation) return validation;
        if (!force && online === true && Date.now() - lastValidation < 60000 && vault.valid()) return vault.active.grant.context;
        validation = (async () => {
            await broadcast('validating');
            const current = await context();
            const value = vault.active;
            if (!value) { await state(true); return current; }
            if (value.profile.id !== current.profile || value.grant.context?.session !== current.session) {
                const previousUser = value.profile.user;
                if (previousUser !== current.user) await vault.forgetUser(previousUser);
                else { vault.lock(); await db.remove('recovery', 'active'); }
                await broadcast('locked', { reason: 'Cambió la cuenta o la elección. Desbloquea su perfil para continuar.' });
                await state(true);
                return current;
            }
            vault.barrier();
            const version = vault.epoch;
            // Quarantine survives a worker termination during revalidation.
            value.profile.revalidating = true;
            await db.put('profiles', value.profile);
            try {
                if (value.grant.context.access !== current.access) {
                    await db.clear(current.profile);
                    for (const profile of await db.profiles()) {
                        if (profile.user === current.user && profile.id !== current.profile) await db.clear(profile.id, true);
                    }
                }
                value.grant.context = current;
                const rows = await db.records(current.profile);
                for (const row of rows) {
                    if (vault.active !== value || vault.epoch !== version) throw new Error('Cambió el contexto.');
                    const cached = await vault.readRecord(row, true);
                    const rule = cached && SicratPwaPolicy.match(new URL(cached.url, self.location.origin));
                    if (!rule) { await db.remove('records', [current.profile, row.key]); continue; }
                    const response = await networkRecord(cached.url, cached, rule, value, version);
                    if (!response.ok) await db.remove('records', [current.profile, row.key]);
                }
                if (vault.active !== value) throw new Error('Cambió el contexto.');
                value.profile.revalidating = false;
                await vault.renew(current);
                lastValidation = Date.now();
                await state(true);
                await broadcast('validated', { validatedAt: current.validated_at });
                return current;
            } catch (failure) {
                // A partial sweep cannot renew unchecked grants. Fail closed, including on a server failure.
                await db.clear(current.profile);
                if (vault.active === value) {
                    value.profile.revalidating = false;
                    await db.put('profiles', value.profile);
                }
                throw failure;
            }
        })().finally(() => { validation = null; });
        return validation;
    }
    async function reachable() {
        try {
            const response = await fetchTimed('/api/pwa/ping', { credentials: 'same-origin' });
            return response.ok && (await response.json()).data?.sicrat_pwa === 1;
        } catch { return false; }
    }
    async function probe() {
        if (probing) return probing;
        probing = (async () => {
            try {
                if (!await reachable()) throw new Error('Sin conexión al servidor.');
                if (vault.active && (online !== true || Date.now() - lastValidation >= 300000)) await validate(true);
                await state(true);
                return true;
            } catch (failure) {
                // A denied account is still a reachable server, not a network outage.
                const reachable = [401, 403].includes(failure.status);
                await state(reachable);
                return reachable;
            }
        })().finally(() => { probing = null; });
        return probing;
    }
    async function read(event, rule) {
        const request = event.request;
        const url = SicratPwaPolicy.canonical(request.url);
        const client = event.clientId;
        if (!vault.active) {
            try { return await fetchTimed(request); }
            catch { await state(false); return error('Esta información no está disponible sin conexión. Desbloquea tu perfil offline.'); }
        }
        try {
            if (!vault.authorized(client) || online !== false && Date.now() - lastValidation >= 60000) {
                if (online === false) return error('Desbloquea tu perfil offline para consultar información guardada.', 423);
                await validate();
                if (!vault.active) return error('La cuenta o elección cambió. Abre nuevamente la pantalla.', 409);
                vault.authorize(client);
            }
            if (validation) await validation;
            const value = vault.assert(client);
            if (value.profile.revalidating) return error('Conéctate para terminar la verificación de acceso.', 423);
            const cached = await vault.get(url);
            if (online === false) {
                return cached ? localResponse(cached) : error('Esta información no está disponible sin conexión.', 503);
            }
            const version = vault.epoch;
            const key = `${version}/${url}`;
            if (!pending.has(key)) {
                const task = networkRecord(url, cached, rule, value, version).catch(async () => {
                    await state(false);
                    if (cached && vault.active === value && vault.authorized(client)) return localResponse(cached);
                    return error('Esta información no está disponible sin conexión.');
                }).finally(() => pending.delete(key));
                pending.set(key, task);
            }
            const task = pending.get(key);
            if (cached && rule.strategy === 'stale-while-revalidate') {
                event.waitUntil(task);
                await broadcast('local', { url, updatedAt: cached.updatedAt, updating: true });
                return localResponse(cached);
            }
            const response = await task;
            if (response.headers.get('X-Sicrat-Local') === '1') await broadcast('local', { url, updatedAt: cached?.updatedAt });
            return response.clone();
        } catch {
            if (!vault.valid()) await broadcast('expired');
            // A storage failure must not break ordinary online requests; never serve unverified local data.
            if (online !== false) {
                try { return await fetchTimed(request); } catch { await state(false); }
            }
            return error('Se requiere conexión a Internet para verificar el acceso.', 423);
        }
    }
    async function mutate(request, client) {
        const path = new URL(request.url).pathname;
        let user = vault.active?.profile.user;
        if (online === false) {
            if (path === '/api/auth/logout') {
                await vault.forgetAll();
                await broadcast('session-ended');
                await invalidate('Se cerró el perfil local. Se requiere conexión para cerrar también la sesión del servidor.');
            }
            return error('Esta operación requiere conexión a Internet.');
        }
        if (!user && path === '/api/auth/logout') {
            try { user = (await context()).user; } catch { /* The server still handles the logout. */ }
        }
        if (['/api/auth/login', '/api/auth/logout', '/api/auth/election'].includes(path)) {
            await invalidate('El contexto se cerró.', client);
            if (path === '/api/auth/logout') {
                await vault.forgetAll();
                await broadcast('session-ended');
            }
        }
        try {
            const response = await fetch(request, { cache: 'no-store' });
            if (response.ok && path === '/api/auth/login') {
                // Una sesión nueva elimina los perfiles anteriores, incluso si el worker había terminado.
                await vault.forgetAll();
                await broadcast('session-ended');
                await broadcast('locked', { reason: 'Se inició una sesión nueva. Los perfiles anteriores están bloqueados.' });
            }
            // A successful write may invalidate lists, permissions, totals or related rows.
            if (response.ok && vault.active) {
                vault.barrier();
                await db.clear(vault.active.profile.id);
                lastValidation = 0;
                await broadcast('invalidated');
            }
            return response;
        } catch {
            await state(false);
            return error('Esta operación requiere conexión a Internet. Si se interrumpió durante el envío, comprueba su resultado antes de reintentar.');
        }
    }
    return { read, mutate, probe, reachable, validate, context, broadcast, state, invalidate, error,
        get online() { return online; }, get validating() { return Boolean(validation); } };
})();
