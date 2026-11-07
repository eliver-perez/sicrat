(() => {
    const labels = {
        personas: 'Personas', estructura: 'Estructura', operaciones: 'Tareas, peticiones e incidencias', jornada: 'Jornada Electoral', mensajes: 'Mensajes',
        persons: 'Personas', members: 'Integrantes', rows: 'Registros', name: 'Nombre', nombre: 'Nombre', paterno: 'Apellido paterno', materno: 'Apellido materno',
        phone: 'Teléfono', email: 'Correo', level_name: 'Nivel', parent: 'Responsable', direct_count: 'Equipo directo', team_count: 'Integrantes del equipo',
        focus: 'Responsable consultado', structure: 'Estructura', state: 'Estado', title: 'Título', description: 'Descripción', message: 'Mensaje', mensaje: 'Mensaje',
        remitente: 'Remitente', f_envio: 'Enviado', seccion: 'Sección', address: 'Dirección', status: 'Estado', data: 'Información', pagination: 'Página'
    };
    const privateFields = /^(?:uuid|.*_uuid|id|.*_id|token|csrf_token|password|.*_url|url|version|permissions|rights|scopes|attachments|files|photos|photo|thumbnail)$/i;
    let records = [];
    let generation = 0;
    let reading = false;
    const element = id => document.getElementById(id);
    const text = (tag, value) => { const node = document.createElement(tag); node.textContent = String(value); return node; };
    function label(key) { return labels[key] || key.replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase()); }
    function clear() {
        generation++; records = [];
        element('offline-records').replaceChildren();
        element('offline-content').hidden = true;
        element('offline-account').textContent = 'Tu información, en este dispositivo';
    }
    function fields(value, depth = 0) {
        const container = document.createElement('div');
        if (depth > 5 || value === null || value === undefined) return container;
        if (Array.isArray(value)) {
            if (!value.length) container.append(text('p', 'Sin registros en esta consulta.'));
            value.forEach(row => { const card = document.createElement('article'); card.className = 'pwa-record'; card.append(fields(row, depth + 1)); container.append(card); });
        } else if (typeof value === 'object') {
            const list = document.createElement('dl');
            Object.entries(value).forEach(([key, child]) => {
                if (privateFields.test(key) || key.startsWith('can_') || child === null || child === '') return;
                list.append(text('dt', label(key)));
                const description = document.createElement('dd');
                if (typeof child === 'object') description.append(fields(child, depth + 1));
                else description.textContent = typeof child === 'boolean' ? (child ? 'Sí' : 'No') : String(child);
                list.append(description);
            });
            container.append(list);
        } else container.textContent = String(value);
        return container;
    }
    function render() {
        const query = element('offline-search').value.trim().toLocaleLowerCase('es');
        const module = element('offline-module').value;
        const shown = records.filter(row => (!module || row.module === module)
            && (!query || JSON.stringify(row.body.data).toLocaleLowerCase('es').includes(query)));
        element('offline-records').replaceChildren();
        element('offline-empty').hidden = shown.length > 0;
        shown.sort((a, b) => b.updatedAt - a.updatedAt).forEach(row => {
            const card = document.createElement('details'); card.className = 'pwa-card';
            const url = new URL(row.url, location.origin);
            const search = url.searchParams.get('search');
            const title = row.body.data?.structure?.name || row.body.data?.focus?.name || row.body.data?.name || row.body.data?.nombre;
            const page = Math.floor(Number(url.searchParams.get('offset') || 0) / Number(url.searchParams.get('limit') || 25)) + 1;
            card.append(text('summary', `${labels[row.module]}${title ? ' · ' + title : ''}${search ? ' · Búsqueda: ' + search : ''} · Página ${page}`));
            card.append(text('p', `Última actualización: ${SicratPwa.date(row.updatedAt)}`));
            const content = document.createElement('div');
            card.addEventListener('toggle', () => { if (card.open && !content.childNodes.length) content.append(fields(row.body.data)); });
            card.append(content); element('offline-records').append(card);
        });
    }
    async function load() {
        if (reading || !SicratPwa.state.unlocked || SicratPwa.state.validating) return;
        reading = true;
        const version = ++generation;
        try {
            const saved = await SicratPwa.send('LIST');
            if (version !== generation) return;
            records = saved;
            const context = SicratPwa.state.context;
            element('offline-account').textContent = `${context.name} · ${context.election_name || 'Contexto general'}`;
            element('offline-state').textContent = `Solo lectura. Última validación: ${SicratPwa.date(context.validated_at)}. No se guardan formularios ni envíos pendientes.`;
            element('offline-content').hidden = false;
            element('offline-unlock').textContent = 'Administrar perfil offline';
            render();
        } catch (error) { clear(); element('offline-state').textContent = error.message; }
        finally { reading = false; }
    }
    element('offline-unlock').addEventListener('click', () => SicratPwa.settings());
    element('offline-search').addEventListener('input', render);
    element('offline-module').addEventListener('change', render);
    window.addEventListener('sicrat:pwa-state', event => { if (event.detail.unlocked) load(); else clear(); });
    window.addEventListener('sicrat:pwa-locked', event => { clear(); element('offline-state').textContent = event.detail; });
    window.addEventListener('sicrat:pwa-validating', clear);
    window.addEventListener('sicrat:pwa-updated', load);
    window.addEventListener('sicrat:pwa-invalidated', () => { clear(); load(); });
})();
