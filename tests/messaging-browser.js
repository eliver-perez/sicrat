'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {spawn}=require('node:child_process');

// Segundo Chrome con cookies y perfil independientes: no se simulan dos usuarios en la misma sesión.
async function peer(base,directory,pause) {
    const profile=path.join(directory,'chrome-chat-peer');fs.mkdirSync(profile,{mode:0o700});
    const process=spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-extensions','--disable-sync','--metrics-recording-only','--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'],{stdio:['ignore','ignore',fs.openSync(path.join(directory,'chat-peer.log'),'a',0o600)]});
    let socket;
    try {
        const portFile=path.join(profile,'DevToolsActivePort');
        for(let n=0;!fs.existsSync(portFile) && n<100;n++)await pause(100);
        const port=Number(fs.readFileSync(portFile,'utf8').split('\n')[0]);let pages;
        for(let n=0;n<100;n++){pages=await(await fetch('http://127.0.0.1:'+port+'/json/list')).json();if(pages.some(page=>page.type==='page'))break;await pause(100);}
        socket=new WebSocket(pages.find(page=>page.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
        let id=0;const pending=new Map(),failures=[];
        socket.addEventListener('message',event=>{const result=JSON.parse(event.data);if(result.method==='Runtime.exceptionThrown')failures.push(result.params.exceptionDetails.exception?.description || result.params.exceptionDetails.text);if(result.id){const task=pending.get(result.id);pending.delete(result.id);if(result.error)task.reject(new Error(result.error.message));else task.resolve(result.result);}});
        const call=(method,params={})=>new Promise((resolve,reject)=>{const next=++id;pending.set(next,{resolve,reject});socket.send(JSON.stringify({id:next,method,params}));});
        const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);return result.result.value;};
        const waitFor=async expression=>{for(let n=0;n<120;n++){if(await evaluate('Boolean('+expression+')'))return;await pause(100);}throw new Error('Sesión B no completó: '+expression+' '+JSON.stringify(await evaluate('({url:location.href,text:document.body.innerText.slice(-2000)})')));};
        await call('Page.enable');await call('Runtime.enable');await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1080,deviceScaleFactor:1,mobile:false});
        return {call,evaluate,waitFor,navigate:async route=>{await call('Page.navigate',{url:base+route});await waitFor('document.readyState==="complete"');},failures,close:()=>{socket.close();process.kill('SIGTERM');}};
    }catch(error){socket?.close();process.kill('SIGTERM');throw error;}
}

