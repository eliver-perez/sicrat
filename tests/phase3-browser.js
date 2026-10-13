const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const [base, directory] = process.argv.slice(2);
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '') || !path.basename(directory || '').match(/^sicrat_phase1_test_[a-f0-9]{12}$/)) throw new Error('El navegador requiere el servidor y directorio desechables de integration.php.');
const profile = path.join(directory, 'chrome-profile');
fs.mkdirSync(profile, {mode: 0o700});
const browser = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-extensions','--disable-sync','--metrics-recording-only','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'], {stdio: ['ignore','ignore',fs.openSync(path.join(directory,'chrome.log'),'a',0o600)]});
const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
let socket; let checks = 0;
const failures = [];
async function main() {
    const portFile = path.join(profile,'DevToolsActivePort');
    for (let attempt=0; !fs.existsSync(portFile) && attempt<100; attempt++) await pause(100);
    if (!fs.existsSync(portFile)) throw new Error('Chrome no inició.');
    const port = Number(fs.readFileSync(portFile,'utf8').split('\n')[0]);
    let targets;
    for (let attempt=0;attempt<100;attempt++) { try { targets=await(await fetch(`http://127.0.0.1:${port}/json/list`)).json();if(targets.some(target=>target.type==='page'))break; } catch(error) { if(attempt===99)throw error; }await pause(100); }
    socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve,reject) => { socket.addEventListener('open',resolve,{once:true}); socket.addEventListener('error',reject,{once:true}); });
    let nextId=0; const pending=new Map();
    socket.addEventListener('message', event => {
        const message=JSON.parse(event.data);
        if (message.method === 'Runtime.exceptionThrown') failures.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
        if (message.id) { const callback=pending.get(message.id); pending.delete(message.id); if (message.error) callback.reject(new Error(message.error.message)); else callback.resolve(message.result); }
    });
    const call=(method,params={}) => new Promise((resolve,reject) => { const id=++nextId; pending.set(id,{resolve,reject}); socket.send(JSON.stringify({id,method,params})); });
    await call('Page.enable'); await call('Runtime.enable'); await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1080,deviceScaleFactor:1,mobile:false});
    async function evaluate(expression) {
        const result=await call('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    }
    async function waitFor(expression) { for (let attempt=0; attempt<120; attempt++) { if (await evaluate('Boolean('+expression+')')) return; await pause(100); } throw new Error('La interfaz no completó: ' + expression); }
    async function navigate(route) { await call('Page.navigate',{url:base+route}); await waitFor('document.readyState === "complete"'); }
    if (process.argv.includes('--only-phase7')) { checks+=await require('./phase7-browser.js')({base,directory,call,evaluate,waitFor,navigate,pause});assert.deepEqual(failures,[]);console.log('Chrome Fase 7 sin excepciones JavaScript.');return; }
    if (process.argv.includes('--only-phase6')) { checks+=await require('./phase6-browser.js')({base,directory,call,evaluate,waitFor,navigate,pause});assert.deepEqual(failures,[]);console.log('Chrome Fase 6 sin excepciones JavaScript.');return; }
    if (process.argv.includes('--only-phase5')) { checks+=await require('./phase5-browser.js')({base,directory,call,evaluate,waitFor,navigate,pause});assert.deepEqual(failures,[]);console.log('Chrome Fase 5 sin excepciones JavaScript.');return; }
    await navigate('/autenticacion/');
    const login=await evaluate(`(async () => { const token=document.querySelector('meta[name="csrf-token"]').content; const result=await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({username:'administrador',password:' nueva contraseña '})}); return result.status; })()`);
    assert.equal(login,200); checks++;
    await navigate('/');
    const selected=await evaluate(`(async () => { const token=document.querySelector('meta[name="csrf-token"]').content; const choices=await (await fetch('/api/auth/elections?limit=100')).json(); const election=choices.data.electoral_processes.find(item=>item.process==='Promoción Fase 3'); const result=await fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({process:election.id})}); return result.status; })()`);
    assert.equal(selected,200); checks++;
    await navigate('/structure/');
    await waitFor('document.querySelectorAll("#structure-members tr").length === 3'); checks++;
    assert.equal(await evaluate('document.getElementById("phase3-message").textContent'),''); checks++;
    await evaluate('document.querySelector("#structure-members button").click()');
    await waitFor('document.getElementById("structure-member-dialog").open'); checks++;
    assert.ok((await evaluate('document.getElementById("structure-member-summary").textContent')).includes('Equipo directo')); checks++;
    assert.equal(await evaluate('document.querySelectorAll("#structure-member-levels select").length'),1); checks++;
    const screenshot=await call('Page.captureScreenshot',{format:'png'}); fs.writeFileSync(path.join(directory,'phase3-structure.png'),Buffer.from(screenshot.data,'base64'),{mode:0o600});
    await evaluate('document.getElementById("structure-member-dialog").close(); document.getElementById("structure-add").click()');
    await waitFor('document.getElementById("structure-add-dialog").open'); checks++;
    await evaluate('document.getElementById("structure-person-search").value="Persona autorizada para incorporar"; document.getElementById("structure-person-find").click()');
    await waitFor('document.getElementById("structure-person").options.length > 1'); checks++;
    assert.equal(await evaluate('document.querySelectorAll("#structure-participations input").length'),1); checks++;
    await navigate('/administration/');
    await waitFor('document.querySelectorAll("#level-list button").length === 3 && document.querySelectorAll("#module-list > div").length + document.querySelectorAll("#module-essential > span").length === 16'); checks++;
    await waitFor('document.getElementById("permission-form").elements.scope.options.length === 6'); checks++;
    assert.equal(await evaluate('document.getElementById("phase3-message").textContent'),''); checks++;
    const configuration=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}); fs.writeFileSync(path.join(directory,'phase3-administration.png'),Buffer.from(configuration.data,'base64'),{mode:0o600});
    if (process.argv.includes('--phase4')) {
        await evaluate(`(async () => { const token=document.querySelector('meta[name="csrf-token"]').content; const choices=await (await fetch('/api/auth/elections?limit=100')).json(); const election=choices.data.electoral_processes.find(item=>item.process==='Proceso 1'); await fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({process:election.id})}); })()`);
        await navigate('/services/');
        await waitFor('!document.getElementById("services-tab-backups").hidden');
        await evaluate('document.getElementById("services-tab-backups").click()');
        await waitFor('document.querySelectorAll("#backups-list tr").length > 1'); checks++;
        await evaluate('document.getElementById("services-tab-jobs").click()'); await waitFor('document.querySelectorAll("#jobs-list tr").length > 1');
        assert.equal(await evaluate('document.getElementById("services-jobs").hidden'),false); checks++;
        assert.equal(await evaluate('document.getElementById("backup-policy-form").elements.retention_days.value'),'14'); checks++;
        await evaluate('document.getElementById("services-tab-audit").click()'); await waitFor('document.querySelectorAll("#audit-list tr").length > 1 && Array.from(document.getElementById("audit-filter").elements.action.options).some(option=>option.value==="prueba_fase4")');
        await evaluate('document.getElementById("audit-filter").elements.action.value="prueba_fase4"; document.getElementById("audit-filter").requestSubmit()');
        await waitFor('document.querySelectorAll("#audit-list tr").length === 2'); checks++;
        assert.ok((await evaluate('document.getElementById("audit-list").textContent')).includes('Comprobación de servicios')); checks++;
        await evaluate('document.getElementById("services-tab-exports").click()'); await waitFor('document.querySelectorAll("#exports-list tr").length > 1');
        assert.ok((await evaluate('document.getElementById("exports-list").textContent')).includes('Completada')); checks++;
        assert.ok((await evaluate('document.getElementById("jobs-list").textContent')).includes('Exportación')); checks++;
        assert.ok((await evaluate('document.getElementById("backup-summary").textContent')).includes('Espacio disponible')); checks++;
        await evaluate('document.getElementById("services-tab-files").click()'); await waitFor('document.querySelectorAll("#file-form select option").length > 1');
        await evaluate('document.getElementById("file-form").elements.record.value = Array.from(document.getElementById("file-form").elements.record.options).find(option=>option.textContent.includes("Evidencia Fase 4")).value');
        const documentNode=await call('DOM.getDocument'); const fileInput=await call('DOM.querySelector',{nodeId:documentNode.root.nodeId,selector:'#file-form input[type=file]'});
        await call('DOM.setFileInputFiles',{nodeId:fileInput.nodeId,files:[path.join(directory,'test-image.png')]});
        await evaluate('document.getElementById("file-form").requestSubmit()');
        await waitFor('document.querySelector("#file-result img")?.complete && document.querySelector("#file-result img")?.naturalWidth > 0'); checks++;
        assert.equal(await evaluate('document.querySelector("#file-result img").naturalWidth'),320); checks++;
        assert.equal(await evaluate('document.querySelector(".swal2-title")?.textContent'),'Archivo procesado y guardado.'); checks++;
        assert.equal(await evaluate('document.getElementById("services-message").textContent'),''); checks++;
        const servicesScreenshot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}); fs.writeFileSync(path.join(directory,'phase4-services.png'),Buffer.from(servicesScreenshot.data,'base64'),{mode:0o600});
        await evaluate(`(async () => { const token=document.querySelector('meta[name="csrf-token"]').content; const result=await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({username:'fase4-admin-cliente',password:'Fase4 cliente seguro'})}); if (result.status!==200) throw new Error('Login cliente Fase 4 falló'); })()`);
        await navigate('/services/'); await waitFor('document.getElementById("notifications-list").textContent.includes("Aviso Fase 4")'); checks++;
        assert.equal(await evaluate('getComputedStyle(document.getElementById("services-backups")).display'),'none'); checks++;
    }
    if (process.argv.includes('--ux')) checks += await require('./ux-browser.js')({base,directory,call,evaluate,waitFor,navigate,pause});
    if (process.argv.includes('--phase5')) checks += await require('./phase5-browser.js')({base,directory,call,evaluate,waitFor,navigate,pause});
    if (process.argv.includes('--phase6')) checks += await require('./phase6-browser.js')({base,directory,call,evaluate,waitFor,navigate,pause});
    if(process.argv.includes('--phase7')) checks+=await require('./phase7-browser.js')({base,directory,call,evaluate,waitFor,navigate,pause});
    assert.deepEqual(failures,[]); checks++;
    console.log(`OK: ${checks} comprobaciones en Chrome, pantallas y formularios sin errores JavaScript.`);
}
main().catch(error => { console.error(error.stack || error.message); process.exitCode=1; }).finally(() => { socket?.close(); browser.kill('SIGTERM'); });
