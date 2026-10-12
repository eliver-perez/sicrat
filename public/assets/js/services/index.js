'use strict';

function InitializeValues(url) {
	const shared = window.SicratPhase3;
	const applicationBase = (url || (typeof homeURL === 'string' ? homeURL : '')).replace(/\/$/, '');
	shared.base = applicationBase;
	const api = { request: async (path, method, data) => (await shared.request(path.replace(/^\/api\//, ''), method, data)).data, options: path => shared.pages(path.replace(/^\/api\//, ''), 'persons') };
	const states = {};
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
		const wrapper = document.createElement('div'); wrapper.style.overflowX = 'auto';
		const grid = document.createElement('table'); grid.className = 'table';
		const heading = document.createElement('tr'); columns.forEach(([label]) => heading.append(text('th', label))); heading.append(text('th', 'Acciones')); grid.append(heading);
		rows.forEach(row => { const line = document.createElement('tr'); columns.forEach(([, key]) => line.append(text('td', row[key]))); const actions = document.createElement('td'); if (decorate) decorate(actions, row); line.append(actions); grid.append(line); });
		wrapper.append(grid); target.append(wrapper);
	};
	const columns = {
		notifications: [['Título', 'titulo'], ['Resumen', 'resumen'], ['Fecha', 'fecha'], ['Leída', 'leida_en']],
		audit: [['Fecha', 'f_registro'], ['Organización', 'organizacion'], ['Elección', 'proceso'], ['Usuario', 'usuario'], ['Acción', 'accion'], ['Entidad', 'entidad'], ['Registro', 'referencia']],
		approvals: [['Fecha', 'fecha'], ['Entidad', 'entidad'], ['Registro', 'entidad_id'], ['Estado', 'estado']],
		exports: [['Fecha', 'fecha'], ['Datos', 'tipo'], ['Formato', 'formato'], ['Estado', 'estado'], ['Error', 'error']],
		jobs: [['Tipo', 'tipo'], ['Estado', 'estado'], ['Intentos', 'intentos'], ['Disponible', 'disponible_en'], ['Error', 'error']],
		deliveries: [['Canal', 'canal'], ['Estado', 'estado'], ['Intentos', 'intentos'], ['Fecha', 'fecha'], ['Error', 'error']],
		backups: [['Fecha', 'fecha'], ['Tipo', 'tipo'], ['Estado', 'estado'], ['Bytes', 'tamanio'], ['Duración (s)', 'duracion'], ['Verificado', 'verificado_en'], ['Error', 'error']]
	};
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
			if (name === 'audit') { const detail = document.createElement('details'); detail.append(text('summary', 'Cambios'), text('pre', JSON.stringify({ antes: row.datos_anteriores, despues: row.datos_nuevos, informacion: row.informacion }, null, 2))); cell.append(detail); }
			if (name === 'approvals') cell.append(action('Consultar', async () => { const detail = await api.request('/api/approvals/' + row.uuid); node('approval-detail').textContent = JSON.stringify(detail, null, 2); node('approval-form').elements.uuid.value = row.uuid; node('approval-dialog').showModal(); }));
			if (name === 'exports' && row.estado === 'completada') { const link = text('a', 'Descargar'); link.href = applicationBase + '/api/exports/' + row.uuid + '/download'; link.className = 'btn'; cell.append(link); }
			if (name === 'jobs' && (context.is_superadmin || (Number(row.own_job) && context.rights.includes('trabajos.administrar')))) {
				if (['pendiente', 'reintento'].includes(row.estado)) cell.append(action('Cancelar', async () => { await api.request('/api/jobs/' + row.id + '/cancel', 'POST', {}); await refresh(name); }));
				if (row.estado === 'fallido' && row.tipo !== 'restauracion') cell.append(action('Reintentar', async () => { await api.request('/api/jobs/' + row.id + '/retry', 'POST', {}); await refresh(name); }));
			}
			if (name === 'backups' && row.estado === 'completado') {
				cell.append(action('Verificar', async () => { await api.request('/api/backups/' + row.id + '/verify', 'POST', {}); notice('Verificación en cola.'); }));
				cell.append(action(Number(row.protegido) ? 'Retirar protección' : 'Proteger', async () => { await api.request('/api/backups/' + row.id + '/protect', 'PUT', { protected: Number(row.protegido) ? 0 : 1 }); await refresh(name); }));
				cell.append(action('Revisar restauración', async () => { const preview = await api.request('/api/backups/' + row.id + '/restore'); node('restore-detail').textContent = JSON.stringify(preview, null, 2); node('restore-form').elements.uuid.value = row.id; node('restore-form').elements.review.value = preview.review; node('restore-form').elements.confirmation.value = ''; node('restore-dialog').showModal(); }));
			}
		});
		const pager = document.querySelector('[data-pager="' + name + '"]'); pager.replaceChildren();
		if (state.offset) pager.append(action('Anterior', async () => { state.offset = Math.max(0, state.offset - 25); await refresh(name); }));
		pager.append(text('span', 'Página ' + (state.offset / 25 + 1)));
		if (data.pagination.has_more) pager.append(action('Siguiente', async () => { state.offset += 25; await refresh(name); }));
		if (name === 'backups') node('backup-summary').textContent = 'Respaldos: ' + data.summary.cantidad + ' · Bytes utilizados: ' + data.summary.utilizado + ' · Bytes disponibles: ' + data.summary.available + ' · Último satisfactorio: ' + (data.summary.ultimo || 'Ninguno') + ' · ' + data.summary.destination;
		if (name === 'jobs' && data.summary) node('jobs-summary').textContent = data.summary.map(item => item.estado + ': ' + item.total).join(' · ');
	};
	document.querySelectorAll('[data-refresh]').forEach(button => button.addEventListener('click', guarded(() => refresh(button.dataset.refresh))));
	document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => node(button.dataset.close).close()));
	['audit', 'jobs', 'backup'].forEach(name => node(name + '-filter').addEventListener('submit', guarded(async event => { const key = name === 'backup' ? 'backups' : name; const filters = fields(event.target); if (key === 'audit' && filters.context === 'all') filters.process = 'all'; delete filters.context; states[key] = { offset: 0, filters }; await refresh(key); })));
	node('notifications-read-all').addEventListener('click', guarded(async () => { await api.request('/api/notifications/read', 'POST', { all: 1 }); await refresh('notifications'); }));
	node('export-form').addEventListener('submit', guarded(async event => { const data = fields(event.target); await api.request('/api/exports', 'POST', { type: data.type, format: data.type === 'paquete' ? 'zip' : data.format, filters: { search: data.search } }); notice('Exportación solicitada. El worker procesará el archivo.'); await refresh('exports'); }));
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
	guarded(async () => {
		context = await api.request('/api/auth/me');
		const can = permission => context.is_superadmin || context.rights.includes(permission);
		await refresh('notifications');
		for (const [section, permission] of [['audit', 'auditoria.ver'], ['approvals', 'aprobaciones.ver'], ['exports', 'exportaciones.generar'], ['jobs', 'trabajos.ver']]) if (can(permission)) { node('services-' + section).hidden = false; await refresh(section); }
		if (can('archivos.subir') && can('personas.ver')) { node('services-files').hidden = false; const people = await api.options('/api/persons'); people.forEach(person => { const option = text('option', person.code + ' · ' + person.name); option.value = person.id; node('file-form').elements.record.append(option); }); }
		if (can('notificaciones.administrar')) { node('services-deliveries').hidden = false; try { const config = await api.request('/api/notification-config'); node('notification-config-form').elements.email.checked = !!Number(config.email_habilitado); node('notification-config-form').elements.whatsapp.checked = !!Number(config.whatsapp_habilitado); await refresh('deliveries'); } catch (error) { notice(error.message); } }
		if (context.is_superadmin) { node('services-backups').hidden = false; const policy = await api.request('/api/backup-policy'); const form = node('backup-policy-form'); form.elements.database_daily.checked = !!Number(policy.bd_diaria); form.elements.incremental_daily.checked = !!Number(policy.archivos_incremental); form.elements.full_weekday.value = policy.archivos_full_dia; form.elements.retention_days.value = policy.retencion_dias; await refresh('backups'); }
	})().catch(error => notice(error.message));
}
