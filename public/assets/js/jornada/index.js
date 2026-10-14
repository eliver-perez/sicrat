'use strict';

window.SicratJornada = Object.create(window.SicratOperations);
function InitializeValues(url) {
    const app=window.SicratJornada, ui=window.SicratPhase3, feedback=window.SicratFeedback;
    ui.base=url.replace(/\/$/,'');window.homeURL=ui.base;
    app.entity='jornada';app.filters={};app.charts=[];
    app.notice=(message,type='success')=>feedback.toast(message,type);
    const can=permission=>app.me.rights?.includes(permission);
    const optionType=value=>({partido:'Partido',coalicion:'Coalición',combinacion:'Combinación exacta',independiente:'Independiente',nulos:'Nulos',no_registrados:'No registrados',otro:'Otro'})[value] || value;
    const label=state=>({preparacion:'En preparación',abierta:'Abierta',cerrada:'Cerrada',pendiente:'Pendiente de validación',preliminar:'Preliminar interno',validada:'Validada',rechazada:'Rechazada',sustituida:'Sustituida',sin_captura:'Sin captura'})[state] || state;
    const safeFields=form=>Object.fromEntries(new FormData(form));
    const reference=(type,id)=>app.catalogs[type].find(row=>Number(row.id)===Number(id))?.uuid || '';
    async function refresh() {
        const capabilities=await app.api('jornada/capabilities');
        app.me={...app.me,...capabilities};app.config=capabilities.configuration;app.enabled=capabilities.enabled;
        app.node('jornada-state').textContent=label(app.config?.estado || 'preparacion');
        app.node('jornada-state').className='ux-badge '+(app.config?.estado==='abierta' ? 'ux-badge-success' : 'ux-badge-neutral');
        app.node('jornada-election').textContent=app.config?.eleccion || 'Elección de trabajo';
        app.node('jornada-clock').textContent=app.config ? 'Captura desde '+new Intl.DateTimeFormat('es-MX',{dateStyle:'medium',timeStyle:'short',timeZone:app.config.zona}).format(new Date(app.config.captura_desde.replace(' ','T')+'Z'))+' · '+app.config.zona : 'Configura la fecha, la zona horaria y el horario de captura.';
        app.node('jornada-status').textContent=app.enabled ? '' : 'Modo histórico: el módulo está deshabilitado. Puedes consultar los registros dentro de tus permisos.';
        const actions=app.node('jornada-state-actions');actions.replaceChildren();
        for(const [action,permission,title,expected] of [['abrir','jornada.abrir','Abrir Jornada Electoral','preparacion'],['cerrar','jornada.cerrar','Cerrar Jornada Electoral','abierta'],['reabrir','jornada.reabrir','Reabrir Jornada Electoral','cerrada']]) {
            if(app.enabled && can(permission) && app.config?.estado===expected) actions.append(app.button(title,async()=>{
                const reason=await feedback.reason(title+' de '+app.config.eleccion+'? Las versiones y el historial se conservan.',{title,confirm:title});if(!reason) return;
                await app.api('jornada/state/'+action,'POST',{version:app.config.version,motivo:reason});await refresh();app.notice('Jornada Electoral '+(action==='cerrar' ? 'cerrada' : 'abierta')+' correctamente.');
                if(app.node('jornada-configuration').hidden===false) await configuration();
            },true));
        }
        return capabilities;
    }
    app.list=app.pager(app.node('jornada-booth-list'),()=> 'jornada/booths?'+new URLSearchParams(app.filters),[
        ['Sección','seccion'],['Casilla',row=>row.nombre+' · '+row.tipo],['Municipio','municipio_nombre'],['Capturistas','capturistas'],['Estado',row=>label(row.estado)],['Propuestas pendientes','propuestas'],['Última captura UTC','ultima_captura']
    ],(cell,row)=>cell.append(app.button('Capturar / consultar',()=>openBooth(row.uuid))));
    async function filterBooths() {
        app.filters=safeFields(app.node('jornada-filters'));
        for(const [key,picker] of [['municipio',app.boothMunicipality],['seccion',app.boothSection],['usuario',app.boothAccount]])if(picker?.values())app.filters[key]=picker.values();
        await app.list.reset();
    }
    app.node('jornada-filters').addEventListener('submit',app.guard(filterBooths));
    app.node('jornada-apply-booth-filters').addEventListener('click',app.guard(filterBooths));
    app.node('jornada-summary-filter').addEventListener('submit',app.guard(()=>summary()));
    app.node('jornada-show-pending').addEventListener('click',app.guard(()=>showFiltered('sin_captura')));
    app.node('jornada-show-proposals').addEventListener('click',app.guard(()=>showFiltered('con_propuestas')));
    async function showFiltered(state) { app.filters={estado:state};app.node('jornada-filters').elements.estado.value=state;await app.activate(app.node('jornada-tab-booths'));await app.list.reset(); }
    async function summary() {
        const nonce=app.summaryNonce=(app.summaryNonce || 0)+1;
        const filters=safeFields(app.node('jornada-summary-filter'));if(app.summaryMunicipality)filters.municipio=app.summaryMunicipality.values();if(app.summarySection)filters.seccion=app.summarySection.values();
        const result=await app.api('jornada/summary?'+new URLSearchParams(Object.fromEntries(Object.entries(filters).filter(([,value])=>value!==''))));if(nonce!==app.summaryNonce)return;
        app.summary=result;app.summaryFilters=filters;
        app.node('jornada-summary-legend').textContent=result.leyenda;
        const progress=app.node('jornada-progress');progress.replaceChildren();
        for(const [key,title] of [['esperadas','Casillas esperadas'],['capturadas','Con captura'],['pendientes','Sin captura'],['preliminares','Vigentes preliminares'],['validadas','Vigentes validadas'],['pendientes_iniciales','Sin versión vigente'],['casillas_con_propuestas','Con propuestas pendientes'],['porcentaje_captura','Avance de captura (%)'],['porcentaje_validacion','Avance validado (%)']]) {
            const card=app.text('div','','card p-4');card.append(app.text('p',title,'ux-help'),app.text('strong',String(result.progreso[key])));progress.append(card);
        }
        progress.append(app.text('p','Última captura UTC: '+(result.progreso.ultima_actualizacion || 'Sin capturas'),'ux-help'));
        app.table(app.node('jornada-candidate-table'),[['Candidatura','nombre'],['Cargo','cargo'],['Votos derivados','votos'],['Porcentaje','porcentaje']],result.candidaturas);
        app.table(app.node('jornada-option-table'),[['Opción exacta','nombre'],['Tipo',row=>optionType(row.tipo)],['Votos crudos','votos'],['Porcentaje','porcentaje']],result.opciones);
        app.node('jornada-denominator').textContent='Casillas contabilizadas: '+result.casillas_contribuyentes+'. Denominador: '+result.denominador+' ('+({total_acta:'Todas las filas del acta',candidaturas:'Filas que suman a candidaturas',seleccionadas:'Filas seleccionadas explícitamente'})[result.criterio_denominador]+'). Total de acta: '+result.total_acta+'. Nulos: '+result.clasificaciones.nulo+'. Otros: '+result.clasificaciones.otro+'. Los totales por candidatura son derivados; no se suman otra vez al total crudo.';
        for(const chart of app.charts)chart.destroy();app.charts=[];
        const {u:Apex}=await import(ui.base+'/template/assets/apexcharts.esm-DPbJ6jlt.js');if(nonce!==app.summaryNonce)return;
        const chart=new Apex(app.node('jornada-candidate-chart'),{chart:{type:'bar',height:Math.max(220,70+result.candidaturas.length*28),toolbar:{show:false}},series:[{name:'Votos',data:result.candidaturas.map(row=>row.votos)}],xaxis:{categories:result.candidaturas.map(row=>row.nombre)},plotOptions:{bar:{horizontal:true}},colors:['#3b82f6'],noData:{text:'Configura candidaturas para visualizar la gráfica.'},dataLabels:{enabled:true},tooltip:{enabled:false},legend:{show:false}});
        const advance=new Apex(app.node('jornada-progress-chart'),{chart:{type:'bar',height:210,toolbar:{show:false}},series:[{name:'Casillas',data:[result.progreso.capturadas,result.progreso.validadas,result.progreso.pendientes]}],xaxis:{categories:['Con captura','Validadas','Sin captura']},colors:['#3b82f6'],dataLabels:{enabled:true},tooltip:{enabled:false},legend:{show:false}});
        app.charts=[chart,advance];await chart.render();await advance.render();
    }
    async function configuration() {
        const result=await app.api('jornada/configuration');app.catalogs=result.catalogs;
        const target=app.node('jornada-config-content');target.replaceChildren();
        if(can('jornada.configurar')) {
            const checklist=app.text('div','','ux-section');checklist.append(app.text('h6','Checklist antes de abrir'));
            for(const [key,title] of [['opciones','Opciones exactas activas del acta'],['casillas','Casillas asociadas'],['asignaciones','Capturistas con acceso'],['permisos','Permisos de captura vigentes']]) checklist.append(window.SicratUI.badge(title+': '+(result.checklist[key] ? 'Completo' : 'Pendiente'),result.checklist[key]));target.append(checklist);
            const config=result.configuration;
            if(result.locked)target.append(app.text('p','La configuración del acta está protegida porque existen capturas. Puedes consultar el acta, gestionar asignaciones y revisar versiones con los permisos correspondientes.','ux-help my-3'));
            if(app.enabled && !result.locked) app.form(target,'Fecha y política de Jornada Electoral',form=>{
                form.id='jornada-config-form';app.field(form,'fecha_eleccion','Fecha de la elección','date',config?.fecha_eleccion || '',true);app.field(form,'zona','Zona horaria IANA','text',config?.zona || 'America/Monterrey',true);app.field(form,'captura_local','Permitir captura desde (hora de la zona elegida)','datetime-local',config?.captura_local || '',true);
                app.field(form,'politica_inicial','Primera captura','select',config?.politica_inicial || 'validar',true,{validar:'Pendiente de validación',preliminar:'Publicar como preliminar interno'});app.field(form,'denominador','Denominador para porcentajes','select',config?.denominador || 'total_acta',true,{total_acta:'Todas las filas del acta',candidaturas:'Filas que suman a candidaturas',seleccionadas:'Filas seleccionadas explícitamente'});app.check(form,'foto_obligatoria','Exigir fotografía del acta',Boolean(Number(config?.foto_obligatoria)));
                form.append(app.text('p','La hora se almacena en UTC. La configuración del acta queda protegida cuando existen capturas. El cierre bloquea capturas y correcciones; las propuestas pendientes pueden ser revisadas por quien conserva el permiso de validación.','ux-full ux-help'));
            },async data=>{await app.api('jornada/configuration','PUT',{...data,version:config?.version || 1,foto_obligatoria:data.foto_obligatoria ? 1 : 0});await refresh();await configuration();app.notice('Configuración guardada correctamente.');});
            if(config) {
                for(const [type,title] of [['partidos','Partidos'],['candidaturas','Candidaturas'],['coaliciones','Coaliciones'],['opciones','Opciones exactas del acta']]) {
                    const box=app.text('div','','ux-section');box.append(app.text('h6',title));target.append(box);
                    app.table(box.appendChild(app.text('div')),[['Nombre','nombre'],['Activo',row=>Number(row.activo) ? 'Sí' : 'No'],...(type==='opciones' ? [['Orden','orden'],['Tipo',row=>optionType(row.tipo)]] : ['partidos','candidaturas'].includes(type) ? [['Imagen',row=>{if(!row.imagen_uuid || !can('archivos.ver'))return row.imagen_uuid ? 'Imagen privada' : 'Sin imagen';const image=document.createElement('img');image.src=ui.base+'/media/'+row.imagen_uuid+'?size=thumb';image.alt=row.nombre;image.width=60;return image;}]] : [])],app.catalogs[type],(cell,row)=>{if(app.enabled && !result.locked){cell.append(app.button('Editar',()=>catalogForm(type,box,row)));if(['partidos','candidaturas'].includes(type) && can('archivos.subir'))cell.append(app.button('Imagen',()=>catalogImage(type,row)));}});
                    if(app.enabled && !result.locked)box.append(app.button('Agregar '+title.toLowerCase(),()=>catalogForm(type,box)));
                }
                const preview=app.text('div','','ux-section');preview.id='jornada-acta-preview';preview.append(app.text('h6','Vista previa: filas exactas del acta en orden'));
                for(const row of app.catalogs.opciones.filter(row=>Number(row.activo)))preview.append(app.text('p',row.orden+'. '+row.nombre+' · '+row.tipo));target.append(preview);
                if(app.enabled && !result.locked) await associateForms(target);
            }
        }
        const assign=app.node('jornada-assign-content');assign.replaceChildren();
        if(app.enabled && result.configuration && can('jornada.asignar_capturistas')) {
            let users,booths;
            const form=app.form(assign,'Asignar o retirar capturistas',fields=>{fields.id='jornada-assign-form';app.field(fields,'activo','Decisión','select','1',true,{'1':'Asignar / reactivar','0':'Retirar asignación'});fields.append(app.text('p','Selecciona cuentas con acceso y permiso de captura. Una asignación no otorga permisos. Puedes seleccionar varias casillas y varios capturistas. Retirar una asignación conserva sus versiones.','ux-full ux-help'));},async data=>{if(data.activo==='0' && !await feedback.confirm('¿Retirar las asignaciones seleccionadas conservando las capturas?',{confirm:'Retirar asignaciones'}))return;await app.api('jornada/assignments','PUT',{usuarios:users.values(),casillas:booths.values(),activo:Number(data.activo)});await configuration();app.notice('Asignaciones actualizadas correctamente.');});
            users=await app.picker(form,'Capturistas por nombre o cuenta','jornada/accounts',true,[],true);booths=await app.picker(form,'Casillas por sección','jornada/booths?administrar=1',true,[],true);
            if(can('jornada.configurar') && can('notificaciones.administrar'))await notificationForm(assign);
        }
    }
    async function catalogForm(type,target,row={}) {
        target.querySelector('form')?.remove();let partyChoices=[];
        const form=app.form(target,row.uuid ? 'Editar '+row.nombre : 'Nuevo registro',fields=>{
            app.field(fields,'nombre','Nombre / etiqueta exacta','text',row.nombre || '',true).maxLength=180;app.field(fields,'activo','Estado','select',row.activo ?? 1,true,{'1':'Activo','0':'Inactivo'});
            if(type==='partidos'){app.field(fields,'siglas','Siglas','text',row.siglas || '',true).maxLength=40;app.field(fields,'color','Color opcional (#RRGGBB)','text',row.color || '').maxLength=7;}
            if(type==='candidaturas')app.field(fields,'cargo','Cargo','text',row.cargo || '',true).maxLength=120;
            if(['coaliciones','opciones'].includes(type))app.field(fields,'candidatura','Candidatura','select',reference('candidaturas',row.candidatura),false,{'':'Sin candidatura',...Object.fromEntries(app.catalogs.candidaturas.filter(item=>Number(item.activo)).map(item=>[item.uuid,item.nombre+' · '+item.cargo]))});
            if(type==='coaliciones') for(const party of app.catalogs.partidos.filter(item=>Number(item.activo))) {const control=app.check(fields,'party_'+party.uuid,party.nombre,(row.partidos || []).map(Number).includes(Number(party.id)));partyChoices.push([party.uuid,control]);}
            if(type==='opciones') {
                app.field(fields,'tipo','Tipo de fila','select',row.tipo || 'partido',true,{partido:'Partido',coalicion:'Coalición',combinacion:'Combinación exacta',independiente:'Independiente',nulos:'Nulos',no_registrados:'No registrados',otro:'Otro'});app.field(fields,'orden','Orden impreso','number',row.orden || '',true);
                for(const [key,catalog,title] of [['partido','partidos','Partido de referencia'],['coalicion','coaliciones','Coalición de referencia']])app.field(fields,key,title,'select',reference(catalog,row[key]),false,{'':'Sin referencia',...Object.fromEntries(app.catalogs[catalog].filter(item=>Number(item.activo)).map(item=>[item.uuid,item.nombre]))});
                app.check(fields,'suma_candidatura','Sumar esta fila a la candidatura seleccionada',Boolean(Number(row.suma_candidatura)));app.check(fields,'en_denominador','Incluir en denominador de filas seleccionadas',row.en_denominador===undefined || Boolean(Number(row.en_denominador)));app.field(fields,'clasificacion','Clasificación de la fila','select',row.clasificacion || 'valido',true,{valido:'Válido',nulo:'Nulo',otro:'Otro'});
            }
        },async data=>{if(type==='coaliciones')data.partidos=partyChoices.filter(([,control])=>control.checked).map(([uuid])=>uuid);if(type==='opciones'){data.suma_candidatura=data.suma_candidatura ? 1 : 0;data.en_denominador=data.en_denominador ? 1 : 0;}await app.api('jornada/catalogs/'+type+(row.uuid ? '/'+row.uuid : ''),row.uuid ? 'PUT' : 'POST',data);await configuration();app.notice('Catálogo guardado correctamente.');});
        form.querySelector('input')?.focus();
    }
    async function upload(file,entity,record) {
        const data=new FormData();data.append('file',file);data.append('entity',entity);data.append('record',record);data.append('process',String(app.me.process_id));
        const response=await fetch(ui.base+'/api/files',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:data});const result=await response.json();if(!response.ok){const error=new Error(result.message || 'No fue posible subir la fotografía.');error.status=response.status;throw error;}return result.data;
    }
    async function catalogImage(type,row) {
        const target=app.node('jornada-version-content');target.replaceChildren();app.node('jornada-version-title').textContent='Imagen de '+row.nombre;
        app.form(target,'Subir imagen privada',form=>{const file=app.field(form,'imagen','JPEG, PNG o WebP','file','',true);file.accept='image/jpeg,image/png,image/webp';},async(data,form)=>{const file=await upload(form.elements.imagen.files[0],'jornada_'+type,row.uuid);const values={nombre:row.nombre,activo:row.activo,[type==='partidos' ? 'logo' : 'foto']:file.uuid};if(type==='candidaturas')values.cargo=row.cargo;else {values.siglas=row.siglas;values.color=row.color;}await app.api('jornada/catalogs/'+type+'/'+row.uuid,'PUT',values);app.node('jornada-version-dialog').close();await configuration();app.notice('Imagen electoral guardada.');});app.node('jornada-version-dialog').showModal();
    }
    async function associateForms(target) {
        let section,physical;const types=Object.fromEntries((await app.api('polling-booth-types')).map(type=>[type.codigo,type.nombre]));
        const form=app.form(target,'Registrar y asociar casilla física',fields=>{app.field(fields,'nombre','Nombre de casilla','text','',true);app.field(fields,'tipo','Tipo de casilla','select','',true,types);app.field(fields,'ubicacion','Ubicación','text');app.field(fields,'domicilio','Domicilio','text');},async data=>{await app.api('jornada/booths','POST',{...data,seccion:section.values()});await configuration();app.notice('Casilla asociada correctamente.');});
        section=await app.picker(form,'Sección electoral','jornada/territories?type=seccion&catalogue=1',false,[],true);
        const existing=app.form(target,'Asociar una casilla del catálogo existente',()=>{},async()=>{await app.api('jornada/booths','POST',{fisica:physical.values()});await configuration();app.notice('Casilla existente asociada.');});physical=await app.picker(existing,'Casilla física por sección','jornada/physical-booths',false,[],true);
    }
    async function notificationForm(target) {
        let recipient;const form=app.form(target,'Resúmenes administrativos opcionales',fields=>{
            app.field(fields,'canal','Canal','select','email',true,{email:'Correo',whatsapp:'WhatsApp autorizado'});app.field(fields,'modo','Contenido','select','validado',true,{validado:'Validado interno',preliminar:'Preliminar interno'});app.field(fields,'frecuencia','Frecuencia mínima (minutos)','number','30',true);app.field(fields,'activo','Estado','select','1',true,{'1':'Habilitado','0':'Deshabilitado'});app.check(fields,'incluir_totales','Incluir totales agregados',true);app.check(fields,'incluir_avance','Incluir avance de captura y validación',true);app.check(fields,'incluir_estado','Incluir estado de Jornada',true);app.field(fields,'plantilla','Plantilla WhatsApp autorizada','text');app.field(fields,'idioma','Idioma WhatsApp','text','es_MX');fields.append(app.text('p','Sólo destinatarios administrativos con alcance de elección y un canal verificado con consentimiento. El resumen contiene progreso y totales; no incluye personas, preferencias ni listas de votantes.','ux-full ux-help'));
        },async data=>{await app.api('jornada/notifications','PUT',{...data,usuario:recipient.values(),incluir_totales:data.incluir_totales ? 1 : 0,incluir_avance:data.incluir_avance ? 1 : 0,incluir_estado:data.incluir_estado ? 1 : 0});app.notice('Aviso administrativo configurado.');});recipient=await app.picker(form,'Destinatario administrativo','jornada/accounts',false,[],true);
        const list=app.text('div');target.append(list);const pager=app.pager(list,'jornada/notifications',[['Destinatario','nombre'],['Canal','canal'],['Modo','modo'],['Minutos','frecuencia'],['Estado',row=>Number(row.activo) ? 'Activo' : 'Deshabilitado']]);await pager.load();
    }
    async function openBooth(uuid) {
        await refresh();const booth=await app.api('jornada/booths/'+uuid);app.currentBooth=booth;
        const target=app.node('jornada-booth-content');target.replaceChildren();app.node('jornada-booth-title').textContent='Sección '+booth.seccion+' · '+booth.nombre+' · '+booth.tipo;
        const dialog=app.node('jornada-booth-dialog');if(!dialog.open)dialog.showModal();
        app.description(target,{'Elección':app.config?.eleccion,'Capturas conservadas':booth.capturas,'Versión vigente':booth.vigente ? 'v'+booth.vigente.numero+' · '+label(booth.vigente.estado) : 'Sin versión vigente','Capturistas':booth.asignaciones.filter(row=>Number(row.activo)).map(row=>row.nombre).join(', ')});
        if(booth.capturas)target.append(app.text('p','Esta casilla ya tiene capturas. Toda captura adicional queda como propuesta pendiente de validación y conserva la versión vigente.','ux-help my-4'));
        if(booth.puede_capturar) await captureForm(target,booth,false);
        else target.append(app.text('p','La captura requiere Jornada abierta, horario iniciado, permiso y asignación activa a esta casilla (o autorización específica para capturar fuera de asignaciones).','ux-help my-4'));
        if(app.enabled && app.config?.estado==='abierta' && can('resultados.corregir'))target.append(app.button('Crear corrección con historial',()=>captureForm(target,booth,true)));
        const history=app.text('div','','ux-section');target.append(history);history.append(app.text('h6','Versiones y propuestas pendientes'));const list=app.text('div');history.append(list);
        const pager=app.pager(list,'jornada/booths/'+uuid+'/history',[['Versión','numero'],['Origen','origen'],['Estado',row=>label(row.estado)],['Capturista','capturista'],['Fecha UTC','fecha'],['Total','total']],(cell,row)=>cell.append(app.button('Ver / comparar',()=>version(row.uuid))));await pager.load();
    }
    async function captureForm(target,booth,correction) {
        target.querySelector('[data-capture-form]')?.remove();
        const key=window.SicratSecurity.uuid();let draft=null,photo=null,uploadedFile=null;
        const votes=new Map();const original=new Map((booth.vigente?.votos || []).map(row=>[row.uuid,row.votos]));
        const form=app.form(target,correction ? 'Nueva corrección' : 'Capturar acta',fields=>{
            fields.dataset.captureForm='1';fields.append(app.text('p','Introduce enteros no negativos en cada fila, respetando el orden del acta.','ux-full ux-help'));
            for(const option of booth.opciones) {const field=app.field(fields,'vote_'+option.uuid,option.nombre,'number',correction ? original.get(option.uuid) ?? 0 : 0,true);field.min='0';field.max='2147483647';field.step='1';field.inputMode='numeric';field.classList.add('text-lg');votes.set(option.uuid,field);}
            const image=app.field(fields,'imagen',app.config?.foto_obligatoria ? 'Fotografía del acta (obligatoria)' : 'Fotografía del acta (opcional)','file');image.accept='image/jpeg,image/png,image/webp';image.required=Boolean(Number(app.config?.foto_obligatoria)) && !(correction && booth.vigente?.foto_uuid);
            if(correction && booth.vigente?.foto_uuid){const reuse=app.check(fields,'reutilizar','Utilizar la fotografía de la versión vigente',true);reuse.addEventListener('change',()=>image.required=Boolean(Number(app.config?.foto_obligatoria)) && !reuse.checked);}
            app.field(fields,'notas','Observaciones','textarea').maxLength=2000;if(correction){app.field(fields,'motivo','Motivo obligatorio de la corrección','textarea','',true).maxLength=500;if(can('resultados.validar'))app.check(fields,'validar','Validar esta corrección explícitamente',false);}
            const total=app.text('p','Total del acta: 0','ux-full font-semibold');total.dataset.captureTotal='1';fields.append(total);
            const calculate=()=>{let count=0;for(const field of votes.values())if(/^\d+$/.test(field.value))count+=Number(field.value);total.textContent='Total del acta: '+count.toLocaleString('es-MX');};for(const field of votes.values())field.addEventListener('input',()=>{field.setCustomValidity(/^\d+$/.test(field.value) ? '' : 'Introduce un entero no negativo, sin decimales ni separadores.');calculate();});calculate();
        },async(data,fields)=>{
            const values={};for(const [uuid,field] of votes){if(!/^\d+$/.test(field.value)||Number(field.value)>2147483647){field.setCustomValidity('Introduce un entero no negativo sin decimales.');field.reportValidity();return;}values[uuid]=field.value;}
            const file=fields.elements.imagen.files[0];const retained=correction && data.reutilizar ? booth.vigente?.foto_uuid : null;
            if(Number(app.config.foto_obligatoria) && !file && !retained)throw new Error('Se requiere fotografía del acta.');
            const count=Object.values(values).reduce((total,value)=>total+Number(value),0);
            if(!await feedback.confirm(app.config.eleccion+' · sección '+booth.seccion+' · '+booth.nombre+'. Total: '+count+'. Fotografía: '+(file || retained ? 'sí' : 'no')+'. '+(correction ? 'Se creará una nueva versión con motivo e historial.' : booth.capturas ? 'Se enviará como propuesta pendiente; la versión vigente se conserva.' : 'Se aplicará la política de primera captura configurada.'),{title:correction ? 'Confirmar corrección' : 'Confirmar captura',confirm:'Registrar versión'}))return;
            if(!correction || file){draft ??= (await app.api('jornada/drafts','POST',{casilla:booth.uuid,clave:key,correccion:correction ? 1 : 0})).uuid;}
            if(file && file!==uploadedFile){photo=(await upload(file,'jornada_borradores',draft)).uuid;uploadedFile=file;}
            const result=await app.api('jornada/'+(correction ? 'corrections' : 'captures'),'POST',{casilla:booth.uuid,clave:key,borrador:draft,votos:values,foto:file ? photo : retained,notas:data.notas,motivo:data.motivo || '',validar:data.validar ? 1 : 0,actual_esperada:booth.actual_esperada});
            await openBooth(booth.uuid);app.notice('Versión '+result.numero+' registrada: '+label(result.estado)+'.',result.estado==='pendiente' ? 'info' : 'success');
        },correction ? 'Registrar corrección' : 'Registrar captura');
        form.scrollIntoView({block:'nearest'});
    }
    async function version(uuid) {
        const row=await app.api('jornada/versions/'+uuid);const booth=await app.api('jornada/booths/'+row.casilla_uuid);
        const target=app.node('jornada-version-content');target.replaceChildren();app.node('jornada-version-title').textContent='Versión '+row.numero+' · '+label(row.estado);
        app.description(target,{'Capturista':row.capturista,'Fecha UTC':row.fecha,'Origen':row.origen,'Vigente':row.vigente ? 'Sí' : 'No','Motivo':row.motivo,'Observaciones':row.notas,'Total':row.total});
        if(!row.visible)target.append(app.text('p','No tienes permiso para consultar los votos ni la fotografía de esta versión.','ux-help'));
        else {
            const current=new Map((booth.vigente?.votos || []).map(vote=>[vote.uuid,Number(vote.votos)]));
            const comparison=row.votos.map(vote=>({...vote,vigente:current.get(vote.uuid) ?? 0,diferencia:Number(vote.votos)-(current.get(vote.uuid) ?? 0)}));
            const table=app.text('div');target.append(table);app.table(table,[['Opción exacta','nombre'],['Versión revisada','votos'],['Versión vigente','vigente'],['Diferencia','diferencia']],comparison);
            if(row.foto_uuid && can('archivos.ver')){const link=app.text('a','Abrir acta privada','btn');link.href=ui.base+'/media/'+row.foto_uuid;link.target='_blank';link.rel='noopener';const image=document.createElement('img');image.src=ui.base+'/media/'+row.foto_uuid+'?size=thumb';image.alt='Acta de sección '+row.seccion+' · '+row.casilla;image.width=200;target.append(image,link);}
            for(const decision of row.decisiones)app.description(target.appendChild(app.text('div','', 'ux-section')),{'Decisión':decision.accion,'Revisor':decision.revisor,'Fecha UTC':decision.fecha,'Motivo':decision.motivo,'Cambios':decision.cambios});
            if(app.enabled && can('resultados.validar') && ['pendiente','preliminar'].includes(row.estado))app.form(target,'Resolver versión revisada',fields=>{app.field(fields,'aprobar','Decisión','select','1',true,row.estado==='pendiente' ? {'1':'Validar y publicar como vigente','0':'Rechazar propuesta'} : {'1':'Validar preliminar vigente'});app.field(fields,'motivo','Motivo de la decisión','textarea','',true).maxLength=500;},async data=>{
                if(!await feedback.confirm('¿'+(data.aprobar==='1' ? 'Validar' : 'Rechazar')+' la versión '+row.numero+' después de revisar las diferencias con la vigente?',{confirm:data.aprobar==='1' ? 'Validar versión' : 'Rechazar propuesta'}))return;
                await app.api('jornada/versions/'+uuid,'PUT',{...data,actual_esperada:booth.actual_esperada});app.node('jornada-version-dialog').close();await openBooth(booth.uuid);app.notice('Decisión registrada correctamente.');
            },'Confirmar decisión');
        }
        const dialog=app.node('jornada-version-dialog');if(!dialog.open)dialog.showModal();
    }
    function reports() {
        const target=app.node('jornada-export-content');target.replaceChildren();
        if(!can('resultados.exportar') || !can('exportaciones.generar')) {target.append(app.text('p','No tienes permiso para generar informes de Jornada Electoral.','ux-help'));return;}
        app.form(target,'Programar informe',form=>{form.id='jornada-export-form';app.field(form,'type','Informe','select','jornada_avance',true,{jornada_avance:'Avance de Jornada',jornada_candidaturas:'Resultados por candidatura',jornada_opciones:'Votos crudos por opción exacta',jornada_secciones:'Resultados por sección',jornada_casillas:'Resultados por casilla',jornada_pendientes:'Casillas pendientes de captura',jornada_validacion:'Actas pendientes de validación',jornada_historial:'Historial de versiones',jornada_asignaciones:'Asignaciones de capturistas'});app.field(form,'format','Formato','select','xlsx',true,{xlsx:'Excel',pdf:'PDF',csv:'CSV'});app.field(form,'modo','Modo','select','validado',true,can('resultados.ver_preliminares') ? {validado:'Validado interno',preliminar:'Preliminar interno'} : {validado:'Validado interno'});},async data=>{await app.api('exports','POST',{type:data.type,format:data.format,filters:{...(data.type==='jornada_casillas' || data.type==='jornada_pendientes' || data.type==='jornada_historial' ? app.filters : app.summaryFilters),modo:data.modo}});app.notice('La exportación fue programada y puedes continuar trabajando.','info');},'Programar exportación');
    }
    app.open=openBooth;app.loadSummary=summary;app.configure=configuration;
    (async()=>{
        app.me=(await ui.request('auth/me')).data;await refresh();
        app.node('jornada-app').hidden=false;
        for(const [panel,allowed] of [['dashboard',can('resultados.ver')],['configuration',can('jornada.configurar') || can('jornada.asignar_capturistas')],['reports',can('resultados.exportar')],['booths',can('resultados.ver') || can('resultados.capturar') || can('resultados.validar') || can('jornada.asignar_capturistas')]])app.node('jornada-tab-'+panel).hidden=!allowed;
        app.node('jornada-summary-filter').elements.modo.querySelector('option[value=preliminar]').disabled=!can('resultados.ver_preliminares');
        app.activate=window.SicratUI.tabs(app.node('jornada-tabs'),async button=>{const panel=button.dataset.panel;try{if(panel==='booths')await app.list.load();if(panel==='dashboard')await summary();if(panel==='configuration')await configuration();if(panel==='reports')reports();}catch(error){feedback.error(error);}});
        const first=can('resultados.capturar') && !can('jornada.configurar') ? app.node('jornada-tab-booths') : can('resultados.ver') ? app.node('jornada-tab-dashboard') : app.node('jornada-tabs').querySelector('button:not([hidden])');
        if(can('resultados.capturar') && !can('jornada.configurar')){app.node('jornada-filters').elements.mis.checked=true;app.filters={mis:'1'};}
        if(!app.node('jornada-tab-booths').hidden) {
            const target=app.node('jornada-booth-territories');
            app.boothMunicipality=await app.picker(target,'Municipio','jornada/territories?type=municipio');
            app.boothSection=await app.picker(target,'Sección','jornada/territories?type=seccion');
            app.boothAccount=await app.picker(target,'Capturista asignado','jornada/accounts?assigned=1');
        }
        if(can('resultados.ver')) {
            app.summaryMunicipality=await app.picker(app.node('jornada-summary-territories'),'Municipio','jornada/territories?type=municipio');
            app.summarySection=await app.picker(app.node('jornada-summary-territories'),'Sección','jornada/territories?type=seccion');
        }
        ui.closeButtons();await app.activate(first);const requestedBooth=new URL(location.href).searchParams.get('casilla');if(requestedBooth)await openBooth(requestedBooth);app.initialized=true;
    })().catch(error=>{app.node('jornada-app').hidden=true;app.node('jornada-status').textContent=error.message;feedback.error(error);});
}
