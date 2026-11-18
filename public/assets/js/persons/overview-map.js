'use strict';
window.SicratPersonsOverview = (() => {
    let map, layer, request, timer, generation = 0, active = false;
    const el = id => document.getElementById(id);
    const text = (tag,value,css) => SicratPhase3.text(tag,value,css);
    const status = value => { el('persons-map-status').textContent = value; };
    const filters = () => ({search:el('field-filter-search').value,section:el('select-filter-section').value});
    const query = params => $.getJSON(homeURL+'/api/persons/map?'+new URLSearchParams(params));
    function button(label, action) {
        const result = text('button',label,'btn text-primary');
        result.type = 'button'; result.onclick = action;
        return result;
    }
    function personInfo(person) {
        const box = text('div','','person-popup');
        box.append(text('strong',person.name), text('small',[person.code,person.section && 'Sección '+person.section].filter(Boolean).join(' · ')));
        return box;
    }
    function render(point, version) {
        const group = point.count>1;
        const title = group ? point.count+' personas' : point.name;
        const icon = group ? L.divIcon({className:'person-cluster',html:String(point.count),iconSize:[38,38]}) : SicratPersonMap.icon();
        const marker = L.marker([point.latitude,point.longitude],{icon,title}).addTo(layer);
        marker.bindTooltip(group ? text('span',title) : personInfo(point));
        const popup = group ? text('div',title,'person-popup') : personInfo(point);
        if (group) {
            const bounds = point.bounds;
            if (map.getZoom()<map.getMaxZoom()) popup.append(button('Acercar',()=>{
                const area = L.latLngBounds([bounds.south,bounds.west],[bounds.north,bounds.east]);
                map.setView(area.getCenter(),Math.min(19,Math.max(map.getZoom()+1,map.getBoundsZoom(area))),{animate:false});
            }));
            const people = text('div','','person-cluster-list');
            let page = 0;
            async function load(offset) {
                const current = ++page;
                people.textContent = 'Cargando…';
                try {
                    const response = await query({...filters(),...bounds,view:'people',offset});
                    if (generation!==version || current!==page) return;
                    people.replaceChildren();
                    response.data.points.forEach(person => people.append(button(person.name+' · '+person.code,()=>openPerson(person.id))));
                    if (offset) people.append(button('Anterior',()=>load(offset-25)));
                    if (response.data.has_more) people.append(button('Siguiente',()=>load(offset+25)));
                    marker.getPopup()?.update();
                } catch { if (page===current) people.textContent = 'No fue posible cargar las personas.'; }
            }
            popup.append(button('Ver personas',()=>load(0)),people);
        } else popup.append(button('Abrir ficha',()=>openPerson(point.id)));
        marker.bindPopup(popup,{maxWidth:240,minWidth:120,maxHeight:200,autoPanPadding:[12,12]});
    }
    function refresh() {
        if (!active || !map) return;
        clearTimeout(timer);
        const version = ++generation;
        request?.abort(); layer.clearLayers();
        status('Cargando ubicaciones…');
        timer = setTimeout(async () => {
            const bounds = map.getBounds(), size = map.getSize();
            try {
                request = query({...filters(),south:Math.max(-90,bounds.getSouth()),north:Math.min(90,bounds.getNorth()),
                    west:Math.max(-180,bounds.getWest()),east:Math.min(180,bounds.getEast()),
                    rows:Math.max(1,Math.round(size.y/64)),columns:Math.max(1,Math.round(size.x/64))});
                const {data} = await request;
                if (version!==generation) return;
                data.points.forEach(point=>render(point,version));
                status(data.points.length ? '' : 'Sin ubicaciones en esta zona para los filtros elegidos.');
            } catch (error) {
                if (version===generation) status(error.responseJSON?.message || 'No fue posible cargar las ubicaciones.');
            }
        },180);
    }
    async function switchView(showMap) {
        if (active===showMap) return;
        active = showMap;
        const version = ++generation;
        clearTimeout(timer); request?.abort();
        el('persons-list-panel').hidden = showMap; el('persons-map-panel').hidden = !showMap;
        el('persons-map-toggle').setAttribute('aria-pressed',String(showMap));
        el('persons-list-toggle').setAttribute('aria-pressed',String(!showMap));
        if (!showMap) { map?.remove(); map = layer = null; GetPersons(true); return; }
        status('Cargando mapa…');
        try {
            const [,response] = await Promise.all([SicratPersonMap.ready(),query({view:'initial'})]);
            if (version!==generation) return;
            const v = response.data;
            map = SicratPersonMap.create(el('persons-overview-map'),[v.latitude,v.longitude],v.zoom,{
                maxBounds:[[-85,-180],[85,180]],maxBoundsViscosity:1
            });
            layer = L.layerGroup().addTo(map);
            // Leaflet puede desplazar el mapa para que el popup quepa en un teléfono.
            // Mantenerlo abierto durante ese movimiento y consultar la nueva zona al cerrarlo.
            let fittingPopup = false;
            map.on('autopanstart',()=>{fittingPopup=true;});
            map.on('moveend',()=>{if (!fittingPopup) refresh();});
            map.on('popupclose',()=>{
                if (fittingPopup) {fittingPopup=false;refresh();}
            });
            map.on('dragstart zoomstart',()=>{fittingPopup=false;});
            refresh();
        } catch (error) {
            if (version===generation) status(error.responseJSON?.message || 'No fue posible cargar el mapa.');
        }
    }
    return {get active(){return active;},refresh,init(){
        el('persons-list-toggle').onclick = ()=>switchView(false);
        el('persons-map-toggle').onclick = ()=>switchView(true);
        el('field-filter-search').addEventListener('input',refresh);
    }};
})();
