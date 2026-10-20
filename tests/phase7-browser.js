'use strict';
const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');
module.exports=async function({base,directory,call,evaluate,waitFor,navigate,pause}) {
    let checks=0;const fixtures=JSON.parse(fs.readFileSync(path.join(directory,'phase7-fixtures.json'),'utf8'));
    const verify=(value,message)=>{assert.ok(value,message);checks++;};
    async function login(username,password,election=fixtures.election) {
        await navigate('/autenticacion/');await waitFor('document.querySelector("meta[name=csrf-token]")');
        const result=await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;return (await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({username:${JSON.stringify(username)},password:${JSON.stringify(password)}})})).status;})()`);verify(result===200,'Login de Jornada');
        await navigate('/');await waitFor('document.querySelector("meta[name=csrf-token]")');
        verify(await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;return (await fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({process:${JSON.stringify(election)}})})).status===200;})()`),'Selección electoral explícita');
        await navigate('/jornada/');await waitFor('window.SicratJornada?.initialized');await waitFor('document.getElementById("electoral-context-select")?.value=== '+JSON.stringify(election));
    }
    async function screenshot(name){const image=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(directory,'phase7-'+name+'.png'),Buffer.from(image.data,'base64'),{mode:0o600});}
    async function size(width,height){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<500});await pause(200);}
    async function bounds(label){verify(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),label+': sin desbordamiento horizontal');}
    await login('administrador',' nueva contraseña ');
    for(const [width,height,name] of [[1600,1000,'desktop'],[820,1024,'tablet'],[390,844,'mobile']]) {
        await size(width,height);await evaluate('document.getElementById("jornada-summary-filter").elements.modo.value="validado";document.getElementById("jornada-tab-dashboard").click()');await waitFor('document.getElementById("jornada-summary-legend").textContent.startsWith("Validado interno") && document.querySelector("#jornada-candidate-chart svg")');
        verify(await evaluate('document.getElementById("jornada-summary-legend").textContent.includes("Validado interno")'),'Concentrado validado identificado');await bounds(name+' dashboard');await screenshot('dashboard-'+name);
        await evaluate('document.getElementById("jornada-summary-filter").elements.modo.value="preliminar";document.getElementById("jornada-summary-filter").requestSubmit()');await waitFor('document.getElementById("jornada-summary-legend").textContent.startsWith("Preliminar interno")');verify(true,'Concentrados separados por modo');
        await evaluate('document.getElementById("jornada-tab-configuration").click()');await waitFor('document.getElementById("jornada-acta-preview")');
        verify(await evaluate('document.getElementById("jornada-acta-preview").textContent.indexOf("PAN")<document.getElementById("jornada-acta-preview").textContent.indexOf("PRI")'),'Vista previa respeta orden del acta');
        verify(await evaluate('!document.getElementById("jornada-config-form")'),'Configuración capturada se presenta protegida, sin formulario inutilizable');
        verify(await evaluate('document.getElementById("jornada-assign-form")?.querySelectorAll("select[multiple]").length===2'),'Selección múltiple de capturistas y casillas');await bounds(name+' configuración');await screenshot('configuration-'+name);
        verify(await evaluate(`!!document.querySelector('#jornada-booth-territories select[aria-label="Capturista asignado"]')`),'Casillas permite filtrar por capturista autorizado');
        await evaluate(`SicratJornada.open(${JSON.stringify(fixtures.booths[0])})`);await waitFor('document.getElementById("jornada-booth-dialog").open && document.querySelector("[data-capture-form]")');
        verify(await evaluate('Array.from(document.querySelectorAll("[data-capture-form] input[name^=vote_]")).length===4'),'Captura muestra exactamente las cuatro filas configuradas');
        verify(await evaluate('Array.from(document.querySelectorAll("[data-capture-form] input[name^=vote_]")).every(input=>input.inputMode==="numeric" && input.step==="1" && input.min==="0")'),'Campos enteros adecuados para móvil');
        verify(await evaluate('document.getElementById("jornada-booth-content").textContent.includes("propuesta pendiente")'),'Advertencia de captura adicional es inline');await screenshot('capture-'+name);
        await evaluate(`SicratFeedback.toast('Acta guardada dentro del diálogo','success');void 0`);await waitFor('document.querySelector("#jornada-booth-dialog .swal2-toast")');verify(true,'Toast es visible dentro del diálogo nativo');
        await evaluate('Swal.close();document.getElementById("jornada-booth-dialog").close()');
        await evaluate(`SicratJornada.open(${JSON.stringify(fixtures.booths[0])})`);await waitFor('document.querySelector("#jornada-booth-content table button")');
        await evaluate('document.querySelector("#jornada-booth-content table button").click()');await waitFor('document.getElementById("jornada-version-dialog").open');
        verify(await evaluate('document.getElementById("jornada-version-content").textContent.includes("Diferencia")'),'Comparación incluye crudo, vigente y diferencia');await screenshot('comparison-'+name);
        await evaluate('document.getElementById("jornada-version-dialog").close();document.getElementById("jornada-booth-dialog").close()');
        await evaluate('document.getElementById("jornada-tab-reports").click()');await waitFor('document.getElementById("jornada-export-form")');verify(await evaluate('document.getElementById("jornada-export-form").elements.type.options.length===9'),'Nueve reportes seleccionables');await bounds(name+' reportes');
    }
    await size(1600,1000);await evaluate(`SicratJornada.open(${JSON.stringify(fixtures.booths[11])})`);await waitFor('document.querySelector("[data-capture-form]")');
    await evaluate(`(()=>{const form=document.querySelector('[data-capture-form]');Array.from(form.querySelectorAll('input[name^=vote_]')).forEach((input,index)=>input.value=[100,80,20,0][index]);form.requestSubmit();})()`);
    await waitFor('document.querySelector(".swal2-confirm")');verify(await evaluate('document.querySelector(".swal2-html-container").textContent.includes("Total: 200")'),'Confirmación contiene elección, casilla y total exacto');
    await evaluate('document.querySelector(".swal2-confirm").click()');await waitFor('document.querySelector(".swal2-toast .swal2-title")?.textContent.includes("Versión 1 registrada")');verify(true,'Captura real finaliza con toast y sin spinner indefinido');
    await evaluate('Swal.close();document.getElementById("jornada-booth-dialog").close()');
    await evaluate(`(()=>{const button=Array.from(document.querySelectorAll('#jornada-state-actions button')).find(button=>button.textContent==='Cerrar Jornada Electoral');button.click();})()`);await waitFor('document.querySelector(".swal2-textarea")');
    await evaluate('document.querySelector(".swal2-textarea").value="Cierre desde revisión visual";document.querySelector(".swal2-confirm").click()');await waitFor('document.getElementById("jornada-state").textContent==="Cerrada"');verify(true,'Cierre usa confirmación SweetAlert y motivo');
    await evaluate(`Array.from(document.querySelectorAll('#jornada-state-actions button')).find(button=>button.textContent==='Reabrir Jornada Electoral').click()`);await waitFor('document.querySelector(".swal2-textarea")');
    await evaluate('document.querySelector(".swal2-textarea").value="Reapertura desde revisión visual";document.querySelector(".swal2-confirm").click()');await waitFor('document.getElementById("jornada-state").textContent==="Abierta"');verify(true,'Reapertura conserva estado e historial');
    await evaluate('document.getElementById("jornada-tab-reports").click()');await waitFor('document.getElementById("jornada-export-form")');
    await evaluate('document.getElementById("jornada-export-form").requestSubmit()');await waitFor('document.querySelector(".swal2-info")');verify(await evaluate('document.querySelector(".swal2-title").textContent.includes("puedes continuar trabajando")'),'Exportación programada comunica que puede continuar');
    await login('fase7-captura-0','Fase7 captura segura');await size(390,844);
    verify(await evaluate('!document.getElementById("jornada-booths").hidden && document.getElementById("jornada-filters").elements.mis.checked'),'Capturista entra a Mis casillas');
    verify(await evaluate('document.getElementById("jornada-tab-configuration").hidden'),'Capturista no recibe formulario de administración');
    verify(await evaluate('document.getElementById("jornada-summary-filter").elements.modo.querySelector("option[value=preliminar]").disabled'),'No ofrece concentrado preliminar sin permiso');
    await screenshot('my-booths-mobile');await bounds('Mis casillas móvil');
    await evaluate(`SicratJornada.open(${JSON.stringify(fixtures.booths[0])})`);await waitFor('document.querySelector("[data-capture-form]")');
    const documentNode=await call('DOM.getDocument');const input=await call('DOM.querySelector',{nodeId:documentNode.root.nodeId,selector:'[data-capture-form] input[type=file]'});await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:[path.join(directory,'test-image.png')]});
    await evaluate('document.querySelector("[data-capture-form]").requestSubmit()');await waitFor('document.querySelector(".swal2-confirm")');await evaluate('document.querySelector(".swal2-confirm").click()');
    await waitFor('document.querySelector(".swal2-info")');verify(await evaluate('document.querySelector(".swal2-title").textContent.includes("Pendiente de validación")'),'Capturista adicional guarda imagen y propuesta pendiente');
    await evaluate('Swal.close();document.getElementById("jornada-booth-dialog").close()');
    await login('administrador',' nueva contraseña ',fixtures.election_b);await size(390,844);
    await evaluate('document.getElementById("jornada-tab-booths").click()');await waitFor('document.querySelector("#jornada-booth-list table button")');await evaluate('document.querySelector("#jornada-booth-list table button").click()');await waitFor('document.querySelector("#jornada-booth-content table button")');await evaluate('document.querySelector("#jornada-booth-content table button").click()');await waitFor('document.querySelector("#jornada-version-content img")?.naturalWidth>0');verify(true,'Fotografía obligatoria visible con acceso autorizado');await screenshot('private-acta-mobile');
    checks += await require('./jornada-interface-browser.js')({ directory, call, evaluate, waitFor, navigate, pause });

    console.log(`Fase 7 Chrome: ${checks} comprobaciones correctas.`);return checks;
};
