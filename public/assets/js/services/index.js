'use strict';

function InitializeValues(url) {
	const shared = window.SicratPhase3;
	const applicationBase = (url || (typeof homeURL === 'string' ? homeURL : '')).replace(/\/$/, '');
	shared.base = applicationBase;
	const api = { request: async (path, method, data) => (await shared.request(path.replace(/^\/api\//, ''), method, data)).data, options: path => shared.pages(path.replace(/^\/api\//, ''), 'persons') };
	const states = {};
	const visual = window.SicratUI;
	const loaded = new Set();
	let context = {};
	const node = id => document.getElementById(id);
	const text = (tag, value) => { const element = document.createElement(tag); element.textContent = value == null ? '' : String(value); return element; };
	const notice = value => { node('services-message').textContent = value; shared.message(value); };
	const guarded = work => async event => { if (event) event.preventDefault(); try { await work(event); } catch (error) { notice(error.message); } };
	const fields = form => Object.fromEntries(new FormData(form).entries());
	const action = (label, work) => { const button = text('button', label); button.type = 'button'; button.className = 'btn'; button.addEventListener('click', guarded(work)); return button; };
	const table = (target, columns, rows, decorate) => {
		target.replaceChildren();
		if (!rows.length) { target.append(text('p', 'Sin registros.')); return; }
		const wrapper = document.createElement('div'); wrapper.style.overflowX = 'auto'; wrapper.style.maxHeight = '28rem'; wrapper.style.overflowY = 'auto';
		const grid = document.createElement('table'); grid.className = 'table';
		const heading = document.createElement('tr'); columns.forEach(([label]) => heading.append(text('th', label))); heading.append(text('th', 'Acciones')); grid.append(heading);
		rows.forEach(row => { const line = document.createElement('tr'); columns.forEach(([, key]) => {
				const cell = text('td', ''); const raw = row[key];
				if (key === 'estado') cell.append(visual.badge(visual.label(raw), ['completado','completada','aprobada','enviado'].includes(raw)));
				else if (key === 'leida_en') cell.append(visual.badge(raw ? 'Leída' : 'Sin leer', !!raw));
				else if (['tipo','entidad','accion'].includes(key)) cell.textContent = visual.label(raw);
				else if (key === 'tamanio') cell.textContent = bytes(raw);
				else cell.textContent = raw == null ? (['organization_name','process_name'].includes(key) ? 'Configuración global' : '—') : String(raw);
				line.append(cell);
			}); const actions = document.createElement('td'); if (decorate) decorate(actions, row); line.append(actions); grid.append(line); });
		wrapper.append(grid); target.append(wrapper);
	};
	const columns = {
		notifications: [['Título', 'titulo'], ['Resumen', 'resumen'], ['Fecha', 'fecha'], ['Estado', 'leida_en']],
		audit: [['Fecha', 'f_registro'], ['Organización', 'organization_name'], ['Elección', 'process_name'], ['Usuario', 'user_name'], ['Acción', 'accion'], ['Entidad', 'entidad']],
		approvals: [['Fecha', 'fecha'], ['Entidad', 'entidad'], ['Estado', 'estado']],
		exports: [['Fecha', 'fecha'], ['Datos', 'tipo'], ['Formato', 'formato'], ['Estado', 'estado'], ['Error', 'error']],
		jobs: [['Tipo', 'tipo'], ['Estado', 'estado'], ['Intentos', 'intentos'], ['Disponible', 'disponible_en'], ['Error', 'error']],
		deliveries: [['Canal', 'canal'], ['Estado', 'estado'], ['Intentos', 'intentos'], ['Fecha', 'fecha'], ['Error', 'error']],
		backups: [['Fecha', 'fecha'], ['Tipo', 'tipo'], ['Estado', 'estado'], ['Tamaño', 'tamanio'], ['Duración (s)', 'duracion'], ['Verificado', 'verificado_en'], ['Error', 'error']]
	};
	function bytes(value) { const size = Number(value || 0); if (size < 1024) return size + ' B'; const unit = size < 1048576 ? ['KB',1024] : size < 1073741824 ? ['MB',1048576] : ['GB',1073741824]; return (size / unit[1]).toFixed(1) + ' ' + unit[0]; }
	const urls = { notifications: '/api/notifications', audit: '/api/audit', approvals: '/api/approvals', exports: '/api/exports', jobs: '/api/jobs', deliveries: '/api/notification-deliveries', backups: '/api/backups' };
	const refresh = async name => {
		const state = states[name] || (states[name] = { offset: 0, filters: {} });
		const query = new URLSearchParams({ ...state.filters, limit: 25, offset: state.offset });
		const data = await api.request(urls[name] + '?' + query);
		table(node(name + '-list'), columns[name], data.rows, (cell, row) => {
			if (name === 'notifications') {
				if (!row.leida_en) cell.append(action('Marcar leída', async () => { await api.request('/api/notifications/read', 'POST', { ids: [row.uuid] }); await refresh(name); }));
				if (row.enlace) { const link = text('a', 'Abrir'); const url = new URL(row.enlace, location.origin); if (url.origin === location.origin) { link.href = url.href; link.className = 'btn'; cell.append(link); } }
			}
			if (name === 'audit') { const detail = document.createElement('details'); detail.append(text('summary', 'Ver cambios')); const content = document.createElement('div'); visual.details(content,{antes:row.datos_anteriores,despues:row.datos_nuevos,informacion:row.informacion},true); detail.append(content); cell.append(detail); }
			if (name === 'approvals') cell.append(action('Consultar', async () => { const detail = await api.request('/api/approvals/' + row.uuid); visual.details(node('approval-detail'), {estado:visual.label(detail.estado),tipo:visual.label(detail.entidad),fecha:detail.fecha,pasos:detail.steps.map(step => ({paso:step.orden,estado:visual.label(step.estado),motivo:step.motivo,fecha:step.fecha}))}); const pending = detail.steps.find(step => step.estado === 'pendiente'); Array.from(node('approval-form').querySelectorAll('button[name=decision]')).forEach(button => button.disabled = detail.estado !== 'pendiente' || !(context.is_superadmin || (context.rights.includes('aprobaciones.resolver') && Number(pending?.aprobador) === Number(context.id)))); node('approval-form').elements.uuid.value = row.uuid; node('approval-dialog').showModal(); }));
			if (name === 'exports' && row.estado === 'completada') { const link = text('a', 'Descargar'); link.href = applicationBase + '/api/exports/' + row.uuid + '/download'; link.className = 'btn'; cell.append(link); }
			if (name === 'jobs' && (context.is_superadmin || (Number(row.own_job) && context.rights.includes('trabajos.administrar')))) {
				if (['pendiente', 'reintento'].includes(row.estado)) cell.append(action('Cancelar', async () => { await api.request('/api/jobs/' + row.id + '/cancel', 'POST', {}); await refresh(name); }));
				if (row.estado === 'fallido' && row.tipo !== 'restauracion') cell.append(action('Reintentar', async () => { await api.request('/api/jobs/' + row.id + '/retry', 'POST', {}); await refresh(name); }));
			}
			if (name === 'backups' && row.estado === 'completado') {
				cell.append(action('Verificar', async () => { await api.request('/api/backups/' + row.id + '/verify', 'POST', {}); notice('Verificación en cola.'); }));
				cell.append(action(Number(row.protegido) ? 'Retirar protección' : 'Proteger', async () => { await api.request('/api/backups/' + row.id + '/protect', 'PUT', { protected: Number(row.protegido) ? 0 : 1 }); await refresh(name); }));
				cell.append(action('Revisar restauración', async () => { const preview = await api.request('/api/backups/' + row.id + '/restore'); visual.details(node('restore-detail'),{contenido:visual.label(preview.type),archivos:preview.files,base_de_datos:preview.database ? 'Incluida' : 'No incluida',destino:preview.target,confirmacion:preview.confirmation}); node('restore-form').elements.uuid.value = row.id; node('restore-form').elements.review.value = preview.review; node('restore-form').elements.confirmation.value = ''; node('restore-dialog').showModal(); }));
			}
		});
		const pager = document.querySelector('[data-pager="' + name + '"]'); pager.replaceChildren();
		if (state.offset) pager.append(action('Anterior', async () => { state.offset = Math.max(0, state.offset - 25); await refresh(name); }));
		pager.append(text('span', 'Página ' + (state.offset / 25 + 1)));
		if (data.pagination.has_more) pager.append(action('Siguiente', async () => { state.offset += 25; await refresh(name); }));
		if (name === 'backups') visual.details(node('backup-summary'),{respaldos:data.summary.cantidad,espacio_utilizado:bytes(data.summary.utilizado),espacio_disponible:bytes(data.summary.available),ultimo_respaldo:data.summary.ultimo || 'Ninguno',destino:data.summary.destination});
		if (name === 'jobs' && data.summary) node('jobs-summary').textContent = data.summary.map(item => visual.label(item.estado) + ': ' + item.total).join(' · ');
	};
	document.querySelectorAll('[data-refresh]').forEach(button => button.addEventListener('click', guarded(() => refresh(button.dataset.refresh))));
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
		const link = text('a', 'Abrir archivo privado'); link.href = applicationBase + '/media/' + file.uuid; target.append(link);
		if (file.mime.startsWith('image/')) { const image = document.createElement('img'); image.src = applicationBase + '/media/' + file.uuid + '?size=thumb'; image.alt = 'Miniatura de la imagen procesada'; image.style.maxWidth = '320px'; target.append(image); }
		notice('Archivo procesado y guardado.');
	}));
	node('approval-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); await api.request('/api/approvals/' + data.uuid, 'PUT', { reason: data.reason, approved: event.submitter.value }); node('approval-dialog').close(); await refresh('approvals'); }));
	node('notification-config-form').addEventListener('submit', guarded(async event => { await api.request('/api/notification-config', 'PUT', { email: Number(event.target.elements.email.checked), whatsapp: Number(event.target.elements.whatsapp.checked) }); notice('Canales actualizados.'); }));
	node('backup-form').addEventListener('submit', guarded(async event => { await api.request('/api/backups', 'POST', fields(event.target)); notice('Respaldo en cola.'); await refresh('backups'); }));
	node('backup-policy-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); data.database_daily = Number(event.target.elements.database_daily.checked); data.incremental_daily = Number(event.target.elements.incremental_daily.checked); await api.request('/api/backup-policy', 'PUT', data); notice('Política guardada.'); }));
	node('restore-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); await api.request('/api/backups/' + data.uuid + '/restore', 'POST', data); node('restore-dialog').close(); notice('Restauración confirmada y puesta en cola.'); await refresh('backups'); }));
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
		if (['user','organization','process'].includes(kind)) node('audit-' + kind + '-more').hidden = !data.pagination.has_more;
	}
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
	let fileOffset = 0;
	async function filePeople(append = false) {
		const result = await shared.request('persons?' + new URLSearchParams({search:node('file-person-search').value,limit:25,offset:fileOffset}));
		const select = node('file-form').elements.record; const previous = select.value;
		if (!append) { select.replaceChildren(); const empty = text('option','Selecciona una persona'); empty.value=''; select.append(empty); }
		result.data.persons.forEach(person => { const option=text('option',person.code + ' · ' + person.name); option.value=person.id; select.append(option); });
		if (Array.from(select.options).some(option => option.value === previous)) select.value=previous;
		node('file-person-more').hidden = !result.pagination.has_more;
	}
	node('file-person-find').addEventListener('click',guarded(async () => { fileOffset=0; await filePeople(); }));
	node('file-person-more').addEventListener('click',guarded(async () => { fileOffset+=25; await filePeople(true); }));
	const load = async name => {
		if (loaded.has(name)) return;
		if (name === 'files') {
			await filePeople();
		} else if (name === 'deliveries') {
			const config = await api.request('/api/notification-config'); node('notification-config-form').elements.email.checked = !!Number(config.email_habilitado); node('notification-config-form').elements.whatsapp.checked = !!Number(config.whatsapp_habilitado); await refresh(name);
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
		const can = permission => context.is_superadmin || context.rights.includes(permission);
		const permitted = {notifications:true,audit:can('auditoria.ver'),approvals:can('aprobaciones.ver'),exports:can('exportaciones.generar'),jobs:can('trabajos.ver'),files:can('archivos.subir') && can('personas.ver'),deliveries:can('notificaciones.administrar'),backups:context.is_superadmin};
		Object.entries(permitted).forEach(([name,allowed]) => { node('services-tab-' + name).hidden = !allowed; });
		const activate = visual.tabs(node('services-tabs'),button => guarded(() => load(button.dataset.service))());
		await activate(node('services-tab-notifications'));
	})().catch(error => notice(error.message));
}
