'use strict';
(() => {
    const library = document.currentScript.dataset.library;
    const fallback = {latitude:23.6345, longitude:-102.5528, zoom:5};
    const permissionError = 'No fue posible obtener tu ubicación. Puedes seleccionar el punto manualmente.';
    const node = (tag, text, css) => SicratPhase3.text(tag, text, css);
    function coordinates(lat, lng) {
        if ([lat,lng].some(v => v == null || String(v).trim() === '')) return null;
        const point = [Number(lat),Number(lng)];
        return point.every(Number.isFinite) && Math.abs(point[0])<=90 && Math.abs(point[1])<=180 ? point : null;
    }
    function create(canvas, center, zoom, options = {}) {
        const map = L.map(canvas,{scrollWheelZoom:false, maxZoom:19,...options}).setView(center, zoom);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,
            attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'}).addTo(map);
        const observer = new ResizeObserver(() => map.invalidateSize({animate:false,pan:true}));
        observer.observe(canvas);
        map.once('unload', () => observer.disconnect());
        return map;
    }
    function icon() {
        return L.divIcon({className:'person-map-pin',html:'●',iconSize:[28,28],iconAnchor:[14,14]});
    }
    function locate(button, status, current, apply) {
        button.hidden = !navigator.geolocation;
        let version = 0;
        button.onclick = () => {
            const attempt = ++version;
            button.disabled = true;
            status.textContent = 'Consultando ubicación actual…';
            function finish(callback) {
                if (current() && version === attempt) {
                    button.disabled = false;
                    callback();
                }
            }
            const fail = () => finish(() => { status.textContent = permissionError; });
            try {
                navigator.geolocation.getCurrentPosition(({coords}) => {
                    const point = coordinates(coords.latitude,coords.longitude);
                    if (!point) return fail();
                    finish(() => { status.textContent = ''; apply(point); });
                }, fail, {enableHighAccuracy:true, timeout:12000, maximumAge:0});
            } catch { fail(); }
        };
        return () => { version++; button.disabled = false; };
    }

    function mount(parent, {organization, fields, point, id, markerTitle='Ubicación de la persona', controls}) {
        const details = node(fields ? 'details' : 'div','',fields ? 'ux-full ux-section person-map-section' : 'person-map-section');
        const canvas = node('div','','person-location-map');
        canvas.id = id;
        canvas.setAttribute('aria-label',fields ? 'Mapa para seleccionar ubicación' : 'Ubicación registrada');
        const instructions = fields ? 'Toca el mapa o arrastra el marcador para ajustar el punto.' : 'Ubicación registrada.';
        const help = node('p',instructions,'ux-help');
        help.setAttribute('role','status');
        if (fields) {
            SicratUI.accordion(details,'Ubicación en mapa (opcional)','pin');
            if (controls) details.append(controls);
        }
        else canvas.classList.add('person-location-preview');
        const actions = node('div','','person-map-actions');
        const button = node('button','Usar mi ubicación actual','btn');
        SicratUI.labeledAction(button,button.textContent,'locate-fixed');
        button.type = 'button';
        if (fields && navigator.geolocation) { actions.append(button); details.append(actions); }
        details.append(help,canvas);
        parent.append(details);
        let map, marker, disposed = false, loading = false;
        const selected = () => fields ? coordinates(fields[0].value,fields[1].value) : point;
        function place(position, write = false) {
            if (!map) return;
            if (!position) { marker?.remove(); marker = null; return; }
            if (!marker) {
                marker = L.marker(position,{icon:icon(),draggable:!!fields,autoPan:true,title:markerTitle}).addTo(map);
                marker.on('dragend', () => { cancelLocate(); const p=marker.getLatLng().wrap(); place([p.lat,p.lng],true); });
            } else marker.setLatLng(position);
            if (write) {
                help.textContent = instructions;
                fields.forEach((field,i) => { field.value = position[i].toFixed(7); field.dispatchEvent(new Event('input',{bubbles:true})); });
            }
        }
        const cancelLocate = locate(button,help,() => !disposed && !!map, position => {
            map.setView(position,16,{animate:false}); place(position,true);
            help.textContent = 'Puedes ajustar el punto antes de guardar.';
        });
        function manual() {
            cancelLocate();
            help.textContent = instructions;
            const position = selected();
            place(position);
            if (position && map) map.setView(position,Math.max(14,map.getZoom()),{animate:false});
        }
        fields?.forEach(field => field.addEventListener('change',manual));
        async function load() {
            if ((fields && !details.open) || disposed || loading) return;
            if (map) { map.invalidateSize(); return; }
            loading = true; button.disabled = true;
            try {
                const config = organization && !selected()
                    ? Promise.resolve(identityRequest(`organizations/${organization}/person-map`,'get')).then(r=>r.data,()=>fallback)
                    : fallback;
                const [,defaults] = await Promise.all([import(library),config]);
                if (disposed) return;
                const position = selected();
                map = create(canvas,position || [defaults.latitude,defaults.longitude],position ? 14 : defaults.zoom);
                place(position);
                if (fields) {
                    map.on('click', e => { cancelLocate(); const p=e.latlng.wrap(); place([p.lat,p.lng],true); });
                    map.on('resize', () => {
                        const position = marker?.getLatLng();
                        if (position && canvas.clientWidth && !map.getBounds().contains(position)) map.panTo(position,{animate:false});
                    });
                }
                button.disabled = false;
            } catch { help.textContent = 'No fue posible cargar el mapa.'; }
            finally { loading = false; }
        }
        if (fields) {
            details.ontoggle = load;
            details.open = true;
        } else load();
        return () => {
            disposed = true; cancelLocate(); map?.remove(); details.remove();
            fields?.forEach(field => field.removeEventListener('change',manual));
        };
    }
    function mountBooth(parent,location,id) {
        const parts=String(location || '').split(',');
        const position=parts.length===2 ? coordinates(parts[0],parts[1]) : null;
        const controls=node('div','','ux-form-grid');
        const fields=[['latitude','Latitud',90],['longitude','Longitud',180]].map(([name,label,bound],index)=>{
            const wrapper=node('label',label);
            const input=node('input','','form-input');
            Object.assign(input,{name,type:'number',step:'any',min:String(-bound),max:String(bound),value:position?.[index] ?? ''});
            wrapper.append(input);
            controls.append(wrapper);
            return input;
        });
        if (location && !position) controls.append(node('p','Ubicación registrada anteriormente: '+location,'ux-full ux-help'));
        return mount(parent,{fields,controls,id,markerTitle:'Ubicación de la casilla'});
    }
    window.SicratPersonMap = {ready:()=>import(library), create, mount, mountBooth, icon, coordinates};

    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('person-map-settings');
        if (!dialog) return;
        const form = dialog.querySelector('form'), save = form.querySelector('[type=submit]');
        const button = document.getElementById('person-settings-locate'), status = document.getElementById('person-settings-status');
        let map, organization, cancelLocate, generation = 0;
        document.getElementById('person-map-settings-open').onclick = event => SicratPhase3.run(async () => {
            organization = document.getElementById('select-persons-organization').value;
            if (!organization) throw new Error('Selecciona una organización.');
            const version = ++generation;
            dialog.showModal(); save.disabled = button.disabled = true; button.hidden = !navigator.geolocation;
            status.textContent = 'Cargando mapa…';
            try {
                const [,response] = await Promise.all([import(library),identityRequest(`organizations/${organization}/person-map`,'get')]);
                if (version !== generation || !dialog.open) return;
                const view = response.data;
                map = create(document.getElementById('person-settings-canvas'),[view.latitude,view.longitude],view.zoom,{zoomAnimation:false});
                map.on('click',event => { cancelLocate?.(); map.panTo(event.latlng,{animate:false}); });
                cancelLocate = locate(button,status,()=>dialog.open && version===generation,p=>map.setView(p,16,{animate:false}));
                map.on('dragstart zoomstart',cancelLocate);
                save.disabled = button.disabled = false; status.textContent = 'Arrastra el mapa o toca un punto para centrar la vista.';
            } catch { if (version===generation) status.textContent='No fue posible cargar la vista inicial. Cierra el mapa y vuelve a intentarlo.'; }
        },event.currentTarget);
        dialog.addEventListener('close', () => { generation++; cancelLocate?.(); map?.remove(); map=null; });
        form.onsubmit = event => {
            event.preventDefault();
            if (!map || save.disabled) return;
            SicratPhase3.run(async () => {
                const center = map.getCenter().wrap();
                await identityRequest(`organizations/${organization}/person-map`,'put',{latitude:center.lat,longitude:center.lng,zoom:map.getZoom()});
                dialog.close(); ShowToastMessage('Vista inicial del mapa guardada.','success');
            },form);
        };
    });
})();
