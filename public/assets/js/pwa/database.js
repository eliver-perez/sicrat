/* Almacenamiento transaccional: datos cifrados, metadatos y referencias CryptoKey no exportables. */
globalThis.SicratPwaDatabase = (() => {
    let opening;
    function open() {
        if (!opening) opening = new Promise((resolve, reject) => {
            const request = indexedDB.open('sicrat-offline', 2);
            request.onupgradeneeded = event => {
                const db = request.result;
                if (event.oldVersion < 1) {
                    db.createObjectStore('profiles', { keyPath: 'id' });
                    const records = db.createObjectStore('records', { keyPath: ['profile', 'key'] });
                    records.createIndex('profile', 'profile');
                    records.createIndex('module', ['profile', 'module']);
                }
                if (event.oldVersion < 2) {
                    db.createObjectStore('keys', { keyPath: 'id' });
                    db.createObjectStore('recovery', { keyPath: 'id' });
                }
            };
            request.onsuccess = () => {
                request.result.onversionchange = () => { request.result.close(); opening = null; };
                resolve(request.result);
            };
            request.onerror = () => { opening = null; reject(request.error); };
            request.onblocked = () => reject(new Error('Cierra otras ventanas de SICRAT e inténtalo de nuevo.'));
        });
        return opening;
    }
    async function transaction(stores, mode, work) {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(stores, mode);
            let result;
            tx.oncomplete = () => resolve(result?.result);
            tx.onerror = tx.onabort = () => reject(tx.error || new Error('Almacenamiento no disponible.'));
            result = work(tx);
        });
    }
    const get = (store, key) => transaction([store], 'readonly', tx => tx.objectStore(store).get(key));
    const put = (store, value) => transaction([store], 'readwrite', tx => tx.objectStore(store).put(value));
    const putIf = (store, value, allowed) => transaction([store], 'readwrite', tx => {
        if (allowed()) return tx.objectStore(store).put(value);
    });
    const remove = (store, key) => transaction([store], 'readwrite', tx => tx.objectStore(store).delete(key));
    const profiles = () => transaction(['profiles'], 'readonly', tx => tx.objectStore('profiles').getAll());
    const records = (profile, module = null) => transaction(['records'], 'readonly', tx => {
        const index = tx.objectStore('records').index(module ? 'module' : 'profile');
        return index.getAll(module ? [profile, module] : profile);
    });
    function clear(profile, deleteProfile = false) {
        return transaction(['records', 'profiles', 'keys', 'recovery'], 'readwrite', tx => {
            if (deleteProfile) {
                tx.objectStore('profiles').delete(profile);
                tx.objectStore('keys').delete(profile);
                const recovery = tx.objectStore('recovery');
                const current = recovery.get('active');
                current.onsuccess = () => { if (current.result?.profile === profile) recovery.delete('active'); };
            }
            const cursor = tx.objectStore('records').index('profile').openCursor(IDBKeyRange.only(profile));
            cursor.onsuccess = () => {
                if (cursor.result) { cursor.result.delete(); cursor.result.continue(); }
            };
        });
    }
    function clearAll() {
        return transaction(['records', 'profiles', 'keys', 'recovery'], 'readwrite', tx => {
            for (const name of ['records', 'profiles', 'keys', 'recovery']) tx.objectStore(name).clear();
        });
    }
    return { get, put, putIf, remove, profiles, records, clear, clearAll };
})();
