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
    const camera = () => evaluate(`({
        center: window.structureTestMap.getCenter(), zoom: window.structureTestMap.getZoom(),
        scroll: ${dialog}.scrollTop, page: window.scrollY,
        search: document.getElementById('structure-map-search').value,
        section: document.getElementById('structure-map-section').value,
        title: document.getElementById('structure-map-title').textContent,
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
    await waitFor(`document.querySelector('.ux-entity-selector tr[data-value="${f.members.child}"]')`);
    await click(`.ux-entity-selector tr[data-value="${f.members.child}"]`);
    await count(1);
    check(await evaluate('document.getElementById("structure-map-title").textContent.startsWith("Estructura de Claudio")'),'Rama de un subordinado');
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
    await evaluate('Swal.close();document.getElementById("person-detail").close()');
    await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
    await count(1);
    assert.deepEqual(await camera(),before); checks++;
    check(await evaluate(`${dialog}.open && location.href===${JSON.stringify(route)} && !!document.querySelector('${map} .leaflet-popup')`),'Cerrar conserva el contexto y la selección de Estructura');
    await shot('structure-map-branch-dark');

    await api(grand,'PUT',{latitude:'',longitude:'',section:original.section || '',phone:original.phone || ''});
    await evaluate(`${dialog}.close()`);
    await navigate('/autenticacion/');
    check((await api('auth/login','POST',{username:'administrador',password:' nueva contraseña '})).status===200,'Restablece la sesión del resto de pruebas');
    await navigate('/');
    await api('auth/election','POST',{process:f.election});
    return checks;
};
