const assert=require('node:assert/strict');
module.exports=async function({fixture,directory,call,evaluate,waitFor,navigate,api,click,shot}) {
    let checks=0;const check=(value,message)=>{assert.ok(value,message);checks++;};
    const close=()=>evaluate('document.querySelectorAll("dialog[open]").forEach(d=>d.close());Swal.close()');
    async function viewport(width,height,mobile=false){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile});await evaluate('new Promise(resolve=>setTimeout(resolve,400))');}
    const cards=()=>evaluate('Array.from(document.querySelectorAll("#structure-summary .structure-stat-value"),e=>e.textContent)');
    await viewport(1440,1080);
    await navigate('/structure/');await waitFor('document.querySelectorAll("#structure-summary .structure-stat").length===4');
    assert.deepEqual(await cards(),['Coordinador UX','Coordinador','2','3']);checks++;
    check(await evaluate('Array.from(document.querySelectorAll("#structure-summary .structure-stat"),e=>e.querySelector("svg")&&getComputedStyle(e.querySelector(".structure-stat-icon")).backgroundColor!=="rgba(0, 0, 0, 0)").every(Boolean)'),'Cuatro tarjetas con icono y color');
    check(await evaluate('document.querySelectorAll("#structure-summary .structure-stat")[0].getBoundingClientRect().top===document.querySelectorAll("#structure-summary .structure-stat")[3].getBoundingClientRect().top'),'Cuatro columnas en escritorio');
    check(await evaluate('Array.from(document.querySelectorAll("#structure-members tr"),r=>r.cells.length===7&&!r.cells[0].querySelector("button")&&!r.cells[1].querySelector("button")&&r.cells[6].querySelector("button svg[data-lucide=eye]")&&r.cells[6].querySelector("button svg[data-lucide=users]")).every(Boolean)'),'Datos separados de acciones accesibles');
    checks+=await require('./structure-navigation-browser.js')({fixture,call,evaluate,waitFor,click,shot});
    await shot('structure-cards-desktop');
    await click(`#structure-members tr[data-member="${fixture.members.child}"] button[title="Ver equipo"]`);await waitFor('document.querySelectorAll("#structure-summary .structure-stat-value")[0].textContent==="Responsable UX"');
    assert.deepEqual(await cards(),['Responsable UX','Coordinador','1','1']);checks++;
    await click('#structure-members button[title="Ver ficha"]');await waitFor('document.getElementById("structure-member-dialog").open');
    check(await evaluate('document.getElementById("structure-member-name").textContent.includes("Descendiente UX")'),'Ficha desde icono');await close();
    await click('#structure-roots');await waitFor('document.getElementById("structure-navigation").textContent.startsWith("Inicio de la estructura")');
    check(await evaluate('document.getElementById("structure-summary").hidden&&!document.getElementById("structure-navigation").textContent.includes("visible")'),'Inicio sin resumen obsoleto');
    await click('#structure-all');await waitFor('document.querySelectorAll("#structure-members tr[data-member]").length===5');
    check(await evaluate('document.getElementById("structure-navigation").textContent.startsWith("Todos los integrantes")'),'Todos los integrantes');
    await click('#structure-members tr[data-member] td:nth-child(2)');await waitFor('document.getElementById("structure-member-dialog").open');checks++;await close();
    await click('#structure-mine');await waitFor('document.querySelectorAll("#structure-summary .structure-stat-value")[0]?.textContent==="Coordinador UX"');
    await viewport(390,844,true);
    check(await evaluate('(()=>{const cards=Array.from(document.querySelectorAll("#structure-summary .structure-stat"),n=>n.getBoundingClientRect());return cards.every((r,i)=>r.left>=0&&r.right<=innerWidth&&(!i||r.top>=cards[i-1].bottom));})()'),'Tarjetas apiladas en móvil');
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Sin desbordamiento horizontal móvil');await evaluate('document.getElementById("structure-summary").scrollIntoView({block:"start"})');await shot('structure-cards-mobile');
    await click('#structure-add');await waitFor('document.getElementById("structure-add-dialog").open');
    // Registrar peticiones reales y demorar la búsqueda para comprobar doble Enter/clic.
    await evaluate(`window.__interfaceRequests=[];window.__searchDelay=300;window.__interfaceFetch=window.fetch;window.fetch=async function(url,options){window.__interfaceRequests.push({url:String(url),method:options?.method||'GET'});if(String(url).includes('/api/persons?')&&window.__searchDelay)await new Promise(r=>setTimeout(r,window.__searchDelay));return window.__interfaceFetch.apply(this,arguments);};document.getElementById('structure-person-search').value='Persona catálogo UI';document.getElementById('structure-person-search').focus();`);
    await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
    check(await evaluate('document.getElementById("structure-person-find").disabled&&document.getElementById("structure-person-find").textContent==="Buscando…"'),'Búsqueda bloquea mientras procesa');
    await evaluate('document.getElementById("structure-person-search").dispatchEvent(new KeyboardEvent("keydown",{key:"Enter",bubbles:true,cancelable:true}));document.getElementById("structure-person-find").click()');
    await waitFor('document.querySelectorAll("#structure-person-results tbody tr").length===25&&!structureState.personSearching');
    check(await evaluate('window.__interfaceRequests.length===1&&window.__interfaceRequests[0].method==="GET"'),'Enter y clic repetidos hacen una sola búsqueda, cero submit');
    check(await evaluate('Array.from(document.querySelectorAll("#structure-person-results th"),e=>e.textContent).join("|")==="Clave|Nombre|Sección|Teléfono"'),'Tabla compacta con datos disponibles');
    check(await evaluate('document.getElementById("structure-person-results").getBoundingClientRect().height<=innerHeight*.45+1&&getComputedStyle(document.querySelector("#structure-person-results th:nth-child(4)")).display==="none"'),'Resultados compactos en móvil priorizan clave, nombre y sección');
    await shot('structure-person-search-mobile');
    await click('#structure-person-next');await waitFor('document.querySelectorAll("#structure-person-results tbody tr").length===2&&!structureState.personSearching');
    check(await evaluate('document.getElementById("structure-person-next").disabled&&!document.getElementById("structure-person-previous").disabled&&window.__interfaceRequests.at(-1).url.includes("offset=25")'),'Paginación incremental y límites');
    await click('#structure-person-previous');await waitFor('document.querySelectorAll("#structure-person-results tbody tr").length===25&&!structureState.personSearching');
    check(await evaluate('document.getElementById("structure-person-previous").disabled'),'Anterior vuelve al inicio');
    const selection=await evaluate('document.querySelector("#structure-person-results tbody tr td:nth-child(2)").textContent');
    await click('#structure-person-results tbody tr');
    check(await evaluate(`document.getElementById('structure-person-search-box').hidden&&!document.getElementById('structure-person-selected').hidden&&document.getElementById('structure-person-selected').textContent.includes(${JSON.stringify(selection)})&&document.getElementById('structure-add-form').elements.responsible.value===${JSON.stringify(fixture.members.root)}`),'Fila selecciona persona y conserva responsable');
    check(await evaluate('document.getElementById("structure-add-levels").querySelector("select")&&document.getElementById("structure-participations").children.length>0'),'Niveles y participaciones conservados');
    await click('#structure-person-change');await evaluate('window.__searchDelay=0;document.getElementById("structure-person-search").value="Persona catálogo UI 27"');await click('#structure-person-find');
    await waitFor('document.querySelectorAll("#structure-person-results tbody tr").length===1&&!structureState.personSearching');
    check(await evaluate('document.querySelector("#structure-person-results tbody").textContent.includes("Persona catálogo UI 27")'),'Botón ejecuta la misma búsqueda');
    // Fallo real de búsqueda: recuperar controles y permitir reintentar.
    await evaluate('window.fetch=async(url,options)=>String(url).includes("/api/persons?")?new Response(JSON.stringify({message:"Error de búsqueda de prueba"}),{status:500,headers:{"Content-Type":"application/json"}}):window.__interfaceFetch(url,options);document.getElementById("structure-person-find").click()');
    await waitFor('!structureState.personSearching&&!document.getElementById("structure-person-find").disabled');
    check(await evaluate('Boolean(document.querySelector(".swal2-toast.swal2-icon-error"))'),'Error de búsqueda conserva feedback real');await close();
    await navigate('/autenticacion/');check((await api('auth/login','POST',{username:'administrador',password:' nueva contraseña '})).status===200,'Login Super Administrador');
    await navigate('/');check((await api('auth/election','POST',{process:fixture.election})).status===200,'Elección de revisión administrativa');
    await viewport(1440,1080);
    await navigate(`/structure/?structure=${fixture.structure}`);await waitFor('document.querySelector("#structure-members tr[data-member]")');
    for(const [id,title,icon] of [['structure-create','Nueva estructura','plus'],['structure-clone','Crear copia independiente','copy'],['structure-associate','Asociar elección','link'],['structure-add','Agregar integrante','plus'],['structure-requests','Solicitudes de transferencia','arrow-right-left'],['structure-history','Historial de la estructura','history']])check(await evaluate(`document.getElementById('${id}').title===${JSON.stringify(title)}&&document.getElementById('${id}').querySelector('svg[data-lucide="${icon}"]')`),'Icono y tooltip '+id);
    check(await evaluate('(()=>{const ids=["structure-select","structure-create","structure-clone","structure-associate"],r=ids.map(id=>document.getElementById(id).getBoundingClientRect());return r.every(x=>Math.abs(x.top-r[0].top)<10);})()'),'Cabecera horizontal');
    check(await evaluate('document.getElementById("structure-navigation").textContent.includes("Tu usuario no se encuentra registrado")&&!document.getElementById("structure-navigation").textContent.includes("alcance")'),'Mensaje neutral sin integrante propio');
    await shot('structure-admin-desktop');
    for(const id of ['structure-create','structure-clone','structure-associate']){await click('#'+id);await waitFor('document.getElementById("structure-container-dialog").open');check(await evaluate('document.getElementById("structure-election-options").children.length>0'),'Acción conserva elecciones '+id);await close();}
    await viewport(390,844,true);
    check(await evaluate('["structure-select","structure-create","structure-clone","structure-associate"].every(id=>{const r=document.getElementById(id).getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1})'),'Cabecera con wrap en móvil');await shot('structure-admin-mobile');
    // Instrumentación previa a la navegación, sin suprimir respuestas/errores.
    const instrumentation=await call('Page.addScriptToEvaluateOnNewDocument',{source:`window.__configurationRequests=[];const originalFetch=window.fetch;window.fetch=function(url,options){window.__configurationRequests.push(String(url));return originalFetch.apply(this,arguments);};`});
    await navigate('/administration/');await waitFor('document.querySelector("#permission-form [name=scope]").options.length===6');
    check(await evaluate('!window.__configurationRequests.some(u=>u.includes("/territories"))&&!document.body.textContent.includes("La cuenta no está disponible")'),'Sin consulta automática a una cuenta inaplicable');
    await click('#administration-tab-permissions');await click('#permission-tab-role');
    const hints={propio:'persona vinculada',directos:'directamente',descendientes:'descendientes',territorio:'sección electoral',eleccion:'elección seleccionada',organizacion:'organización del usuario'};
    for(const [scope,phrase] of Object.entries(hints)){
        await evaluate(`(()=>{const scope=document.querySelector('#permission-form [name=scope]');scope.value='${scope}';scope.dispatchEvent(new Event('change'));})()`);
        check(await evaluate(`document.getElementById('permission-scope-help').textContent.includes(${JSON.stringify(phrase)})`),'Descripción del alcance '+scope);
    }
    await shot('administration-scope-mobile');
    await click('#permission-tab-territories');await waitFor('document.querySelector("#territory-options input")');
    check(await evaluate('document.querySelector("#territory-form [name=user]").value===""&&!window.__configurationRequests.some(u=>u.includes("/territories"))'),'Territorios espera selección explícita');
    // Una selección inválida sí debe informar el error del servidor.
    await evaluate('(()=>{const select=document.querySelector("#territory-form [name=user]");select.value=Array.from(select.options).find(o=>o.textContent.includes("administrador ·")).value;select.dispatchEvent(new Event("change"));})()');
    await waitFor('document.querySelector(".swal2-title")?.textContent==="La cuenta no está disponible."');checks++;await evaluate('Swal.close()');
    await evaluate(`(()=>{const select=document.querySelector('#territory-form [name=user]');select.value=${JSON.stringify(fixture.user)};select.dispatchEvent(new Event('change'));})()`);
    await waitFor('!document.querySelector("#territory-form button:not([type])").disabled');checks++;
    await viewport(1440,1080);
    await click('#administration-tab-levels');await evaluate('Array.from(document.querySelectorAll("#level-list button")).find(b=>b.textContent.includes("Coordinador")).click();document.getElementById("level-form").elements.same_level.checked=false;document.getElementById("level-form").requestSubmit()');
    await waitFor('document.querySelector(".swal2-title")?.textContent.includes("responsables del mismo nivel")');
    check((await api('structure-levels')).data.find(l=>l.id===fixture.level).same_level===1,'Guardado inválido revierte y muestra causa');await shot('administration-level-invalid');
    // Corregir desde el formulario de ficha existente, empezando por los descendientes.
    for(const key of ['grand','child']){
        await navigate(`/structure/?structure=${fixture.structure}&member=${fixture.members[key]}`);await waitFor('document.getElementById("structure-member-dialog").open');
        await evaluate(`document.getElementById('structure-administration').open=true;document.querySelector('#structure-member-levels select').value=${JSON.stringify(fixture.lowerLevel)};document.getElementById('structure-level-form').requestSubmit()`);
        await waitFor('!document.getElementById("structure-member-dialog").open');
        check((await api(`structures/${fixture.structure}/members/${fixture.members[key]}`)).data.levels[fixture.election]===fixture.lowerLevel,'Relación corregida desde ficha '+key);
    }
    // También el integrante que incorporó la regresión previa comparte el nivel inicial.
    const members=(await api(`structures/${fixture.structure}?parent=`)).data.members;
    const candidate=members.find(m=>m.person===fixture.persons.candidate);
    check((await api(`structures/${fixture.structure}/members/${candidate.id}/level`,'PUT',{levels:{[fixture.election]:fixture.lowerLevel}})).status===200,'Corregir integrante incorporado');
    await navigate('/administration/');await waitFor('document.querySelector("#permission-form [name=scope]").options.length===6');
    await click('#administration-tab-levels');await evaluate('Array.from(document.querySelectorAll("#level-list button")).find(b=>b.textContent.includes("Coordinador")).click();document.getElementById("level-form").elements.same_level.checked=false;document.getElementById("level-form").requestSubmit()');
    await waitFor('document.querySelector(".swal2-title")?.textContent==="Nivel guardado."');
    check((await api('structure-levels')).data.find(l=>l.id===fixture.level).same_level===0,'Nivel existente guarda tras corregir relaciones');await shot('administration-level-saved');
    await call('Page.removeScriptToEvaluateOnNewDocument',{identifier:instrumentation.identifier});
    console.log(`Interfaz Estructura/Configuración: ${checks} comprobaciones Chrome.`);return checks;
};
