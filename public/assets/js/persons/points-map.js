'use strict';
window.SicratPointsMap = ({canvas, status: notice, filters, query, select}) => {
    let map, layer, request, timer, generation = 0, active = false, fittingPopup = false;
    let suspended, needsRefresh = false;
    const text = (tag,value,css) => SicratPhase3.text(tag,value,css);
    const status = value => { notice.textContent = value; };
    function button(label, action) {
        const result = text('button',label,'btn text-primary');
        result.type = 'button'; result.onclick = action;
        return result;
    }
    function personInfo(person) {
        const box = text('div','','person-popup');
        box.append(text('strong',person.name), text('small',[person.code,person.section != null && 'Sección '+String(person.section).padStart(4,'0'),person.level].filter(Boolean).join(' · ')));
        return box;
    }
    function showPerson(id, marker) {
        const selection = suspended = {marker};
        Promise.resolve(select(id)).catch(() => { if (suspended === selection) resume(); });
    }
    function render(point, version, filterValues) {
        const group = point.count>1;
        const title = group ? point.count+' personas' : point.name;
        const icon = group ? L.divIcon({className:'person-cluster',html:String(point.count),iconSize:[38,38]}) : SicratPersonMap.icon();
        const marker = L.marker([point.latitude,point.longitude],{icon,title}).addTo(layer);
        marker.recordKey = group ? Object.values(point.bounds).join(',') : point.id;
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
                    const response = await query({...filterValues,...bounds,view:'people',offset});
                    if (generation!==version || current!==page) return;
                    marker.peopleOffset = offset;
                    people.replaceChildren();
                    response.data.points.forEach(person => {
                        const item = button('',()=>showPerson(person.id,marker));
                        item.append(personInfo(person));
                        people.append(item);
                    });
                    if (offset) people.append(button('Anterior',()=>load(offset-25)));
                    if (response.data.has_more) people.append(button('Siguiente',()=>load(offset+25)));
                    marker.getPopup()?.update();
                } catch { if (page===current) people.textContent = 'No fue posible cargar las personas.'; }
            }
            marker.loadPeople = load;
            popup.append(button('Ver personas',()=>load(0)),people);
        } else popup.append(button('Abrir ficha',()=>showPerson(point.id,marker)));
        marker.bindPopup(popup,{maxWidth:240,minWidth:120,maxHeight:200,autoPanPadding:[12,12]});
        marker.on('popupopen',()=>marker.closeTooltip());
        marker.on('tooltipopen',()=>{ if (marker.isPopupOpen()) marker.closeTooltip(); });
        return marker;
    }
    function refresh(keepPopup = false, previousMarker = null) {
        if (!active || !map) return;
        if (suspended) { needsRefresh = true; return; }
        clearTimeout(timer);
        const version = ++generation;
        request?.abort();
        const selected = previousMarker || (keepPopup ? layer.getLayers().find(marker=>marker.isPopupOpen()) : null);
        fittingPopup = false;
        if (!selected) layer.clearLayers();
        const filterValues = filters();
        status('Cargando ubicaciones…');
        timer = setTimeout(async () => {
            const bounds = map.getBounds(), size = map.getSize();
            try {
                request = query({...filterValues,south:Math.max(-90,bounds.getSouth()),north:Math.min(90,bounds.getNorth()),
                    west:Math.max(-180,bounds.getWest()),east:Math.min(180,bounds.getEast()),
                    rows:Math.max(1,Math.round(size.y/64)),columns:Math.max(1,Math.round(size.x/64))});
                const {data} = await request;
                if (version!==generation) return;
                if (suspended) { needsRefresh = true; return; }
                fittingPopup = false;
                layer.clearLayers();
                for (const point of data.points) {
                    const marker = render(point,version,filterValues);
                    if (selected?.recordKey === marker.recordKey) {
                        const popup = marker.getPopup();
                        popup.options.autoPan = false;
                        marker.openPopup();
                        marker.once('popupclose',()=>{ popup.options.autoPan = true; });
                        if (selected.peopleOffset !== undefined) marker.loadPeople?.(selected.peopleOffset);
                    }
                }
                const total = data.points.reduce((sum, point) => sum + point.count, 0);
                status(total ? `${total} ${total===1 ? 'persona con ubicación' : 'personas con ubicación'} en esta zona.`
                    : 'Sin ubicaciones en esta zona para los filtros elegidos.');
            } catch (error) {
                if (version===generation) {
                    if (suspended) { needsRefresh = true; return; }
                    layer.clearLayers();
                    status(error.responseJSON?.message || 'No fue posible cargar las ubicaciones.');
                }
            }
        },180);
    }
    async function open() {
        if (active) return;
        active = true;
        const version = ++generation;
        status('Cargando mapa…');
        try {
            request = query({view:'initial'});
            const [,response] = await Promise.all([SicratPersonMap.ready(),request]);
            if (version!==generation) return;
            const v = response.data;
            map = SicratPersonMap.create(canvas,[v.latitude,v.longitude],v.zoom,{
                maxBounds:[[-85,-180],[85,180]],maxBoundsViscosity:1
            });
            layer = L.layerGroup().addTo(map);
            // Leaflet puede desplazar el mapa para acomodar el popup en pantallas pequeñas.
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
    function resume() {
        if (!suspended) return;
        const {marker} = suspended;
        suspended = null;
        if (needsRefresh || !marker.isPopupOpen()) refresh(true,marker);
        needsRefresh = false;
    }
    function close() {
        active = false;
        generation++;
        clearTimeout(timer);
        request?.abort();
        fittingPopup = needsRefresh = false;
        suspended = null;
        map?.remove();
        map = layer = null;
    }
    return {get active(){return active;},open,close,refresh,resume};
};
