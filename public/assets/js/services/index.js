'use strict';

function InitializeValues(url) {
	const shared = window.SicratPhase3;
	const applicationBase = (url || (typeof homeURL === 'string' ? homeURL : '')).replace(/\/$/, '');
	shared.base = applicationBase;
	const api = { request: async (path, method, data) => (await shared.request(path.replace(/^\/api\//, ''), method, data)).data };
	const states = {};
	const visual = window.SicratUI;
	const loaded = new Set();
	let context = {};
	const node = id => document.getElementById(id);
	const text = (tag, value) => { const element = document.createElement(tag); element.textContent = value == null ? '' : String(value); return element; };
	const notice = value => shared.message(value,/exportación|en cola|programad|puesta en cola/i.test(value) ? 'info' : 'success');
	const guarded = work => window.SicratFeedback ? window.SicratFeedback.guard(work) : async event => { if (event) event.preventDefault(); try { await work(event); } catch (error) { notice(error.message); } };
	const fields = form => Object.fromEntries(new FormData(form).entries());
	const action = (label, work, icon) => {
		const button = text('button', label);
		button.type = 'button'; button.className = 'btn';
		button.addEventListener('click', guarded(work));
		return icon ? visual.action(button,label,icon) : button;
	};
	const linkAction = (url,label,icon) => {
		const link = text('a',label);
		link.href = url; link.className = 'btn';
		return visual.action(link,label,icon);
	};
	const emptyStates = {
		notifications:['bell','No tienes notificaciones pendientes.'],
		audit:['history','No hay actividad para los filtros seleccionados.'],
		approvals:['clipboard-check','No tienes solicitudes de aprobación pendientes.'],
		files:['files','No hay archivos disponibles.'],
		exports:['download','No hay exportaciones registradas.'],
		jobs:['list-todo','No hay trabajos para los filtros seleccionados.'],
		deliveries:['send','No hay envíos registrados.'],
		backups:['archive','No hay respaldos para los filtros seleccionados.']
	};
	const empty = (target,name) => {
		const [icon,message] = emptyStates[name];
		const box = text('p',''); box.className = 'services-empty';
		box.append(visual.icon(icon),text('span',message));
		target.replaceChildren(box);
	};
	function notifications(rows, target) {
		if (!rows.length) { empty(target,'notifications'); return; }
		const list = text('ul',''); list.className = 'services-notification-list';
		for (const row of rows) {
			const item = text('li',''); item.className = 'services-notification';
			const copy = text('div',''), title = text('strong',row.titulo);
			const date = text('time',row.fecha); date.dateTime = row.fecha.replace(' ','T');
			const controls = text('div',''); controls.className = 'services-actions';
			if (!row.leida_en) {
				item.classList.add('unread');
				const unread = text('span','Sin leer'); unread.className = 'ux-sr-only'; title.append(unread);
				controls.append(action('Marcar como leída',async () => {
					await api.request('/api/notifications/read','POST',{ids:[row.uuid]});
					await refresh('notifications');
				},'check'));
			}
			if (row.enlace) {
				const url = new URL(row.enlace,location.origin);
				if (url.origin === location.origin) controls.append(linkAction(url.href,'Abrir','eye'));
			}
			copy.append(title,text('p',row.resumen),date);
			item.append(copy);
			if (controls.children.length) item.append(controls);
			list.append(item);
		}
		target.replaceChildren(list);
	}
	const columns = {
		audit: [['Fecha','f_registro'],['Organización','organization_name'],['Elección','process_name'],['Usuario','user_name'],['Acción','accion'],['Entidad','entidad']],
		approvals: [['Tipo','entidad'],['Solicitante','solicitante_nombre'],['Fecha','fecha'],['Estado','estado']],
		files: [['Archivo','nombre_original'],['Tipo','mime_type'],['Tamaño','tamanio'],['Fecha','f_registro'],['Estado','estado']],
		exports: [['Tipo','tipo'],['Fecha','fecha'],['Formato','formato'],['Estado','estado'],['Archivo','archivo_nombre']],
		jobs: [['Tarea','tipo'],['Estado','estado'],['Intentos','intentos'],['Disponible','disponible_en'],['Inicio','inicio'],['Finalización','fin'],['Resultado','error']],
		deliveries: [['Destinatario','destinatario_nombre'],['Canal','canal'],['Fecha','fecha'],['Estado','estado']],
		backups: [['Fecha','fecha'],['Tipo','tipo'],['Estado','estado'],['Tamaño','tamanio'],['Duración (s)','duracion'],['Verificado','verificado_en'],['Error','error']]
	};
	function cellValue(cell,key,raw) {
		if (['estado','fecha','f_registro','disponible_en','inicio','fin','verificado_en'].includes(key)) cell.classList.add('services-nowrap');
		if (key === 'estado') cell.append(visual.badge(raw === 'activo' ? 'Activo' : visual.label(raw),['activo','completado','completada','aprobada','enviado'].includes(raw)));
		else if (['tipo','entidad','accion'].includes(key)) cell.textContent = visual.label(raw);
		else if (key === 'mime_type') cell.textContent = {'application/pdf':'PDF','image/webp':'Imagen WebP','image/jpeg':'Imagen JPEG','image/png':'Imagen PNG'}[raw] || raw;
		else if (key === 'canal') cell.textContent = {email:'Correo',whatsapp:'WhatsApp'}[raw] || visual.label(raw);
		else if (key === 'tamanio') cell.textContent = bytes(raw);
		else cell.textContent = raw ?? (['organization_name','process_name'].includes(key) ? 'Configuración global' : '—');
	}
	function table(target,name,rows,decorate) {
		if (!rows.length) { empty(target,name); return; }
		const wrapper = text('div',''); wrapper.className = 'services-table';
		wrapper.tabIndex = 0;
		wrapper.setAttribute('role','region');
		wrapper.setAttribute('aria-labelledby','services-tab-'+name);
		const grid = text('table','');
		const heading = grid.createTHead().insertRow();
		const showActions = name !== 'deliveries';
		for (const label of [...columns[name].map(([label]) => label),...(showActions ? ['Acciones'] : [])]) {
			const header = text('th',label); header.scope = 'col'; heading.append(header);
		}
		const body = grid.createTBody();
		for (const row of rows) {
			const line = body.insertRow();
			for (const [,key] of columns[name]) cellValue(line.insertCell(),key,row[key]);
			if (showActions) {
				const actions = text('div',''); actions.className = 'services-actions';
				decorate(actions,row);
				line.insertCell().append(actions);
			}
		}
		wrapper.append(grid); target.replaceChildren(wrapper);
	}
	function bytes(value) { const size = Number(value || 0); if (size < 1024) return size + ' B'; const unit = size < 1048576 ? ['KB',1024] : size < 1073741824 ? ['MB',1048576] : ['GB',1073741824]; return (size / unit[1]).toFixed(1) + ' ' + unit[0]; }
	const urls = { notifications: '/api/notifications', audit: '/api/audit', approvals: '/api/approvals', files: '/api/files', exports: '/api/exports', jobs: '/api/jobs', deliveries: '/api/notification-deliveries', backups: '/api/backups' };
	const refresh = async name => {
		const state = states[name] || (states[name] = { offset: 0, filters: {} });
		const sequence = state.sequence = (state.sequence || 0) + 1;
		const query = new URLSearchParams({ ...state.filters, limit: 25, offset: state.offset });
		const data = await api.request(urls[name] + '?' + query);
		if (states[name] !== state || state.sequence !== sequence) return;
		if (state.offset && !data.rows.length) { state.offset = Math.max(0,state.offset - 25); return refresh(name); }
		const target = node(name + '-list');
		if (name === 'notifications') {
			notifications(data.rows,target);
			node('notifications-read-all').disabled = !data.rows.length;
		} else table(target,name,data.rows,(cell,row) => {
			if (name === 'audit') {
				const detail = text('details',''), summary = text('summary','Ver cambios');
				summary.className = 'btn'; visual.action(summary,'Ver cambios','eye');
				const content = text('div','');
				visual.details(content,{antes:row.datos_anteriores,despues:row.datos_nuevos,informacion:row.informacion},true);
				detail.append(summary,content); cell.append(detail);
			}
			if (name === 'files') cell.append(linkAction(applicationBase+'/media/'+row.uuid,'Abrir archivo','eye'));
			if (name === 'approvals') cell.append(action('Consultar', async () => { const detail = await api.request('/api/approvals/' + row.uuid); visual.details(node('approval-detail'), {estado:visual.label(detail.estado),tipo:visual.label(detail.entidad),solicitante:row.solicitante_nombre,fecha:detail.fecha,pasos:detail.steps.map(step => ({paso:step.orden,estado:visual.label(step.estado),motivo:step.motivo,fecha:step.fecha}))}); const pending = detail.steps.find(step => step.estado === 'pendiente'); Array.from(node('approval-form').querySelectorAll('button[name=decision]')).forEach(button => button.disabled = detail.estado !== 'pendiente' || !(context.is_superadmin || (context.rights.includes('aprobaciones.resolver') && Number(pending?.aprobador) === Number(context.id)))); node('approval-form').elements.uuid.value = row.uuid; node('approval-dialog').showModal(); },'eye'));
			if (name === 'exports' && row.archivo_nombre && (context.is_superadmin || context.rights.includes('exportaciones.descargar'))) {
				cell.append(linkAction(applicationBase+'/api/exports/'+row.uuid+'/download','Descargar','download'));
			}
			if (name === 'jobs' && (context.is_superadmin || (Number(row.own_job) && context.rights.includes('trabajos.administrar')))) {
				if (['pendiente', 'reintento'].includes(row.estado)) cell.append(action('Cancelar', async () => { if (!await window.SicratFeedback.confirm('¿Cancelar este trabajo pendiente?',{confirm:'Cancelar trabajo'})) return; await api.request('/api/jobs/' + row.id + '/cancel', 'POST', {}); await refresh(name); notice('Trabajo cancelado.'); },'circle-x'));
				if (row.estado === 'fallido' && row.tipo !== 'restauracion') cell.append(action('Reintentar', async () => { await api.request('/api/jobs/' + row.id + '/retry', 'POST', {}); await refresh(name); },'rotate-ccw'));
			}
			if (name === 'backups' && row.estado === 'completado') {
				cell.append(action('Verificar', async () => { await api.request('/api/backups/' + row.id + '/verify', 'POST', {}); notice('Verificación en cola.'); }));
				cell.append(action(Number(row.protegido) ? 'Retirar protección' : 'Proteger', async () => { await api.request('/api/backups/' + row.id + '/protect', 'PUT', { protected: Number(row.protegido) ? 0 : 1 }); await refresh(name); }));
				cell.append(action('Revisar restauración', async () => { const preview = await api.request('/api/backups/' + row.id + '/restore'); visual.details(node('restore-detail'),{contenido:visual.label(preview.type),archivos:preview.files,base_de_datos:preview.database ? 'Incluida' : 'No incluida',destino:preview.target,confirmacion:preview.confirmation}); node('restore-form').elements.uuid.value = row.id; node('restore-form').elements.review.value = preview.review; node('restore-form').elements.confirmation.value = ''; node('restore-dialog').showModal(); }));
			}
		});
		const pager = document.querySelector('[data-pager="' + name + '"]'); pager.replaceChildren();
		pager.hidden = !state.offset && !data.pagination.has_more;
		if (!pager.hidden) {
			if (state.offset) pager.append(action('Anterior', async () => { state.offset = Math.max(0,state.offset - 25); await refresh(name); }));
			pager.append(text('span','Página '+(state.offset / 25 + 1)));
			if (data.pagination.has_more) pager.append(action('Siguiente', async () => { state.offset += 25; await refresh(name); }));
		}
		if (name === 'backups') visual.details(node('backup-summary'),{respaldos:data.summary.cantidad,espacio_utilizado:bytes(data.summary.utilizado),espacio_disponible:bytes(data.summary.available),ultimo_respaldo:data.summary.ultimo || 'Ninguno',destino:data.summary.destination});
		if (name === 'jobs' && data.summary) node('jobs-summary').textContent = data.summary.map(item => visual.label(item.estado) + ': ' + item.total).join(' · ');
	};
	document.querySelectorAll('[data-refresh]').forEach(button => {
		visual.action(button,'Actualizar','refresh-cw');
		button.addEventListener('click', guarded(() => refresh(button.dataset.refresh)));
	});
	visual.action(node('jobs-refresh'),'Actualizar','refresh-cw');
	visual.action(node('notifications-read-all'),'Marcar todas como leídas','check-check');
	document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => node(button.dataset.close).close()));
	['audit', 'jobs', 'backup'].forEach(name => node(name + '-filter').addEventListener('submit', guarded(async event => { const key = name === 'backup' ? 'backups' : name; const filters = fields(event.target); if (key === 'audit' && filters.context === 'all') filters.process = 'all'; delete filters.context; states[key] = { offset: 0, filters }; await refresh(key); })));
	node('notifications-read-all').addEventListener('click', guarded(async () => { await api.request('/api/notifications/read', 'POST', { all: 1 }); await refresh('notifications'); }));
	node('export-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); await api.request('/api/exports', 'POST', { type: data.type, format: data.type === 'paquete' ? 'zip' : data.format, filters: { search: data.search } }); notice('Exportación solicitada. Puedes consultar su progreso en esta pestaña.'); await refresh('exports'); }));
	node('file-form').addEventListener('submit', guarded(async event => {
		const body = new FormData(event.target); body.set('entity', 'personas');
		const uploadUrl = new URL(applicationBase + '/api/files', location.href); if (uploadUrl.origin !== location.origin) throw new Error('Origen de carga no autorizado.');
		const response = await fetch(uploadUrl.href, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': context.csrf_token }, body });
		const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message);
		const target = node('file-result'); target.replaceChildren(); const file = result.data;
		target.append(linkAction(applicationBase+'/media/'+file.uuid,'Abrir archivo procesado','eye'));
		if (file.mime.startsWith('image/')) { const image = document.createElement('img'); image.src = applicationBase + '/media/' + file.uuid + '?size=thumb'; image.alt = 'Miniatura de la imagen procesada'; target.append(image); }
		notice('Archivo procesado y guardado.');
		if (canListFiles()) { if (states.files) states.files.offset = 0; await refresh('files'); }
	}));
	node('approval-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); if(event.submitter.value==='0' && !await window.SicratFeedback.confirm('¿Rechazar esta solicitud?',{confirm:'Rechazar solicitud'}))return; await api.request('/api/approvals/' + data.uuid, 'PUT', { reason: data.reason, approved: event.submitter.value }); node('approval-dialog').close(); await refresh('approvals'); }));
	node('notification-config-form').addEventListener('submit', guarded(async event => {
		if (node('notification-channel-controls').disabled) return;
		await api.request('/api/notification-config', 'PUT', { email: Number(event.target.elements.email.checked), whatsapp: Number(event.target.elements.whatsapp.checked) });
		notice('Canales actualizados.');
	}));
	node('backup-form').addEventListener('submit', guarded(async event => { await api.request('/api/backups', 'POST', fields(event.target)); notice('Respaldo en cola.'); await refresh('backups'); }));
	node('backup-policy-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); data.database_daily = Number(event.target.elements.database_daily.checked); data.incremental_daily = Number(event.target.elements.incremental_daily.checked); await api.request('/api/backup-policy', 'PUT', data); notice('Política guardada.'); }));
	node('restore-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); if (!await window.SicratFeedback.confirm('¿Programar la restauración en el destino revisado? Revisa la confirmación escrita antes de continuar.',{confirm:'Programar restauración'})) return; await api.request('/api/backups/' + data.uuid + '/restore', 'POST', data); node('restore-dialog').close(); notice('Restauración confirmada y puesta en cola. Puedes continuar trabajando.'); await refresh('backups'); }));
	const optionOffsets = {user:0,organization:0,process:0,action:0,entity:0}; let auditSearchTimer; let auditRequest = 0;
	async function auditOptions(kind, append = false) {
		const form = node('audit-filter'); const selected = form.elements[kind].value;
		const filters = fields(form); delete filters.user; delete filters.action; delete filters.entity; delete filters[kind];
		if (context.is_superadmin || filters.context === 'all') filters.process = 'all';
		delete filters.context;
		const query = new URLSearchParams({...filters,kind,limit:['action','entity'].includes(kind) ? 100 : 25,offset:optionOffsets[kind],search:['user','organization','process'].includes(kind) ? node('audit-' + kind + '-search').value : ''});
		const request = kind === 'user' ? ++auditRequest : 0;
		const data = await api.request('/api/audit/options?' + query);
		if (kind === 'user' && request !== auditRequest) return;
		const select = form.elements[kind];
		if (!append) { select.replaceChildren(); const empty = text('option',{user:'Todos los usuarios autorizados',process:'Elección de trabajo',organization:'Organización de trabajo',action:'Todas las actividades',entity:'Todas las áreas'}[kind]); empty.value = ''; select.append(empty); }
		data.rows.forEach(row => { const option = text('option',['action','entity'].includes(kind) ? visual.label(row.label) : row.label); option.value = row.value; select.append(option); });
		if (Array.from(select.options).some(option => option.value === selected)) select.value = selected;
		if (['user','organization','process'].includes(kind)) node('audit-' + kind + '-more').hidden = true;
	}
	['organization','process','user'].forEach(kind=>{
        const select=node('audit-filter').elements[kind],search=node('audit-'+kind+'-search');
        const host=select.closest('label') || select.parentElement;
        select.hidden=true;search.hidden=true;search.closest('label')?.classList.add('hidden');node('audit-'+kind+'-more').hidden=true;
        const row=text('div','');row.className='ux-search-row';const summary=text('span',select.selectedOptions[0]?.textContent || 'Todas las opciones');summary.className='form-input';
        const find=action('Buscar '+{organization:'organización',process:'elección',user:'usuario'}[kind],()=>visual.entitySelector({title:'Seleccionar '+{organization:'organización',process:'elección',user:'usuario'}[kind],selected:select.value,
            load:async(search,offset)=>{const filters=fields(node('audit-filter'));delete filters[kind];delete filters.user;delete filters.action;delete filters.entity;if(context.is_superadmin || filters.context==='all')filters.process='all';delete filters.context;return api.request('/api/audit/options?'+new URLSearchParams({...filters,kind,limit:25,offset,search}));},
            choose:async item=>{let option=Array.from(select.options).find(option=>option.value===item.value);if(!option){option=text('option',item.label);option.value=item.value;select.append(option);}select.value=item.value;summary.textContent=item.label;select.dispatchEvent(new Event('change'));}
        }));visual.action(find,'Buscar '+{organization:'organización',process:'elección',user:'usuario'}[kind],'search');const clear=action('Quitar filtro',()=>{select.value='';summary.textContent={organization:'Organización de trabajo',process:'Elección de trabajo',user:'Todos los usuarios autorizados'}[kind];select.dispatchEvent(new Event('change'));});visual.action(clear,'Quitar filtro','x');row.append(summary,find,clear);host.append(row);
        select.addEventListener('change',()=>{summary.textContent=select.selectedOptions[0]?.textContent || 'Sin filtro';});
    });
	node('audit-user-search').addEventListener('input',() => { clearTimeout(auditSearchTimer); auditSearchTimer = setTimeout(() => { optionOffsets.user=0; guarded(() => auditOptions('user'))(); },250); });
	node('audit-user-more').addEventListener('click',guarded(async () => { optionOffsets.user+=25; await auditOptions('user',true); }));
	['organization','process'].forEach(kind => {
		let timer;
		node('audit-' + kind + '-search').addEventListener('input',() => { clearTimeout(timer); timer=setTimeout(() => { optionOffsets[kind]=0; guarded(() => auditOptions(kind))(); },250); });
		node('audit-' + kind + '-more').addEventListener('click',guarded(async () => { optionOffsets[kind]+=25; await auditOptions(kind,true); }));
	});
	node('audit-filter').elements.organization.addEventListener('change',guarded(async () => { node('audit-filter').elements.process.value=''; if (context.is_superadmin) node('audit-filter').elements.context.value='all'; optionOffsets.process=0; await auditOptions('process'); optionOffsets.user=0; await auditOptions('user'); }));
	node('audit-filter').elements.context.addEventListener('change',guarded(async () => { optionOffsets.user=0; await auditOptions('user'); }));
	node('export-form').elements.type.addEventListener('change',() => { node('export-form').elements.format.disabled = node('export-form').elements.type.value === 'paquete'; });
    const filePerson = node('file-form').elements.record;
    const uploadFile = node('file-form').elements.file;
    uploadFile.addEventListener('change',()=>{
        node('file-name').textContent = uploadFile.files[0]?.name || 'Ningún archivo seleccionado';
    });
    const fileSummary = node('file-person-summary');
    visual.labeledAction(node('file-person-find'),'Seleccionar persona','contact-round');
    async function filePeople() {
        filePerson.replaceChildren();const empty=text('option','Selecciona una persona');empty.value='';filePerson.append(empty);
    }
    node('file-person-find').addEventListener('click',guarded(()=>visual.entitySelector({title:'Seleccionar persona para el archivo',selected:filePerson.value,
        load:async(search,offset)=>{const result=await shared.request('persons?'+new URLSearchParams({search,limit:25,offset}));return {rows:result.data.persons.map(person=>({value:person.id,label:person.code+' · '+person.name})),pagination:result.pagination};},
        choose:person=>{filePerson.replaceChildren();const option=text('option',person.label);option.value=person.value;option.selected=true;filePerson.append(option);fileSummary.textContent=person.label;filePerson.dispatchEvent(new Event('change'));}
    })));
    filePerson.addEventListener('invalid',event=>{event.preventDefault();node('file-person-find').focus();});
	const can = permission => context.is_superadmin || context.rights?.includes(permission);
	const canListFiles = () => can('archivos.ver') && can('personas.ver');
	const channels = ['email','whatsapp'].map(name=>node('notification-config-form').elements[name]);
	function updateChannel(control) {
		control.closest('label')?.querySelector('[data-channel-state]').replaceChildren(control.checked ? 'Habilitado' : 'Deshabilitado');
	}
	channels.forEach(control=>control.addEventListener('change',()=>updateChannel(control)));
	const load = async name => {
		if (loaded.has(name)) return;
		if (name === 'files') {
			const upload = can('archivos.subir') && can('personas.ver');
			node('file-form').hidden = !upload;
			if (upload) await filePeople();
			if (canListFiles()) {
				document.querySelector('[data-refresh="files"]').hidden = false;
				await refresh(name);
			} else empty(node('files-list'),'files');
		} else if (name === 'deliveries') {
			const scopes = context.scopes?.['notificaciones.administrar'] || [];
			const canConfigure = context.is_superadmin || scopes.includes('organizacion');
			let canReadHistory = canConfigure || (context.process_id && scopes.includes('eleccion'));
			if (canReadHistory) {
				try {
					await refresh(name);
				} catch (error) {
					if (error.status !== 403) throw error;
					canReadHistory = false;
				}
			}
			if (!canReadHistory) {
				document.querySelector('[data-refresh="deliveries"]').disabled = true;
				const explanation = text('p','Selecciona una elección para la que tengas acceso al historial de envíos.');
				explanation.className = 'ux-help';
				node('deliveries-list').replaceChildren(explanation);
			}
			if (canConfigure) {
				try {
					const config = await api.request('/api/notification-config');
					channels[0].checked = !!Number(config.email_habilitado);
					channels[1].checked = !!Number(config.whatsapp_habilitado);
					channels.forEach(updateChannel);
					node('notification-channel-controls').disabled = false;
				} catch (error) {
					if (error.status !== 403) throw error;
				}
			}
			node('notification-channel-help').hidden = !node('notification-channel-controls').disabled;
		} else if (name === 'backups') {
			const policy = await api.request('/api/backup-policy'); const form = node('backup-policy-form'); form.elements.database_daily.checked = !!Number(policy.bd_diaria); form.elements.incremental_daily.checked = !!Number(policy.archivos_incremental); form.elements.full_weekday.value = policy.archivos_full_dia; form.elements.retention_days.value = policy.retencion_dias; await refresh(name);
		} else {
			await refresh(name);
			if (name === 'audit') { await auditOptions('organization'); await auditOptions('process'); await auditOptions('user'); await auditOptions('action'); await auditOptions('entity'); }
		}
		loaded.add(name);
	};
	guarded(async () => {
		context = await api.request('/api/auth/me');
		const permitted = {notifications:true,audit:can('auditoria.ver'),approvals:can('aprobaciones.ver'),exports:can('exportaciones.generar'),jobs:can('trabajos.ver'),files:can('personas.ver') && (can('archivos.subir') || can('archivos.ver')),deliveries:can('notificaciones.administrar'),backups:context.is_superadmin};
		Object.entries(permitted).forEach(([name,allowed]) => { node('services-tab-' + name).hidden = !allowed; });
		const activate = visual.tabs(node('services-tabs'),button => {
			button.scrollIntoView?.({block:'nearest',inline:'nearest'});
			return guarded(() => load(button.dataset.service))();
		});
		await activate(node('services-tab-notifications'));
	})().catch(error => { node('services-message').textContent = error.message; if (window.SicratFeedback) window.SicratFeedback.error(error); });
}
