const assert = require('node:assert/strict');
module.exports = async ({f,call,evaluate,waitFor,navigate,api,click}) => {
    let checks = 0;
    const check = (value,label) => { assert.ok(value,label); checks++; };
    const fits = 'document.documentElement.scrollWidth<=innerWidth+1';
    async function width(value) {
        await call('Emulation.setDeviceMetricsOverride',{width:value,height:900,deviceScaleFactor:1,mobile:value<768});
        await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
    }
    await navigate('/persons/');
    await waitFor('document.getElementById("select-filter-section").options.length');
    await click('#persons-view-toggle');
    check(await evaluate('document.querySelector("#persons-list-toggle").getAttribute("aria-checked")==="true"'),'Menú compacto indica Lista como vista activa');
    await click('#persons-map-toggle');
    check(await evaluate('document.querySelector("#persons-map-toggle").getAttribute("aria-checked")==="true" && document.getElementById("persons-view-menu").hidden'),'Menú confirma Mapa y se cierra');
    await waitFor('document.querySelector("#persons-overview-map .person-cluster")');
    await evaluate('document.getElementById("light-dark-mode").click()');
    if (await evaluate('document.documentElement.dataset.theme!=="dark"')) await click('#light-dark-mode');
    for (const w of [320,390,768,1440]) {
        await width(w);
        check(await evaluate(fits+' && document.getElementById("persons-overview-map").getBoundingClientRect().right<=innerWidth+1'),'Mapa sin desborde '+w);
        await click('#persons-view-toggle');
        check(await evaluate('(()=>{const r=document.getElementById("persons-view-menu").getBoundingClientRect();return r.left>=0 && r.right<=innerWidth+1;})()'),'Menú dentro del viewport '+w);
        await click('#persons-view-toggle');
    }
    await waitFor('document.querySelector("#persons-overview-map .person-cluster")');
    await click('#persons-overview-map .person-cluster');
    await evaluate('Array.from(document.querySelectorAll(".leaflet-popup button")).find(b=>b.textContent==="Ver personas").click()');
    await waitFor('document.querySelectorAll(".person-cluster-list button").length===3');
    await click('.person-cluster-list button');
    await waitFor('document.getElementById("person-detail").open && !document.getElementById("person-consult").hidden');
    for (const w of [320,390,768,1440]) {
        await width(w);
        check(await evaluate(fits+' && document.getElementById("person-detail").clientWidth>=document.getElementById("person-detail").scrollWidth-1'),'Ficha sin desborde '+w);
    }
    await evaluate('document.getElementById("person-detail").close()');
    await evaluate('const search=document.getElementById("field-filter-search");search.value="Claudio";search.dispatchEvent(new Event("input"));');
    await waitFor('document.querySelector("#persons-overview-map .person-map-pin[title*=Claudio]") && document.getElementById("persons-map-status").textContent.startsWith("1 persona")');
    await click('#persons-overview-map .person-map-pin[title*=Claudio]');
    await waitFor('document.querySelector("#persons-overview-map .leaflet-popup strong")?.textContent.includes("Claudio")');
    await click('#persons-overview-map .leaflet-popup .person-popup button');
    await waitFor('document.getElementById("person-detail").open && document.getElementById("person-consult").textContent.includes("Claudio Javier")');
    check(await evaluate('!document.getElementById("person-assign-structure") && document.querySelector("#person-structure-action a").textContent.includes("Ver en estructura")'),'El marcador abre la ficha compartida con la acción válida');
    await evaluate('document.getElementById("person-detail").close()');
    await click('#persons-view-toggle');
    await click('#persons-list-toggle');
    await click('#btn-persons-new');
    await evaluate('document.querySelector("#person-quick-location details").open=true');
    await waitFor('document.querySelector("#person-quick-map .leaflet-pane")');
    const origin=await evaluate('location.origin');
    await call('Browser.setPermission',{permission:{name:'geolocation'},setting:'granted',origin});
    await call('Emulation.setGeolocationOverride',{latitude:26.41,longitude:-99.04,accuracy:20});
    await click('#person-quick-location .person-map-actions button');
    await waitFor('document.querySelector("#person-quick-map .leaflet-marker-icon") && Number(document.querySelector("#person-quick-location [name=latitude]").value)===26.41');
    check(await evaluate('new_person && !registering_person'),'Alta conserva las coordenadas como borrador');
    await click('#btn-persons-cancel');
    await click('#person-import-open');
    await evaluate(`(()=>{const f=document.querySelector('#person-import-upload input[type=file]'),d=new DataTransfer();d.items.add(new File(['Nombre\\nRevisión sin aplicar'],'personas.csv',{type:'text/csv'}));f.files=d.files;document.getElementById('person-import-upload').requestSubmit();})()`);
    await waitFor('personImportId && !personImportBusy');
    const id=await evaluate('personImportId');
    await click('#person-import [data-close]');await click('#person-import-open');
    check(await evaluate('personImportId==='+JSON.stringify(id)+' && !document.getElementById("person-import-mapping").classList.contains("hidden")'),'Cerrar conserva selección y progreso');
    await click('#person-import-cancel');await waitFor('document.querySelector("#person-import .swal2-confirm")');await click('#person-import .swal2-confirm');
    await waitFor('personImportId===null && !document.getElementById("person-import-upload").classList.contains("hidden")');
    check((await api('person-imports/'+id)).status===404,'Cancelar elimina la importación temporal');
    await click('#person-import [data-close]');
    await navigate('/administration/');
    await waitFor('document.querySelector("#permission-form [name=permission] optgroup")');
    await click('#administration-tab-permissions');
    check(await evaluate('!Array.from(document.querySelectorAll("#permission-form [name=permission] option")).some(o=>o.value.startsWith("encuestas."))'),'Módulos desactivados ausentes del editor de roles');
    for (const w of [320,390,768,1440]) {
        await width(w);
        check(await evaluate(fits),'Permisos sin desborde '+w);
    }
    await navigate('/autenticacion/');
    await api('auth/login','POST',{username:'mapa-consulta',password:'Consulta segura prueba'});await navigate('/');
    await api('auth/election','POST',{process:f.election});await navigate('/structure/');
    await waitFor('document.getElementById("structure-navigation").textContent.includes("Tu usuario no se encuentra registrado")');
    check(await evaluate('document.getElementById("structure-summary").hidden'),'Usuario sin membresía recibe el mensaje correspondiente');
    await evaluate(`document.querySelector('#structure-members [data-member="${f.members.root}"] [aria-label="Ver ficha"]').click()`);
    await waitFor('document.getElementById("structure-member-dialog").open && document.querySelector("#structure-team .structure-team-card")');
    const personEdit = '#structure-team [aria-label="Editar persona"]';
    check(await evaluate(`!document.querySelector(${JSON.stringify(personEdit)})`),'Sin permiso de edición el equipo conserva sólo las acciones de consulta');

    await navigate('/persons/');await waitFor('document.querySelector("#table-persons tr.person-open")');await evaluate(`openPerson(${JSON.stringify(f.persons.candidate)})`);
    await waitFor('document.getElementById("person-detail").open');
    check(await evaluate('!document.getElementById("person-assign-structure")'),'Sin permiso para formar equipo se oculta la acción');
    check(await evaluate('!document.querySelector("#person-consult .person-map-section")'),'La ficha sin coordenadas omite el mapa de consulta');
    return checks;
};
