/* Keys and passphrases never go to IndexedDB, Cache Storage, localStorage or the server. */
globalThis.SicratPwaVault = (() => {
    const db = SicratPwaDatabase;
    const encoder = new TextEncoder();
    const decoder = new TextDecoder();
    let active = null;
    let epoch = 0;
    const clients = new Set();
    const iterations = 600000;

    async function derive(phrase, salt) {
        const material = await crypto.subtle.importKey('raw', encoder.encode(phrase), 'PBKDF2', false, ['deriveKey']);
        return crypto.subtle.deriveKey({ name: 'PBKDF2', salt, iterations, hash: 'SHA-256' }, material,
            { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
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
    function valid(value = active) {
        if (!value) return false;
        const now = Date.now();
        const elapsed = Math.max(now - value.grant.localAt, performance.now() - value.started);
        return now >= value.grant.lastSeen && elapsed >= 0
            && value.grant.serverAt + elapsed < value.grant.expiresAt;
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
    async function create(context, phrase, client) {
        if (typeof phrase !== 'string' || phrase.length < 14 || phrase.trim().split(/\s+/).length < 4) {
            throw new Error('Usa una frase local de al menos cuatro palabras y 14 caracteres, distinta de tu contraseña de SICRAT.');
        }
        if (await db.get('profiles', context.profile)) throw new Error('Este perfil ya existe. Desbloquéalo o elimina sus datos primero.');
        lock();
        const version = epoch;
        const salt = crypto.getRandomValues(new Uint8Array(16));
        const key = await derive(phrase, salt);
        if (version !== epoch) throw new Error('El contexto cambió. Vuelve a autorizar el perfil.');
        active = { key, profile: { id: context.profile, user: context.user, organization: context.organization,
            election: context.election, salt, schema: 1 }, grant: {}, started: performance.now() };
        clients.add(client);
        await renew(context);
    }
    async function unlock(id, phrase, client) {
        lock();
        const version = epoch;
        const profile = await db.get('profiles', id);
        if (!profile || profile.schema !== 1) throw new Error('El perfil no está disponible en este dispositivo.');
        const key = await derive(phrase, profile.salt);
        let grant;
        try { grant = await unseal(key, profile.sealed, profile.id); }
        catch { throw new Error('No fue posible desbloquear el perfil. Revisa tu frase local.'); }
        if (version !== epoch) throw new Error('El contexto cambió. Vuelve a desbloquear el perfil.');
        active = { key, profile, grant, started: performance.now() };
        clients.add(client);
        // Expired profiles may only be renewed after a full online sweep by the worker.
        return valid();
    }
    async function renew(context) {
        const value = active;
        if (!value || value.profile.id !== context.profile) throw new Error('La cuenta o elección cambió.');
        value.grant = { context, serverAt: context.validated_at, expiresAt: context.expires_at,
            localAt: Date.now(), lastSeen: Date.now() };
        value.started = performance.now();
        await persist(value);
    }
    async function checkpoint() {
        const value = assert();
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
    return { create, unlock, renew, checkpoint, valid, assert, lock, get, save, list, readRecord, forgetUser,
        get active() { return active; }, get epoch() { return epoch; }, barrier: () => { epoch++; }, authorize: client => clients.add(client),
        authorized: client => clients.has(client) && valid() };
})();
