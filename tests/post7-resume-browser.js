'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
module.exports=async function({base,directory,call,evaluate,waitFor,navigate,pause,api,login,fixture,conversation}){
 let checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;};
 const capture=async(name)=>{const data=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(directory,'resume-'+name+'.png'),Buffer.from(data.data,'base64'),{mode:0o600});};
 // La referencia de Tailwick usa una pestaña aparte para mantener intacta la sesión bajo prueba.
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 const original=fs.readFileSync(path.join(__dirname,'../tailwick/apps-chat.html'),'utf8');
 const reference=original.replaceAll('/assets/',base+'/template/assets/');
 const {targetId}=await call('Target.createTarget',{url:'about:blank',background:true});
 try {
  const {sessionId}=await call('Target.attachToTarget',{targetId,flatten:true});
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},sessionId);
  const {frameTree}=await call('Page.getFrameTree',{},sessionId);
  await call('Page.setDocumentContent',{frameId:frameTree.frame.id,html:reference},sessionId);
  await pause(900);
  const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);
  fs.writeFileSync(path.join(directory,'resume-tailwick-reference.png'),Buffer.from(shot.data,'base64'),{mode:0o600});
 } finally {
  await call('Target.closeTarget',{targetId});
 }
 await login('revision-admin','Revision local segura');await api('/api/operations/conversaciones/'+conversation.uuid+'/send','POST',{text:'Respuesta recibida desde otra cuenta SICRAT'});
 await login('administrador',' nueva contraseña ');await navigate('/operations/?module=conversaciones&record='+conversation.uuid);await waitFor('document.getElementById("operation-detail-dialog").open && document.querySelectorAll(".ux-chat-message").length>=2 && document.querySelector("#chat-composer textarea")');
 check(await evaluate('document.querySelector(".ux-chat-sidebar-head h6").textContent==="Conversaciones" && document.querySelector(".ux-chat-search input").placeholder.includes("conversación")'),'Cabecera Conversaciones y buscador integrados en la columna lateral');
 check(await evaluate('document.getElementById("operation-settings").closest(".ux-chat-menu-content") && document.getElementById("operation-new").querySelector("svg")'),'Nueva conversación compacta y configuración contextual');
 check(await evaluate('document.querySelector("[data-open-chat].active")?.dataset.openChat===window.SicratOperations.selected'),'Conversación seleccionada resaltada');
 check(await evaluate('document.querySelector(".ux-chat-outgoing") && document.querySelector(".ux-chat-message:not(.ux-chat-outgoing)")'),'Mensajes enviados y recibidos diferenciados con datos reales');
 check(await evaluate('document.querySelector(".ux-chat-preview").textContent.includes("Respuesta recibida")'),'Último mensaje actualizado en la lista');
 check((await api('/api/operations/conversaciones')).rows.find(row=>row.uuid===conversation.uuid).no_leidos===0,'Lectura del chat actualiza conteo real');
 check(await evaluate('document.querySelector(".ux-chat-options").closest(".ux-chat-title") && document.getElementById("chat-composer").parentElement.id==="operation-panel-summary"'),'Opciones en cabecera y escritura dentro del panel de conversación');
 check(await evaluate('(()=>{const layout=document.querySelector(".ux-chat-layout").getBoundingClientRect(),left=document.querySelector(".ux-chat-sidebar").getBoundingClientRect(),composer=document.getElementById("chat-composer").getBoundingClientRect();return left.width>250 && left.width<350 && composer.bottom<=layout.bottom+1 && composer.bottom>=layout.bottom-8;})()'),'Dos columnas y compositor al pie siguiendo el layout de Tailwick');
 check(await evaluate('!Array.from(document.querySelectorAll("[src],[href]")).some(node=>(node.getAttribute("src") || node.getAttribute("href") || "").includes("/tailwick/"))'),'Sin referencias runtime a carpeta temporal');await capture('chat-desktop');
 await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});await pause(200);check(await evaluate('document.querySelector(".ux-chat-sidebar").hidden && !document.querySelector(".ux-chat-room").hidden'),'Conversación ocupa pantalla móvil');await capture('chat-mobile');
 await evaluate('document.querySelector(".ux-chat-back").click()');check(await evaluate('!document.querySelector(".ux-chat-sidebar").hidden && document.querySelector(".ux-chat-room").hidden'),'Volver recupera lista compacta en móvil');await capture('chat-mobile-list');
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await navigate('/polling-booth/');await waitFor('window.SicratBooths?.initialized');await evaluate('document.getElementById("booths-new").click()');await waitFor('document.querySelector("#booths-form select[name=type]")');check(await evaluate('Array.from(document.querySelector("#booths-form select[name=type]").options).map(option=>option.value).includes("BASICA") && !document.querySelector("#booths-form input[name=type]")'),'Alta de casilla selecciona un tipo activo sin texto libre');await evaluate('document.getElementById("booths-dialog").close()');await evaluate(`Array.from(document.querySelectorAll('#booths-list tbody tr')).find(row=>row.textContent.includes('Básica editada')).querySelector('button').click()`);await waitFor('document.getElementById("booths-dialog").open && document.querySelector("#booths-form select[name=type]")');check(await evaluate('document.querySelector("#booths-form select[name=type]").value==="BASICA"'),'Edición utiliza el mismo catálogo y conserva tipo seleccionado');await capture('booth-select');
 const revoked=JSON.parse(fs.readFileSync(path.join(directory,'post7-resume-fixtures.json'),'utf8'));
 await login('revision-sin-importar','Revision permisos segura');await navigate('/persons/');await pause(500);check(await evaluate('!document.getElementById("person-import-open") && !document.getElementById("person-import")'),'Usuario revocado no tiene opción ni modal de importación');
 await evaluate(`window.__importCalls=0;$.ajaxPrefilter(options=>{if(options.url.includes('/api/person-imports'))window.__importCalls++;});personImportId=${JSON.stringify(revoked.import)};InitializePersonImport();importPreview();applyPersonImport();automaticPersonImport();`);await pause(200);check(await evaluate('window.__importCalls===0'),'Invocar funciones de importación no analiza ni procesa cuando el permiso está ausente');await capture('person-import-revoked');
 await login('revision-admin','Revision local segura');await navigate('/persons/');await waitFor('document.getElementById("person-import-open")');await evaluate('document.getElementById("person-import-open").click()');
 const file=path.join(directory,'chrome-automaticas.csv');let csv='name,email,curp\n';for(let n=0;n<121;n++)csv+=`Alta automática Chrome ${n},chromeauto${n}@example.com,\n`;csv+='Coincidencia Chrome,,CHROME-DUP\nOtra coincidencia Chrome,,CHROME-DUP\nCorreo inválido Chrome,no-correo,\n';fs.writeFileSync(file,csv,{mode:0o600});
 const doc=await call('DOM.getDocument'),input=await call('DOM.querySelector',{nodeId:doc.root.nodeId,selector:'#person-import-upload input[type=file]'});await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:[file]});await evaluate('document.getElementById("person-import-upload").requestSubmit()');await waitFor('document.querySelectorAll("#person-import-columns select").length>1 && !personImportBusy');
 await evaluate('(()=>{const form=document.getElementById("person-import-mapping");form.elements.name.value="0";form.elements.email.value="1";form.elements.curp.value="2";document.getElementById("import-mode").value="automatic";document.getElementById("import-mode").dispatchEvent(new Event("change"));window.__mappingWrites=0;$.ajaxPrefilter(options=>{if(options.url.includes("/mapping"))window.__mappingWrites++;});form.requestSubmit();form.requestSubmit();})()');check(await evaluate('personImportBusy && document.getElementById("person-import-automatic").disabled'),'Proceso automático muestra estado y bloquea doble envío');
 await waitFor('!personImportBusy && document.getElementById("person-import-planned").textContent.includes("121 personas creadas")');check(await evaluate('window.__mappingWrites===1'),'Doble submit sólo guarda una configuración');
 check(await evaluate('document.getElementById("person-import-planned").textContent.includes("3 filas separadas") && !document.getElementById("person-import-review").classList.contains("hidden")'),'Altas automáticas atraviesa varios lotes sin intervención y ofrece revisar excepciones');await capture('person-import-automatic');
 await evaluate('document.getElementById("person-import-review").click()');await waitFor('!personImportBusy && document.querySelectorAll("#person-import-preview>div").length===3');check(await evaluate('!document.getElementById("person-import-preview").textContent.includes("Alta automática Chrome")'),'Revisión manual no repite las altas ya terminadas');await capture('person-import-manual-exceptions');
 await login('administrador',' nueva contraseña ');
 for(const permission of ['personas.crear','personas.editar','personas.participar'])await api('/api/users/'+fixture.user+'/permissions','PUT',{permission,election:fixture.election,scope:'organizacion',value:0});
 await login('revision-admin','Revision local segura');
 await navigate('/persons/');
 check(await evaluate('!document.getElementById("btn-persons-new")'),'Alta rápida ausente al revocar Crear personas');
 check(await evaluate('!document.querySelector("#import-mode option[value=automatic]")'),'Altas automáticas se oculta cuando falta permiso de crear');
 await evaluate(`openPerson(${JSON.stringify(fixture.person)})`);
 await waitFor('document.getElementById("person-detail").open');
 check(await evaluate('!document.getElementById("person-edit") && document.getElementById("person-edit-form").hidden && !document.querySelector("#person-consult input,#person-consult select,#person-consult textarea")'),'Ficha de sólo consulta al revocar edición');
 check(await evaluate('document.getElementById("person-consult").getBoundingClientRect().width>=document.getElementById("person-detail").clientWidth*.85'),'Ficha de sólo consulta aprovecha la anchura del diálogo');
 check(await evaluate(`document.querySelector('#person-structure .person-structure-card') &&
     !document.querySelector('#person-participation-options, #person-participations button, #person-structure .person-structure-card > .ux-actions button')`),'Participaciones sin controles al revocar permiso');
 await capture('person-readonly');
 await login('administrador',' nueva contraseña ');for(const permission of ['personas.crear','personas.editar','personas.participar'])await api('/api/users/'+fixture.user+'/permissions','PUT',{permission,election:fixture.election,scope:'organizacion',value:1});
 console.log('Reanudación Chrome: '+checks+' comprobaciones correctas.');return checks;
};
