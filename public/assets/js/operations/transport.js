'use strict';

window.SicratTransport = {
    needs: {requiere:'Necesita transporte',propio:'Transporte propio',no_definido:'No definido'},

    picker(app,target,{label,path,initial=null,empty='Sin seleccionar',filter=false}) {
        const ui=window.SicratUI;
        let selected=initial;
        const caption=app.text('span',label);
        caption.id='transport-picker-'+(++app.sequence);
        const controls=app.text('div','','transport-picker-controls');
        const clear=filter ? null : ui.action(app.button('Quitar selección',()=>set(null)),'Quitar '+label.toLowerCase(),'x');
        const choose=app.button('',()=>ui.entitySelector({
            title:label,
            compact:true,
            selected:selected?.uuid ?? (filter ? '' : undefined),
            load:async(search,offset)=>{
                const result=await app.api(path+(path.includes('?') ? '&' : '?')+new URLSearchParams({search:search.trim(),limit:25,offset}));
                const rows=result.rows.map(row=>({...row,label:row.nombre,detail:row.codigo_persona}));
                if(filter && !offset && !search.trim()) rows.unshift({uuid:'',label:'Todos'},{uuid:'none',label:'Sin responsable'});
                return {...result,rows};
            },
            choose:row=>set(row.uuid ? row : null),
        }));
        choose.setAttribute('aria-haspopup','dialog');
        const value=app.text('span');
        value.id=caption.id+'-value';
        choose.setAttribute('aria-labelledby',caption.id+' '+value.id);
        function set(row) {
            selected=row;
            value.textContent=row?.label || empty;
            if(clear) clear.hidden=!row;
            target.dispatchEvent(new Event('transport-picker-change'));
            if(clear===document.activeElement) choose.focus();
        }
        choose.append(ui.icon('user-round'),value,ui.icon('chevron-down'));
        controls.append(choose);
        if(clear) controls.append(clear);
        target.append(caption,controls);
        set(initial);
        return {value:()=>selected?.uuid || ''};
    },

    init(app) {
        const ui=window.SicratUI;
        const detail=app.node('person-detail');
        const mapPanel=app.node('transport-map-panel');
        const list=app.node('operation-list');
        const picker=this.picker(app,app.node('transport-coordinator-filter'),{
            label:'Responsable',path:'operations/transport-coordinators',empty:'Todos',filter:true,
        });
        let summaryRequest=0;
        const map=SicratPointsMap({
            canvas:app.node('transport-map'),
            status:app.node('transport-map-status'),
            filters:()=>app.filters,
            query:params=>$.getJSON(SicratPhase3.base+'/api/operations/transport-map',params),
            describe:person=>{
                const card=app.text('div','','person-popup transport-popup');
                card.append(app.text('strong',person.name));
                if(person.section!=null) card.append(app.text('small','Sección '+String(person.section).padStart(4,'0')));
                card.append(app.text('span',[this.needs[person.need],person.coordination && app.label(person.coordination)].filter(Boolean).join(' · ')));
                card.append(app.text('small',person.coordinator ? 'Coordina '+person.coordinator : 'Sin responsable'));
                if(person.provider) card.append(app.text('small','Traslado por '+person.provider));
                return card;
            },
            actions:(target,person,perform)=>{
                const actions=app.text('div','','ux-actions transport-popup-actions');
                if(detail && Number(person.can_open)) actions.append(ui.labeledAction(
                    app.button('Abrir ficha',()=>perform(()=>openPerson(person.id))),'Abrir ficha','contact-round'
                ));
                if(Number(person.can_coordinate)) actions.append(ui.labeledAction(
                    app.button('Coordinar',()=>perform(async()=>{
                        try { await app.openDetail(person.id,'actions'); }
                        catch(error) { window.SicratFeedback.error(error); throw error; }
                    })),'Coordinar','bus'
                ));
                if(actions.children.length) target.append(actions);
            },
        });
        app.node('operation-detail-dialog').addEventListener('close',map.resume);
        if(detail) {
            InitializePersonIdentity(()=>map.refresh(true));
            detail.addEventListener('close',map.resume);
        }
        ui.viewMenu(app.node('transport-view-toggle'),app.node('transport-view-menu'),async(_,index)=>{
            const showMap=index===1;
            list.hidden=showMap;
            mapPanel.hidden=!showMap;
            if(showMap) await map.open();
            else { map.close(); await app.guard(()=>app.list.reset())(); }
        });
        async function summary() {
            const version=++summaryRequest;
            const result=await app.api('operations/transport-summary?'+new URLSearchParams(app.filters));
            if(version!==summaryRequest) return;
            const target=app.node('operation-summary');
            const counts=new Map(result.totals.map(row=>[row.estado,row]));
            const needs=counts.get('requiere');
            const coordinated=Number(needs?.coordinadas || 0);
            const total=result.totals.reduce((sum,row)=>sum+Number(row.cantidad),0);
            const values=[
                ['Personas',total],
                ...Object.entries(SicratTransport.needs).map(([key,label])=>[label,Number(counts.get(key)?.cantidad || 0)]),
                ['Coordinadas',coordinated],
                ['Por coordinar',Number(needs?.cantidad || 0)-coordinated],
            ];
            target.replaceChildren(...values.map(([label,count])=>{
                const item=app.text('span');
                item.append(document.createTextNode(label+' '),app.text('strong',String(count)));
                return item;
            }));
            target.hidden=false;
        }
        return {
            filter:()=>({coordinator:picker.value()}),
            async refresh(reset=false) {
                if(!mapPanel.hidden) {
                    if(map.active) map.refresh(!reset);
                    else await map.open();
                    await summary();
                } else await Promise.all([reset ? app.list.reset() : app.list.load(),summary()]);
            },
        };
    },

    columns(app) {
        return [
            ['Persona','titulo'],
            ['Sección',row=>row.section_number==null ? null : String(row.section_number).padStart(4,'0')],
            ['Necesidad',row=>this.needs[row.estado]],
            ['Coordinación',row=>{
                if(!row.coordination) return row.coordinador;
                const box=app.text('div');
                box.append(SicratUI.badge(app.label(row.coordination),row.coordination==='coordinada'));
                if(row.coordinador) box.append(app.text('div',row.coordinador,'op-small-note'));
                return box;
            }],
            ['Proveedor','proveedor'],
            ['Confirmación voluntaria',row=>row.confirmado_en || 'Sin registro voluntario'],
        ];
    },

    edit(app,row,target) {
        const ui=window.SicratUI;
        let coordinator,provider;
        const form=app.form(target,'Necesidad y coordinación',fields=>{
            const input=app.field(fields,'need','Necesidad de transporte','select',row.necesidad,true,this.needs);
            const status=app.text('small','','op-small-note transport-coordination');
            const updateStatus=()=>{
                status.textContent=input.value==='requiere' ? (coordinator.value() ? 'Coordinada' : 'Pendiente de coordinar') : '';
                status.hidden=!status.textContent;
            };
            app.field(fields,'external_provider','Proveedor externo','text',row.proveedor_externo).maxLength=180;
            const coordinatorField=app.text('div','','transport-picker');
            const providerField=app.text('div','','transport-picker');
            const personPath='operations/transporte/people?action=editar&participating=';
            coordinator=this.picker(app,coordinatorField,{
                label:'Persona que coordina',path:personPath+'1',
                initial:row.coordinador_uuid ? {uuid:row.coordinador_uuid,label:row.coordinador_nombre} : null,
                empty:'Sin responsable',
            });
            provider=this.picker(app,providerField,{
                label:'Proveedor registrado',path:personPath+'0',
                initial:row.proveedor_uuid ? {uuid:row.proveedor_uuid,label:row.proveedor_nombre} : null,
                empty:'Sin proveedor registrado',
            });
            coordinatorField.append(status);
            coordinatorField.addEventListener('transport-picker-change',updateStatus);
            input.addEventListener('change',updateStatus);
            updateStatus();
            fields.append(coordinatorField,providerField);
            app.field(fields,'notes','Observaciones logísticas','textarea',row.observaciones_logisticas).maxLength=4000;
        },async data=>{
            const changes={...data,version:row.version};
            if(coordinator.value()!==(row.coordinador_uuid || '')) changes.coordinator=coordinator.value();
            if(provider.value()!==(row.proveedor_uuid || '')) changes.provider=provider.value();
            await app.api('operations/transporte/'+row.uuid,'PUT',changes);
            await Promise.all([app.openDetail(row.uuid,'actions'),app.transport.refresh()]);
        });
        form.classList.add('transport-edit');
        const save=ui.labeledAction(form.querySelector('button[type=submit]'),'Guardar','save');
        const footer=app.text('div','','ux-modal-footer ux-full');
        const close=app.button('Cancelar',()=>app.node('operation-detail-dialog').close());
        footer.append(ui.labeledAction(close,'Cancelar','x'),save);
        form.append(footer);
    },
};
