const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

module.exports = async function ({base,directory,call,evaluate,waitFor,navigate,pause}) {
    let checks = 0;
    const captures = [];
    const verify = (value, description) => { assert.ok(value, description); checks++; };
    const sizes = [[1600,1000,'wide'],[1280,960,'laptop'],[820,1024,'tablet'],[390,844,'mobile']];
    async function size(width,height) { await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false}); await pause(350); }
    async function capture(name) {
        const result = await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        const filename = 'ux-' + name + '.png';
        fs.writeFileSync(path.join(directory,filename),Buffer.from(result.data,'base64'),{mode:0o600});
        captures.push(filename);
    }
    async function login(username,password) {
        const status = await evaluate(`(async () => { const token=document.querySelector('meta[name="csrf-token"]').content; return (await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({username:${JSON.stringify(username)},password:${JSON.stringify(password)}})})).status; })()`);
        verify(status === 200,'Login de la cuenta ficticia de UX');
        await navigate('/');
    }
    async function election(name) {
        await waitFor('document.querySelectorAll("#electoral-context-select option").length > 1');
        await evaluate(`(() => { const field=document.getElementById('electoral-context-select'); field.value=Array.from(field.options).find(option=>option.textContent.includes(${JSON.stringify(name)})).value; field.dispatchEvent(new Event('change')); })()`);
        await waitFor(`document.readyState === 'complete' && document.getElementById('electoral-context-select')?.selectedOptions[0]?.textContent.includes(${JSON.stringify(name)})`);
        await pause(150);
    }
    async function tab(group,name,expression) {
        await evaluate(`document.getElementById(${JSON.stringify(group + '-tab-' + name)}).click()`);
        if (expression) await waitFor(expression);
        verify(await evaluate(`!document.getElementById(${JSON.stringify(group + '-' + name)}).hidden`),'Pestaña visible: ' + group + '/' + name);
    }
    async function dialogReview(id,name) {
        for (const [width,height,label] of [[1600,1000,'wide'],[390,844,'mobile']]) {
            await size(width,height);
            verify(await evaluate(`(() => { const dialog=document.getElementById(${JSON.stringify(id)}); const rect=dialog.getBoundingClientRect(); return dialog.open && !!(dialog.getAttribute('aria-label') || dialog.getAttribute('aria-labelledby')) && rect.left>=0 && rect.right<=innerWidth+1 && rect.top>=0 && rect.bottom<=innerHeight+1; })()`),'Diálogo accesible y dentro del viewport: ' + name + '/' + label);
            await capture(name + '-' + label);
        }
        await evaluate(`document.getElementById(${JSON.stringify(id)}).close()`);
        await size(1600,1000);
    }
    async function bounds(description) {
        verify(await evaluate('parseFloat(getComputedStyle(document.querySelector("main")).paddingTop) >= 12'),description + ': separación superior uniforme');
        const geometry = await evaluate(`(() => { const header=document.querySelector('.app-header'); const selector=document.getElementById('electoral-context-select'); const toggle=document.getElementById('button-toggle-menu'); const rectangles=[header,selector,toggle].filter(Boolean).map(element=>element.getBoundingClientRect()); return {width:innerWidth,scroll:document.documentElement.scrollWidth,rectangles:rectangles.map(rect=>({left:rect.left,right:rect.right}))}; })()`);
        verify(geometry.scroll <= geometry.width + 2,description + ': sin desbordamiento horizontal de página');
        verify(geometry.rectangles.every(rect=>rect.left >= -1 && rect.right <= geometry.width+1),description + ': header dentro del viewport');
        if (geometry.rectangles.length) verify(geometry.rectangles[1].left >= geometry.rectangles[2].right,description + ': selector a la derecha del menú');
    }
    await login('administrador',' nueva contraseña ');
    await election('Promoción Fase 3');
    const routes = [['dashboard','/'],['organizations','/organizations/'],['elections','/electoral-process/'],['sections','/sections/'],['polling','/polling-booth/'],['persons','/persons/'],['users','/users/'],['structure','/structure/'],['administration','/administration/'],['services','/services/']];
    const profile = await evaluate(`document.querySelector('a[href*="/profile/"]').getAttribute('href')`);
    routes.push(['profile',new URL(profile,base).pathname]);
    for (const [width,height,label] of sizes) {
        await size(width,height);
        for (const [name,route] of routes) {
            await navigate(route);
            await waitFor('document.getElementById("electoral-context-select")?.options.length > 1');
            await pause(200);
            await bounds(name + ' ' + label);
            verify(await evaluate('!Array.from(document.querySelectorAll("main > .card")).some(card=>card.textContent.startsWith("Elección de trabajo"))'),'No existe tarjeta electoral anterior');
            if (['organizations','users','persons','sections'].includes(name)) verify(await evaluate('Array.from(document.querySelectorAll("main .btn.hidden")).every(button=>getComputedStyle(button).display==="none")'),'Controles ocultos conservan su estado visual');
            await capture(name + '-' + label);
        }
    }
    await size(1600,1000); await navigate('/administration/');
    await waitFor('document.querySelectorAll("#module-list > div").length > 0');
    for (const [width,height,label] of sizes) {
        await size(width,height);
        const count = await evaluate('getComputedStyle(document.getElementById("module-list")).gridTemplateColumns.split(" ").length');
        verify(count === (width >= 1280 ? 3 : width >= 640 ? 2 : 1),'Grid de módulos en ' + label);
    }
    await size(1600,1000);
    await evaluate(`(() => { const row=Array.from(document.querySelectorAll('#module-list > div')).find(item=>item.querySelector('h6').textContent==='Estructura organizacional'); window.uxModule=row.querySelectorAll('input')[1]; window.uxInitialModule=window.uxModule.checked; window.uxOriginalRequest=window.SicratPhase3.request; window.uxModule.click(); window.SicratPhase3.request=async function(path,method,data) { if (method==='PUT' && path.includes('/modules/')) throw new Error('Fallo simulado de conexión'); return window.uxOriginalRequest.call(this,path,method,data); }; document.getElementById('module-save').click(); })()`);
    await waitFor('document.getElementById("module-save-status").textContent.includes("Error:")');
    verify(await evaluate('!document.getElementById("module-save").disabled'),'Guardar módulos permite reintentar un fallo visible');
    await evaluate('window.SicratPhase3.request=window.uxOriginalRequest; document.getElementById("module-save").click()');
    await waitFor('document.getElementById("module-save-status").textContent==="Cambios guardados"');
    verify(await evaluate(`Array.from(document.querySelectorAll('#module-list > div')).find(item=>item.querySelector('h6').textContent==='Estructura organizacional').querySelectorAll('input')[1].checked !== window.uxInitialModule`),'Módulo guardado y leído desde API con CSRF');
    await evaluate(`(() => { const row=Array.from(document.querySelectorAll('#module-list > div')).find(item=>item.querySelector('h6').textContent==='Estructura organizacional'); row.querySelectorAll('input')[1].click(); document.getElementById('module-save').click(); })()`);
    await waitFor('document.getElementById("module-save-status").textContent==="Cambios guardados"');
    verify(await evaluate(`Array.from(document.querySelectorAll('#module-list > div')).find(item=>item.querySelector('h6').textContent==='Estructura organizacional').querySelectorAll('input')[1].checked === window.uxInitialModule`),'Estado inicial del módulo recuperado en el ensayo');
    await tab('administration','permissions');
    await waitFor('document.getElementById("permission-form").elements.permission.options.length > 1');
    verify(await evaluate('(() => { const form=document.getElementById("role-form"); const input=form.elements.name.getBoundingClientRect(); const button=form.querySelector("button").getBoundingClientRect(); return Math.abs(input.bottom-button.bottom)<2; })()'),'Crear rol alineado con su input');
    await evaluate('document.getElementById("role-form").elements.name.value="Rol de revisión visual"; document.getElementById("role-form").requestSubmit()');
    await waitFor('document.getElementById("role-list").textContent.includes("Rol de revisión visual")');
    verify(true,'Crear rol conserva el flujo real y muestra la confirmación');
    await evaluate('document.getElementById("permission-tab-role").click(); document.getElementById("permission-inspect").click()');
    await waitFor('document.querySelectorAll("#permission-list tr").length > 1');
    verify(await evaluate('!document.getElementById("permission-list").textContent.includes(".ver") && !document.getElementById("permission-list").textContent.includes("Base o global")'),'Permisos legibles sin códigos principales');
    await capture('permissions-wide');
    await evaluate('document.getElementById("permission-tab-user").click()');
    verify(await evaluate('document.getElementById("permission-role-field").hidden && !document.getElementById("permission-user-field").hidden'),'Excepción individual muestra sólo controles aplicables');
    await evaluate('document.getElementById("permission-form").elements.value.value="0"; document.getElementById("permission-form").elements.value.dispatchEvent(new Event("change"))');
    verify((await evaluate('document.getElementById("permission-decision-help").textContent')).includes('heredado'),'Ayuda sobre revocaciones');
    await evaluate('document.getElementById("permission-tab-territories").click()');
    await waitFor('document.querySelectorAll("#territory-options input").length > 0');
    verify(await evaluate('document.getElementById("territory-options").getBoundingClientRect().height >= 150'),'Multiselección de territorios con altura apropiada');
    await evaluate('document.querySelector("#territory-options input").click()');
    verify((await evaluate('document.getElementById("territory-count").textContent')).includes('1 seleccionadas'),'Contador de selección');
    await capture('territories-wide');
    await size(390,844); await capture('territories-mobile');
    await evaluate('document.getElementById("permission-tab-territories").focus(); document.getElementById("permission-tab-territories").dispatchEvent(new KeyboardEvent("keydown",{key:"Home",bubbles:true}))');
    verify(await evaluate('document.getElementById("permission-tab-roles").getAttribute("aria-selected")==="true"'),'Navegación con teclado entre pestañas');
    await size(1600,1000); await navigate('/persons/');
    await waitFor('document.querySelector(".person-open")');
    await evaluate('document.querySelector(".person-open").click()');
    await waitFor('document.getElementById("person-detail").open');
    verify(await evaluate('Array.from(document.querySelectorAll("#person-edit-fields legend")).map(item=>item.textContent).join("|")==="Datos personales|Contacto|Domicilio"'),'Ficha de Persona agrupada');
    for (const [width,height,label] of sizes) {
        await size(width,height); await capture('person-dialog-' + label);
        verify(await evaluate('(() => { const rect=document.getElementById("person-detail").getBoundingClientRect(); return rect.left>=0 && rect.right<=innerWidth+1 && rect.top>=0 && rect.bottom<=innerHeight+1; })()'),'Modal de Persona dentro del viewport ' + label);
    }
    await evaluate('document.getElementById("person-detail").close(); document.getElementById("person-import-open").click()');
    await waitFor('document.getElementById("person-import").open'); await capture('import-mobile');
    await size(1600,1000); await capture('import-wide');
    const importFile=path.join(directory,'ux-import.csv'); fs.writeFileSync(importFile,'name,last_name\nPersona de revisión visual,Prueba\n',{mode:0o600});
    const importDocument=await call('DOM.getDocument'); const importInput=await call('DOM.querySelector',{nodeId:importDocument.root.nodeId,selector:'#person-import-upload input[type=file]'});
    await call('DOM.setFileInputFiles',{nodeId:importInput.nodeId,files:[importFile]});
    await evaluate('document.getElementById("person-import-upload").requestSubmit()');
    await waitFor('document.querySelectorAll("#person-import-columns select").length>1 && !personImportBusy');
    await capture('import-mapping-wide');
    await evaluate('document.getElementById("person-import-mapping").requestSubmit()');
    await waitFor('document.getElementById("person-import-preview").textContent.includes("Persona de revisión visual") && !personImportBusy');
    verify(true,'Importación conserva carga y revisión reales');
    await capture('import-review-wide'); await size(390,844); await capture('import-review-mobile');
    await evaluate('document.getElementById("person-import").close(); document.getElementById("identity-policy-open").click()');
    await waitFor('document.getElementById("identity-policy").open'); await dialogReview('identity-policy','identity-policy');
    await navigate('/users/'); await waitFor('document.querySelector("#table-users tbody tr[data-id]")');
    await evaluate('document.querySelector("#table-users tbody tr[data-id]").click()');
    await waitFor('document.getElementById("user-access-dialog").open');
    verify(await evaluate('document.getElementById("user-access-dialog").textContent.includes("Accesos electorales") && document.getElementById("user-access-dialog").textContent.includes("Persona vinculada")'),'Accesos y persona separados');
    await capture('user-dialog-wide'); await size(390,844); await capture('user-dialog-mobile');
    await size(1600,1000); await navigate('/structure/'); await waitFor('document.querySelector("#structure-members button")');
    await evaluate('document.querySelector("#structure-members button").click()'); await waitFor('document.getElementById("structure-member-dialog").open');
    await capture('structure-dialog-wide'); await size(390,844); await capture('structure-dialog-mobile');
    await evaluate('document.getElementById("structure-member-dialog").close()');
    for (const [button,name] of [['structure-create','new-structure'],['structure-clone','clone-structure'],['structure-associate','associate-election']]) {
        await evaluate(`document.getElementById(${JSON.stringify(button)}).click()`); await waitFor('document.getElementById("structure-container-dialog").open'); await dialogReview('structure-container-dialog',name);
    }
    await evaluate('document.getElementById("structure-add").click()'); await waitFor('document.getElementById("structure-add-dialog").open'); await dialogReview('structure-add-dialog','add-member');
    for (const [button,name] of [['structure-history','structure-history'],['structure-requests','transfer-requests']]) {
        await evaluate(`document.getElementById(${JSON.stringify(button)}).click()`); await waitFor('document.getElementById("structure-list-dialog").open'); await dialogReview('structure-list-dialog',name);
    }
    await evaluate(`(() => { const buttons=Array.from(document.querySelectorAll('#structure-members button')); buttons.find(button=>button.textContent.includes('Primera incorporación')).click(); })()`);
    await waitFor('document.getElementById("structure-member-dialog").open && !document.getElementById("structure-create-account").hidden');
    await evaluate('document.getElementById("structure-create-account").click()'); await waitFor('document.getElementById("structure-account-dialog").open'); await dialogReview('structure-account-dialog','create-account');
    await evaluate('document.getElementById("structure-member-dialog").close()'); await election('Proceso 1');
    await size(1600,1000); await navigate('/services/');
    await waitFor('document.querySelectorAll("#notifications-list tr").length > 0 || document.getElementById("notifications-list").textContent.includes("Sin registros")');
    verify(await evaluate('document.getElementById("audit-list").children.length===0 && document.getElementById("backups-list").children.length===0'),'Servicios no carga datasets de pestañas inactivas');
    for (const name of ['audit','approvals','files','exports','jobs','deliveries','backups']) {
        await tab('services',name);
        await pause(300);
        await capture('service-' + name + '-wide');
        await size(390,844); await bounds('Servicio ' + name + ' móvil'); await capture('service-' + name + '-mobile');
        await size(1600,1000);
    }
    await tab('services','audit');
    await waitFor('document.querySelectorAll("#audit-filter select[name=user] option").length > 1');
    verify(await evaluate('!document.getElementById("audit-filter").textContent.includes("(ID)") && document.getElementById("audit-filter").querySelectorAll("input[type=number]").length===0'),'Auditoría filtra por nombres');
    verify(await evaluate('!document.getElementById("audit-list").textContent.includes(" · 1 · ")'),'Auditoría presenta nombres de contexto');
    await tab('services','approvals');
    const approvalButton = await evaluate('!!document.querySelector("#approvals-list button")');
    if (approvalButton) {
        await evaluate('document.querySelector("#approvals-list button").click()'); await waitFor('document.getElementById("approval-dialog").open');
        verify(await evaluate('!document.getElementById("approval-detail").textContent.includes("entidad_id")'),'Aprobación legible');
        await capture('approval-wide'); await size(390,844); await capture('approval-mobile');
        await evaluate('document.getElementById("approval-dialog").close()');
    }
    await tab('services','backups');
    await evaluate(`(() => { const dialog=document.getElementById('restore-dialog'); window.SicratUI.details(document.getElementById('restore-detail'),{contenido:'Base de datos y archivos',archivos:2,destino:'Destino independiente de prueba',confirmacion:'RESTAURAR copia de prueba'}); dialog.showModal(); })()`);
    await dialogReview('restore-dialog','restore-preview');
    await size(390,844); await navigate('/');
    verify(await evaluate('document.getElementById("app-menu").inert'),'Menú móvil cerrado fuera del recorrido de foco');
    await evaluate('document.getElementById("button-toggle-menu").click()'); await pause(350);
    verify(await evaluate('!document.getElementById("app-menu").inert'),'Menú móvil abierto accesible');
    await capture('sidebar-mobile');
    await evaluate('document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}))');
    verify(await evaluate('document.getElementById("app-menu").inert'),'Escape cierra el menú móvil');
    await login('captura-a',' legacy secret '); await navigate('/services/');
    await waitFor('document.getElementById("services-tab-notifications").getAttribute("aria-selected")==="true"');
    verify(await evaluate('Array.from(document.querySelectorAll("#services-tabs button:not([hidden])")).length===1'),'Capturista sólo ve Notificaciones');
    verify(await evaluate('document.getElementById("services-backups").hidden && document.getElementById("services-tab-backups").hidden'),'Respaldos y navegación técnica no visibles al cliente');
    await capture('restricted-services-mobile'); await navigate('/administration/');
    verify(await evaluate('!document.getElementById("administration-tabs")'),'Acceso web restringido conservado');
    await capture('restricted-access-mobile');
    await login('fase4-admin-cliente','Fase4 cliente seguro'); await navigate('/services/');
    await waitFor('document.getElementById("notifications-list").textContent.includes("Aviso Fase 4")');
    verify(await evaluate('!document.getElementById("services-tab-audit").hidden && document.getElementById("services-tab-backups").hidden'),'Navegación según permisos del administrador cliente');
    await capture('client-services-mobile');
    await evaluate(`(async () => { const token=document.querySelector('meta[name="csrf-token"]').content; await fetch('/api/auth/logout',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:'{}'}); })()`);
    await navigate('/autenticacion/'); await capture('login-mobile'); await size(1600,1000); await capture('login-wide');
    verify(await evaluate('document.documentElement.lang==="es" && !!document.querySelector("label[for=field-password]")'),'Autenticación conserva controles con etiqueta y español');
    fs.writeFileSync(path.join(directory,'ux-captures.json'),JSON.stringify(captures,null,2),{mode:0o600});
    // Hoja de contacto privada generada en Chrome, fuera de la aplicación.
    for (const suffix of ['wide','laptop','tablet','mobile']) {
        const files = captures.filter(name=>name.endsWith('-' + suffix + '.png') && routes.some(([route])=>name==='ux-' + route + '-' + suffix + '.png'));
        const html = '<html><head><title>UX gallery ' + suffix + '</title></head><body style="margin:0;background:#e2e8f0;font-family:Arial"><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:10px">' + files.map(name=>'<figure style="margin:0;background:white"><figcaption style="padding:8px">'+name+'</figcaption><img style="width:100%;height:300px;object-fit:contain;object-position:top" src="data:image/png;base64,'+fs.readFileSync(path.join(directory,name)).toString('base64')+'"></figure>').join('') + '</div></body></html>';
        await call('Page.navigate',{url:'about:blank'}); await waitFor('location.href === "about:blank" && document.readyState === "complete"');
        const frame = (await call('Page.getFrameTree')).frameTree.frame.id;
        await call('Page.setDocumentContent',{frameId:frame,html});
        await waitFor(`document.title === 'UX gallery ${suffix}' && Array.from(document.images).every(image => image.complete && image.naturalWidth > 0)`);
        await size(1500,1400); const result=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
        fs.writeFileSync(path.join(directory,'ux-contact-' + suffix + '.png'),Buffer.from(result.data,'base64'),{mode:0o600});
    }
    console.log(`UX: ${checks} comprobaciones adicionales en Chrome; ${captures.length} capturas privadas.`);
    return checks;
};
