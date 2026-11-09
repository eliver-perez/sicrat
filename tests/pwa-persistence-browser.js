/* Prueba de cierre real de Chrome: perfil de disco temporal y proxy local con corte de red. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const [base, directory] = process.argv.slice(2);
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '') || !/^sicrat_phase1_test_[a-f0-9]{12}$/.test(path.basename(directory || ''))) throw new Error('Se requiere el entorno desechable de integration.php.');
const fixture = JSON.parse(fs.readFileSync(path.join(directory, 'pwa.json'), 'utf8'));
const profileDirectory = path.join(directory, 'chrome-persistence');
fs.mkdirSync(profileDirectory, { mode: 0o700 });
let online = true;
let blockedRequests = 0;
let browser;
let socket;
let checks = 0;
const failures = [];
const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const check = (condition, message) => { assert.ok(condition, message); checks++; };

// El corte afecta también al worker y a un proceso Chrome recién abierto, antes de cargar JavaScript.
const proxy = http.createServer((request, response) => {
    const url = new URL(request.url);
    if (url.origin !== base || !online) { if (url.origin === base) blockedRequests++; request.socket.destroy(); return; }
    const upstream = http.request(url, { method: request.method, headers: request.headers }, incoming => {
        response.writeHead(incoming.statusCode, incoming.headers);
        incoming.pipe(response);
    });
    upstream.on('error', () => response.destroy());
    request.pipe(upstream);
});
proxy.on('connect', (request, connection) => connection.destroy());
let call;
let evaluate;
let waitFor;
let navigate;
const send = (type, values = {}) => evaluate(`SicratPwa.send(${JSON.stringify(type)}, ${JSON.stringify(values)})`);

async function closeBrowser() {
    if (!browser) return;
    const process = browser;
    const exited = once(process, 'exit');
    try { await call('Browser.close'); } catch { process.kill('SIGTERM'); }
    await exited;
    socket?.close();
    browser = null;
}
async function openBrowser() {
    const portFile = path.join(profileDirectory, 'DevToolsActivePort');
    fs.rmSync(portFile, { force: true });
    browser = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
        '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--disable-sync', '--metrics-recording-only',
        '--remote-debugging-port=0', `--user-data-dir=${profileDirectory}`,
        `--proxy-server=http://127.0.0.1:${proxy.address().port}`, '--proxy-bypass-list=<-loopback>', 'about:blank'
    ], { stdio: ['ignore', 'ignore', fs.openSync(path.join(directory, 'pwa-persistence-chrome.log'), 'a', 0o600)] });
    for (let attempt = 0; attempt < 100 && !fs.existsSync(portFile); attempt++) await pause(100);
    assert.ok(fs.existsSync(portFile), 'Chrome inició');
    const [port, browserPath] = fs.readFileSync(portFile, 'utf8').trim().split('\n');
    socket = new WebSocket(`ws://127.0.0.1:${port}${browserPath}`);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    const pending = new Map();
    let sequence = 0;
    socket.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.method === 'Runtime.exceptionThrown') failures.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
        if (!message.id) return;
        const callback = pending.get(message.id);
        pending.delete(message.id);
        if (message.error) callback.reject(new Error(message.error.message)); else callback.resolve(message.result);
    });
    call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    });
    const targets = await call('Target.getTargets');
    const target = targets.targetInfos.find(target => target.type === 'page');
    const { sessionId } = await call('Target.attachToTarget', { targetId: target.targetId, flatten: true });
    await call('Page.enable', {}, sessionId);
    await call('Page.setLifecycleEventsEnabled', { enabled: true }, sessionId);
    await call('Runtime.enable', {}, sessionId);
    evaluate = async expression => {
        const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, sessionId);
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    waitFor = async expression => {
        for (let attempt = 0; attempt < 200; attempt++) {
            if (await evaluate(expression)) return;
            await pause(100);
        }
        throw new Error('Tiempo agotado: ' + expression + '\n' + await evaluate('document.body.innerText'));
    };
    navigate = async route => {
        const loaded = new Set();
        const onLoad = event => {
            const message = JSON.parse(event.data);
            if (message.sessionId === sessionId && message.method === 'Page.lifecycleEvent' && message.params.name === 'load') loaded.add(message.params.loaderId);
        };
        socket.addEventListener('message', onLoad);
        try {
            const navigation = await call('Page.navigate', { url: base + route }, sessionId);
            for (let attempt = 0; attempt < 200 && !loaded.has(navigation.loaderId); attempt++) await pause(100);
            assert.ok(loaded.has(navigation.loaderId), 'Carga del nuevo documento: ' + route);
        } finally { socket.removeEventListener('message', onLoad); }
    };
}
async function login(user) {
    await navigate('/autenticacion/');
    await waitFor('Boolean(window.SicratPwa && navigator.serviceWorker.controller && document.querySelector("meta[name=csrf-token]"))');
    await evaluate('SicratPwa.refresh(true)');
    check(await evaluate(`(async () => (await fetch('/api/auth/login', { method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify({username:${JSON.stringify(user)},password:'Prueba PWA segura 2026'}) })).status === 200)()`), 'Login online');
    await navigate('/persons/');
    await waitFor('Boolean(window.SicratPwa?.state.unlocked)');
}
async function workerEval(expression) {
    const targets = await call('Target.getTargets');
    const worker = targets.targetInfos.find(target => target.type === 'service_worker' && target.url === base + '/service-worker.js');
    const { sessionId } = await call('Target.attachToTarget', { targetId: worker.targetId, flatten: true });
    try {
        const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, sessionId);
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    } finally { await call('Target.detachFromTarget', { sessionId }); }
}
async function readPersons() {
    return evaluate(`(async () => { const response = await fetch(${JSON.stringify(fixture.personsPath)}); return {status:response.status, body:await response.json()}; })()`);
}
async function ageGrant(milliseconds, futureSeen = 0) {
    // Solo la prueba modifica y vuelve a cifrar la fecha de su concesión ficticia; no hay endpoint productivo.
    return evaluate(`(async () => {
        const db = await new Promise((resolve, reject) => { const request=indexedDB.open('sicrat-offline'); request.onsuccess=()=>resolve(request.result); request.onerror=()=>reject(request.error); });
        const get=(store,id)=>new Promise(resolve=>{const r=db.transaction(store).objectStore(store).get(id);r.onsuccess=()=>resolve(r.result);});
        const current=await get('recovery','active');
        const row=await get('profiles',current.profile);
        const {key}=await get('keys',current.profile);
        const aad=new TextEncoder().encode(row.id);
        const grant=JSON.parse(new TextDecoder().decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:row.sealed.iv,additionalData:aad},key,row.sealed.ciphertext)));
        grant.serverAt=Date.now()-${milliseconds}; grant.expiresAt=grant.serverAt+43200000;
        grant.context.validated_at=grant.serverAt; grant.context.expires_at=grant.expiresAt;
        grant.localAt=grant.serverAt; grant.lastSeen=${futureSeen} ? Date.now()+${futureSeen} : grant.localAt; grant.elapsedMs=0;
        const iv=crypto.getRandomValues(new Uint8Array(12));
        row.sealed={iv,ciphertext:await crypto.subtle.encrypt({name:'AES-GCM',iv,additionalData:aad},key,new TextEncoder().encode(JSON.stringify(grant)))};
        await new Promise((resolve,reject)=>{const tx=db.transaction('profiles','readwrite');tx.objectStore('profiles').put(row);tx.oncomplete=resolve;tx.onerror=()=>reject(tx.error);});
        db.close();
        return {serverAt:grant.serverAt,expiresAt:grant.expiresAt,localAt:grant.localAt};
    })()`);
}
async function main() {
    proxy.listen(0, '127.0.0.1');
    await once(proxy, 'listening');
    await openBrowser();
    await navigate('/assets/pwa/icon-192.png');
    await evaluate(`new Promise((resolve,reject)=>{
        const request=indexedDB.open('sicrat-offline',1);
        request.onupgradeneeded=()=>{const db=request.result;db.createObjectStore('profiles',{keyPath:'id'});const records=db.createObjectStore('records',{keyPath:['profile','key']});records.createIndex('profile','profile');records.createIndex('module',['profile','module']);};
        request.onsuccess=()=>{const db=request.result;const tx=db.transaction('profiles','readwrite');tx.objectStore('profiles').put({id:'legacy-test',user:-1,schema:1});tx.oncomplete=()=>{db.close();resolve();};};request.onerror=()=>reject(request.error);
    })`);
    await login('pwa-a');
    check(!(await send('STATUS')).profiles.some(row=>row.id==='legacy-test'), 'Migración de esquema 1 a 2 y limpieza de perfil anterior tras validar');
    await evaluate(`(async () => { const me=(await(await fetch('/api/auth/me')).json()).data; await fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf_token},body:JSON.stringify({process:${JSON.stringify(fixture.election)}})}); })()`);
    await navigate('/persons/');
    await waitFor('Boolean(window.SicratPwa?.state.unlocked)');
    check((await send('STATUS')).persistent, 'El perfil tiene recuperación persistente');
    check((await readPersons()).status === 200, 'Consulta autorizada guardada');
    const keyAudit = await workerEval(`(async () => {
        const {key}=await storage.get('keys',vault.active.profile.id);
        let rejected=0; for(const format of ['raw','jwk']) {try {await crypto.subtle.exportKey(format,key);} catch {rejected++;}}
        return {native:key instanceof CryptoKey,extractable:key.extractable,rejected,raw:JSON.stringify(await storage.get('keys',vault.active.profile.id))};
    })()`);
    check(keyAudit.native && !keyAudit.extractable && keyAudit.rejected === 2 && !keyAudit.raw.includes('"k":'), 'CryptoKey recuperada no exportable; sin representación raw/JWK');
    check(await evaluate('sessionStorage.getItem("sicrat-pwa-session") === null && localStorage.getItem("sicrat-pwa-session") === null'), 'No se guarda el secreto anterior en Web Storage');
    online = false;
    for (const age of [30 * 60000, 2 * 3600000, 6 * 3600000, 12 * 3600000 - 60000]) {
        const grant = await ageGrant(age);
        await evaluate('sessionStorage.clear()');
        await closeBrowser();
        await openBrowser();
        await navigate('/persons/');
        await waitFor('Boolean(document.querySelector("[data-offline-shell]") && window.SicratPwa?.state.unlocked)');
        const status = await send('STATUS');
        check(status.context.validated_at === grant.serverAt && status.context.expires_at === grant.expiresAt, 'Reapertura no renueva la concesión a los ' + age / 60000 + ' minutos');
        check(status.remainingMs > 0 && status.remainingMs <= 43200000 - age, 'El tiempo restante descuenta el cierre completo');
        check((await send('LIST')).some(row => JSON.stringify(row.body).includes('Persona privada PWA A')), 'Datos autorizados recuperados tras reiniciar Chrome');
        check(await evaluate('sessionStorage.getItem("sicrat-pwa-session") === null'), 'Recuperación sin sessionStorage');
    }
    check(blockedRequests > 0, 'El servidor fue inaccesible también para procesos recién abiertos');
    await ageGrant(2 * 3600000, 3600000);
    await evaluate('sessionStorage.clear()');
    await closeBrowser(); await openBrowser(); await navigate('/persons/');
    await waitFor('Boolean(window.SicratPwa && document.getElementById("offline-state").textContent.includes("reloj"))');
    check(!(await send('STATUS')).unlocked, 'Retroceder el reloj respecto al último uso bloquea la recuperación');
    await ageGrant(43200001);
    await evaluate('sessionStorage.clear()');
    await closeBrowser(); await openBrowser(); await navigate('/persons/');
    await waitFor('Boolean(window.SicratPwa && document.getElementById("offline-state").textContent.includes("12 horas"))');
    check(!(await send('STATUS')).unlocked && await evaluate('document.getElementById("offline-content").hidden'), 'Concesión vencida no muestra datos');
    check(await evaluate('SicratPwa.send("LIST").then(()=>false,()=>true)'), 'Concesión vencida tampoco permite LIST');

    online = true;
    await login('pwa-a');
    const renewed = await send('STATUS');
    check(renewed.context.expires_at - renewed.context.validated_at === 43200000 && renewed.remainingMs > 43100000, 'Una nueva validación real renueva 12 horas');
    await readPersons();
    await login('pwa-b');
    const b = await send('STATUS');
    check(b.profile !== fixture.a.profile && !b.profiles.some(row => row.user === fixture.a.user), 'B no conserva perfiles de A');
    check(await workerEval(`(async () => !(await storage.get('keys',${JSON.stringify(fixture.a.profile)})))()`), 'Cambiar de cuenta elimina la clave de A');
    await readPersons();
    online = false;
    check(await evaluate(`SicratPwa.send('RECOVER',{profile:${JSON.stringify(fixture.a.profile)}}).then(()=>false,()=>true)`), 'La página no puede pedir recuperar A');
    await evaluate('sessionStorage.clear()');
    await closeBrowser(); await openBrowser(); await navigate('/persons/');
    await waitFor('Boolean(window.SicratPwa?.state.unlocked)');
    const records = JSON.stringify(await send('LIST'));
    check(records.includes('Persona privada PWA B') && !records.includes('Persona privada PWA A'), 'Tras otro cierre, solo se recupera B');

    online = true;
    await login('pwa-b');
    check(await evaluate(`(async () => (await fetch('/api/auth/logout',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content}})).status === 200)()`), 'Logout del servidor');
    check(await workerEval("(async () => !(await storage.get('recovery','active')) && !(await storage.profiles()).length && !(await storage.get('keys'," + JSON.stringify(b.profile) + ")))()"), 'Logout elimina claves, perfiles y autorización recuperable');
    online = false;
    await evaluate('sessionStorage.clear()');
    await closeBrowser(); await openBrowser(); await navigate('/persons/');
    await waitFor('Boolean(window.SicratPwa && document.getElementById("offline-state").textContent.includes("requiere conexión"))');
    check(!(await send('STATUS')).unlocked && await evaluate('SicratPwa.send("RECOVER").then(()=>false,()=>true)'), 'Reabrir después de logout no recupera datos');

    online = true;
    await login('pwa-b');
    await workerEval(`(async () => { globalThis.originalPutIf=storage.putIf; storage.putIf=(store,...args)=>{if(store==='keys')throw new DOMException('Prueba de clonación','DataCloneError');return originalPutIf(store,...args);};await storage.clear(vault.active.profile.id,true);vault.lock(); })()`);
    const fallback = await send('CONNECT');
    check(fallback.unlocked && !fallback.persistent, 'Fallback conserva solo acceso de sesión cuando CryptoKey no puede persistir');
    check(await workerEval("(async () => !(await storage.get('recovery','active')) && !(await storage.get('keys',vault.active.profile.id)))()"), 'Fallback no guarda claves exportadas ni habilita recuperación falsa');
    await workerEval('storage.putIf=originalPutIf');
    assert.deepEqual(failures, []);
    console.log(`Persistencia PWA: ${checks} comprobaciones Chrome, cierres completos y reapertura sin red.`);
}
main().catch(error => { console.error(error.stack || error); process.exitCode = 1; }).finally(async () => {
    await closeBrowser();
    proxy.closeAllConnections();
    proxy.close();
});
