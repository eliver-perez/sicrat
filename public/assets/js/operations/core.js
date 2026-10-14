'use strict';

window.SicratOperations = {
	entity: '', me: {}, definition: {}, handlers: {}, sequence: 0,
	node(id) { return document.getElementById(id); },
	place(target,control) { const submit=target.tagName==='FORM' ? target.querySelector(':scope > .ux-modal-footer') || Array.from(target.children).find(child=>child.tagName==='BUTTON' && child.type==='submit') : null;target.insertBefore(control,submit || null); },
	text(tag, value = '', className = '') { return window.SicratPhase3.text(tag,value,className); },
	label(value) { return ({validacion:'Pendiente de validación',correccion:'Requiere corrección',no_definido:'No definido',requiere:'Requiere transporte',propio:'Transporte propio',coordinada:'Coordinada',pendiente:'Pendiente',en_proceso:'En proceso',en_atencion:'En atención',asistio:'Asistió',no_asistio:'No asistió',cancelo:'Canceló',ninguno:'Sin control de participantes',agregado:'Conteo agregado',autocaptura:'Autocapturada',asistida:'Capturada con la persona',observacion:'Observación del capturista',historica:'Captura histórica',automatica:'Calculada automáticamente',manual:'Capturada manualmente',activa:'Activa',programado:'Programado',en_curso:'En curso',finalizado:'Finalizado'})[value] || window.SicratUI.label(value); },
	can(permission) { return window.SicratPhase3.allowed(this.me,permission); },
	moduleEnabled(code) { return (this.modules || []).some(module=>module.code===code && module.enabled); },
	async api(path, method = 'GET', data = null) {
		const response=await window.SicratPhase3.request(path.replace(/^\/api\//,''),method,data);
		if (response.pagination && Array.isArray(response.data)) return {rows:response.data,pagination:response.pagination};
		if (response.pagination && response.data && typeof response.data==='object') return {...response.data,pagination:response.pagination};
		return response.data;
	},
	notice(message, type = 'success') {
		if (window.SicratFeedback) { window.SicratFeedback.toast(message,/exportación|en cola|programad/i.test(message) ? 'info' : type); return; }
		this.node('operations-message').textContent=message;
		const dialog=document.activeElement?.closest('dialog[open]') || Array.from(document.querySelectorAll('dialog[open]')).at(-1);
		if (dialog) { let status=dialog.querySelector('[data-operation-message]'); if (!status) { status=this.text('p','','mb-4 text-primary'); status.dataset.operationMessage='1'; status.setAttribute('role','status'); dialog.prepend(status); } status.textContent=message; }
	},
	guard(work) {
		if (window.SicratFeedback) return window.SicratFeedback.guard(work);
		return async event => {
			event?.preventDefault(); const form=event?.currentTarget?.tagName==='FORM' ? event.currentTarget : null;
			const buttons=form ? Array.from(form.querySelectorAll('button[type=submit],button:not([type])')).filter(button=>!button.disabled) : [];
			buttons.forEach(button=>button.disabled=true);
			try { await work(event); } catch(error) { this.notice(error.message || 'No se pudo completar la operación.'); }
			finally { buttons.forEach(button=>button.disabled=false); }
		};
	},
	button(label, work, primary = false) { const button=this.text('button',label,'btn'+(primary ? ' bg-primary text-white' : '')); button.type='button'; button.addEventListener('click',this.guard(work)); return button; },
	field(target,name,label,type = 'text',value = '',required = false,options = null) {
		const wrapper=this.text('label',label); const control=document.createElement(options ? 'select' : type==='textarea' ? 'textarea' : 'input');
		control.name=name; control.className='form-input'; control.required=required;
		if (!options && type!=='textarea') control.type=type;
		if (type==='textarea') { control.rows=3; control.maxLength=10000; wrapper.className='ux-full'; }
		if (type==='text') control.maxLength=200;
		if (options) Object.entries(options).forEach(([key,text])=>window.SicratPhase3.option(control,key,text));
		control.value=value==null ? '' : String(value); wrapper.append(control); this.place(target,wrapper); return control;
	},
	check(target,name,label,checked = false) { const wrapper=this.text('label','','ux-full'); const field=document.createElement('input'); field.type='checkbox'; field.name=name; field.checked=checked; field.className='form-checkbox'; wrapper.append(field,document.createTextNode(' '+label)); this.place(target,wrapper); return field; },
	section(target,title,note = '') { const optional=/opcional/i.test(title);const fieldset=document.createElement(optional ? 'details' : 'fieldset'); fieldset.className='ux-full ux-section'; fieldset.append(this.text(optional ? 'summary' : 'legend',title)); if (note) fieldset.append(this.text('p',note,'op-small-note mb-3')); const fields=this.text('div','','op-form-fields'); fieldset.append(fields); target.append(fieldset); return fields; },
	values(form) { return Object.fromEntries(new FormData(form)); },
	description(target,values) { window.SicratUI.details(target,values); },
	form(target,title,fields,submit,label = 'Guardar') {
		const form=this.text('form','','op-form-fields ux-section');form.addEventListener('submit',event=>event.preventDefault()); form.append(this.text('h6',title,'ux-full')); fields(form);
		const button=this.text('button',label,'btn bg-primary text-white'); button.type='submit'; form.append(button);
		form.addEventListener('submit',this.guard(()=>submit(this.values(form),form))); target.append(form); return form;
	},
	async picker(target,label,path,multiple = false,initial = [],required = false,style='select') {
		const chosen=new Map(initial.map(item=>[item.uuid,item.label || item.nombre || item.titulo])); const box=this.text('div','','op-picker ux-full');
		const searchRow=this.text('div','','ux-inline-form'); const search=this.field(searchRow,'picker_search_'+(++this.sequence),label,'search'); search.setAttribute('aria-label','Buscar '+label.toLowerCase());
		let offset=0,hasMore=false,nonce=0; const select=document.createElement('select'); select.className='form-input'; select.multiple=multiple; select.required=required; select.setAttribute('aria-label',label);
		if (multiple) select.size=6;
		const chips=this.text('div','','op-picker-chosen'); const more=this.button('Más opciones',()=>load(true));
		const selectedCard=this.text('div','','ux-selected-person');selectedCard.hidden=true;
		const cards=this.text('div','','ux-choice-list');cards.hidden=style!=='choices';
		function redraw() {
			chips.replaceChildren();
			if(multiple){chosen.forEach((name,uuid)=>chips.append(window.SicratOperations.button(name+' ×',()=>{chosen.delete(uuid); Array.from(select.options).forEach(option=>{if(option.value===uuid) option.selected=false;});cards.querySelectorAll('input').forEach(input=>input.checked=chosen.has(input.value)); redraw();})));if(style==='choices')select.hidden=true;}
			else{const selected=select.value && Array.from(select.options).find(option=>option.value===select.value);selectedCard.replaceChildren();selectedCard.hidden=!selected;searchRow.hidden=select.hidden=Boolean(selected);if(selected)selectedCard.append(window.SicratOperations.text('span',selected.textContent),window.SicratOperations.button('Cambiar',()=>{chosen.clear();select.value='';select.dispatchEvent(new Event('change'));search.focus();}));}
		}
		const load=async append=> {
			const request=++nonce; if (!append) offset=0;
			const result=await this.api(path+(path.includes('?') ? '&' : '?')+new URLSearchParams({search:search.value,limit:25,offset})); if (request!==nonce) return;
			if (!append) { select.replaceChildren(); if (!multiple) window.SicratPhase3.option(select,'','Selecciona una opción'); chosen.forEach((name,uuid)=>window.SicratPhase3.option(select,uuid,name,true)); }
			const rows=result.rows || result.members || result.sections || result;
			rows.forEach(item=>{const uuid=item.uuid || item.id; const name=item.label || (item.section ? 'Sección '+item.section+' · '+item.municipality : item.codigo_persona ? item.codigo_persona+' · '+item.nombre : item.nombre || item.titulo || item.name); if (!Array.from(select.options).some(option=>option.value===uuid)) window.SicratPhase3.option(select,uuid,name,chosen.has(uuid));});
			if(style==='choices'){
				if(!append)cards.replaceChildren();
				if(rows.length===1 && !result.pagination?.has_more && offset===0 && !search.value.trim() && !chosen.size){const item=rows[0];chosen.set(item.uuid || item.id,item.label || item.nombre || item.name);}
				rows.forEach(item=>{const uuid=item.uuid || item.id;if(Array.from(cards.querySelectorAll('input')).some(input=>input.value===uuid))return;const wrapper=this.text('label','','ux-choice');const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.value=uuid;checkbox.checked=chosen.has(uuid);const name=item.label || item.nombre || item.name;wrapper.append(window.SicratUI.icon('vote'),this.text('span',name),checkbox);checkbox.addEventListener('change',()=>{if(checkbox.checked)chosen.set(uuid,name);else chosen.delete(uuid);Array.from(select.options).forEach(option=>option.selected=chosen.has(option.value));redraw();});cards.append(wrapper);});
			}
			hasMore=!!result.pagination?.has_more; more.hidden=!hasMore; if (hasMore) offset+=25;
			redraw();
		};
		searchRow.classList.add('ux-search-row');searchRow.append(this.button('Buscar',()=>load(false))); box.append(selectedCard,searchRow,select,cards,chips,more); this.place(target,box);
		select.addEventListener('change',()=> { if (!multiple) chosen.clear(); Array.from(select.options).forEach(option=> { if (option.selected && option.value) chosen.set(option.value,option.textContent); else chosen.delete(option.value); }); redraw(); });
		redraw(); await load(false); return {box,select,values:()=>multiple ? Array.from(chosen.keys()) : select.value,chosen,reset:async()=>{chosen.clear();select.value='';search.value='';redraw();await load(false);}};
	},
	personPicker(target,label,action = 'crear',multiple = false,initial = [],required = false,entity = this.entity,participating = true) { return this.picker(target,label,'operations/'+entity+'/people?action='+action+'&participating='+(participating ? '1' : '0'),multiple,initial,required); },
	table(target,columns,rows,actions = null) {
		target.replaceChildren(); if (!rows.length) { target.append(this.text('p','Sin registros para los filtros seleccionados.','text-default-600 p-3')); return; }
		const wrapper=this.text('div','','op-table-scroll'); const table=document.createElement('table'); const head=document.createElement('thead'); const heading=document.createElement('tr');
		columns.forEach(([label])=>{const th=this.text('th',label); th.scope='col'; heading.append(th);}); if (actions) heading.append(this.text('th','Acciones')); head.append(heading); table.append(head);
		const body=document.createElement('tbody'); rows.forEach(row=>{ const line=document.createElement('tr'); columns.forEach(([,key])=>{const cell=document.createElement('td'); const value=typeof key==='function' ? key(row) : row[key]; if (value instanceof Node) cell.append(value); else cell.textContent=value==null || value==='' ? '—' : String(value); line.append(cell);}); if (actions) {const cell=document.createElement('td'); actions(cell,row);line.append(cell);} body.append(line); }); table.append(body); wrapper.append(table); target.append(wrapper);
	},
	pager(target,path,columns,actions = null,renderer = null) {
		const content=this.text('div'); const controls=this.text('div','','ux-pagination mt-4'); let offset=0,hasMore=false,nonce=0;
		const previous=this.button('Anterior',()=>{offset=Math.max(0,offset-25);return load();}); const next=this.button('Siguiente',()=>{if(hasMore) offset+=25;return load();}); const status=this.text('span'); status.setAttribute('aria-live','polite'); controls.append(previous,status,next); target.replaceChildren(content,controls);
		const load=async()=>{const request=++nonce; const url=typeof path==='function' ? path() : path; const result=await this.api(url+(url.includes('?') ? '&' : '?')+'limit=25&offset='+offset); if (request!==nonce) return; if(renderer)renderer(content,result.rows,actions);else this.table(content,columns,result.rows,actions); hasMore=!!result.pagination?.has_more; if(window.SicratFeedback){window.SicratFeedback.setDisabled(previous,offset===0);window.SicratFeedback.setDisabled(next,!hasMore);}else{previous.disabled=offset===0;next.disabled=!hasMore;}status.textContent='Página '+(Math.floor(offset/25)+1);};
		return {load,reset:()=>{offset=0;return load();}};
	},
	async approval(uuid) {
		const approval=await this.api('approvals/'+uuid); const target=this.node('operation-approval-content'); target.replaceChildren();
		this.description(target,{'Estado':this.label(approval.estado),'Solicitada':approval.fecha,'Actividad':approval.informacion?.operacion,'Registro':approval.informacion?.titulo,'Versión':approval.informacion?.version});
		const step=approval.steps.find(step=>step.estado==='pendiente');
		if (approval.estado==='pendiente' && this.can('aprobaciones.resolver') && (this.me.is_superadmin || Number(step?.aprobador)===Number(this.me.id))) {
			this.form(target,'Resolver validación',form=> { this.field(form,'reason','Motivo','textarea','',true); this.field(form,'approved','Decisión','select','1',true,{'1':'Aprobar','0':'Devolver para corrección'}); },async values=>{if(values.approved==='0' && !await window.SicratFeedback.confirm('¿Devolver esta validación para corrección?',{confirm:'Devolver para corrección'}))return;await this.api('approvals/'+uuid,'PUT',{reason:values.reason,approved:Number(values.approved)});this.node('operation-approval-dialog').close();await this.open(this.selected);this.notice('Validación resuelta.');},'Resolver');
		}
		this.node('operation-approval-dialog').showModal();
	},
	async evidence(target,row) {
		const entity=this.entity;
		if (!this.can('archivos.ver')) { target.append(this.text('p','No tienes permiso para consultar evidencias.')); return; }
		const list=this.text('div'); target.append(list);
		const pager=this.pager(list,'operations/'+entity+'/'+row.uuid+'/files',[['Archivo','nombre_original'],['Vista',file=>{if(!file.mime_type.startsWith('image/'))return 'Documento PDF';const image=document.createElement('img');image.src=window.SicratPhase3.base+'/media/'+file.uuid+'?size=thumb';image.alt=file.nombre_original;image.width=80;return image;}]],(cell,file)=>{const link=this.text('a','Abrir','btn');link.href=window.SicratPhase3.base+'/media/'+file.uuid;link.target='_blank';link.rel='noopener';cell.append(link);}); await pager.load();
		const prefix=this.definition.permission;
		if (this.can('archivos.subir') && [prefix+'.crear',prefix+'.editar',prefix+'.completar'].some(permission=>this.can(permission))) {
			this.form(target,'Agregar evidencia',form=>{const file=this.field(form,'file',entity==='eventos' ? 'Imagen' : 'Imagen o documento permitido','file','',true);file.accept=entity==='eventos' ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,image/webp,application/pdf';},async(values,form)=>{
				const data=new FormData();data.append('file',form.elements.file.files[0]);data.append('entity',entity);data.append('record',row.uuid);data.append('process',String(this.me.process_id));
				const response=await fetch(window.SicratPhase3.base+'/api/files',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:data}); const result=await response.json(); if (!response.ok) throw new Error(result.message);form.reset();await pager.reset();this.notice('Evidencia guardada correctamente.');
			},'Subir evidencia');
		}
	},
	async exportDialog(row = null) {
		const dialog=this.node('operation-settings-dialog'); const target=this.node('operation-settings-content');target.replaceChildren();this.node('operation-settings-title').textContent='Exportar '+this.definition.title.toLowerCase();
		this.form(target,'Formato de exportación',form=>this.field(form,'format','Formato','select','xlsx',true,{xlsx:'Excel',csv:'CSV',pdf:'PDF'}),async values=>{const type=this.entity==='encuestas' ? 'encuestas_resultados' : this.entity; const filters={...this.filters};if (row && this.entity==='encuestas') filters.survey=row.uuid;await this.api('exports','POST',{process:this.me.process_id,type,format:values.format,filters});dialog.close();this.notice('Exportación en cola. Consulta el resultado en Servicios y notificaciones.');},'Solicitar exportación');
		dialog.showModal();
	},
	async open(uuid) {
		const panel=this.selected===uuid ? this.detailPanel || 'summary' : 'summary';this.selected=uuid; const row=await this.api('operations/'+this.entity+'/'+uuid); this.current=row;
		this.node('operation-detail-title').textContent=row.titulo || row.title || row.evento || row.encuesta || row.folio || row.persona_nombre || row.resultado || this.definition.title;
		if(this.entity==='conversaciones' && this.chatOptions)this.node('operation-detail-dialog').append(this.chatOptions);
		['summary','actions','history','evidence'].forEach(key=>this.node('operation-panel-'+key).replaceChildren());
		await this.handlers[this.entity].detail(this,row,this.node('operation-panel-summary'),this.node('operation-panel-actions'));
		if(this.entity==='conversaciones' && window.SicratMessaging){window.SicratMessaging.activate(this);return;}
		this.node('operation-tab-history').hidden=['conversaciones','transporte'].includes(this.entity); this.node('operation-tab-evidence').hidden=['conversaciones','transporte','metas'].includes(this.entity);
		this.detailLoaded=new Set(); this.detailTabs ??= window.SicratUI.tabs(this.node('operation-detail-tabs'),async button=> {
			const key=button.id.replace('operation-tab-','');this.detailPanel=key; if (this.detailLoaded.has(key)) return;
			try {
			if (key==='history') { const target=this.node('operation-panel-history'); const pager=this.pager(target,'operations/'+this.entity+'/'+this.selected+'/history',[['Fecha','fecha'],['Actividad',row=>this.label(row.accion)],['Usuario','usuario'],['Seguimiento','nota']]);await pager.load(); }
			if (key==='evidence') await this.evidence(this.node('operation-panel-evidence'),this.current);
			this.detailLoaded.add(key);
			} catch(error) { if(window.SicratFeedback)window.SicratFeedback.error(error);else this.notice(error.message,'error'); }
		});
		// El callback usa el estado actual; la carga se reinicia también al consultar otra ficha.
		await this.detailTabs(this.node('operation-tab-'+panel)); const dialog=this.node('operation-detail-dialog'); if (!dialog.open) dialog.showModal();
	}
};
