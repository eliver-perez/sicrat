/* Las claves AES se generan no exportables y se persisten como CryptoKey; nunca como bytes, JWK o cadenas. */
globalThis.SicratPwaVault = (() => {
    const db = SicratPwaDatabase;
    const encoder = new TextEncoder();
    const decoder = new TextDecoder();
    let active = null;
    let epoch = 0;
    const clients = new Set();
    const maximumLease = 12 * 60 * 60 * 1000;

    function usableKey(key) {
        return key instanceof CryptoKey && !key.extractable && key.type === 'secret'
            && key.algorithm.name === 'AES-GCM' && key.algorithm.length === 256
            && key.usages.length === 2 && key.usages.includes('encrypt') && key.usages.includes('decrypt');
    }
    async function seal(key, value, identity) {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: encoder.encode(identity) },
            key, encoder.encode(JSON.stringify(value)));
        return { iv, ciphertext };
    }
    async function unseal(key, envelope, identity) {
        const cleartext = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: envelope.iv,
            additionalData: encoder.encode(identity) }, key, envelope.ciphertext);
        return JSON.parse(decoder.decode(cleartext));
    }
    function lock() { epoch++; active = null; clients.clear(); }
    function elapsed(value = active) {
        if (!value) return Infinity;
        return Math.max(Date.now() - value.grant.localAt,
            value.resumedElapsed + performance.now() - value.started);
    }
    function remaining(value = active) {
        return value ? Math.max(0, value.grant.expiresAt - value.grant.serverAt - elapsed(value)) : 0;
    }
    function valid(value = active) {
        if (!value) return false;
        const grant = value.grant;
        const duration = grant.expiresAt - grant.serverAt;
        return [grant.serverAt, grant.expiresAt, grant.localAt, grant.lastSeen, grant.elapsedMs].every(Number.isFinite)
            && duration > 0 && duration <= maximumLease && Date.now() >= grant.lastSeen
            && elapsed(value) >= 0 && remaining(value) > 0;
    }
    function assert(client) {
        if (!valid()) { lock(); throw new Error('Se requiere conexión a Internet. Han pasado más de 12 horas desde la última verificación de acceso o cambió el reloj del dispositivo.'); }
        if (client && !clients.has(client)) throw new Error('Desbloquea tu perfil offline para consultar información guardada.');
        return active;
    }
    async function persist(value) {
        const sealed = await seal(value.key, value.grant, value.profile.id);
        if (active !== value) return;
        value.profile.sealed = sealed;
        await db.putIf('profiles', value.profile, () => active === value);
    }
    async function create(context, client) {
        lock();
        const version = epoch;
        const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
        if (version !== epoch) throw new Error('El contexto cambió. Vuelve a abrir la pantalla.');
        const value = { key, profile: { id: context.profile, user: context.user, organization: context.organization,
            election: context.election, schema: 2 },
            grant: { context, serverAt: context.validated_at, expiresAt: context.expires_at,
                localAt: context.localValidatedAt ?? Date.now(), lastSeen: Date.now(), elapsedMs: 0 },
            started: performance.now(), resumedElapsed: 0,
            persistent: false };
        active = value;
        clients.add(client);
        // Probar escritura, lectura y uso reales: algunas configuraciones privadas no permiten clonar CryptoKey.
        try {
            await db.putIf('keys', { id: context.profile, key }, () => active === value);
            const stored = await db.get('keys', context.profile);
            if (!usableKey(stored?.key)) throw new Error('Clave persistente no disponible.');
            const proof = await seal(key, { profile: context.profile }, context.profile + '/probe');
            if ((await unseal(stored.key, proof, context.profile + '/probe')).profile !== context.profile) throw new Error('Clave no recuperable.');
            value.persistent = true;
        } catch (error) {
            if (active !== value) throw error;
            await db.remove('keys', context.profile);
            // Fallback cerrado: conservar solo la clave en memoria, sin exportarla ni guardar otro secreto.
        }
        if (active !== value) throw new Error('El contexto cambió. Vuelve a abrir la pantalla.');
        await renew(context);
    }
    async function restore(id, client) {
        lock();
        const version = epoch;
        const [profile, stored] = await Promise.all([db.get('profiles', id), db.get('keys', id)]);
        if (!profile || profile.schema !== 2 || !usableKey(stored?.key)) {
            throw new Error('Se requiere conexión para preparar el acceso en este dispositivo.');
        }
        const grant = await unseal(stored.key, profile.sealed, profile.id);
        if (version !== epoch) throw new Error('El contexto cambió. Vuelve a abrir la pantalla.');
        if (grant.context?.profile !== id || grant.context.user !== profile.user
            || grant.context.organization !== profile.organization || grant.context.election !== profile.election) {
            throw new Error('La autorización guardada no corresponde a este contexto.');
        }
        active = { key: stored.key, profile, grant, started: performance.now(), persistent: true,
            resumedElapsed: Math.max(grant.elapsedMs || 0, Date.now() - grant.localAt) };
        clients.add(client);
        // Recuperar no renueva fechas. Solo el barrido online puede renovar un perfil vencido.
        return valid();
    }
    async function remember() {
        const value = assert();
        if (value.persistent && !value.profile.revalidating) {
            await db.putIf('recovery', { id: 'active', profile: value.profile.id }, () => active === value);
        }
    }
    async function renew(context) {
        const value = active;
        if (!value || value.profile.id !== context.profile) throw new Error('La cuenta o elección cambió.');
        value.grant = { context, serverAt: context.validated_at, expiresAt: context.expires_at,
            localAt: context.localValidatedAt ?? Date.now(), lastSeen: Date.now(), elapsedMs: 0 };
        value.started = performance.now();
        value.resumedElapsed = Math.max(0, Date.now() - value.grant.localAt);
        await persist(value);
    }
    async function checkpoint() {
        const value = assert();
        value.grant.elapsedMs = Math.max(value.grant.elapsedMs, elapsed(value));
        value.grant.lastSeen = Math.max(value.grant.lastSeen, Date.now());
        await persist(value);
    }
    async function keyFor(url) {
        const hash = await crypto.subtle.digest('SHA-256', encoder.encode(url));
        return Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join('');
    }
    async function readRecord(row, allowExpired = false) {
        const value = allowExpired ? active : assert();
        if (!value || row.profile !== value.profile.id) return null;
        const body = await unseal(value.key, row.sealed, `${row.profile}/${row.key}`);
        if (active !== value) return null;
        return { ...body, key: row.key, module: row.module };
    }
    async function get(url) {
        const value = assert();
        const key = await keyFor(url);
        const row = await db.get('records', [value.profile.id, key]);
        return row ? readRecord(row) : null;
    }
    async function save(record, expectedEpoch = epoch) {
        const value = active;
        if (!value || expectedEpoch !== epoch) return;
        const key = await keyFor(record.url);
        const sealed = await seal(value.key, record, `${value.profile.id}/${key}`);
        if (active !== value || expectedEpoch !== epoch) return;
        const bytes = sealed.ciphertext.byteLength;
        if (bytes > 512 * 1024) return; // Never let a large accidental response fill the device.
        await db.putIf('records', { profile: value.profile.id, key, module: record.module, bytes, touched: Date.now(), sealed },
            () => active === value && expectedEpoch === epoch);
        const rows = (await db.records(value.profile.id)).sort((a, b) => b.touched - a.touched);
        let total = 0;
        for (let index = 0; index < rows.length; index++) {
            total += rows[index].bytes;
            if (index >= SicratPwaPolicy.maxRecords || total > SicratPwaPolicy.maxBytes) {
                await db.remove('records', [value.profile.id, rows[index].key]);
            }
        }
    }
    async function list(module, client) {
        const value = assert(client);
        const rows = await db.records(value.profile.id, module || null);
        const records = await Promise.all(rows.map(row => readRecord(row)));
        assert(client);
        return records.filter(Boolean);
    }
    async function forgetUser(user) {
        lock();
        for (const profile of await db.profiles()) if (profile.user === user) await db.clear(profile.id, true);
    }
    async function forgetAll() {
        lock();
        await db.clearAll();
    }
    return { create, restore, remember, renew, checkpoint, valid, remaining, assert, lock, get, save, list, readRecord, forgetUser, forgetAll,
        get active() { return active; }, get epoch() { return epoch; }, barrier: () => { epoch++; }, authorize: client => clients.add(client),
        authorized: client => clients.has(client) && valid() };
})();
