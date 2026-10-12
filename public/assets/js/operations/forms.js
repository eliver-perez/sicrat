'use strict';

window.SicratOperationForms = {
	day() { const date=new Date();return [date.getFullYear(),String(date.getMonth()+1).padStart(2,'0'),String(date.getDate()).padStart(2,'0')].join('-'); },
	now() { const date=new Date();return this.day()+'T'+String(date.getHours()).padStart(2,'0')+':'+String(date.getMinutes()).padStart(2,'0'); },
	async interaction(app,target) {
		const person=await app.personPicker(target,'Persona','crear',false,[],true);
		app.field(target,'type','Tipo de interacción','select','VISITA',true,{VISITA:'Visita',LLAMADA:'Llamada',WHATSAPP:'WhatsApp',MENSAJE:'Correo o mensaje',EVENTO:'Reunión',OTRO:'Otro'});
		app.field(target,'date','Cuándo se realizó','datetime-local',this.now(),true); app.field(target,'result','Resultado','text','',true);app.field(target,'notes','Notas y contexto','textarea');
		const capabilities=await app.api('operations/capabilities');
		const elections=await app.picker(target,'Elecciones relacionadas','operations/interaction-elections',true,capabilities.election ? [{uuid:capabilities.election.uuid,label:capabilities.election.nombre}] : []);
		let task=null,item=null,complete=null;
		if (app.can('tareas.ver') && app.moduleEnabled('tareas_metas')) {
			const related=app.section(target,'Tarea relacionada','Seleccionar una tarea no la completa. Un intento sin contacto conserva su estado.');
			task=await app.picker(related,'Tarea','operations/tareas'); item=app.field(related,'task_item','Asignación o subtarea','select','',false,{'':'Selecciona una tarea primero'});
			complete=app.check(related,'complete_task','Marcar la asignación relacionada como completada');
			complete.closest('label').hidden=!app.can('tareas.completar');complete.disabled=!app.can('tareas.completar');
			task.select.addEventListener('change',app.guard(async()=>{item.replaceChildren();window.SicratPhase3.option(item,'','Selecciona la asignación');if(!task.values())return;const result=await app.api('operations/tareas/'+task.values()+'/items?limit=100');result.rows.forEach(row=>window.SicratPhase3.option(item,row.uuid,(row.persona_nombre || row.titulo || 'Asignación')+' · '+app.label(row.estado)));}));
		}
		return form=>({...app.values(form),person:person.values(),processes:elections.values(),task:task?.values() || '',task_item:item?.value || '',complete_task:complete?.checked && !complete.disabled ? 1 : 0});
	},
	questions(app,target,definitions = []) {
		const container=app.text('div','','ux-full'); target.append(container); const editors=[]; let sequence=0;
		const add=question=> {
			const box=app.text('fieldset','','op-question op-form-fields'); const key=question?.key || 'q'+(++sequence); const heading=app.text('legend','Pregunta '+(editors.length+1)); box.append(heading);
			const title=app.field(box,'question_title','Pregunta','text',question?.title || '',true);title.maxLength=500;
			const type=app.field(box,'question_type','Tipo','select',question?.type || 'TEXTO',true,{TEXTO:'Texto breve',TEXTO_LARGO:'Texto largo',NUMERO:'Número',SI_NO:'Sí / No',SELECCION_UNICA:'Una opción',SELECCION_MULTIPLE:'Varias opciones',ESCALA:'Escala',FECHA:'Fecha'});
			const required=app.check(box,'question_required','Respuesta obligatoria',!!Number(question?.required));
			const options=app.field(box,'question_options','Opciones: una por línea','textarea',(question?.options || []).map(option=>option.label).join('\n'));
			const minimum=app.field(box,'question_min','Valor mínimo','number',question?.config?.min ?? 1); const maximum=app.field(box,'question_max','Valor máximo','number',question?.config?.max ?? 5);
			const when=app.field(box,'condition_question','Mostrar cuando una pregunta anterior coincida','select',question?.config?.when?.question || '',false,{'':'Siempre'});const originalEquals=question?.config?.when?.equals;const equals=app.field(box,'condition_value','Respuesta que debe coincidir','text',typeof originalEquals==='boolean' ? originalEquals ? 'Sí' : 'No' : originalEquals ?? '');
			const editor={key,box,title,type,required,options,minimum,maximum,when,equals,original:question}; editors.push(editor);
			function updateTypes() { const choices=['SELECCION_UNICA','SELECCION_MULTIPLE'].includes(type.value);options.closest('label').hidden=!choices;options.disabled=!choices;const numeric=['NUMERO','ESCALA'].includes(type.value);minimum.closest('label').hidden=!numeric;maximum.closest('label').hidden=!numeric;minimum.disabled=maximum.disabled=!numeric;equals.closest('label').hidden=!when.value;equals.disabled=!when.value; }
			function conditions() { editors.forEach((item,index)=> { const selected=item.when.value; item.when.replaceChildren();window.SicratPhase3.option(item.when,'','Siempre');editors.slice(0,index).forEach((previous,position)=>window.SicratPhase3.option(item.when,previous.key,previous.title.value || 'Pregunta '+(position+1),previous.key===selected));item.equals.closest('label').hidden=!item.when.value;item.equals.disabled=!item.when.value; }); }
			type.addEventListener('change',updateTypes);when.addEventListener('change',updateTypes);title.addEventListener('change',conditions);
			box.append(app.button('Quitar pregunta',()=>{box.remove();editors.splice(editors.indexOf(editor),1);conditions();}));container.append(box); conditions(); updateTypes();
		};
		definitions.forEach(question=>{sequence++;add(question);}); if (!definitions.length) add(null);
		target.append(app.button('Agregar pregunta',()=>{if(editors.length>=100)throw new Error('Máximo cien preguntas.');add(null);}));
		return ()=>editors.map(editor=>{
			const config={}; if (['NUMERO','ESCALA'].includes(editor.type.value)) { config.min=Number(editor.minimum.value);config.max=Number(editor.maximum.value); }
			if (editor.when.value) { const previous=editors.find(item=>item.key===editor.when.value);let expected=editor.equals.value;if(previous.type.value==='SI_NO' && ['sí','si','no'].includes(expected.toLowerCase()))expected=expected.toLowerCase()==='no' ? 0 : 1;const choices=previous.options.value.split('\n').map(value=>value.trim()).filter(Boolean);const position=choices.indexOf(String(expected));config.when={question:editor.when.value,equals:position>=0 ? (previous.original?.options[position]?.value || String(position+1)) : expected}; }
			const options=['SELECCION_UNICA','SELECCION_MULTIPLE'].includes(editor.type.value) ? editor.options.value.split('\n').map(value=>value.trim()).filter(Boolean).map((label,index)=>({label,value:editor.original?.options[index]?.value || String(index+1)})) : [];
			return {key:editor.key,title:editor.title.value,type:editor.type.value,required:editor.required.checked ? 1 : 0,config,options};
		});
	},
	async survey(app,target,row = null) {
		app.field(target,'title','Nombre de la encuesta','text',row?.encuesta || '',true); app.field(target,'description','Descripción','textarea',row?.descripcion || '').maxLength=500;
		app.field(target,'start','Inicio de aplicación','datetime-local',row?.inicio?.replace(' ','T') || '');app.field(target,'end','Fin de aplicación','datetime-local',row?.fin?.replace(' ','T') || '');
		const frequency=app.field(target,'frequency','Frecuencia por persona','select',row?.frecuencia || 'unica',true,{unica:'Una sola vez',multiple:'Varias veces con intervalo'});const interval=app.field(target,'interval','Cada cuántas unidades','number',row?.intervalo || 1);interval.min='1';interval.max='1000';
		const unit=app.field(target,'unit','Unidad del intervalo','select',row?.unidad || 'dias',false,{horas:'Horas',dias:'Días',semanas:'Semanas',meses:'Meses'});
		const toggle=()=>{interval.disabled=unit.disabled=frequency.value!=='multiple';};frequency.addEventListener('change',toggle);toggle();
		const questions=this.questions(app,app.section(target,'Preguntas','Las respuestas conservarán esta versión. Las condiciones usan una pregunta anterior; para opciones puedes escribir su etiqueta.'),row?.questions || []);
		return form=>({...app.values(form),questions:questions()});
	},
	async task(app,target) {
		app.field(target,'title','Título de la tarea','text','',true);app.field(target,'description','Descripción','textarea');
		const mode=app.field(target,'mode','Modalidad','select','individual',true,{individual:'Asignación individual a varias personas',colaborativa:'Tarea colaborativa con subtareas'});
		app.field(target,'priority','Prioridad','select','NORMAL',true,{BAJA:'Baja',NORMAL:'Normal',ALTA:'Alta',URGENTE:'Urgente'});app.field(target,'start','Fecha de inicio','datetime-local',this.now(),true);app.field(target,'deadline','Fecha límite','datetime-local');
		const assignments=await app.personPicker(target,'Personas asignadas','crear',true); const subtaskSection=app.section(target,'Subtareas','Cada subtarea conserva su propio estado.');const subtasks=[];
		const add=async()=>{const box=app.text('div','','op-question op-form-fields ux-full');subtaskSection.append(box);const title=app.field(box,'subtask_title','Título de subtarea','text','',true);const person=await app.personPicker(box,'Responsable de subtarea','crear');subtasks.push({box,title,person});box.append(app.button('Quitar subtarea',()=>{box.remove();subtasks.splice(subtasks.findIndex(item=>item.box===box),1);}));};
		subtaskSection.append(app.button('Agregar subtarea',async()=>{if(subtasks.length>=100)throw new Error('Máximo cien subtareas.');await add();}));
		const toggle=()=>{assignments.box.hidden=mode.value!=='individual';assignments.select.disabled=mode.value!=='individual';subtaskSection.parentElement.hidden=mode.value!=='colaborativa';subtasks.forEach(item=>item.title.disabled=mode.value!=='colaborativa');};mode.addEventListener('change',app.guard(async()=>{if(mode.value==='colaborativa' && !subtasks.length)await add();toggle();}));toggle();
		const validation=app.check(target,'validation','Requiere validación para considerarse completada');app.check(target,'allow_pdf','Permitir PDF administrativo como evidencia');
		const approver=await app.picker(target,'Aprobador opcional','operations/tareas/approvers?action=crear');
		const recurrence=app.section(target,'Recurrencia opcional','Se generan nuevas ocurrencias mediante el scheduler y la cola comunes.');const recurrent=app.check(recurrence,'recurrent','Repetir esta tarea');app.field(recurrence,'recurrence_frequency','Frecuencia','select','semanal',false,{diario:'Diaria',semanal:'Semanal',mensual:'Mensual'});const every=app.field(recurrence,'recurrence_every','Cada cuántos periodos','number','1');every.min='1';every.max='31';const until=app.field(recurrence,'recurrence_until','Repetir hasta','date');
		recurrent.addEventListener('change',()=>until.required=recurrent.checked);
		const entities={'':'Sin relación',personas:'Persona'};['eventos','peticiones','incidencias','interacciones'].forEach(entity=>{const module={eventos:'eventos',peticiones:'peticiones_incidencias',incidencias:'peticiones_incidencias',interacciones:'interacciones'}[entity];if(app.can(entity+'.ver') && app.moduleEnabled(module))entities[entity]=app.label(entity);});
		const relatedType=app.field(target,'related_entity','Registro relacionado opcional','select','',false,entities);const relatedBox=app.text('div','','ux-full');target.append(relatedBox);let related=null;
		relatedType.addEventListener('change',app.guard(async()=>{relatedBox.replaceChildren();related=null;if(!relatedType.value)return;related=relatedType.value==='personas' ? await app.personPicker(relatedBox,'Persona relacionada','crear') : await app.picker(relatedBox,'Registro relacionado','operations/'+relatedType.value);}));
		return form=>{const data=app.values(form);return {...data,validation:validation.checked ? 1 : 0,approver:approver.values(),allow_pdf:form.elements.allow_pdf.checked ? 1 : 0,related_entity:relatedType.value,related_record:related?.values() || '',assignees:mode.value==='individual' ? assignments.values() : [],subtasks:mode.value==='colaborativa' ? subtasks.map(item=>({title:item.title.value,person:item.person.values()})) : [],recurrence:recurrent.checked ? {frequency:data.recurrence_frequency,every:Number(data.recurrence_every),until:until.value} : null};};
	},
	async goal(app,target,row = null) {
		app.field(target,'title','Nombre de la meta','text',row?.titulo || '',true);const origin=app.field(target,'origin','Origen del avance','select',row?.origen || 'manual',true,{manual:'Captura manual',automatica:'Datos reales del sistema'});app.field(target,'mode','Responsabilidad','select','individual',true,{individual:'Individual',equipo:'Equipo a cargo',general:'General con distribución'});
		const responsible=await app.personPicker(target,'Responsable','crear');const quantity=app.field(target,'quantity','Objetivo','number','',true);quantity.min='1';
		app.field(target,'start','Inicio del periodo','date',row?.fecha_inicio || this.day(),true);app.field(target,'end','Fin del periodo','date',row?.fecha_fin || '',true);
		const manual=app.field(target,'manual_indicator','Unidad del indicador manual','text',row?.indicador || 'Gestiones',true);manual.maxLength=40;
		const indicators=await app.api('operations/indicators');const automatic=app.field(target,'automatic_indicator','Indicador automático','select',row?.indicador || indicators[0]?.code || '',true,Object.fromEntries(indicators.map(item=>[item.code,item.label])));
		const toggle=()=>{manual.closest('label').hidden=origin.value!=='manual';manual.disabled=origin.value!=='manual';automatic.closest('label').hidden=origin.value!=='automatica';automatic.disabled=origin.value!=='automatica';};origin.addEventListener('change',toggle);toggle();
		const parent=await app.picker(target,'Meta padre opcional','operations/metas',false,row ? [{uuid:row.uuid,label:row.titulo}] : []);
		return form=>({...app.values(form),responsible:responsible.values(),parent:parent.values(),indicator:origin.value==='manual' ? manual.value : automatic.value});
	},
	async event(app,target) {
		app.field(target,'title','Nombre del evento o actividad','text','',true);const type=await app.picker(target,'Tipo de evento','operations/event-types',false,[],true);app.field(target,'description','Descripción','textarea');app.field(target,'start','Fecha y hora de inicio','datetime-local',this.now(),true);app.field(target,'end','Fin','datetime-local');app.field(target,'place','Lugar','text');
		const mode=app.field(target,'attendance','Seguimiento de participantes','select','individual',true,{individual:'Personas o invitados individuales',agregado:'Sólo conteo agregado',ninguno:'Sin control de participantes'});const external=app.check(target,'external','Permitir invitados externos');const qr=app.check(target,'qr','Habilitar QR opcional de asistencia');
		const toggle=()=>{external.disabled=qr.disabled=mode.value!=='individual';};mode.addEventListener('change',toggle);toggle();
		const responsibles=await app.personPicker(target,'Responsables del evento','crear',true);app.field(target,'notes','Notas','textarea');
		return form=>({...app.values(form),type:type.values(),responsibles:responsibles.values(),external:external.checked && !external.disabled ? 1 : 0,qr:qr.checked && !qr.disabled ? 1 : 0});
	},
	async caseRecord(app,target) {
		const person=await app.personPicker(target,app.entity==='peticiones' ? 'Persona solicitante' : 'Persona relacionada','crear');let external=null,name=null,contact=null;
		if (app.entity==='peticiones') { external=app.check(target,'external','El solicitante es externo a SICRAT');name=app.field(target,'external_name','Nombre del solicitante externo','text');contact=app.field(target,'external_contact','Contacto mínimo del solicitante','text');const toggle=()=>{person.box.hidden=external.checked;person.select.disabled=external.checked;name.closest('label').hidden=contact.closest('label').hidden=!external.checked;name.required=external.checked;name.disabled=contact.disabled=!external.checked;};external.addEventListener('change',toggle);toggle(); }
		app.field(target,'category','Categoría o tipo','text','General',true);app.field(target,'description',app.entity==='peticiones' ? 'Solicitud' : 'Problema reportado','textarea','',true);app.field(target,'priority','Prioridad','select','NORMAL',true,{BAJA:'Baja',NORMAL:'Normal',ALTA:'Alta',URGENTE:'Urgente'});const responsible=await app.personPicker(target,'Responsable','crear');const validation=app.check(target,'validation','El cierre requiere validación superior');app.check(target,'allow_pdf','Permitir documento administrativo PDF');
		const approver=await app.picker(target,'Aprobador opcional','operations/'+app.entity+'/approvers?action=crear');
		let event=null;if(app.entity==='incidencias' && app.can('eventos.ver') && app.moduleEnabled('eventos')) event=await app.picker(target,'Evento relacionado opcional','operations/eventos');
		return form=>({...app.values(form),person:external?.checked ? '' : person.values(),responsible:responsible.values(),approver:approver.values(),validation:validation.checked ? 1 : 0,allow_pdf:form.elements.allow_pdf.checked ? 1 : 0,event:event?.values() || ''});
	},
	async conversation(app,target) {
		const types={};if(app.can('mensajes.enviar'))types.DIRECTA='Conversación directa';if(app.can('mensajes.crear_grupos'))types.GRUPO='Grupo manual';if(app.can('mensajes.crear_grupos_estructura'))types.ESTRUCTURA='Grupo automático de estructura';
		const type=app.field(target,'type','Tipo de conversación','select',Object.keys(types)[0],true,types);const title=app.field(target,'title','Nombre del grupo','text');const announcements=app.check(target,'announcements','Sólo administradores publican anuncios');const pdf=app.check(target,'allow_pdf','Permitir documentos administrativos PDF');const participants=app.text('div','','ux-full');target.append(participants);
		let recipient=null,members=null,structure=null,root=null,level=null,structureMode=null;
		const rebuild=async()=>{
			participants.replaceChildren();recipient=members=structure=root=level=null;title.required=type.value!=='DIRECTA';title.disabled=type.value==='DIRECTA';announcements.disabled=type.value==='DIRECTA';
			if(type.value==='DIRECTA')recipient=await app.picker(participants,'Cuenta destinataria','operations/contacts?permission=mensajes.enviar',false,[],true);
			else if(type.value==='GRUPO')members=await app.picker(participants,'Cuentas integrantes','operations/contacts?permission=mensajes.crear_grupos',true);
			else {
				structure=await app.picker(participants,'Estructura','structures',false,[],true);structureMode=app.field(participants,'structure_mode','Grupo basado en','select','estructura',true,{estructura:'Toda la estructura autorizada',equipo:'Equipo a cargo',nivel:'Nivel organizacional'});const subset=app.text('div','','ux-full');participants.append(subset);
				const choose=async()=>{subset.replaceChildren();root=level=null;if(structureMode.value==='equipo' && structure.values()){root=await app.picker(subset,'Responsable del equipo','structures/'+structure.values(),false,[],true);}else if(structureMode.value==='nivel'){const result=await app.api('structure-levels');level=app.field(subset,'level','Nivel','select','',true,Object.fromEntries((result.levels || result).map(item=>[item.id,item.name || item.nombre])));}};
				structure.select.addEventListener('change',app.guard(choose));structureMode.addEventListener('change',app.guard(choose));
			}
		};type.addEventListener('change',app.guard(rebuild));await rebuild();
		return form=>({...app.values(form),type:type.value,title:type.value==='DIRECTA' ? 'Conversación directa' : title.value,recipient:recipient?.values() || '',members:members?.values() || [],structure:structure?.values() || '',root:root?.values() || '',level:level?.value || '',structure_mode:structureMode?.value || '',announcements:announcements.checked && !announcements.disabled ? 1 : 0,allow_pdf:pdf.checked ? 1 : 0});
	}
};
