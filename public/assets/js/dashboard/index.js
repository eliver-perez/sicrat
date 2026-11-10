/* Dashboard has no offline writes. The existing service worker owns encrypted reads. */
var homeURL;
function InitializeValues(home) {
    homeURL = home;
    if (typeof callBack !== 'undefined' && ['missingInfo', 'deniedAccess'].includes(callBack)) {
        ShowToastMessage(callBack === 'missingInfo' ? 'Falta Información' : 'Acceso denegado', 'error');
    }
    window.SicratDashboard?.start();
}
window.SicratDashboard = (() => {
    'use strict';
    const node = id => document.getElementById(id);
    const make = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
    const date = value => value ? new Date(value.replace(' ', 'T')).toLocaleString('es-MX', {dateStyle: 'medium', timeStyle: 'short'}) : '';
    let started = false, base, charts = [], chartLibrary, editing = null, deleting = null, offset = 0, notes = [], summaryLocal = false, notesLocal = false, busy = false, generation = 0;
    const status = (id, text) => { const target = node(id); if (target) { target.textContent = text; target.hidden = !text; } };
    const writeState = () => { document.querySelectorAll('#dashboard [data-note-write]').forEach(el => { el.disabled = !navigator.onLine || notesLocal || busy; }); };
    async function request(path, method = 'GET', body) {
        if (method !== 'GET' && (!navigator.onLine || notesLocal)) throw new Error('Conéctate y actualiza tus notas para guardar cambios.');
        const response = await fetch(base + 'api/' + path, {method, credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content}, ...(body ? {body: JSON.stringify(body)} : {})});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'No fue posible consultar la información.');
        return {data: result.data, local: response.headers.get('X-Sicrat-Local') === '1', updated: Number(response.headers.get('X-Sicrat-Updated'))};
    }
    const empty = (target, text) => target.append(make('p', text, 'db-empty'));
    function icon(name) {
        const paths = {'users':'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0','network':'M9 3h6v6H9z M2 15h6v6H2z M16 15h6v6h-6z M12 9v3 M5 15v-3h14v3','vote':'m9 12 2 2 4-4 M4 5h16v15H4z','clipboard-check':'M9 4H5v17h14V4h-4 M9 2h6v4H9z m-1 11 3 3 5-5','map-pin':'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0 M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0','inbox':'M4 4h16v16H4z M4 14h5l2 3h2l2-3h5','building':'M5 21V3h14v18 M9 7h1 m4 0h1 M9 11h1 m4 0h1 M10 21v-5h4v5'};
        const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('viewBox','0 0 24 24'); svg.setAttribute('fill','none'); svg.setAttribute('stroke','currentColor'); svg.setAttribute('stroke-width','1.7'); svg.setAttribute('stroke-linecap','round'); svg.setAttribute('stroke-linejoin','round'); svg.setAttribute('aria-hidden','true');
        const path = document.createElementNS(svg.namespaceURI,'path'); path.setAttribute('d',paths[name] || paths.inbox); svg.append(path); return svg;
    }
    async function renderSummary(data, nonce) {
        node('db-context').textContent = data.election || 'Tu espacio de trabajo';
        status('db-message', data.message);
        const kpis = node('db-kpis'); kpis.replaceChildren(); kpis.style.setProperty('--db-count', data.kpis.length || 1); kpis.style.setProperty('--db-tablet-count', Math.min(data.kpis.length || 1, 3)); kpis.style.setProperty('--db-mobile-count', Math.min(data.kpis.length || 1, 2));
        const colors = ['#0f766e','#4f46e5','#b45309','#0369a1','#7c3aed','#be185d'];
        data.kpis.forEach((item, i) => {
            const card = make('article', undefined, 'db-kpi'); card.dataset.key = item.key; card.style.setProperty('--kpi-color', colors[i]);
            const top = make('div', undefined, 'db-kpi-top'), badge = make('span', undefined, 'db-kpi-icon'); badge.append(icon(item.icon));
            top.append(make('h3', item.label, 'db-kpi-label'), badge);
            card.append(top, make('div', new Intl.NumberFormat('es-MX').format(item.value), 'db-kpi-value'), make('p', item.detail, 'db-kpi-detail')); kpis.append(card);
        });
        node('db-pending-panel').hidden = !data.pending_available;
        node('db-activity-panel').hidden = !data.activity_available;
        node('db-pending-count').textContent = data.pending.length === 12 ? '12 recientes' : String(data.pending.length);
        const pending = node('db-pending'); pending.replaceChildren();
        if (!data.pending.length) empty(pending, 'No hay pendientes que requieran tu atención en este momento.');
        data.pending.forEach(item => {
            const row = make('article', undefined, 'db-list-item'); const link = make('a', item.title, 'db-item-title'); link.href = base + item.url;
            row.append(make('p', item.module + (item.urgent ? ' · Prioritario' : ''), 'db-item-meta' + (item.urgent ? ' db-item-urgent' : '')), link);
            if (item.deadline) row.append(make('p', 'Fecha límite: ' + date(item.deadline), 'db-item-meta')); pending.append(row);
        });
        const activity = node('db-activity'); activity.replaceChildren();
        if (!data.activity.length) empty(activity, 'Aún no hay movimientos recientes en tu ámbito.');
        data.activity.forEach(item => {
            const row = make('article', undefined, 'db-list-item db-timeline-item'), content = make('div');
            content.append(make('p', item.actor + ' · ' + item.action, 'db-item-title'), make('p', item.module + ' · ' + date(item.date), 'db-item-meta')); row.append(make('span', undefined, 'db-dot'), content); activity.append(row);
        });
        charts.forEach(chart => chart.destroy()); charts = []; const container = node('db-charts'); container.replaceChildren();
        node('dashboard').dataset.chartCount = data.charts.length;
        const slots = data.charts.map((item, index) => {
            const panel = make('section', undefined, 'db-panel'); panel.dataset.chart = item.key; panel.style.gridRow = index + 1; panel.style.order = index * 2;
            const plot = make('div', undefined, 'db-chart'); plot.setAttribute('aria-hidden','true');
            const details = make('details', undefined, 'db-chart-data'), values = make('ul');
            item.rows.forEach(row => { const li = make('li'); li.append(make('span', row.label), make('strong', row.value)); values.append(li); });
            details.append(make('summary', 'Consultar cifras de la gráfica'), values);
            panel.append(make('h3', item.title), make('p', item.type === 'area' ? 'Últimos ' + data.days + ' días · tu ámbito autorizado' : 'Estado actual · tu ámbito autorizado', 'db-muted'), plot, details); container.append(panel);
            return {item, plot};
        });
        if (!slots.length) return;
        try {
            chartLibrary ||= import(node('dashboard').dataset.charts);
            const {u: ApexCharts} = await chartLibrary;
            if (nonce !== generation) return;
            for (const {item, plot} of slots) {
                const chart = new ApexCharts(plot, {chart: {type: item.type, height: 235, width: '100%', toolbar: {show: false}, animations: {enabled: false}, fontFamily: 'inherit', foreColor: '#64748b', parentHeightOffset: 0}, series: [{name: 'Registros', data: item.rows.map(row => row.value)}], colors: ['#0d9488'], xaxis: {categories: item.rows.map(row => row.label), tickAmount: 5, labels: {trim: true, rotate: -35, hideOverlappingLabels: true, formatter: value => /^\d{4}-/.test(value) ? value.slice(5).split('-').reverse().join('/') : value}}, yaxis: {min: 0, forceNiceScale: true, labels: {formatter: value => Number.isInteger(value) ? String(value) : ''}}, plotOptions: {bar: {borderRadius: 4, columnWidth: '45%'}}, stroke: {width: item.type === 'area' ? 2 : 0, curve: 'straight'}, dataLabels: {enabled: false}, grid: {borderColor: '#e2e8f0', strokeDashArray: 4}, fill: {opacity: item.type === 'area' ? .15 : 1}, tooltip: {x: {show: true}}, noData: {text: 'Sin datos en este periodo'}});
                charts.push(chart); await chart.render();
            }
        } catch { container.querySelectorAll('.db-chart').forEach(el => { el.hidden = true; }); container.querySelectorAll('details').forEach(el => { el.open = true; }); }
    }
    async function summary() {
        const nonce = ++generation;
        try {
            const response = await request('dashboard?days=' + node('db-period').value);
            if (nonce !== generation) return;
            summaryLocal = response.local;
            status('db-status', summaryLocal ? 'Información guardada · ' + date(new Date(response.updated).toISOString()) + '. Conéctate para actualizarla.' : '');
            await renderSummary(response.data, nonce); node('dashboard').dataset.loaded = 'true';
        } catch (error) { status('db-status', error.message); }
    }
    function noteButton(label, action) { const button = make('button', label); button.type = 'button'; button.dataset.noteWrite = ''; button.addEventListener('click', action); return button; }
    function edit(note = null) {
        editing = note;
        node('db-note-title').textContent = note ? 'Editar nota personal' : 'Nueva nota personal';
        node('db-note-text').value = note?.texto || ''; node('db-note-pin').checked = !!note?.fijada;
        const reminder = note?.recordatorio ? new Date(note.recordatorio) : null;
        node('db-note-reminder').value = reminder ? new Date(reminder.getTime() - reminder.getTimezoneOffset() * 60000).toISOString().slice(0,16) : '';
        status('db-note-error',''); node('db-note-dialog').showModal(); node('db-note-text').focus();
    }
    async function loadNotes() {
        try {
            const response = await request('dashboard/notes?offset=' + offset); if (!node('dashboard')?.isConnected) return; notesLocal = response.local; notes = response.data.rows;
            status('db-notes-status', notesLocal ? 'Notas guardadas. Se necesita conexión para modificarlas.' : '');
            const target = node('db-notes'); target.replaceChildren();
            if (!notes.length) empty(target, 'Anota una idea o un recordatorio. Sólo tú podrás consultarlo.');
            notes.forEach(note => {
                const row = make('article', undefined, 'db-list-item' + (note.realizada ? ' db-note-done' : ''));
                if (note.fijada) row.append(make('p', 'Fijada', 'db-eyebrow'));
                row.append(make('p', note.texto, 'db-note-text'));
                if (note.recordatorio) row.append(make('p', 'Recordatorio: ' + date(note.recordatorio), 'db-item-meta' + (!note.realizada && Date.parse(note.recordatorio) <= Date.now() ? ' db-item-urgent' : '')));
                if (note.realizada) row.append(make('p', 'Realizada', 'db-item-meta'));
                const actions = make('div', undefined, 'db-note-actions');
                actions.append(noteButton('Editar', () => edit(note)), noteButton(note.realizada ? 'Reabrir' : 'Realizada', () => mutate(() => request('dashboard/notes/' + note.uuid, 'PUT', dto(note, {realizada: !note.realizada})))), noteButton(note.fijada ? 'Desfijar' : 'Fijar', () => mutate(() => request('dashboard/notes/' + note.uuid, 'PUT', dto(note, {fijada: !note.fijada})))), noteButton('Eliminar', () => { deleting = note; status('db-delete-error',''); node('db-delete-dialog').showModal(); })); row.append(actions); target.append(row);
            });
            node('db-notes-prev').hidden = offset === 0; node('db-notes-next').hidden = !response.data.more;
        } catch (error) { status('db-notes-status', error.message); }
        writeState();
    }
    const dto = (note, changes = {}) => { const value = {...note, ...changes}; return {texto: value.texto, recordatorio: value.recordatorio, fijada: Number(value.fijada), realizada: Number(value.realizada)}; };
    async function mutate(action, errorTarget = 'db-notes-status', dialog = null) {
        if (busy) return;
        busy = true; writeState();
        try { await action(); if (dialog) node(dialog).close(); await loadNotes(); }
        catch (error) { status(errorTarget, error.message); }
        finally { busy = false; writeState(); }
    }
    function start() {
        if (started || !node('dashboard')) return; started = true; base = node('dashboard').dataset.base.replace(/\/$/, '') + '/';
        node('db-period').addEventListener('change', summary);
        node('db-refresh').addEventListener('click', () => { summary(); loadNotes(); });
        node('db-note-new').addEventListener('click', () => edit());
        node('db-note-cancel').addEventListener('click', () => node('db-note-dialog').close());
        node('db-delete-cancel').addEventListener('click', () => node('db-delete-dialog').close());
        node('db-delete-confirm').addEventListener('click', () => mutate(() => request('dashboard/notes/' + deleting.uuid, 'DELETE'), 'db-delete-error', 'db-delete-dialog'));
        node('db-note-form').addEventListener('submit', event => {
            event.preventDefault(); const reminder = node('db-note-reminder').value;
            const data = {texto: node('db-note-text').value, recordatorio: reminder ? new Date(reminder).toISOString().replace('.000Z','Z') : null, fijada: Number(node('db-note-pin').checked), realizada: Number(editing?.realizada || false)};
            mutate(() => request('dashboard/notes' + (editing ? '/' + editing.uuid : ''), editing ? 'PUT' : 'POST', data), 'db-note-error', 'db-note-dialog');
        });
        node('db-notes-prev').addEventListener('click', () => { offset = Math.max(0,offset - 50); loadNotes(); });
        node('db-notes-next').addEventListener('click', () => { offset += 50; loadNotes(); });
        window.addEventListener('sicrat:pwa-locked', () => { generation++; editing = deleting = null; notes = []; });
        window.addEventListener('offline', writeState); window.addEventListener('online', () => { summary(); loadNotes(); });
        summary(); loadNotes(); writeState();
    }
    return {start};
})();
