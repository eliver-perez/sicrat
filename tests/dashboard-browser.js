const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
module.exports = async ({base,directory,call,evaluate,waitFor,navigate,pause}) => {
    let checks = 0;
    const check = (value,message) => { assert.ok(value,message); checks++; };
    await navigate('/autenticacion/');
    await waitFor('navigator.serviceWorker.controller && window.SicratPwa');
    const login = await evaluate(`(async()=>{const r=await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({username:'dashboard-a',password:'Dashboard segura 2026'})});return r.status;})()`);
    check(login===200,'Login online');
    await navigate('/');
    await waitFor('document.getElementById("dashboard")?.dataset.loaded === "true" && document.querySelector(".db-note-text")');
    await waitFor('document.querySelector(".apexcharts-canvas")');
    check(await evaluate('document.querySelectorAll(".db-kpi").length>=3 && !document.querySelector("[data-key=jornada]")'),'Allowed KPI rendered, unauthorized Jornada absent');
    check(await evaluate('document.querySelectorAll("[data-chart]").length <=3'),'At most three charts');
    check(await evaluate('!document.querySelector(".ux-module-grid") && !document.getElementById("dashboard").textContent.includes("Abrir personas")'),'Menu cards removed');
    for(const width of [320,390,768,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await pause(500);
        check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'),`No horizontal overflow at ${width}`);
        check(await evaluate('!!document.getElementById("electoral-context-select") && !!document.getElementById("button-toggle-menu")'),'Existing header and navigation present');
        const image=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(directory,`dashboard-${width}.png`),Buffer.from(image.data,'base64'));
    }
    await evaluate('document.getElementById("db-note-new").click()');
    await waitFor('document.getElementById("db-note-dialog").open');
    await evaluate('document.getElementById("db-note-text").value="Nota desde Chrome <img src=x onerror=alert(1)>";document.getElementById("db-note-reminder").value="2027-02-01T12:30";document.getElementById("db-note-pin").checked=true;document.getElementById("db-note-form").requestSubmit()');
    await waitFor('!document.getElementById("db-note-dialog").open && document.getElementById("db-notes").textContent.includes("Nota desde Chrome")');
    check(await evaluate('!document.querySelector("#db-notes img")'),'Notes render as text, no stored HTML/XSS');
    await evaluate('document.querySelector("#db-notes .db-note-actions button").click()');
    await waitFor('document.getElementById("db-note-dialog").open');
    await evaluate('document.getElementById("db-note-text").value="Nota editada Chrome";document.getElementById("db-note-form").requestSubmit()');
    await waitFor('!document.getElementById("db-note-dialog").open && document.getElementById("db-notes").textContent.includes("Nota editada Chrome")');checks++;
    await evaluate('document.querySelectorAll("#db-notes .db-note-actions button")[1].click()');
    await waitFor('document.querySelector(".db-note-done")');checks++;
    // Read both queries after writes, which invalidate the existing PWA private cache.
    await waitFor('window.SicratPwa && SicratPwa.state.unlocked');
    await evaluate('document.getElementById("db-refresh").click()');await pause(1500);
    const etag=await evaluate(`(async()=>{let r=await fetch('/api/dashboard?days=7');return r.headers.get('ETag') || r.headers.get('X-Sicrat-Local') === 'validated';})()`);
    check(!!etag,'Dashboard supplies ETag or validated 304 representation through existing PWA');
    const targets = await call('Target.getTargets');
    const worker = targets.targetInfos.find(target => target.type === 'service_worker' && target.url === base + '/service-worker.js');
    check(!!worker, 'Real service worker controls Dashboard');
    const attached = await call('Target.attachToTarget', {targetId: worker.targetId, flatten: true});
    const workerCall = (method, params = {}) => call(method, params, attached.sessionId);
    await workerCall('Network.enable'); await call('Network.enable');
    const setOffline = async offline => {
        const settings = {offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1};
        await workerCall('Network.emulateNetworkConditions', settings); await call('Network.emulateNetworkConditions', settings);
    };
    await setOffline(true);
    await evaluate('document.getElementById("db-refresh").click()');
    await waitFor('document.getElementById("db-status").textContent.includes("Información guardada") && document.getElementById("db-notes-status").textContent.includes("Notas guardadas")');
    check(await evaluate('[...document.querySelectorAll("[data-note-write]")].every(x=>x.disabled)'),'All note mutations disabled offline');
    const failedWrite=await evaluate(`(async()=>{const r=await fetch('/api/dashboard/notes',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({texto:'No guardar offline'})});return r.status;})()`);
    check(failedWrite>=400,'Worker refuses writes offline, no queue');
    // Real offline navigation uses the existing read-only shell, not cached authenticated HTML.
    await navigate('/');await waitFor('document.getElementById("offline-records")');
    await waitFor('document.getElementById("offline-records").textContent.includes("Dashboard")');
    await evaluate('document.querySelectorAll("#offline-records details").forEach(el=>el.open=true)');
    await waitFor('document.getElementById("offline-records").textContent.includes("Nota editada Chrome")');
    check(await evaluate('document.getElementById("offline-records").textContent.includes("Nota editada Chrome")'),'Reopened offline shell exposes only encrypted authorized saved notes');
    await setOffline(false);
    await navigate('/');await waitFor('document.getElementById("dashboard")?.dataset.loaded === "true"');
    check(await evaluate('document.getElementById("db-status").textContent === ""'),'Online refresh no longer claims saved data');
    return checks;
};
