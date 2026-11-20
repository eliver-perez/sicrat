const assert = require('node:assert/strict');

module.exports = async ({f, call, evaluate, waitFor, navigate, api, click, shot}) => {
    let checks = 0;
    const check = (value,label) => { assert.ok(value,label); checks++; };
    const map = '#structure-map-canvas';
    const dialog = 'document.getElementById("structure-map-dialog")';
    const status = 'document.getElementById("structure-map-status").textContent';
    const count = async value => waitFor(`${status}.startsWith(${JSON.stringify(value+' persona')})`);
    const set = (id,value,event = 'change') => evaluate(`(() => {
        const control = document.getElementById(${JSON.stringify(id)});
        control.value = ${JSON.stringify(value)};
        control.dispatchEvent(new Event(${JSON.stringify(event)}));
    })()`);
    const branches = '#structure-map-branches';
    const available = '#structure-branch-available';
    const selected = '#structure-branch-selected';
    const legend = 'document.getElementById("structure-map-legend").textContent';
    async function pointer(selector,touch = false) {
        const {x,y} = await evaluate(`(() => {
            const control = document.querySelector(${JSON.stringify(selector)});
            const rect = control.getBoundingClientRect();
            return {x:rect.left+rect.width/2,y:rect.top+rect.height/2};
        })()`);
        if (touch) {
            await call('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y}]});
            await call('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
        } else {
            await call('Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',clickCount:1});
            await call('Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',clickCount:1});
        }
    }
    const camera = () => evaluate(`({
        center: window.structureTestMap.getCenter(), zoom: window.structureTestMap.getZoom(),
        scroll: ${dialog}.scrollTop, page: window.scrollY,
        search: document.getElementById('structure-map-search').value,
        section: document.getElementById('structure-map-section').value,
        legend: document.getElementById('structure-map-legend').textContent,
        branchNames: document.getElementById('structure-branch-selected').textContent,
        availableNames: document.getElementById('structure-branch-available').textContent,
        branchSearch: document.getElementById('structure-branch-search').value,
        branchSort: document.getElementById('structure-branch-sort').value,
    })`);

    await navigate('/autenticacion/');
    check((await api('auth/login','POST',{username:'contexto-personas',password:'Contexto seguro pruebas'})).status===200,'Cuenta de estructura');
    await navigate('/');
    check((await api('auth/election','POST',{process:f.election})).status===200,'Elección de la estructura');
    const section = (await api('sections?limit=1')).data.sections[0].id;
    const grand = 'persons/'+f.persons.grand;
    const original = (await api(grand)).data;
    check((await api(grand,'PUT',{latitude:26.39,longitude:-99.03,section})).status===200,'Fixture de ubicación indirecta');

    await navigate('/structure/?structure='+f.structure);
    await waitFor('document.getElementById("structure-navigation").textContent.startsWith("Eliver Perez")');
    const route = await evaluate('location.href');
    const childAction = `#structure-members [data-member="${f.members.child}"] [aria-label="Editar persona"]`;
    const personDialog = 'document.getElementById("person-detail")';
    const personName = 'document.querySelector("#person-edit-fields [name=name]")';
    const personPhone = 'document.querySelector("#person-edit-fields [name=phone]")';
    const child = 'persons/'+f.persons.child;
    const childBefore = (await api(child)).data;
    await waitFor(`document.querySelector(${JSON.stringify(childAction)})`);
    await click(childAction);
    await waitFor(`${personDialog}.open && !document.getElementById('person-edit-form').hidden`);
    check(await evaluate(`location.href===${JSON.stringify(route)} && ${personName}.value==='Claudio Javier'`),'Pencil en equipo directo abre edición sin navegar');
    await evaluate(`${personName}.value='No guardar'; ShowToastMessage('Aviso de prueba','warning')`);
    await waitFor('document.querySelector("#person-detail .swal2-toast")');
    await pointer('#person-detail-close');
    await waitFor(`!${personDialog}.open && document.activeElement.matches(${JSON.stringify(childAction)})`);
    check((await api(child)).data.name===childBefore.name,'X mediante clic descarta borrador y devuelve foco al lápiz, incluso con aviso');
    await evaluate('Swal.close()');
    await click(childAction);
    await waitFor(`${personDialog}.open`);
    await evaluate(`${personName}.value='Claudio editado';document.getElementById('person-edit-form').requestSubmit()`);
    await waitFor(`!${personDialog}.open && document.getElementById('structure-members').textContent.includes('Claudio editado')`);
    check((await api(child)).data.name==='Claudio editado','Guardar actualiza la fila y persiste sin recargar');
    await evaluate('Swal.close()');
    await click(childAction);
    await waitFor(`${personDialog}.open`);
    await evaluate(`${personName}.value=${JSON.stringify(childBefore.name)};document.getElementById('person-edit-form').requestSubmit()`);
    await waitFor(`!${personDialog}.open && document.getElementById('structure-members').textContent.includes('Claudio Javier')`);
    await evaluate('Swal.close()');

    await click(`#structure-members [data-member="${f.members.child}"] [aria-label="Ver ficha"]`);
    const teamEdit = '#structure-team [aria-label="Editar persona"]';
    await waitFor(`document.getElementById('structure-member-dialog').open && document.querySelector(${JSON.stringify(teamEdit)})`);
    await click(teamEdit);
    await waitFor(`${personDialog}.open`);
    check(await evaluate(`${personName}.value==='José Luis' && document.getElementById('structure-member-dialog').open`),'Pencil de rama mantiene su modal debajo');
    await evaluate(`${personPhone}.value='8112340055'`);
    await click('#person-edit-cancel');
    await waitFor(`!${personDialog}.open`);
    check((await api(grand)).data.phone===original.phone,'Cancelar edición de rama descarta el borrador');
    await click(teamEdit);
    await waitFor(`${personDialog}.open`);
    await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await waitFor(`!${personDialog}.open`);
    check(await evaluate('document.getElementById("structure-member-dialog").open'),'Escape conserva el diálogo de rama');
    await call('Emulation.setTouchEmulationEnabled',{enabled:true,maxTouchPoints:1});
    for (const width of [320,390]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true});
        await click(teamEdit);
        await waitFor(`${personDialog}.open`);
        await evaluate(`${personPhone}.value='8112340066'; ShowToastMessage('Aviso en móvil','warning')`);
        await waitFor('document.querySelector("#person-detail .swal2-toast")');
        await pointer('#person-detail-close',true);
        await waitFor(`!${personDialog}.open`);
        check(await evaluate('document.getElementById("structure-member-dialog").open') && (await api(grand)).data.phone===original.phone,'X mediante tap descarta cambios y devuelve a la rama a '+width+' px');
        await evaluate('Swal.close()');
    }
    await call('Emulation.setTouchEmulationEnabled',{enabled:false});
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false});
    await evaluate('Swal.close()');
    const branchPath = await evaluate('document.getElementById("structure-line").textContent');
    await click(teamEdit);
    await waitFor(`${personDialog}.open`);
    await evaluate(`${personName}.value='José Luis actualizado';document.getElementById('person-edit-form').requestSubmit()`);
    await waitFor(`!${personDialog}.open && document.querySelector('#structure-team strong').textContent.includes('actualizado')`);
    check(await evaluate(`document.getElementById('structure-member-dialog').open && document.getElementById('structure-line').textContent===${JSON.stringify(branchPath)} && location.href===${JSON.stringify(route)}`),'Guardar desde la rama actualiza el registro y conserva el breadcrumb');
    await evaluate('Swal.close()');
    await click(teamEdit);
    await waitFor(`${personDialog}.open`);
    await evaluate(`${personName}.value=${JSON.stringify(original.name)};document.getElementById('person-edit-form').requestSubmit()`);
    await waitFor(`!${personDialog}.open`);
    await evaluate('Swal.close();document.getElementById("structure-member-dialog").close()');

    await evaluate(`(() => {
        const create = SicratPersonMap.create;
        SicratPersonMap.create = (canvas,...args) => {
            const instance = create(canvas,...args);
            if (canvas.id === 'structure-map-canvas') window.structureTestMap = instance;
            return instance;
        };
    })()`);
    await click('#structure-map-open');
    await count(2);
    check(await evaluate(`${dialog}.open && location.href === ${JSON.stringify(route)} && !!document.querySelector('${map} .person-cluster')`),'Modal local con agrupación y conteo');
    await set('structure-map-extent','direct'); await count(1);
    check(await evaluate(`document.querySelector('${map} .person-map-pin').title.includes('Claudio')`),'Equipo directo');
    await set('structure-map-extent','indirect'); await count(1);
    await waitFor(`document.querySelector('${map} .person-map-pin')?.title.includes('José Luis')`); checks++;
    await set('structure-map-extent','all'); await count(2);
    await waitFor('document.getElementById("structure-map-section").options.length>1');
    await set('structure-map-section',section); await count(1);
    await set('structure-map-search','Claudio','input');
    await waitFor(`${status}.startsWith('Sin ubicaciones')`); checks++;
    await set('structure-map-search','José','input'); await count(1);
    await click('#structure-map-find-branch');
    const availableChild = `${available} [data-branch="${f.members.child}"] button`;
    const availableGrand = `${available} [data-branch="${f.members.grand}"] button`;
    await waitFor(`document.querySelector(${JSON.stringify(availableChild)})`);
    check(await evaluate('document.getElementById("structure-branch-clear").disabled && document.getElementById("structure-branch-selected-count").textContent==="Seleccionados (0)"'),'Remover todos visible y deshabilitado al inicio');
    await set('structure-branch-search','Claudio','input');
    await waitFor(`document.querySelectorAll('${available} li').length===1 && document.querySelector(${JSON.stringify(availableChild)})`);
    const childCode = (await api(child)).data.code;
    check(await evaluate(`document.querySelector('${available} li strong').textContent.includes('Claudio') && document.querySelector('${available} li small').textContent==='Zona' && !document.querySelector('${available}').textContent.includes(${JSON.stringify(childCode)})`),'La fila tiene nombre y nivel, sin clave visible');
    await click(availableChild);
    check(await evaluate(`${legend}.includes('estructura de Claudio') && document.getElementById('structure-branch-available-count').textContent==='Disponibles (0)'`),'Seleccionar actualiza leyenda y contador inmediatamente');
    await set('structure-branch-search','José','input');
    await waitFor(`document.querySelector(${JSON.stringify(availableGrand)})`);
    await click(availableGrand);
    check(await evaluate(`${legend}==='Mostrando datos de 2 estructuras seleccionadas' && document.querySelectorAll('${selected} li').length===2`),'Dos ramas se combinan sin aplicar formulario');
    await click(`${selected} [data-branch="${f.members.grand}"] button`);
    check(await evaluate(`${legend}.includes('estructura de Claudio') && document.querySelectorAll('${selected} li').length===1`),'Remover una actualiza el mapa');
    await click('#structure-branch-clear');
    check(await evaluate(`${legend}==='Mostrando toda mi estructura' && document.getElementById('structure-branch-clear').disabled`),'Remover todos recupera el alcance predeterminado');
    await set('structure-branch-search','','input');
    await set('structure-branch-sort','level');
    await waitFor(`document.querySelector('${available} li small')?.textContent==='Base'`);
    await set('structure-branch-sort','name');
    await waitFor(`document.querySelector('${available} li strong')?.textContent.startsWith('Claudio')`);
    await click(availableChild);
    await set('structure-branch-search','José','input');
    await set('structure-branch-sort','level');
    await waitFor(`document.querySelector(${JSON.stringify(availableGrand)})`);
    if (await evaluate('document.documentElement.dataset.theme!=="dark"')) await click('#light-dark-mode');
    await waitFor('document.documentElement.dataset.theme==="dark"');
    for (const width of [320,390,768,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
        await evaluate('new Promise(resolve=>setTimeout(resolve,300))');
        check(await evaluate(`(() => {
            const picker = document.querySelector('${branches}');
            const chosen = picker.querySelector('.structure-branch-selected').getBoundingClientRect();
            const choices = picker.querySelector('.structure-branch-available').getBoundingClientRect();
            const layout = innerWidth < 768 ? chosen.top < choices.top : choices.right <= chosen.left;
            return layout && picker.scrollWidth<=picker.clientWidth+1 && document.documentElement.scrollWidth<=innerWidth+1;
        })()`),'Selector en modo oscuro sin desbordamiento a '+width+' px');
        if (width===390 || width===1440) await shot('structure-branch-picker-dark-'+width);
    }
    await click(branches+' [data-close]');
    await count(1);
    await evaluate(`void structureTestMap.setZoom(structureTestMap.getZoom()+1)`); await count(1);
    await click(map+' .person-map-pin');
    check(await evaluate(`document.querySelector('${map} .leaflet-popup').textContent.includes('Sección')`),'Popup con nombre, clave, sección y nivel');
    await click(map+' .leaflet-popup button');
    await waitFor('document.getElementById("person-detail").open && document.querySelector("#person-location-map .leaflet-marker-icon")');
    check(await evaluate('!document.querySelector("#person-consult details") && !document.getElementById("person-consult").textContent.includes("-99.03") && !!document.querySelector("#person-edit [data-lucide=pencil]")'),'Ubicación directa y acción Editar con lápiz');

    if (await evaluate('document.documentElement.dataset.theme!=="dark"')) await click('#light-dark-mode');
    await waitFor('document.documentElement.dataset.theme==="dark"');
    for (const width of [320,390,768,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
        await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
        check(await evaluate(`[document.getElementById('person-detail'),${dialog}].every(d=>d.getBoundingClientRect().right<=innerWidth+1 && d.scrollWidth<=d.clientWidth+1) && document.documentElement.scrollWidth<=innerWidth+1`),'Mapa y ficha sin desbordamiento a '+width+' px');
        if (width===390 || width===1440) await shot('structure-map-dark-'+width);
    }
    const before = await camera();
    await click('#person-edit');
    await evaluate('document.querySelector("#person-edit-fields [name=phone]").value="8112340099"');
    await click('#person-edit-cancel');
    check(await evaluate('!document.getElementById("person-consult").textContent.includes("8112340099")'),'Cancelar descarta sin cerrar el mapa');
    await click('#person-edit');
    await evaluate('document.querySelector("#person-edit-fields [name=phone]").value="8112340099";document.getElementById("person-edit-form").requestSubmit()');
    await waitFor('document.getElementById("person-consult").textContent.includes("8112340099")');
    check((await api(grand)).data.phone==='8112340099','Guardar desde Estructura persiste en la ficha normal');
    await pointer('#person-detail-close');
    await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
    await count(1);
    assert.deepEqual(await camera(),before); checks++;
    check(await evaluate(`${dialog}.open && location.href===${JSON.stringify(route)} && !!document.querySelector('${map} .leaflet-popup')`),'Cerrar conserva el contexto y la selección de Estructura');
    await shot('structure-map-branch-dark');
    await click('#structure-map-find-branch');
    check(await evaluate(`document.querySelector('${branches}').open && document.getElementById('structure-branch-search').value==='José' && document.getElementById('structure-branch-sort').value==='level' && !!document.querySelector('${selected} [data-branch="${f.members.child}"]') && !!document.querySelector(${JSON.stringify(availableGrand)})`),'Reabrir selector conserva sus dos paneles y filtros');
    await click(branches+' [data-close]');

    await api(grand,'PUT',{latitude:'',longitude:'',section:original.section || '',phone:original.phone || ''});
    await evaluate(`${dialog}.close()`);
    await navigate('/autenticacion/');
    check((await api('auth/login','POST',{username:'administrador',password:' nueva contraseña '})).status===200,'Restablece la sesión del resto de pruebas');
    await navigate('/');
    await api('auth/election','POST',{process:f.election});
    return checks;
};
