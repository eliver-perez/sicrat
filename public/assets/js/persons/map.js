'use strict';
(() => {
    const library=document.currentScript.dataset.library;
    let active=null;
    window.SicratPersonMap={
        destroy(){active?.destroy();active=null;},
        mount(parent,editable,organization){
            this.destroy();
            const latitude=document.querySelector('#person-edit-fields [name=latitude]'),longitude=document.querySelector('#person-edit-fields [name=longitude]');
            const details=document.createElement('details');details.className='ux-full ux-section person-map-section';
            const title=document.createElement('summary');title.textContent='Ubicación en mapa (opcional)';
            const canvas=document.createElement('div');canvas.id='person-location-map';canvas.className='person-location-map';canvas.setAttribute('aria-label','Mapa para seleccionar ubicación');
            const help=document.createElement('p');help.className='ux-help';help.setAttribute('role','status');const instruction=editable?'Haz clic o arrastra el marcador. También puedes escribir las coordenadas.':'Ubicación registrada.';help.textContent=instruction;
            const actions=document.createElement('div');actions.className='person-map-actions';
            const locate=document.createElement('button');locate.type='button';locate.className='btn';locate.textContent='Usar mi ubicación para centrar';locate.prepend(SicratUI.icon('locate-fixed'));locate.disabled=true;
            actions.append(locate);details.append(title,actions,help,canvas);parent.append(details);
            let map=null,marker=null,disposed=false,loading=false,observer=null,defaults=null,locationVersion=0;
            const coordinates=()=>{const a=latitude.value.trim(),b=longitude.value.trim();if(!a||!b)return null;const lat=Number(a),lng=Number(b);return Number.isFinite(lat)&&Number.isFinite(lng)&&Math.abs(lat)<=90&&Math.abs(lng)<=180?[lat,lng]:null;};
            function place(point,write=false){
                if(!map)return;
                locate.hidden=!!point;
                if(!point){if(marker){marker.remove();marker=null;}return;}
                if(!marker){marker=L.marker(point,{draggable:editable,autoPan:true,icon:L.divIcon({className:'person-map-pin',html:'<span aria-hidden="true">●</span>',iconSize:[28,28],iconAnchor:[14,14]}),title:'Ubicación de la persona'}).addTo(map);marker.on('dragend',()=>{locationVersion++;const p=marker.getLatLng().wrap();place([p.lat,p.lng],true);});}else marker.setLatLng(point);
                if(write){help.textContent=instruction;latitude.value=Number(point[0]).toFixed(7);longitude.value=Number(point[1]).toFixed(7);latitude.dispatchEvent(new Event('input',{bubbles:true}));longitude.dispatchEvent(new Event('input',{bubbles:true}));}
            }
            function manual(){locationVersion++;help.textContent=instruction;const point=coordinates();place(point);if(point&&map)map.setView(point,Math.max(13,map.getZoom()),{animate:false});}
            latitude.addEventListener('change',manual);longitude.addEventListener('change',manual);
            details.addEventListener('toggle',async()=>{
                if(!details.open||disposed)return;
                if(map){map.invalidateSize();return;}if(loading)return;loading=true;
                try {
                    const results=await Promise.all([import(library),Promise.resolve(identityRequest(`organizations/${organization}/person-map`,'get')).catch(()=>({data:{latitude:23.6345,longitude:-102.5528,zoom:5},unavailable:true}))]);if(disposed)return;
                    defaults=results[1].data;if(results[1].unavailable)help.textContent='No se pudo consultar el centro configurado. Puedes seleccionar el punto o escribir las coordenadas.';
                    const point=coordinates();map=L.map(canvas,{scrollWheelZoom:false}).setView(point || [defaults.latitude,defaults.longitude],point?14:defaults.zoom);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'}).on('tileerror',()=>{help.textContent='No se pudo cargar parte del mapa. Puedes seguir capturando las coordenadas manualmente.';}).addTo(map);
                    if(editable)map.on('click',event=>{locationVersion++;const point=event.latlng.wrap();place([point.lat,point.lng],true);});
                    place(point);locate.disabled=false;locate.hidden=!!point;observer=new ResizeObserver(()=>map?.invalidateSize());observer.observe(canvas);
                } catch(error){help.textContent='El mapa no está disponible. Puedes escribir las coordenadas manualmente.';}
                finally {loading=false;}
            });
            locate.onclick=()=>{
                if(!map||locate.disabled)return;
                const version=++locationVersion;locate.disabled=true;help.textContent='Consultando ubicación actual…';
                const finish=()=>{if(!disposed)locate.disabled=false;};
                const fallback=()=>{if(!disposed&&version===locationVersion){if(!coordinates())map.setView([defaults.latitude,defaults.longitude],defaults.zoom,{animate:false});help.textContent='Ubicación actual no disponible. Se utiliza el centro configurado; puedes seleccionar el domicilio en el mapa.';}finish();};
                if(!navigator.geolocation||!window.isSecureContext){fallback();return;}
                navigator.geolocation.getCurrentPosition(position=>{
                    if(!disposed&&version===locationVersion&&!coordinates()){
                        map.setView([position.coords.latitude,position.coords.longitude],Math.max(14,defaults.zoom),{animate:false});
                        help.textContent='Mapa centrado en tu ubicación. Selecciona un punto para registrar el domicilio.';
                    }finish();
                },fallback,{enableHighAccuracy:false,timeout:8000,maximumAge:60000});
            };
            active={destroy(){disposed=true;locationVersion++;observer?.disconnect();map?.remove();map=null;latitude.removeEventListener('change',manual);longitude.removeEventListener('change',manual);}};
        }
    };
    document.addEventListener('DOMContentLoaded',()=>{
        const open=document.getElementById('person-map-settings-open'),form=document.getElementById('person-map-settings-form'),dialog=document.getElementById('person-map-settings');
        if(!open)return;let organization=null;
        open.onclick=()=>SicratPhase3.run(async()=>{
            const selected=document.getElementById('select-persons-organization').value;
            if(!selected)throw new Error('Selecciona una organización.');
            const response=await identityRequest(`organizations/${selected}/person-map`,'get');organization=selected;
            Object.entries(response.data).forEach(([key,value])=>{if(form.elements[key])form.elements[key].value=value;});dialog.showModal();
        },open);
        form.onsubmit=event=>{event.preventDefault();SicratPhase3.run(async()=>{
            await identityRequest(`organizations/${organization}/person-map`,'put',Object.fromEntries(new FormData(form)));
            dialog.close();ShowToastMessage('Ubicación inicial del mapa actualizada.','success');
        },form);};
    });
})();
