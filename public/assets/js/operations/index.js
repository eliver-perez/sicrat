'use strict';

function InitializeValues(url) {
	window.homeURL=(url || '').replace(/\/$/,'');
	window.SicratPhase3.base=(url || '').replace(/\/$/,''); const app=window.SicratOperations;app.entity=app.node('operations-root').dataset.entity;app.definition=app.handlers[app.entity];app.filters={};
	window.SicratPhase3.closeButtons();document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('close',()=>dialog.querySelector('[data-operation-message]')?.remove()));
	if(!app.node('operation-filter'))return;
	app.create=async parent=> {
		const target=app.node('operation-create-fields');target.replaceChildren();app.node('operation-create-form').reset();app.node('operation-create-title').textContent=app.entity==='conversaciones' ? ({direct:'Nueva conversación',group:'Nuevo grupo',announcement:'Nuevo anuncio'}[app.chatCreateKind || 'direct']) : parent ? 'Distribuir meta' : app.definition.newLabel;app.createDTO=null;
		const dialog=app.node('operation-create-dialog');if(!dialog.open)dialog.showModal();
		if(app.entity==='conversaciones')app.node('operation-create-form').querySelector('button:not([type]),button[type=submit]').textContent=app.chatCreateKind==='direct' ? 'Abrir conversación' : app.chatCreateKind==='announcement' ? 'Crear anuncio' : 'Crear grupo';
		app.createDTO=await window.SicratOperationForms[app.definition.create](app,target,parent || null);app.editingSurvey=null;
	};
	app.editSurvey=async row=> {
		const target=app.node('operation-create-fields');target.replaceChildren();app.node('operation-create-title').textContent='Editar borrador de encuesta';app.node('operation-create-dialog').showModal();app.createDTO=await window.SicratOperationForms.survey(app,target,row);app.editingSurvey=row;
	};
	app.node('operation-create-form').addEventListener('submit',app.guard(async event=>{
		if(!app.createDTO)throw new Error('Espera a que termine de cargar el formulario.');const data=app.createDTO(event.currentTarget);let result;
		if(app.editingSurvey){await app.api('operations/encuestas/'+app.editingSurvey.uuid,'PUT',{...data,version:app.editingSurvey.version});result={uuid:app.editingSurvey.uuid};}
		else result=await app.api('operations/'+app.entity,'POST',data);
		app.node('operation-create-dialog').close();await app.list.reset();if(result.uuid)await app.open(result.uuid);app.notice(app.entity==='conversaciones' ? app.chatCreateKind==='direct' ? 'Conversación abierta.' : app.chatCreateKind==='announcement' ? 'Anuncio creado.' : 'Grupo creado.' : app.editingSurvey ? 'Borrador guardado.' : 'Registro guardado.');
	}));
	app.node('operation-new').addEventListener('click',app.guard(()=>{if(app.entity==='conversaciones'){app.chatNewMenu.open=!app.chatNewMenu.open;return;}return app.create();}));
	app.node('operation-filter').addEventListener('submit',app.guard(async event=>{app.filters=app.values(event.currentTarget);await app.list.reset();if(app.entity==='transporte')await transportSummary();}));
	app.node('operation-refresh').addEventListener('click',app.guard(async()=>{await app.list.load();if(app.entity==='conversaciones')await app.messagePager?.load();}));
	app.node('operation-export').addEventListener('click',app.guard(()=>app.exportDialog()));
	app.node('operation-settings').addEventListener('click',app.guard(async()=> {
		const target=app.node('operation-settings-content');target.replaceChildren();app.node('operation-settings-title').textContent=app.entity==='eventos' ? 'Tipos de evento' : 'Política de comunicación';
		if(app.entity==='eventos') {
			const list=app.text('div');target.append(list);const pager=app.pager(list,'operations/event-types',[['Tipo','nombre']]);await pager.load();
			app.form(target,'Agregar tipo configurable',form=>app.field(form,'name','Nombre del tipo','text','',true),async data=>{await app.api('operations/event-types','POST',data);await pager.reset();app.notice('Tipo de evento creado.');});
		}else if(['peticiones','incidencias'].includes(app.entity)) {
			app.node('operation-settings-title').textContent='Categorías de '+app.definition.title.toLowerCase();let editing=null;let form;
			const list=app.text('div');target.append(list);const pager=app.pager(list,'operations/case-categories/'+app.entity,[['Categoría','nombre'],['Estado',row=>Number(row.activo) ? 'Activa' : 'Inactiva']],(cell,row)=>cell.append(app.button('Editar',()=>{editing=row.uuid;form.elements.name.value=row.nombre;form.elements.active.value=String(row.activo);form.elements.name.focus();})));
			form=app.form(target,'Agregar o editar categoría',fields=>{app.field(fields,'name','Nombre','text','',true).maxLength=100;app.field(fields,'active','Estado','select','1',true,{'1':'Activa','0':'Inactiva'});},async data=>{await app.api('operations/case-categories/'+app.entity+(editing ? '/'+editing : ''),editing ? 'PUT' : 'POST',data);editing=null;form.reset();await pager.reset();app.notice('Categoría guardada correctamente.');});await pager.load();
		}else {
			const policy=await app.api('operations/message-policy');app.form(target,'Contacto directo en esta elección',form=>app.field(form,'policy','Política','select',policy.policy,true,{coordinacion:'Línea de coordinación: superiores y equipo propio',equipo:'Equipo o ámbito territorial',todos:'Todas las cuentas autorizadas de la elección'}),async data=>{await app.api('operations/message-policy','PUT',data);app.notice('Política de comunicación guardada.');});
			if(app.can('notificaciones.administrar') && app.can('mensajes.enviar')) {
				let person=null;const form=app.form(target,'Comunicación externa autorizada',fields=>{fields.append(app.text('p','Sólo se encola con canal verificado, consentimiento y configuración habilitada. No crea una conversación interna para contactos sin cuenta.','ux-full op-small-note'));app.field(fields,'channel','Canal','select','email',true,{email:'Correo',whatsapp:'WhatsApp con plantilla autorizada'});app.field(fields,'title','Asunto del correo','text');app.field(fields,'summary','Mensaje operativo','textarea');app.field(fields,'template','Plantilla autorizada de WhatsApp','text');app.field(fields,'language','Idioma de plantilla','text','es_MX');},async data=>{await app.api('operations/outgoing','POST',{person:person.values(),channel:data.channel,idempotency:window.SicratSecurity.uuid(),template:data.template || null,content:data.channel==='email' ? {title:data.title,summary:data.summary} : {language:data.language,parameters:[]}});app.notice('Comunicación autorizada en cola.');},'Solicitar envío');person=await app.personPicker(form,'Contacto autorizado','enviar',false,[],true,'conversaciones',false);
			}
		}app.node('operation-settings-dialog').showModal();
	}));
	async function transportSummary() {
		const result=await app.api('operations/transport-summary?'+new URLSearchParams(app.filters));const target=app.node('operation-summary');target.replaceChildren();target.hidden=false;
		if(!result.totals.length || !result.totals.some(row=>Number(row.cantidad))){target.append(app.text('p','Aún no hay necesidades de transporte registradas para esta elección.','card p-5 ux-help'));return;}
		result.totals.forEach(row=>{const card=app.text('div','','card p-5');card.append(app.text('h6',({no_definido:'Personas con necesidad de transporte por definir',requiere:'Personas que requieren transporte',propio:'Personas con transporte propio'})[row.estado] || app.label(row.estado)),app.text('p',String(row.cantidad),'text-lg font-semibold'));if(row.estado==='requiere')card.append(app.text('p',row.coordinadas+' coordinadas · '+(Number(row.cantidad)-Number(row.coordinadas))+' pendientes','op-small-note'));target.append(card);});
	}
	async function initialize() {
		app.me=await app.api('auth/me');app.modules=(await app.api('operations/capabilities')).modules;app.node('operation-help').textContent=app.definition.help;
		const canCreate=app.entity==='conversaciones' ? ['mensajes.enviar','mensajes.crear_grupos','mensajes.crear_grupos_estructura'].some(permission=>app.can(permission)) : !!app.definition.create && app.can(app.definition.permission+'.crear');
		app.node('operation-new').hidden=!canCreate;app.node('operation-new').textContent=app.definition.newLabel || 'Nuevo registro';
		app.node('operation-settings').hidden=!(app.entity==='eventos' && app.can('eventos.administrar')) && !(app.entity==='conversaciones' && app.can('mensajes.administrar_grupos')) && !(['peticiones','incidencias'].includes(app.entity) && app.can(app.entity+'.categorias'));
		app.node('operation-export').hidden=!app.can('exportaciones.generar') || ['conversaciones','encuestas'].includes(app.entity);
		const state=app.node('operation-filter').elements.state;app.definition.states.forEach(value=>window.SicratPhase3.option(state,value,app.label(value)));app.node('operation-state-filter').hidden=!app.definition.states.length;
		if(app.entity==='conversaciones')window.SicratMessaging.layout(app);
		const columns=app.entity==='transporte' ? [['Persona','titulo'],['Necesidad',row=>app.label(row.estado)],['Coordinación','coordinador'],['Proveedor','proveedor'],['Confirmación voluntaria',row=>row.confirmado_en || 'Sin registro voluntario']] : [['Registro',row=>row.folio ? row.folio+' · '+row.titulo : row.titulo],['Estado',row=>window.SicratUI.badge(app.label(row.estado),['completada','cerrada','publicada','finalizado'].includes(row.estado))],['Fecha','fecha']];
		app.list=app.entity==='conversaciones' ? window.SicratChatHistory.conversations(app,app.node('operation-list')) : app.pager(app.node('operation-list'),()=> 'operations/'+app.entity+'?'+new URLSearchParams(app.filters),columns,(cell,row)=>cell.append(app.button(app.entity==='transporte' ? 'Coordinar' : 'Ver detalle',()=>app.open(row.uuid))));await app.list.load();
		if(app.entity==='transporte')await transportSummary();
		const params=new URLSearchParams(location.search);
		if(params.get('record'))await app.open(params.get('record'));
		if(app.entity==='eventos' && params.get('event')) { const event=await app.api('operations/eventos/'+params.get('event'));if(Number(event.proceso)!==Number(app.me.process_id))app.notice('Selecciona la elección del evento en el encabezado para registrar asistencia.');else{await app.open(params.get('event'));app.notice('QR reconocido. Revisa al participante y registra la asistencia mediante la acción autorizada.');} }
	}
	app.guard(initialize)();
}
