'use strict';

module.exports=async function({call,evaluate,waitFor,navigate,fixtures,bounds,capture,verify}) {
    const query=selector=>'document.querySelector('+JSON.stringify(selector)+')';
    const click=selector=>evaluate(query(selector)+'.click()');
    const coordinatorRow='dialog.ux-entity-selector[open] tr[data-value="'+fixtures.people[3]+'"]';
    async function showMap() {
        await click('#transport-view-toggle');
        await evaluate(`(()=>{
            const current=document.activeElement;
            current.dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true,cancelable:true}));
            document.activeElement.click();
        })()`);
        await waitFor('!document.getElementById("transport-map-panel").hidden && document.querySelector("#transport-map .person-cluster")');
    }
    const areas=['interacciones','peticiones','incidencias','conversaciones'];
    for(const width of [320,390,768,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:width<768 ? 844 : 1000,deviceScaleFactor:1,mobile:false});
        for(const entity of areas) {
            await navigate('/operations/?module='+entity);
            await waitFor('document.querySelector("#operation-list tbody tr, #operation-list [data-open-chat]")');
            const theme=await evaluate(`(()=>{
                const header=document.querySelector('#operation-list th');
                const light=header && getComputedStyle(header).backgroundColor;
                document.documentElement.dataset.theme='dark';
                const dark=header && getComputedStyle(header).backgroundColor;
                const ink=header && getComputedStyle(header).color;
                return {light,dark,ink,title:!!document.querySelector('.operation-title-icon')};
            })()`);
            verify(theme.title && (!theme.light || theme.light==='rgb(2, 34, 73)' && theme.dark!==theme.light && theme.ink===theme.light),entity+' '+width+': icono de módulo y acento de marca en ambos temas');
            await bounds(entity+' '+width+' oscuro');
            if(entity==='conversaciones') {
                await click('#operation-list [data-open-chat]');
                await waitFor('document.getElementById("operation-detail-dialog").open');
                await bounds('Mensajería abierta '+width+' oscuro','operation-detail-dialog');
            } else {
                verify(await evaluate(query('#operation-list button[aria-label="Ver detalle"]')+'?.title==="Ver detalle"'),entity+' '+width+': acción con nombre accesible y tooltip');
            }
        }
        await navigate('/operations/?module=transporte');
        await waitFor('document.querySelector("#operation-list tbody tr") && !document.getElementById("operation-summary").hidden');
        await evaluate('document.documentElement.dataset.theme="dark"');
        await bounds('Transporte lista '+width+' oscuro');
        await showMap();
        await bounds('Transporte mapa '+width+' oscuro');
        verify(await evaluate('document.querySelector("#transport-map").clientHeight>=295 && document.getElementById("operation-list").hidden'),'Mapa de altura útil y vista exclusiva a '+width+' px');
        await click('#transport-map .person-cluster');
        await evaluate('Array.from(document.querySelectorAll("#transport-map .leaflet-popup button")).find(button=>button.textContent==="Ver personas").click()');
        await waitFor('document.querySelectorAll("#transport-map .transport-popup").length===2');
        verify(await evaluate('document.querySelector("#transport-map .leaflet-popup").textContent.includes("Ana Operación") && document.querySelector("#transport-map .leaflet-popup").textContent.includes("Elena Operación")'),'Grupo comparte ficha descriptiva con proveedor');
        await bounds('Transporte popup '+width+' oscuro');
        await evaluate('document.getElementById("transport-map").scrollIntoView({block:"center"})');
        await waitFor('Number(getComputedStyle(document.querySelector("#transport-map .leaflet-popup")).opacity)===1');
        verify(await evaluate('(()=>{const popup=document.querySelector("#transport-map .leaflet-popup-content-wrapper").getBoundingClientRect();const controls=document.querySelector("#transport-map .leaflet-control-zoom").getBoundingClientRect();return popup.top>=controls.bottom || popup.left>=controls.right || popup.right<=controls.left;})()'),'Controles sin cubrir el popup');
        await capture('transport-map-dark-'+width);
        await evaluate('Array.from(document.querySelectorAll("#transport-map .transport-popup-actions button")).find(button=>button.textContent==="Coordinar").click()');
        await waitFor('document.getElementById("operation-detail-dialog").open && document.querySelector(".transport-edit") && !document.getElementById("operation-panel-actions").hidden');
        await bounds('Transporte acciones '+width+' oscuro','operation-detail-dialog');
        await click('.transport-edit .transport-picker button[aria-haspopup=dialog]');
        await waitFor('document.querySelector("dialog.ux-entity-selector[open] tr[data-value]")');
        verify(await evaluate('document.querySelector("dialog.ux-entity-selector").getBoundingClientRect().width<=Math.min(innerWidth,512)'), 'Selector de responsable cabe a '+width+' px');
        await capture('transport-picker-dark-'+width);
        await click('dialog.ux-entity-selector [data-close]');
        await evaluate('document.getElementById("operation-detail-dialog").close()');
    }
    await waitFor('document.querySelector("#transport-map .transport-popup-actions button")');
    await evaluate('Array.from(document.querySelectorAll("#transport-map .transport-popup-actions button")).find(button=>button.textContent==="Abrir ficha").click()');
    await waitFor('document.getElementById("person-detail").open');
    verify(await evaluate('document.getElementById("person-detail-title").textContent.includes("Ana Operación")'),'Marcador abre la ficha compartida de la persona');
    await evaluate('document.getElementById("person-detail").close()');
    await waitFor('document.querySelector("#transport-map .transport-popup-actions button")');
    const chooseCoordinator=()=>click('.transport-edit .transport-picker button[aria-haspopup=dialog]');
    const coordinar=()=>evaluate('Array.from(document.querySelectorAll("#transport-map .transport-popup-actions button")).find(button=>button.textContent==="Coordinar").click()');
    const closeEdit=()=>evaluate('Array.from(document.querySelectorAll(".transport-edit .ux-modal-footer button")).find(button=>button.textContent==="Cancelar").click()');
    await coordinar();
    await waitFor('document.getElementById("operation-detail-dialog").open');
    await chooseCoordinator();
    await waitFor(query(coordinatorRow));
    await evaluate(`(()=>{
        const row=document.querySelector(${JSON.stringify('dialog.ux-entity-selector tr[data-value="'+fixtures.people[3]+'"]')});
        row.focus();
        row.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));
    })()`);
    await waitFor('!document.querySelector(".ux-entity-selector[open]") && document.querySelector(".transport-edit .transport-picker").textContent.includes("Diego")');
    await evaluate(`(()=>{
        const form=document.querySelector('.transport-edit');
        form.elements.need.value='propio';
        form.elements.notes.value='Cambio cancelado';
    })()`);
    await closeEdit();
    await waitFor('!document.getElementById("operation-detail-dialog").open');
    await coordinar();
    await waitFor('document.getElementById("operation-detail-dialog").open');
    verify(await evaluate('document.querySelector(".transport-edit").elements.need.value==="requiere" && document.querySelector(".transport-edit").elements.notes.value!=="Cambio cancelado" && document.querySelector(".transport-edit .transport-picker").textContent.includes("Bruno")'),'Cancelar revierte necesidad, observación y responsable sin persistir');
    await chooseCoordinator();
    await waitFor(query(coordinatorRow));
    await click(coordinatorRow);
    await waitFor('!document.querySelector(".ux-entity-selector[open]")');
    await click('.transport-edit button[aria-label="Quitar proveedor registrado"]');
    await evaluate(`(()=>{
        const form=document.querySelector('.transport-edit');
        form.elements.external_provider.value='Proveedor externo Chrome';
        form.elements.notes.value='Observaciones desde Chrome';
        form.requestSubmit();
    })()`);
    await waitFor('document.querySelector("#operation-panel-summary").textContent.includes("Proveedor externo Chrome") && document.querySelector("#operation-panel-summary").textContent.includes("Observaciones desde Chrome") && document.querySelector("#operation-panel-summary").textContent.includes("Diego Operación")');
    await closeEdit();
    await click('#transport-coordinator-filter button[aria-haspopup=dialog]');
    await waitFor(query(coordinatorRow));
    await click(coordinatorRow);
    await evaluate(`(()=>{
        const form=document.getElementById('operation-filter');
        form.elements.state.value='requiere';
        form.elements.section_number.value=${JSON.stringify(fixtures.transportSection)};
        form.elements.search.value='Ana';
        form.requestSubmit();
    })()`);
    await waitFor('document.getElementById("transport-map-status").textContent.startsWith("1 persona") && document.querySelector("#transport-map .person-map-pin") && document.getElementById("operation-summary").textContent.includes("Personas 1")');
    await click('#transport-view-toggle');
    await click('#transport-view-menu [role=menuitemradio]');
    await waitFor('!document.getElementById("operation-list").hidden && document.querySelectorAll("#operation-list tbody tr").length===1');
    verify(await evaluate('document.querySelector("#operation-list tbody").textContent.includes("Ana Operación") && document.querySelector("#operation-list .ux-pagination").hidden'),'Lista conserva los filtros y oculta la paginación de una página');
    await click('#operation-list button[aria-label="Coordinar"]');
    await waitFor('document.getElementById("operation-detail-dialog").open');
    await evaluate('const edit=document.querySelector(".transport-edit");edit.elements.need.value="propio";edit.requestSubmit()');
    await waitFor('document.querySelector(".transport-edit").elements.need.value==="propio" && document.querySelector("#operation-list .operation-empty")');
    verify(await evaluate('document.querySelector("#operation-summary").textContent.includes("Personas 0")'),'Cambiar necesidad actualiza la lista y el resumen después de guardar');
    await closeEdit();
    await evaluate('const form=document.getElementById("operation-filter");form.elements.search.value="Sin coincidencia para esta prueba";form.requestSubmit()');
    await waitFor('document.querySelector("#operation-list .operation-empty")');
    verify(await evaluate('document.querySelector("#operation-list .ux-pagination").hidden && !!document.querySelector("#operation-list .operation-empty svg")'),'Estado vacío compacto y sin paginación');
};
