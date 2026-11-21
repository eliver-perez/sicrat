'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

async function review({call,evaluate,waitFor,directory},name,selector) {
    let checks=0;
    const table='document.querySelector('+JSON.stringify(selector)+')';
    await waitFor(table+'?.tBodies[0]?.rows[0] && '+table+'.offsetWidth');
    const originalTheme=await evaluate('document.documentElement.getAttribute("data-theme")');
    for (const width of [320,390,768,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
        await new Promise(resolve=>setTimeout(resolve,300));
        for (const theme of ['light','dark']) {
            const result=await evaluate(`(async()=>{
                document.documentElement.dataset.theme=${JSON.stringify(theme)};
                const table=${table}, head=table.tHead, row=table.tBodies[0].rows[0];
                head.scrollIntoView({block:'center',inline:'start',behavior:'instant'});
                await new Promise(resolve=>setTimeout(resolve,250));
                const cells=[...head.rows[0].cells].filter(cell=>cell.offsetWidth);
                const color=node=>getComputedStyle(node).backgroundColor;
                const colors=cells.map(cell=>[color(cell),getComputedStyle(cell).color]);
                const body=[color(row),...Array.from(row.cells,color)];
                const controls=[...table.tBodies[0].querySelectorAll('button,.ux-badge')].slice(0,12).map(color);
                const rect=head.getBoundingClientRect();
                let left=Math.max(0,rect.left), right=Math.min(innerWidth,rect.right);
                let scrollable=true;
                for(let parent=head.parentElement;parent;parent=parent.parentElement) {
                    if(!['auto','hidden','scroll','clip'].includes(getComputedStyle(parent).overflowX)) continue;
                    const edge=parent.getBoundingClientRect().left+parent.clientLeft;
                    left=Math.max(left,edge);
                    right=Math.min(right,edge+parent.clientWidth);
                    if(['auto','scroll'].includes(getComputedStyle(parent).overflowX) && parent.scrollWidth>parent.clientWidth+1) {
                        const start=parent.scrollLeft;
                        parent.scrollTo({left:start ? 0 : 16,behavior:'instant'});
                        scrollable &&= parent.scrollLeft!==start;
                        parent.scrollTo({left:start,behavior:'instant'});
                    }
                }
                return {colors,body,controls,scrollable,pageWidth:document.documentElement.scrollWidth,
                    clip:{x:left+scrollX,y:rect.top+scrollY,width:right-left,height:Math.min(rect.height+row.offsetHeight,160),scale:1}};
            })()`);
            const background=theme==='light' ? 'rgb(2, 34, 73)' : 'rgb(38, 59, 82)';
            const context=name+' '+width+' '+theme;
            assert.ok(result.colors.length && result.colors.every(([bg,fg])=>bg===background && fg==='rgb(246, 249, 252)'),context+' encabezados');
            assert.ok(![...result.body,...result.controls].includes(background),context+' filas y acciones');
            assert.ok(result.scrollable,context+' desplazamiento de contenedores');
            assert.ok(result.pageWidth<=width+1,context+' ancho de página '+result.pageWidth);
            checks+=4;
            const shot=await call('Page.captureScreenshot',{format:'png',clip:result.clip});
            fs.writeFileSync(path.join(directory,'table-'+name+'-'+width+'-'+theme+'.png'),Buffer.from(shot.data,'base64'),{mode:0o600});
        }
    }
    if(originalTheme===null) await evaluate('document.documentElement.removeAttribute("data-theme")');
    else await evaluate('document.documentElement.dataset.theme='+JSON.stringify(originalTheme));
    return checks;
}

module.exports=async function(browser) {
    const {call,evaluate,waitFor,navigate,fixtures}=browser;
    let checks=0;
    await navigate('/persons/');
    checks+=await review(browser,'personas','#table-persons');
    await call('DOM.enable');
    await call('CSS.enable');
    const {root}=await call('DOM.getDocument');
    const {nodeId}=await call('DOM.querySelector',{nodeId:root.nodeId,selector:'#table-persons tbody tr'});
    const background='getComputedStyle(document.querySelector("#table-persons tbody tr")).backgroundColor';
    await call('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});
    for(const theme of ['light','dark']) {
        await evaluate('document.documentElement.dataset.theme='+JSON.stringify(theme));
        const resting=await evaluate(background);
        await call('CSS.forcePseudoState',{nodeId,forcedPseudoClasses:['hover']});
        await waitFor(background+'!== '+JSON.stringify(resting));
        await call('CSS.forcePseudoState',{nodeId,forcedPseudoClasses:[]});
        checks++;
    }
    await navigate('/users/');
    checks+=await review(browser,'usuarios','#table-users');
    await navigate('/structure/?structure='+encodeURIComponent(fixtures.structure));
    const member='#structure-members tr[data-member="'+fixtures.rootMember+'"]';
    await waitFor('document.querySelector('+JSON.stringify(member)+') && !document.getElementById("structure-panels").inert');
    await evaluate('document.querySelector('+JSON.stringify(member)+').click()');
    await waitFor('document.querySelector("#structure-preview-table:not([hidden]) tbody tr[data-member]")');
    assert.ok(await evaluate('document.querySelector("#structure-members .structure-selected")?.getAttribute("aria-current")==="true"'),'Selección de integrante activa');
    checks++;
    for(const theme of ['light','dark']) {
        await evaluate('document.documentElement.dataset.theme='+JSON.stringify(theme));
        assert.ok(await evaluate(`(()=>{
            const selected=document.querySelector('#structure-members .structure-selected');
            const other=document.querySelector('#structure-members tr:not(.structure-selected)');
            return getComputedStyle(selected).backgroundColor!==getComputedStyle(other).backgroundColor &&
                getComputedStyle(selected).boxShadow!=='none';
        })()`),'Selección visible en '+theme);
        checks++;
    }
    checks+=await review(browser,'equipo','#structure-preview-table');
    for(const entity of ['transporte','interacciones','peticiones','incidencias']) {
        await navigate('/operations/?module='+entity);
        checks+=await review(browser,entity,'#operation-list table');
        if(['peticiones','incidencias'].includes(entity)) {
            assert.ok(await evaluate(`(()=>{
                const link=document.querySelector('#operations-root nav a[aria-current=page]');
                const heading=document.querySelector('#operation-list th');
                return link && getComputedStyle(link).backgroundColor!==getComputedStyle(heading).backgroundColor;
            })()`),'Estado activo de navegación de '+entity);
            checks++;
        }
    }
    // Servicios tiene evidencias de persona y registros de envío en esta elección.
    await evaluate(`(async()=>{
        const choices=(await (await fetch('/api/auth/elections?limit=100')).json()).data.electoral_processes;
        const process=choices.find(option=>option.process==='Proceso 1').id;
        await SicratPhase3.request('auth/election','POST',{process});
    })()`);
    await navigate('/services/');
    await waitFor('document.querySelector("#services-tab-audit:not([hidden])")');
    for(const tab of ['audit','approvals','files','exports','jobs','deliveries']) {
        await evaluate('document.getElementById('+JSON.stringify('services-tab-'+tab)+').click()');
        checks+=await review(browser,tab,'#'+tab+'-list table');
    }
    console.log('Encabezados de tablas: '+checks+' comprobaciones en Chrome.');
    return checks;
};

module.exports.review=review;
