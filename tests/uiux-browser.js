const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
module.exports=async({base,directory,call,evaluate,waitFor,navigate,pause})=>{
    let checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;};
    const fixture=JSON.parse(fs.readFileSync(path.join(directory,'uiux.json'),'utf8'));
    const capture=async name=>{const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(directory,'uiux-'+name+'.png'),Buffer.from(shot.data,'base64'));};
    const dark=async()=>{await evaluate('(()=>{const button=document.getElementById("light-dark-mode");if(button){if(document.documentElement.dataset.theme!=="dark")button.click();}else document.documentElement.dataset.theme="dark";})()');await pause(450);};
    await dark();
    check(await evaluate('getComputedStyle(document.querySelector(".db-panel")).backgroundColor === "rgb(24, 36, 51)"'),'Dark card uses shared palette');
    check(await evaluate('document.querySelector(".db-activity-subject")?.textContent.length>0 && !!document.querySelector(".db-activity-link")'),'Activity has subject and detail action');
    check(await evaluate('getComputedStyle(document.querySelector(".sicrat-brand img")).filter.includes("invert(1)")'),'Negative SICRAT logo on dark background');
    for(const width of [390,1440]){
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await pause(350);
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Dark dashboard fits '+width);
        await capture('dashboard-dark-'+width);
    }
    await evaluate('document.getElementById("db-notes").scrollIntoView({block:"center"})');await capture('notes-dark');
    await evaluate('document.getElementById("light-dark-mode").click()');await pause(450);
    check(await evaluate('document.documentElement.dataset.theme==="light" && getComputedStyle(document.querySelector(".db-panel")).backgroundColor==="rgb(255, 255, 255)"'),'Actual theme button restores light panels');
    await capture('notes-light');
    await evaluate('document.getElementById("light-dark-mode").click()');await pause(450);
    check(await evaluate('document.documentElement.dataset.theme==="dark" && !!document.querySelector(".apexcharts-tooltip.apexcharts-theme-dark")'),'Actual theme button updates existing charts');
    await navigate('/autenticacion/');
    await waitFor('document.querySelector("meta[name=csrf-token]")');
    check(await evaluate(`(async()=>{const r=await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({username:'administrador',password:' nueva contraseña '})});return r.status===200;})()`),'Admin login');
    await navigate('/');
    await evaluate(`fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({process:${JSON.stringify(fixture.election)}})})`);
    await navigate('/operations/?module=tareas');
    await waitFor('document.getElementById("operation-new") && !document.getElementById("operation-new").hidden');
    await evaluate('document.getElementById("operation-new").click()');
    await waitFor('document.getElementById("operation-create-dialog").open && document.querySelector(".op-picker tr[data-value]")');
    await dark();
    await evaluate('window.picker=document.querySelector(".op-picker");picker.querySelector("input[type=search]").value="ELIV";picker.querySelector("input[type=search]").dispatchEvent(new Event("input"))');
    await waitFor('picker.querySelectorAll("tr[data-value]").length===1 && picker.textContent.includes("Éliver Pérez López")');
    await evaluate('picker.querySelector("tr[data-value] td:nth-child(2)").click()');
    check(await evaluate(`picker.querySelector('select').selectedOptions[0].value===${JSON.stringify(fixture.person)} && picker.querySelector('tr[data-value]').getAttribute('aria-selected')==='true'`),'Clicking label selects the real value and highlights entire row');
    await evaluate('picker.querySelector("tr[data-value]").dispatchEvent(new KeyboardEvent("keydown",{key:" ",bubbles:true}))');
    check(await evaluate('picker.querySelector("select").selectedOptions.length===0'),'Space toggles selection');
    await evaluate('picker.querySelector("tr[data-value]").dispatchEvent(new KeyboardEvent("keydown",{key:"Enter",bubbles:true}))');
    check(await evaluate('picker.querySelector("select").selectedOptions.length===1'),'Enter selects a person');
    for(const width of [390,1440]){
        await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await pause(250);
        check(await evaluate('document.getElementById("operation-create-dialog").getBoundingClientRect().right<=innerWidth+1'),'Selection dialog fits '+width);await capture('selector-dark-'+width);
    }
    await evaluate('document.querySelector("#operation-create-form [name=title]").value="Tarea UIUX seleccionada";document.getElementById("operation-create-form").requestSubmit()');
    await waitFor('!document.getElementById("operation-create-dialog").open');
    check(await evaluate(`(async()=>{const r=await(await fetch('/api/operations/tareas?search=Tarea%20UIUX')).json();return JSON.stringify(r).includes('Tarea UIUX seleccionada');})()`),'Real form saves the selected assignment');
    await navigate('/users/');await waitFor('typeof loadUserAccesses==="function"');
    await evaluate(`loadUserAccesses(${JSON.stringify(fixture.user)})`);
    await waitFor('document.getElementById("user-access-dialog").open');await dark();
    check(await evaluate(`document.getElementById('user-person-current').textContent.includes('Éliver Pérez López') && document.getElementById('user-person-select').selectedOptions[0].textContent.includes(${JSON.stringify(fixture.code)})`),'Reopened linked person shows code and name');
    await evaluate('document.getElementById("user-person-find").click()');
    await waitFor('document.querySelector("dialog.ux-entity-selector tr[data-value]") || document.querySelectorAll("dialog[open]").length===2');
    await evaluate('window.entityDialog=[...document.querySelectorAll("dialog[open]")].at(-1)');
    await waitFor('entityDialog.querySelector("tr[data-value]")');
    await evaluate('entityDialog.querySelector("tr[data-value]").dispatchEvent(new KeyboardEvent("keydown",{key:"Enter",bubbles:true}))');
    await waitFor('!entityDialog.open');check(true,'Entity selector closes after keyboard selection');
    for(const [name,route] of [['persons','/persons/'],['structure','/structure/?structure='+fixture.structure],['services','/services/']]){
        await navigate(route);await waitFor('document.getElementById("electoral-context-select")');await pause(400);await dark();
        for(const width of [390,1440]){
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await pause(250);
            check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),name+' fits '+width);await capture(name+'-dark-'+width);
        }
    }
    await evaluate('document.getElementById("services-tab-files").click();document.getElementById("file-person-find").click()');
    await waitFor('document.querySelector(".ux-entity-selector tbody tr")');
    await evaluate('document.querySelector(".ux-entity-selector tbody tr").click()');
    await waitFor('!document.querySelector(".ux-entity-selector")');
    check(await evaluate('!!document.getElementById("file-form").elements.record.value'),'File attachment uses selectable rows');
    // A second real server login invalidates this browser session without sharing its cookie jar.
    const auth=await fetch(base+'/autenticacion/');const html=await auth.text();
    const csrf=html.match(/name="csrf-token" content="([^"]+)"/)[1];
    const cookies=auth.headers.getSetCookie().map(c=>c.split(';')[0]).join('; ');
    const second=await fetch(base+'/api/auth/login',{method:'POST',headers:{Cookie:cookies,'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({username:'administrador',password:' nueva contraseña '})});
    check(second.status===200,'Second device login accepted');
    await evaluate('fetch("/api/auth/me").catch(()=>{})').catch(()=>{});
    await waitFor('location.pathname.startsWith("/autenticacion") && !!document.querySelector(".sicrat-session-message")');
    check(await evaluate('!document.querySelector("[data-offline-shell]") && document.querySelector(".sicrat-session-message").textContent.includes("sesión")'),'Superseded session opens login with explanation, not offline shell');
    await dark();await capture('login-dark');
    // Legacy jQuery screens also handle a real 401 with no service worker interception.
    await evaluate(`fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({username:'administrador',password:' nueva contraseña '})})`);
    await navigate('/persons/');await waitFor('typeof jQuery==="function" && window.SicratPwa?.state.unlocked');
    await call('Network.setBypassServiceWorker',{bypass:true});
    const fresh=await fetch(base+'/autenticacion/');const freshHtml=await fresh.text();
    await fetch(base+'/api/auth/login',{method:'POST',headers:{Cookie:fresh.headers.getSetCookie().map(c=>c.split(';')[0]).join('; '),'Content-Type':'application/json','X-CSRF-Token':freshHtml.match(/name="csrf-token" content="([^"]+)"/)[1]},body:JSON.stringify({username:'administrador',password:' nueva contraseña '})});
    await evaluate('$.getJSON("/api/auth/me").fail(()=>{});void 0').catch(()=>{});
    await waitFor('location.pathname.startsWith("/autenticacion") && !!document.querySelector(".sicrat-session-message")');
    check(true,'Legacy AJAX session failure reaches login without worker interception');
    await waitFor('window.SicratPwa');
    check(await evaluate('(async()=>{const registration=await navigator.serviceWorker.getRegistration();const status=await SicratPwa.send("STATUS",{},registration.active);return status.profiles.length===0 && !status.unlocked;})()'),'A bypassed 401 also removes persisted offline authorization');
    await call('Network.setBypassServiceWorker',{bypass:false});
    console.log(`UI/UX Chrome: ${checks} comprobaciones.`);return checks;
};
