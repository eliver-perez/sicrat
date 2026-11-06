'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

module.exports = async function ({ directory, call, evaluate, waitFor, navigate }) {
    const fixture = JSON.parse(fs.readFileSync(path.join(directory, 'jornada-media.json'), 'utf8'));
    let checks = 0;
    const check = (condition, message) => { assert.ok(condition, message); checks++; };
    const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    async function api(route, method = 'GET', data = null) {
        return evaluate(`(async () => {
            const response = await fetch('/api/' + ${JSON.stringify(route)}, {
                method:${JSON.stringify(method)},
                headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},
                body:${data === null ? 'undefined' : JSON.stringify(JSON.stringify(data))}
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message);
            return result.data;
        })()`);
    }
    await evaluate('Swal.close()');
    await navigate('/jornada/');
    await waitFor('window.SicratJornada?.initialized');
    await click('#jornada-tab-configuration');
    await waitFor('document.getElementById("jornada-physical-create-form")');
    check(await evaluate(`Array.from(document.querySelectorAll('.jornada-booth-accordion > details > summary span'))
        .map(node=>node.textContent).join('|') === 'Asociar casilla existente|Registrar nueva casilla'`), 'Orden exacto del acordeón');
    check(await evaluate('document.getElementById("jornada-physical-associate").open && !document.getElementById("jornada-physical-create").open'), 'Asociar abierto y Registrar cerrado por defecto');
    check(await evaluate(`document.querySelector('#jornada-physical-associate > p').textContent === 'Busca una casilla del catálogo y asígnala a esta elección.'`), 'Descripción de asociación');
    await click('#jornada-physical-create > summary');
    await waitFor('document.getElementById("jornada-physical-create").open && !document.getElementById("jornada-physical-associate").open');
    checks++;
    check(await evaluate(`document.querySelector('#jornada-physical-create > p').textContent === 'Registra una casilla que todavía no existe en el catálogo y asígnala a esta elección.'`), 'Descripción de registro');
    check(await evaluate(`(() => {
        const form=document.getElementById('jornada-physical-create-form');
        return ['nombre','tipo','ubicacion','domicilio'].every(name=>form.elements[name]) && form.elements.tipo.tagName==='SELECT'
            && form.querySelector('.op-picker input[type=search]') && form.querySelector('button[type=submit] svg');
    })()`), 'Registro mantiene campos, catálogo, buscador y botón Lucide');
    await evaluate(`(() => {
        const input=document.querySelector('#jornada-physical-create-form input[type=search]');
        input.value=''; input.closest('.ux-search-row').querySelector('button').click();
    })()`);
    await waitFor('document.querySelector("#jornada-physical-create-form .op-picker select").options.length > 1');
    await evaluate(`(() => {
        const select=document.querySelector('#jornada-physical-create-form .op-picker select');
        select.value=Array.from(select.options).find(option=>option.value).value;
        select.dispatchEvent(new Event('change'));
    })()`);
    check(await evaluate('!document.querySelector("#jornada-physical-create-form .op-picker .ux-selected-person").hidden'), 'La sección se puede seleccionar');
    for (const width of [1440, 390]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500});
        check(await evaluate(`(() => {
            const form=document.getElementById('jornada-physical-create-form');
            const button=form.querySelector('button[type=submit]');
            return document.documentElement.scrollWidth<=innerWidth+2 && button.offsetWidth<250 && button.offsetWidth<form.offsetWidth*.8;
        })()`), 'Registro responsive y botón compacto: '+width);
        await evaluate('document.getElementById("jornada-settings-physical-booths").scrollIntoView()');
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        fs.writeFileSync(path.join(directory,'acta-accordion-'+width+'.png'),Buffer.from(shot.data,'base64'));
    }
    await click('#jornada-physical-associate > summary');
    await waitFor('document.getElementById("jornada-physical-associate").open && !document.getElementById("jornada-physical-create").open');
    checks++;
    await evaluate(`(() => {
        const input=document.querySelector('#jornada-physical-associate-form input[type=search]');
        input.value='Acta navegador';input.closest('.ux-search-row').querySelector('button').click();
    })()`);
    await waitFor('document.querySelector("#jornada-physical-associate-form .op-picker select").textContent.includes("Acta navegador")');
    await evaluate(`(() => {
        const select=document.querySelector('#jornada-physical-associate-form .op-picker select');
        select.value=Array.from(select.options).find(option=>option.textContent.includes('Acta navegador')).value;
        select.dispatchEvent(new Event('change'));
    })()`);
    check(await evaluate('document.querySelector("#jornada-physical-associate-form button[type=submit]").textContent.includes("Asociar casilla")'), 'Búsqueda y selección de casilla conservadas');

    await api('auth/login','POST',{username:'reglas-captura-0',password:'Reglas Jornada seguras'});
    await navigate('/');
    await api('auth/election','POST',{process:fixture.election});
    await navigate('/jornada/');
    await waitFor('window.SicratJornada?.initialized');
    check(await evaluate('!SicratJornada.me.rights.includes("archivos.subir") && !SicratJornada.me.rights.includes("archivos.ver")'), 'Capturista real sin permiso de archivos');
    await evaluate(`SicratJornada.open(${JSON.stringify(fixture.booth)})`);
    await waitFor('document.getElementById("jornada-booth-dialog").open && document.querySelector("[data-capture-form]")');
    check((await api('jornada/booths/'+fixture.booth)).puede_capturar,'Cuenta autorizada para esta casilla');
    await evaluate(`window.__actaWrites=[]; window.__actaFetch=window.fetch;
        window.fetch=(url,options)=>{if(options?.method==='POST' && (String(url).endsWith('/photo') || String(url).endsWith('/captures')))window.__actaWrites.push(String(url));return window.__actaFetch(url,options);};`);
    async function attach(name) {
        const document=await call('DOM.getDocument');
        const input=await call('DOM.querySelector',{nodeId:document.root.nodeId,selector:'[data-capture-form] input[type=file]'});
        await call('DOM.setFileInputFiles',{nodeId:input.nodeId,files:[path.join(directory,name)]});
        await waitFor('document.querySelector(".jornada-photo-preview:not([hidden]) img")?.naturalWidth > 0');
    }
    await attach(fixture.image);
    check(await evaluate(`document.querySelector('.jornada-photo-name').textContent === ${JSON.stringify(fixture.image)}`),'Miniatura y nombre antes de guardar');
    check(await evaluate('document.querySelector(".jornada-photo-preview figcaption").textContent.includes("KB")'),'Tamaño legible');
    check(await evaluate('window.__actaWrites.length===0'),'Seleccionar no sube ni registra la fotografía');
    await click('.jornada-photo-thumbnail');
    await waitFor('document.getElementById("jornada-photo-dialog").open && document.querySelector("#jornada-photo-dialog img").naturalWidth>0');
    check(await evaluate('document.querySelector("#jornada-photo-dialog img").src.startsWith("blob:")'),'Ampliación local sin rutas físicas');
    await click('#jornada-photo-dialog [data-close]');
    await waitFor('!document.getElementById("jornada-photo-dialog").open');
    await evaluate('document.querySelector("[name=imagen]").click=()=>{window.__changePhoto=true;}');
    await click('.jornada-photo-preview button[aria-label="Cambiar"]');
    check(await evaluate('window.__changePhoto'),'Cambiar abre el selector');
    await attach(fixture.changed);
    check(await evaluate(`document.querySelector('.jornada-photo-name').textContent === ${JSON.stringify(fixture.changed)}`),'Cambiar actualiza la imagen seleccionada');
    await click('.jornada-photo-preview button[aria-label="Quitar"]');
    check(await evaluate('document.querySelector(".jornada-photo-preview").hidden && !document.querySelector("[name=imagen]").files.length'),'Quitar limpia miniatura y selección');
    await attach(fixture.changed);
    for (const width of [1440,390]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500});
        check(await evaluate(`(() => {
            const dialog=document.getElementById('jornada-booth-dialog');
            return dialog.scrollWidth<=dialog.clientWidth+2 && document.documentElement.scrollWidth<=innerWidth+2;
        })()`),'Previsualización responsive: '+width);
        await evaluate('document.querySelector(".jornada-photo-preview").scrollIntoView({block:"center"})');
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        fs.writeFileSync(path.join(directory,'acta-preview-'+width+'.png'),Buffer.from(shot.data,'base64'));
    }
    await evaluate('Swal.close();document.querySelector("[data-capture-form]").requestSubmit()');
    await waitFor('Swal.isVisible() && Swal.getConfirmButton()?.offsetHeight');
    await evaluate('Swal.getCancelButton().click()');
    await waitFor('!Swal.isVisible()');
    check(await evaluate('window.__actaWrites.length===0'),'Cancelar la confirmación no carga archivos');
    check((await api('jornada/booths/'+fixture.booth)).capturas===0,'La previsualización no crea una captura');
    await evaluate('document.querySelector("[data-capture-form]").requestSubmit()');
    await waitFor('Swal.isVisible() && Swal.getConfirmButton()?.offsetHeight');
    await evaluate('Swal.getConfirmButton().click()');
    await waitFor('Swal.getTitle()?.textContent.includes("Versión 1 registrada")');
    const history=(await api('jornada/booths/'+fixture.booth+'/history')).rows;
    const saved=await api('jornada/versions/'+history[0].uuid);
    check(Boolean(saved.foto_uuid),'Captura registrada con fotografía vinculada');
    check(await evaluate('window.__actaWrites.length===2 && !document.querySelector("[data-capture-form]")'),'Una sola carga y un solo registro; formulario retirado');
    check(await evaluate(`(async()=> (await fetch('/media/'+${JSON.stringify(saved.foto_uuid)})).status)()`)===403,'La carga no elimina la protección de media');
    await evaluate('Swal.close();document.getElementById("jornada-booth-dialog").close();window.fetch=window.__actaFetch');
    console.log(`Actas Jornada: ${checks} comprobaciones Chrome de foto, autorización, acordeón y responsive.`);
    return checks;
};
