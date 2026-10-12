'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
module.exports=async function({directory,call,evaluate,waitFor,navigate,pause}) {
    let checks=0;const fixtures=JSON.parse(fs.readFileSync(path.join(directory,'phase5-fixtures.json'),'utf8'));
    const verify=(condition,message)=>{assert.ok(condition,message);checks++;};
    await navigate('/');
    const login=await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;return (await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({username:'administrador',password:' nueva contraseña '})})).status;})()`);
    verify(login===200,'Cuenta de revisión Fase 5');await navigate('/');
    const selected=await evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;return (await fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({process:${JSON.stringify(fixtures.election)}})})).status;})()`);
    verify(selected===200,'Selección electoral Fase 5');
    const entities=['interacciones','encuestas','tareas','metas','eventos','peticiones','incidencias','transporte','conversaciones'];
    async function capture(name){const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(directory,'phase5-'+name+'.png'),Buffer.from(shot.data,'base64'),{mode:0o600});}
    async function bounds(label,dialog=null){
        verify(await evaluate(`document.documentElement.scrollWidth<=innerWidth+2`),label+': página sin desbordamiento horizontal');
        verify(await evaluate('parseFloat(getComputedStyle(document.querySelector("main")).paddingTop)>=12'),label+': separación uniforme');
        verify(await evaluate('document.getElementById("electoral-context-select").value==='+JSON.stringify(fixtures.election)),label+': selector preserva elección');
        if(dialog)verify(await evaluate(`(()=>{const node=document.getElementById(${JSON.stringify(dialog)});const r=node.getBoundingClientRect();return node.open && !!node.getAttribute('aria-labelledby') && r.left>=-1 && r.right<=innerWidth+1 && r.top>=-1 && r.bottom<=innerHeight+1;})()`),label+': diálogo visible y accesible dentro de pantalla');
    }
    for(const [width,height,size] of [[1600,1000,'desktop'],[820,1024,'tablet'],[390,844,'mobile']]){
        await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        for(const entity of entities){
            await navigate('/operations/?module='+entity);
            await waitFor('window.SicratOperations?.entity==='+JSON.stringify(entity)+' && !!window.SicratOperations.list && document.querySelectorAll("#operation-list table tbody tr").length>0');
            verify(await evaluate('!document.getElementById("operations-message").textContent'),entity+'/'+size+': carga sin errores');
            try { await waitFor('document.getElementById("electoral-context-select").value==='+JSON.stringify(fixtures.election)); }
            catch(error) { await capture('selection-failure'); console.error('Contexto de navegador:',await evaluate(`(async()=>({me:window.SicratOperations.me.process_id,options:Array.from(document.getElementById('electoral-context-select').options).map(option=>({value:option.value,label:option.textContent,selected:option.selected})),choices:(await(await fetch('/api/auth/elections')).json()).data}))()`));throw error; }
            await bounds(entity+'/'+size);
            verify(await evaluate('document.querySelectorAll("aside a[href*=operations]").length===7'),entity+'/'+size+': navegación operacional compacta');
            await evaluate('document.querySelector("#operation-list tbody button").click()');
            await waitFor('document.getElementById("operation-detail-dialog").open');
            await bounds(entity+'/'+size+' detalle','operation-detail-dialog');
            verify(await evaluate('!document.getElementById("operation-panel-summary").hidden && document.getElementById("operation-panel-summary").textContent.length>0'),entity+': ficha descriptiva');
            await evaluate('document.getElementById("operation-tab-actions").click()');await waitFor('!document.getElementById("operation-panel-actions").hidden');
            verify(await evaluate('!document.getElementById("operation-detail-dialog").querySelector("[data-operation-message]")'),entity+': acciones sin errores');
            if(entity==='transporte')verify(await evaluate('document.querySelector("#operation-panel-actions select[name=need]").value==="no_definido"'),'Logística nueva muestra No definido');
            if(['encuestas','transporte','conversaciones'].includes(entity))await capture(entity+'-'+size);
            await evaluate('document.getElementById("operation-detail-dialog").close()');
            if(entity!=='transporte'){
                await evaluate('document.getElementById("operation-new").click()');await waitFor('!!window.SicratOperations.createDTO');
                await bounds(entity+'/'+size+' alta','operation-create-dialog');
                verify(await evaluate('!document.getElementById("operation-create-dialog").querySelector("[data-operation-message]")'),entity+': formulario cargado');
                verify(await evaluate('document.querySelector("#operation-create-fields input, #operation-create-fields select")!==null'),entity+': controles comprensibles');
                if(size==='mobile')await capture(entity+'-form-mobile');
                await evaluate('document.getElementById("operation-create-dialog").close()');
            }
        }
    }
    await call('Emulation.setDeviceMetricsOverride',{width:1600,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate('/operations/?module=interacciones');await waitFor('!!window.SicratOperations.list && window.SicratOperations.entity==="interacciones"');
    await evaluate('document.getElementById("operation-new").click()');await waitFor('!!window.SicratOperations.createDTO');
    await evaluate(`(()=>{const form=document.getElementById('operation-create-form');const picker=form.querySelector('select[aria-label="Persona"]');picker.value=${JSON.stringify(fixtures.people[0])};picker.dispatchEvent(new Event('change'));form.elements.result.value='Interacción desde Chrome';form.requestSubmit();})()`);
    await waitFor('document.getElementById("operation-detail-dialog").open && document.getElementById("operation-panel-summary").textContent.includes("Interacción desde Chrome")');checks++;
    await evaluate('document.getElementById("operation-tab-evidence").click()');await waitFor('!!document.querySelector("#operation-panel-evidence input[type=file]")');
    const documentNode=await call('DOM.getDocument');const input=await call('DOM.querySelector',{nodeId:documentNode.root.nodeId,selector:'#operation-panel-evidence input[type=file]'});
    await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:[path.join(directory,'test-image.png')]});
    await evaluate('document.querySelector("#operation-panel-evidence form").requestSubmit()');await waitFor('!!document.querySelector("#operation-panel-evidence img")?.naturalWidth');checks++;
    await capture('private-evidence-desktop');
    await navigate('/operations/?module=encuestas');await waitFor('!!window.SicratOperations.list && window.SicratOperations.entity==="encuestas"');
    await evaluate('document.getElementById("operation-new").click()');await waitFor('!!window.SicratOperations.createDTO');
    await evaluate(`(()=>{const form=document.getElementById('operation-create-form');form.elements.title.value='Borrador desde Chrome';form.elements.question_title.value='Detalle de actividad';form.requestSubmit();})()`);
    await waitFor('document.getElementById("operation-detail-dialog").open && document.getElementById("operation-panel-summary").textContent.includes("Borrador desde Chrome")');checks++;
    await evaluate('document.getElementById("operation-tab-actions").click();Array.from(document.querySelectorAll("#operation-panel-actions button")).find(button=>button.textContent==="Editar borrador").click()');
    await waitFor('document.getElementById("operation-create-dialog").open && !!window.SicratOperations.createDTO');
    verify(await evaluate('document.getElementById("operation-create-form").elements.question_title.value==="Detalle de actividad"'),'Editor conserva preguntas del borrador');
    await evaluate('document.getElementById("operation-create-dialog").close();document.getElementById("operation-detail-dialog").close()');
    await evaluate(`window.SicratOperations.open(${JSON.stringify(fixtures.survey)})`);await waitFor('document.getElementById("operation-detail-dialog").open');
    await evaluate('document.getElementById("operation-tab-actions").click();Array.from(document.querySelectorAll("#operation-panel-actions button")).find(button=>button.textContent==="Aplicar encuesta").click()');await waitFor('document.getElementById("operation-settings-dialog").open');
    await evaluate(`(()=>{const form=document.querySelector('#operation-settings-content form');const person=form.querySelector('select[aria-label="Persona encuestada"]');person.value=${JSON.stringify(fixtures.people[1])};person.dispatchEvent(new Event('change'));form.elements.texto.value='Captura desde Chrome';form.elements.opcion.value='capacitacion';form.elements.opcion.dispatchEvent(new Event('change',{bubbles:true}));form.elements.si.value='0';form.elements.numero.value='2';form.elements.escala.value='3';form.elements.fecha.value=window.SicratOperationForms.day();form.elements.multiple.options[1].selected=true;})()`);
    verify(await evaluate('document.querySelector("#operation-settings-content form").elements.condicional.disabled'),'Pregunta condicional se oculta y no exige respuesta');await capture('survey-application-desktop');
    await evaluate('document.querySelector("#operation-settings-content form").requestSubmit()');await waitFor('!document.getElementById("operation-settings-dialog").open');checks++;
    await navigate('/operations/?module=conversaciones');await waitFor('!!window.SicratOperations.list && window.SicratOperations.entity==="conversaciones"');
    await evaluate(`window.SicratOperations.open(${JSON.stringify(fixtures.conversation)})`);await waitFor('document.getElementById("operation-detail-dialog").open');
    verify(await evaluate('!document.querySelector("#operation-panel-summary script")'),'Contenido de mensaje usa escape seguro');
    verify(await evaluate('document.getElementById("operation-panel-summary").textContent.includes("<script>literal</script>")'),'HTML privado se muestra como texto literal');
    await evaluate('document.getElementById("operation-tab-actions").click()');
    verify(await evaluate('!document.getElementById("operation-panel-actions").textContent.includes("Administrar integrantes")'),'Conversación directa conserva integrantes fijos');
    await navigate('/persons/');await waitFor('document.getElementById("person-detail")!==null');
    await evaluate(`openPerson(${JSON.stringify(fixtures.people[0])})`);await waitFor('document.getElementById("person-detail").open && document.getElementById("person-operational-activity").textContent.includes("Actividad cronológica") && document.querySelectorAll("#person-operational-activity table tr").length>1');checks++;
    verify(await evaluate('document.getElementById("person-operational-activity").textContent.includes("Logística de la participación")'),'Ficha de Personas incorpora logística sin sustituir identidad');await capture('person-timeline-desktop');
    console.log('Fase 5 Chrome: '+checks+' comprobaciones correctas.');return checks;
};