module.exports=async function(context) {
    const {base,directory,call,evaluate,waitFor,navigate,pause}=context;
    const fixtures=JSON.parse(fs.readFileSync(path.join(directory,'messaging-fixtures.json'),'utf8'));
    let checks=0;const verify=(condition,message)=>{assert.ok(condition,message);checks++;};
    const b=await peer(base,directory,pause),a={call,evaluate,waitFor,navigate};
    const tracking=`window.__chatRequests=[];window.__chatToasts=[];document.addEventListener('DOMContentLoaded',()=>{const originalToast=window.SicratFeedback.toast;window.SicratFeedback.toast=(message,type)=>{window.__chatToasts.push({message,type});return originalToast(message,type);};});const originalFetch=window.fetch;window.fetch=async(...args)=>{const response=await originalFetch(...args);const url=String(args[0]);if(url.includes('/messages?') || url.endsWith('/send') || /conversaciones\\?/.test(url)){let body;try{body=await response.clone().json();}catch{}window.__chatRequests.push({url,status:response.status,rows:body?.data?.rows?.map(row=>row.id) || [],at:Date.now()});}return response;};`;
    async function login(client,letter){
        await client.call('Page.addScriptToEvaluateOnNewDocument',{source:tracking});
        await client.navigate('/autenticacion/');
        verify(await client.evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;return (await fetch('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({username:'chat-${letter}',password:'Mensajeria prueba segura'})})).status;})()`)===200,'Login real '+letter);
        await client.navigate('/');
        verify(await client.evaluate(`(async()=>{const token=document.querySelector('meta[name="csrf-token"]').content;return (await fetch('/api/auth/election',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({process:${JSON.stringify(fixtures.election)}})})).status;})()`)===200,'Contexto electoral '+letter);
        await client.navigate('/operations/?module=conversaciones');await client.waitFor('!!window.SicratOperations?.list && !!document.querySelector("[data-open-chat]")');
    }
    async function menu(client,kind){await client.evaluate('document.getElementById("operation-new").click()');await client.evaluate('document.getElementById('+JSON.stringify('chat-new-'+kind)+').click()');await client.waitFor('document.getElementById("operation-create-dialog").open && !!window.SicratOperations.createDTO');}
    async function open(client){await client.evaluate('window.SicratOperations.open('+JSON.stringify(fixtures.direct)+')');await client.waitFor('document.getElementById("operation-detail-dialog").open && !!document.getElementById("chat-composer") && !!window.SicratOperations.chatSession');}
    async function send(client,text){const before=await client.evaluate('window.__chatRequests.filter(row=>row.url.endsWith("/send")).length');await client.evaluate(`(()=>{const form=document.getElementById('chat-composer');form.elements.text.value=${JSON.stringify(text)};form.requestSubmit();form.requestSubmit();})()`);await client.waitFor('document.querySelector(".ux-chat-message-list").textContent.includes('+JSON.stringify(text.trim())+') && !document.querySelector("#chat-composer button[type=submit]").disabled');verify(await client.evaluate('window.__chatRequests.filter(row=>row.url.endsWith("/send")).length')===before+1,'Envío doble simultáneo realiza sólo una petición');}
    async function capture(client,name){const shot=await client.call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(directory,'messaging-'+name+'.png'),Buffer.from(shot.data,'base64'),{mode:0o600});}
    try {
        await login(a,'a');await login(b,'b');
        verify(await evaluate('!!document.getElementById("chat-new-direct") && !!document.getElementById("chat-new-group") && !!document.getElementById("chat-new-announcement")'),'A tiene tres acciones autorizadas');
        verify(await b.evaluate('!!document.getElementById("chat-new-direct") && !!document.getElementById("chat-new-group") && !document.getElementById("chat-new-announcement")'),'B sin autorización no ve Nuevo anuncio');
        await menu(a,'direct');
        verify(await evaluate('!document.querySelector("#operation-create-form [name=title], #operation-create-form [name=type], #operation-create-form [name=allow_pdf], #operation-create-form [name=announcements]")'),'Directa sólo muestra buscador/destinatario');
        await evaluate(`(()=>{const select=document.querySelector('#operation-create-form select[aria-label="Destinatario"]');select.value=${JSON.stringify(fixtures.accounts.b)};select.dispatchEvent(new Event('change'));})()`);
        await waitFor('!document.getElementById("operation-create-dialog").open && document.getElementById("operation-detail-dialog").open && !!window.SicratOperations.chatSession');
        verify(await evaluate('window.SicratOperations.selected')===fixtures.direct,'Seleccionar destinatario reutiliza y abre directa existente');
        await open(b);
        verify(await evaluate('document.querySelector(".ux-chat-title h6").textContent')==='Cuenta chat B' && await b.evaluate('document.querySelector(".ux-chat-title h6").textContent')==='Cuenta chat A','Ambos encabezados priorizan el interlocutor real');
        verify(await b.evaluate('!document.querySelector(".ux-chat-thread .ux-pagination, .ux-chat-sidebar .ux-pagination")'),'Sin paginación visible en mensajes ni conversaciones');
        verify(await b.evaluate('document.querySelector(".ux-chat-message-list").scrollHeight-document.querySelector(".ux-chat-message-list").scrollTop-document.querySelector(".ux-chat-message-list").clientHeight<80'),'Conversación existente abre en sus últimos mensajes, al pie');
        const toastsA=await evaluate('window.__chatToasts.length'),toastsB=await b.evaluate('window.__chatToasts.length');
        const originA=await evaluate('performance.timeOrigin'),originB=await b.evaluate('performance.timeOrigin');
        await b.evaluate('window.__originalThread=document.querySelector(".ux-chat-message-list");window.__originalComposer=document.getElementById("chat-composer");window.__originalComposer.elements.text.value="Borrador de B conservado"');
        await send(a,'Chrome A → B automático');await b.waitFor('document.querySelector(".ux-chat-message-list").textContent.includes("Chrome A → B automático")');checks++;
        verify(await b.evaluate('document.getElementById("chat-composer").elements.text.value==="Borrador de B conservado" && window.__originalThread===document.querySelector(".ux-chat-message-list") && window.__originalComposer===document.getElementById("chat-composer")'),'Recepción incremental conserva hilo, formulario y borrador');
        await send(b,'Chrome B → A respuesta permitida');await waitFor('document.querySelector(".ux-chat-message-list").textContent.includes("Chrome B → A respuesta permitida")');checks++;
        verify(await evaluate('performance.timeOrigin')===originA && await b.evaluate('performance.timeOrigin')===originB,'Ambas sesiones reciben sin recargar página');
        verify(await evaluate('window.__chatToasts.length')===toastsA && await b.evaluate('window.__chatToasts.length')===toastsB,'Envío normal se confirma en la burbuja sin toast de éxito');
        verify(!(await evaluate('document.body.innerText')).includes('La política actual no permite este contacto directo') && !(await b.evaluate('document.body.innerText')).includes('La política actual no permite este contacto directo'),'Ninguna respuesta vuelve a evaluar inicio de contacto');
        verify(await evaluate('document.querySelectorAll(".ux-chat-message-row").length===new Set(Array.from(document.querySelectorAll(".ux-chat-message-row")).map(node=>node.dataset.messageId)).size'),'Sin burbujas duplicadas después de enviar y recibir');
        await pause(3300);
        const requests=await b.evaluate('window.__chatRequests.filter(row=>row.url.includes("/messages?"))');
        verify(requests.filter(row=>!new URL(row.url,base).searchParams.has('after')).length===1,'Una carga inicial del historial, siguientes consultas incrementales');
        verify(requests.some(row=>new URL(row.url,base).searchParams.has('after') && row.rows.length===0),'Polling sin cambios no descarga historial');
        verify(requests.filter(row=>new URL(row.url,base).searchParams.has('after')).every(row=>row.rows.every(id=>id>Number(new URL(row.url,base).searchParams.get('after')))),'Todos los deltas contienen sólo mensajes posteriores al cursor');
        // Se dispara el mismo evento de Page Visibility API; el reloj y las peticiones son reales.
        await b.evaluate('window.__chatVisibility="hidden";Object.defineProperty(document,"visibilityState",{configurable:true,get:()=>window.__chatVisibility});document.dispatchEvent(new Event("visibilitychange"))');
        await pause(300);const before=await b.evaluate('window.__chatRequests.length');await send(a,'Mensaje durante pestaña oculta');await pause(3400);
        verify(await b.evaluate('window.__chatRequests.length')===before,'Pestaña oculta no sigue consultando mensajes');
        verify(!await b.evaluate('document.querySelector(".ux-chat-message-list").textContent.includes("Mensaje durante pestaña oculta")'),'Mensaje espera mientras está oculta');
        const resumed=Date.now();await b.evaluate('window.__chatVisibility="visible";document.dispatchEvent(new Event("visibilitychange"))');await b.waitFor('document.querySelector(".ux-chat-message-list").textContent.includes("Mensaje durante pestaña oculta")');
        verify(Date.now()-resumed<2500,'Regreso a visible consulta inmediatamente');
        // Archivo que llega después del texto: el receptor no tiene que volver a cargar mensajes.
        await send(a,'Texto antes del adjunto tardío');await b.waitFor('document.querySelector(".ux-chat-message-list").textContent.includes("Texto antes del adjunto tardío")');
        await evaluate('const input=document.getElementById("chat-composer").elements.file;input.id="chat-late-file"');
        const root=await call('DOM.getDocument'),input=await call('DOM.querySelector',{nodeId:root.root.nodeId,selector:'#chat-late-file'});
        await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:[path.join(directory,'test-image.png')]});
        verify(await evaluate(`(async()=>{const line=Array.from(document.querySelectorAll('.ux-chat-message-row')).find(node=>node.textContent.includes('Texto antes del adjunto tardío'));const data=new FormData();data.append('file',document.getElementById('chat-late-file').files[0]);data.append('entity','mensajes');data.append('record',line.dataset.messageUuid);data.append('process',${fixtures.process});return (await fetch('/api/files',{method:'POST',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:data})).status;})()` )===200,'Adjunto tardío real cargado');
        await b.waitFor('Array.from(document.querySelectorAll(".ux-chat-message-row")).some(node=>node.textContent.includes("Texto antes del adjunto tardío") && node.querySelector("img")?.naturalWidth>0)');checks++;
        await evaluate('document.getElementById("chat-composer").reset()');
        // Aumentar el hilo sin refrescar para comprobar posición de lectura y no leídos.
        await send(a,'Bloque largo para scroll '+('contenido de prueba '.repeat(100)));
        await b.waitFor('document.querySelector(".ux-chat-message-list").scrollHeight>document.querySelector(".ux-chat-message-list").clientHeight+200');
        await b.evaluate('document.querySelector(".ux-chat-message-list").scrollTop=0');await pause(200);await b.waitFor('!document.querySelector(".ux-chat-thread .ux-chat-load-status:not([hidden])")');
        await b.evaluate('(()=>{const list=document.querySelector(".ux-chat-message-list");window.__readingAnchor=Array.from(list.querySelectorAll(".ux-chat-message-row")).find(node=>node.getBoundingClientRect().bottom>list.getBoundingClientRect().top);window.__readingTop=window.__readingAnchor.getBoundingClientRect().top-list.getBoundingClientRect().top;})()');
        await send(a,'Nuevo mensaje sin mover lectura');await b.waitFor('document.querySelector(".ux-chat-message-list").textContent.includes("Nuevo mensaje sin mover lectura")');
        verify(await b.evaluate('Math.abs(window.__readingAnchor.getBoundingClientRect().top-document.querySelector(".ux-chat-message-list").getBoundingClientRect().top-window.__readingTop)<3'),'Nuevo mensaje conserva posición visual cuando se lee historial');
        await b.waitFor('document.querySelector("#operation-list .ux-chat-unread")');checks++;
        await b.evaluate('const list=document.querySelector(".ux-chat-message-list");list.scrollTop=list.scrollHeight');await b.waitFor('!document.querySelector("#operation-list .ux-chat-unread")');checks++;
        verify((await b.evaluate('document.getElementById("operation-list").textContent')).includes('Nuevo mensaje sin mover lectura'),'Lista lateral actualiza resumen y no leídos');
        await capture(b,'two-sessions-desktop');
        await b.call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});await pause(200);
        verify(await b.evaluate('document.documentElement.scrollWidth<=innerWidth+2 && document.querySelector(".ux-chat-sidebar").hidden && !document.querySelector(".ux-chat-room").hidden'),'Chat móvil sin desbordamiento y con panel correcto');await capture(b,'two-sessions-mobile');
        await require('./messaging-media-browser.js')({a,b,fixtures,directory,pause,verify,capture});
        await evaluate('document.getElementById("operation-detail-dialog").close()');await menu(a,'group');
        verify(await evaluate('!!document.querySelector("#operation-create-form [name=title]") && !document.querySelector("#operation-create-form select[aria-label=Destinatario]") && !document.getElementById("operation-create-fields").textContent.includes("Sólo las cuentas administradoras")'),'Nuevo grupo sólo muestra opciones propias');
        await evaluate(`(()=>{const form=document.getElementById('operation-create-form');form.elements.title.value='Grupo desde Chrome';const select=form.querySelector('select[aria-label="Cuentas integrantes"]');select.value=${JSON.stringify(fixtures.accounts.b)};select.dispatchEvent(new Event('change'));form.requestSubmit();})()`);await waitFor('!document.getElementById("operation-create-dialog").open && document.getElementById("operation-detail-dialog").open');checks++;
        await evaluate('document.getElementById("operation-detail-dialog").close()');await menu(a,'announcement');
        verify(await evaluate('document.getElementById("operation-create-fields").textContent.includes("Sólo las cuentas administradoras") && !document.querySelector("#operation-create-form select[aria-label=Destinatario]")'),'Nuevo anuncio presenta sólo sus opciones');
        await evaluate(`(()=>{const form=document.getElementById('operation-create-form');form.elements.title.value='Anuncio desde Chrome';const select=form.querySelector('select[aria-label="Cuentas integrantes"]');select.value=${JSON.stringify(fixtures.accounts.b)};select.dispatchEvent(new Event('change'));form.requestSubmit();})()`);await waitFor('!document.getElementById("operation-create-dialog").open && document.getElementById("operation-detail-dialog").open');checks++;
        await evaluate(`Promise.all([window.SicratOperations.open(${JSON.stringify(fixtures.direct)}),window.SicratOperations.open(${JSON.stringify(fixtures.announcement)})])`);
        verify(await evaluate('window.SicratOperations.selected')===fixtures.announcement && await evaluate('window.SicratOperations.chatSession.uuid')===fixtures.announcement,'Cambios rápidos de conversación conservan el chat y cursor del último destino');
        await evaluate('document.getElementById("operation-detail-dialog").close()');await pause(300);const closed=await evaluate('window.__chatRequests.length');await pause(3200);
        verify(await evaluate('window.__chatRequests.length')===closed,'Cerrar conversación detiene consultas de mensajes');
        assert.deepEqual(b.failures,[]);checks++;console.log('Mensajería Chrome: '+checks+' comprobaciones con dos perfiles y sesiones independientes.');return checks;
    }catch(error){await capture(a,'failure-a');await capture(b,'failure-b');throw error;}finally{b.close();}
};
