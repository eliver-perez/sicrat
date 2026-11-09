const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

module.exports = async ({ base, directory, call, evaluate, waitFor, navigate, pause }) => {
    const fixture = JSON.parse(fs.readFileSync(path.join(directory, 'pwa.json'), 'utf8'));
    let checks = 0;
    const check = (condition, message) => { assert.ok(condition, message); checks++; };
    const send = (type, values = {}) => evaluate(`SicratPwa.send(${JSON.stringify(type)}, ${JSON.stringify(values)})`);
    const control = action => execFileSync('php', [path.join(__dirname, 'pwa-control.php'), fixture.database, action], { env: process.env });
    const read = url => evaluate(`(async () => { const r = await fetch(${JSON.stringify(url)}); return {status:r.status,body:await r.json(),local:r.headers.get('X-Sicrat-Local'),etag:r.headers.get('ETag')}; })()`);
    async function login(user) {
        await navigate('/autenticacion/');
        await waitFor('document.querySelector("meta[name=csrf-token]") && navigator.serviceWorker.controller && window.SicratPwa');
        const status = await evaluate(`(async () => { const r = await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({username:${JSON.stringify(user)},password:'Prueba PWA segura 2026'})}); return r.status; })()`);
        check(status === 200, 'Online login remains functional');
        if (user === 'pwa-a') await election(fixture.election);
        await navigate('/persons/');
        await waitFor('location.pathname === "/persons/" && window.SicratPwa && SicratPwa.state.unlocked');
    }
    await login('pwa-a');
    await waitFor('document.getElementById("electoral-context-select").value === ' + JSON.stringify(fixture.election));
    check(await evaluate('!document.querySelector("#sicrat-pwa-panel, .sicrat-pwa-toolbar, [data-pwa-settings]")'), 'No offline administration UI');
    for (const width of [320, 390, 768, 1440]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
        await pause(350);
        const geometry = await evaluate(`(() => {
            const ids = ['button-toggle-menu', 'electoral-context-select', 'light-dark-mode', 'header-notification-toggle'];
            const rects = ids.map(id => document.getElementById(id).getBoundingClientRect());
            return { fits: document.documentElement.scrollWidth <= innerWidth + 1,
                aligned: rects.every(rect => Math.abs(rect.y + rect.height / 2 - rects[0].y - rects[0].height / 2) < 2),
                font: Math.min(...Array.from(document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea')).filter(field => field.getBoundingClientRect().width > 0).map(field => parseFloat(getComputedStyle(field).fontSize))),
                viewport: document.querySelector('meta[name="viewport"]').content };
        })()`);
        check(geometry.fits && geometry.aligned, 'One header row without page overflow at ' + width);
        check(!/user-scalable\s*=\s*no|maximum-scale/.test(geometry.viewport), 'Manual zoom remains enabled');
        if (width < 768) check(geometry.font >= 16, 'Mobile controls use at least 16 px');
        if (width < 768) {
            check(await evaluate(`(() => {
                const table = document.querySelector('main table');
                const scroller = table.closest('.overflow-x-auto');
                scroller.scrollLeft = 100;
                const works = scroller.scrollLeft > 0 && document.documentElement.scrollWidth <= innerWidth + 1;
                scroller.scrollLeft = 0;
                return works;
            })()`), 'Wide table scrolls inside its container');
            check(await evaluate(`(() => {
                const dialog = document.createElement('dialog');
                dialog.className = 'card';
                dialog.innerHTML = '<input class="form-input text-xs" type="search"><input class="form-input" type="date"><select class="form-input"><option>Opción</option></select><textarea class="form-input"></textarea>';
                document.body.append(dialog);
                dialog.showModal();
                dialog.querySelector('input').focus();
                const readable = Array.from(dialog.querySelectorAll('input,select,textarea')).every(field => parseFloat(getComputedStyle(field).fontSize) >= 16);
                const fits = dialog.getBoundingClientRect().right <= innerWidth && document.documentElement.scrollWidth <= innerWidth + 1;
                dialog.close(); dialog.remove();
                return readable && fits;
            })()`), 'Dynamic modal fields retain readable type and fit viewport');
        }
        fs.writeFileSync(path.join(directory, 'mobile-header-' + width + '.png'), Buffer.from((await call('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
    }
    await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });
    const manifest = await evaluate('(async () => (await fetch("/manifest.webmanifest")).json())()');
    check(manifest.start_url === '/' && manifest.scope === '/' && manifest.display === 'standalone', 'Installable manifest scope/start/display');
    check(manifest.icons.some(icon => icon.sizes === '192x192') && manifest.icons.some(icon => icon.sizes === '512x512'), 'Install icons');
    check(await evaluate('Array.from(document.querySelectorAll("link[rel=apple-touch-icon]")).some(link => link.href.endsWith("/apple-180x180-touch-icon.png"))'), 'Apple metadata');
    const installability = await call('Page.getInstallabilityErrors');
    check(installability.installabilityErrors.length === 0, 'Chrome installability: ' + JSON.stringify(installability));
    check(await evaluate('sessionStorage.getItem("sicrat-pwa-session") === null'), 'No tab secret is required');
    await evaluate('SicratPwa.refresh()');
    check((await send('STATUS')).unlocked, 'Server-authorized encrypted profile enabled');
    const first = await read(fixture.personsPath);
    check(first.status === 200 && first.body.data.persons[0].name.includes('PWA A'), 'Previously visited paginated private data');
    const second = await read(fixture.personsPath);
    check(second.local === 'validated', '304 reused encrypted representation without downloading JSON');
    const branch = `/api/structures/${fixture.structure}?limit=25&offset=0&parent=roots&search=`;
    check((await read(branch)).status === 200, 'Only requested structure branch stored');
    const targets = await call('Target.getTargets');
    const workerTarget = targets.targetInfos.find(target => target.type === 'service_worker' && target.url === base + '/service-worker.js');
    check(Boolean(workerTarget), 'Real registered service worker');
    const attached = await call('Target.attachToTarget', { targetId: workerTarget.targetId, flatten: true });
    const workerCall = (method, params = {}) => call(method, params, attached.sessionId);
    async function workerEval(expression) {
        const result = await workerCall('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    }
    await workerCall('Runtime.enable');
    await workerCall('Network.enable');
    await call('Network.enable');
    const setOffline = async offline => {
        const settings = { offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1 };
        await workerCall('Network.emulateNetworkConditions', settings);
        await call('Network.emulateNetworkConditions', settings);
    };
    const raw = await workerEval(`(async () => ({profiles:await storage.profiles(),records:await storage.records(${JSON.stringify(fixture.a.profile)})}))()`);
    check(raw.records.length >= 2 && !JSON.stringify(raw).includes('Persona privada'), 'Raw IndexedDB contains no private plaintext');
    check(raw.records.every(row => !row.url && !row.body && row.sealed) && raw.profiles.every(row => !row.key && !row.phrase), 'URLs/search/body and keys are not stored in plaintext');
    check(await workerEval('vault.active.key.extractable === false'), 'AES key remains non-extractable');
    check(await workerEval(`(async()=>{const row=(await storage.records(${JSON.stringify(fixture.a.profile)}))[0];row.key='wrong-context';try{await vault.readRecord(row);return false;}catch{return true;}})()`), 'AES-GCM rejects substituted record identity');

    // Recuperación automática tras perder la clave en memoria: no se solicita una frase al usuario.
    await setOffline(true);
    await send('PROBE');
    await workerEval('vault.lock()');
    await navigate('/persons/');
    await waitFor('document.querySelector("[data-offline-shell]") && window.SicratPwa?.state.unlocked && document.getElementById("offline-records").children.length >= 2');
    check((await send('LIST')).some(row => JSON.stringify(row.body).includes('Persona privada PWA A')), 'Same tab automatically recovers encrypted reads after worker memory loss');
    await setOffline(false);
    await send('PROBE');
    await navigate('/persons/');
    await waitFor('document.getElementById("electoral-context") && window.SicratPwa?.state.unlocked');

    async function election(id) {
        const result = await evaluate(`(async()=>{const me=(await(await fetch('/api/auth/me')).json()).data;const r=await fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf_token},body:JSON.stringify({process:${JSON.stringify(id)}})});return r.status;})()`);
        check(result === 200, 'Online election switch remains functional');
    }
    await election(fixture.secondElection);
    const secondProfile = (await evaluate('(async()=>(await(await fetch("/api/pwa/context")).json()).data)()')).profile;
    await send('CONNECT');
    await setOffline(true); await send('PROBE');
    check(secondProfile !== fixture.a.profile && (await read(fixture.personsPath)).status === 503, 'Same user cannot reuse a query from another election');
    await setOffline(false); await send('PROBE');
    await election(fixture.election);
    await send('CONNECT');

    control('rename');
    await workerEval(`globalThis.__pwaNativeFetch = fetch; globalThis.fetch = async function(input, options) { if (String(input).includes('/api/structures/')) await new Promise(resolve => setTimeout(resolve, 500)); return __pwaNativeFetch(input, options); };`);
    const start = Date.now();
    const stale = await read(branch);
    check(stale.local === '1' && stale.body.data.name === 'Rama PWA consultada' && Date.now() - start < 450, 'Structure is served immediately while revalidating');
    await pause(700);
    await workerEval('globalThis.fetch = __pwaNativeFetch');
    check((await read(branch)).body.data.name === 'Rama PWA actualizada', 'Background update becomes visible');

    await setOffline(true);
    check((await send('PROBE')).online === false, 'Real failed probe, independent of navigator.onLine');
    check((await read(fixture.personsPath)).local === '1', 'Network-first offline fallback');
    await navigate('/persons/');
    await waitFor('document.querySelector("[data-offline-shell]") && window.SicratPwa');
    check(await evaluate('getComputedStyle(document.body).backgroundColor === "rgb(241, 245, 249)"'), 'Offline shell CSS survives reopening');
    await send('RECOVER');
    await evaluate('SicratPwa.refresh()');
    await waitFor('document.getElementById("offline-records").children.length >= 2');
    check(await evaluate('!document.getElementById("sicrat-offline-band").hidden'), 'Permanent offline banner');
    const saved = await send('LIST');
    check(saved.some(row => JSON.stringify(row.body).includes('Persona privada PWA A')), 'Reopened profile recovers only visited information');
    const missing = await read('/api/persons?search=never-consulted&limit=25');
    check(missing.status === 503 && missing.body.message.includes('no está disponible sin conexión'), 'Unknown query gives human-readable offline response');
    const write = await evaluate(`(async () => {const r=await fetch('/api/persons',{method:'POST',body:'name=No enviar'});return {status:r.status,body:await r.json()};})()`);
    check(write.status === 503 && write.body.message.includes('requiere conexión'), 'Offline writes blocked');
    check(await workerEval('(async () => {const db=await new Promise(resolve=>{const r=indexedDB.open("sicrat-offline");r.onsuccess=()=>resolve(r.result);});return [...db.objectStoreNames].join(",");})()') === 'keys,profiles,records,recovery', 'Only encrypted data, key handles and recovery metadata; no write queue');
    const otherTab = await call('Target.createTarget', { url: base + '/offline.html' });
    const otherSession = await call('Target.attachToTarget', { targetId: otherTab.targetId, flatten: true });
    const otherEvaluate = async expression => {
        const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, otherSession.sessionId);
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    for (let attempt = 0; attempt < 100 && !await otherEvaluate('Boolean(window.SicratPwa && navigator.serviceWorker.controller)'); attempt++) await pause(100);
    check(await otherEvaluate("SicratPwa.send('LIST').then(()=>false,()=>true)"), 'Another tab cannot inherit an offline decryption grant');
    await setOffline(false);
    await otherEvaluate('SicratPwa.send("CONNECT")');
    await waitFor('document.getElementById("offline-content").hidden');
    check(await evaluate('document.getElementById("offline-records").childNodes.length === 0'), 'Unlocking another tab clears the previous tab private DOM');
    await call('Target.closeTarget', { targetId: otherTab.targetId });
    await send('CONNECT');
    await evaluate('SicratPwa.refresh()');
    await waitFor('document.getElementById("offline-records").children.length >= 2');
    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'Offline view fits mobile width');
    await evaluate('document.querySelector("#offline-records details").open = true');
    await pause(100);
    fs.writeFileSync(path.join(directory, 'pwa-offline-mobile.png'), Buffer.from((await call('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
    await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });

    await setOffline(true); await send('PROBE');
    // Persist an expired grant through actual Web Crypto, without adding a test endpoint to production.
    await workerEval(`(async () => {
        const value=vault.active; value.grant.expiresAt=value.grant.serverAt-1;
        const iv=crypto.getRandomValues(new Uint8Array(12));
        const ciphertext=await crypto.subtle.encrypt({name:'AES-GCM',iv,additionalData:new TextEncoder().encode(value.profile.id)},value.key,new TextEncoder().encode(JSON.stringify(value.grant)));
        value.profile.sealed={iv,ciphertext}; await storage.put('profiles',value.profile); vault.lock();
    })()`);
    check(await evaluate(`SicratPwa.send('RECOVER').then(()=>false,e=>e.message.includes('12 horas'))`), 'Expired grant cannot unlock offline');
    await evaluate('SicratPwa.refresh()');
    check(await evaluate('document.getElementById("offline-content").hidden'), 'Expired data removed from visible interface');
    await setOffline(false);
    await send('RECOVER');
    const renewed = await send('STATUS');
    check(renewed.unlocked && renewed.context.expires_at - renewed.context.validated_at === 43200000, 'Real online validation renews expired profile');
    check(renewed.count >= 2, 'Renewal reauthorizes the saved queries');
    await evaluate('SicratPwa.refresh()');
    check(await evaluate('document.getElementById("sicrat-offline-band").hidden'), 'Reconnection hides banner after real validation');

    await setOffline(true); await send('PROBE');
    control('revoke');
    await setOffline(false); await send('PROBE');
    check(!(await send('LIST')).some(row => row.module === 'personas'), 'Revoked permissions remove saved data on reconnect');
    await setOffline(true); await send('PROBE');
    check((await read(fixture.personsPath)).status !== 200, 'Revoked data unavailable after going offline again');
    control('restore');
    await setOffline(false); await send('PROBE');
    await read(fixture.personsPath);
    check((await send('LIST')).some(row => row.module === 'personas'), 'Reauthorized data only returns after another permitted query');
    await read(branch);
    await setOffline(true); await send('PROBE');
    control('unassign');
    await setOffline(false); await send('PROBE');
    check(!(await send('LIST')).some(row => row.url.includes(fixture.structure)), 'Removed structure association invalidates snapshots even without a permission fingerprint change');

    await send('LOCK');
    check(await evaluate(`SicratPwa.send('RECOVER',{profile:${JSON.stringify(fixture.a.profile)}}).then(()=>false,()=>true)`), 'Recovery cannot choose an arbitrary profile');
    await login('pwa-b');
    await waitFor('document.getElementById("electoral-context-select").disabled');
    check(await evaluate('document.getElementById("electoral-context-select").selectedOptions[0].textContent.includes("Proceso 2")'), 'Single election is displayed as a non-interactive control');
    check(!(await send('STATUS')).profiles.some(profile => profile.user === fixture.a.user), 'Account change deletes A profiles even when worker was locked');
    check((await send('STATUS')).unlocked, 'B prepares its encrypted profile automatically');
    const b = await read(fixture.personsPath);
    check(JSON.stringify(b.body).includes('Persona privada PWA B') && !JSON.stringify(b.body).includes('Persona privada PWA A'), 'B only receives its own authorized data');
    await setOffline(true); await send('PROBE');
    check(!(await send('LIST')).some(row => JSON.stringify(row.body).includes('Persona privada PWA A')), 'B cannot search or recover A offline');
    await setOffline(false); await send('PROBE');
    const logout = await evaluate(`(async()=>{const r=await fetch('/api/auth/logout',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content}});return r.status;})()`);
    check(logout === 200 && !(await send('STATUS')).profiles.some(profile => profile.user === fixture.b.user), 'Logout deletes user data without deleting public assets');
    const cacheAudit = await evaluate(`(async()=>{const keys=await caches.keys();const urls=[];for(const key of keys){const cache=await caches.open(key);urls.push(...(await cache.keys()).map(r=>new URL(r.url).pathname));}return {keys,urls};})()`);
    check(cacheAudit.keys.some(key => key.startsWith('sicrat-static-')) && cacheAudit.urls.includes('/offline.html'), 'Shared static cache survives logout');
    check(cacheAudit.urls.every(url => !url.startsWith('/api/') && !url.startsWith('/media/') && url !== '/' && !url.endsWith('.php')), 'No API, media or dynamic HTML in Cache Storage');
    await login('pwa-a');
    check(await evaluate('sessionStorage.getItem("sicrat-pwa-session") === null'), 'No tab secret is required');
    await read(fixture.personsPath);
    await setOffline(true); await send('PROBE');
    control('disable');
    await setOffline(false); await send('PROBE');
    await waitFor('document.querySelector("[data-offline-shell]")');
    check(!(await send('STATUS')).profiles.some(profile => profile.user === fixture.a.user), 'Disabled account loses all offline grants');

    await evaluate('caches.open("sicrat-static-obsolete")');
    fs.writeFileSync(path.join(directory, 'pwa-revision'), '2');
    await evaluate('(async()=>{const r=await navigator.serviceWorker.getRegistration();await r.update();})()');
    for (let attempt = 0; attempt < 120; attempt++) {
        const ready = await evaluate('(async () => { const keys = await caches.keys(); return keys.some(key => key.endsWith("-test-2")) && !keys.some(key => key.endsWith("-test-1")); })()');
        if (ready) break;
        await pause(100);
    }
    await pause(800);
    await waitFor('window.SicratPwa && navigator.serviceWorker.controller');
    const upgraded = await evaluate('caches.keys()');
    check(upgraded.some(key => key.endsWith('-test-2')) && !upgraded.some(key => key.endsWith('-test-1') || key.endsWith('-obsolete')), 'Real worker upgrade activates new cache and removes obsolete versions');
    return checks;
};
