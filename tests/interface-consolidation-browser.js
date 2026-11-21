'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

async function captureLayouts({call,evaluate,directory},name,selector) {
    const target = 'document.querySelector('+JSON.stringify(selector)+')';
    const original = await evaluate('document.documentElement.dataset.theme');
    let checks = 0;
    for (const width of [320,390,768,1440]) {
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500});
        for (const theme of ['light','dark']) {
            const result = await evaluate(`(async()=>{
                document.documentElement.dataset.theme=${JSON.stringify(theme)};
                const target=${target};
                target.scrollIntoView({block:'center',inline:'nearest',behavior:'instant'});
                if (target.classList.contains('ux-selection-table')) target.parentElement.scrollTop=0;
                await new Promise(resolve=>setTimeout(resolve,300));
                return {visible:target.offsetWidth>0,page:document.documentElement.scrollWidth,viewport:innerWidth,
                    dialog:target.closest('dialog')?.scrollWidth,target:target.closest('dialog')?.clientWidth};
            })()`);
            const label = name+' '+width+' '+theme;
            assert.ok(result.visible,label+' visible');
            assert.ok(result.page<=width+2 && result.viewport<=width+2,label+' ancho de página '+result.page);
            assert.ok(result.dialog==null || result.dialog<=result.target+2,label+' ancho de diálogo '+result.dialog+'/'+result.target);
            checks += 3;
            const shot = await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            fs.writeFileSync(path.join(directory,'consolidacion-'+name+'-'+width+'-'+theme+'.png'),Buffer.from(shot.data,'base64'),{mode:0o600});
        }
    }
    await evaluate('document.documentElement.dataset.theme='+JSON.stringify(original || 'light'));
    return checks;
}

async function selectBoothPosition(browser) {
    const {call,evaluate,waitFor,base}=browser;
    await waitFor('document.querySelector("#booth-location-map.leaflet-container")');
    const locationButton='[id="booths-form"] .person-map-actions button';
    await call('Browser.grantPermissions',{origin:base,permissions:['geolocation']});
    await call('Emulation.setGeolocationOverride',{latitude:25.6751,longitude:-100.3152,accuracy:5});
    await evaluate('document.querySelector('+JSON.stringify(locationButton)+').click()');
    const coords='document.querySelector("#booths-form [name=latitude]")';
    await waitFor(coords+'.value==="25.6751000" && document.querySelector("#booth-location-map .leaflet-marker-draggable")');
    await call('Emulation.clearGeolocationOverride');
    await call('Browser.resetPermissions');
    await evaluate(`(function(){
        const map=document.querySelector('#booth-location-map');
        map.scrollIntoView({block:'center',behavior:'instant'});
        navigator.geolocation.getCurrentPosition=(_success,error)=>error();
        document.querySelector(${JSON.stringify(locationButton)}).click();
    })()`);
    await waitFor('document.querySelector("#booths-form [role=status]").textContent.includes("seleccionar el punto manualmente")');
    const point=await evaluate(`(()=>{const rect=document.querySelector('#booth-location-map').getBoundingClientRect();
        return {x:rect.left+rect.width*.65,y:rect.top+rect.height*.45};})()`);
    const click=async (position)=> {
        await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...position});
        await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...position});
    };
    await click(point);
    await waitFor(coords+'.value!=="25.6751000"');
    const before=await evaluate(coords+'.value');
    const marker=await evaluate(`(()=>{const rect=document.querySelector('#booth-location-map .leaflet-marker-draggable').getBoundingClientRect();
        return {x:rect.left+rect.width/2,y:rect.top+rect.height/2};})()`);
    await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...marker});
    await call('Input.dispatchMouseEvent',{type:'mouseMoved',button:'left',buttons:1,x:marker.x+22,y:marker.y+20});
    await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,x:marker.x+22,y:marker.y+20});
    await waitFor(coords+'.value!=='+JSON.stringify(before));
    return evaluate(`(()=>{const form=document.querySelector('#booths-form form');
        return form.elements.latitude.value+', '+form.elements.longitude.value;})()`);
}

module.exports={captureLayouts,selectBoothPosition};
