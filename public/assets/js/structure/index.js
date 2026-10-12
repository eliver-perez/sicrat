let homeURL;
const structureState = {id: null, member: null, offset: 0, members: [], elections: [], levels: {}, mode: 'create', listMode: '', listOffset: 0};
function InitializeValues(url) {
    homeURL = url; const ui = window.SicratPhase3; ui.base = url; ui.closeButtons();
    const element = id => document.getElementById(id);
    const action = (id, work) => element(id).addEventListener('click', () => ui.run(work));
    const submit = (id, work) => element(id).addEventListener('submit', event => { event.preventDefault(); ui.run(() => work(new FormData(event.target))); });
    async function initialize() {
        structureState.me = (await ui.request('auth/me')).data;
        structureState.available = await ui.pages('auth/elections','electoral_processes');
        structureState.selected = structureState.available.find(election => election.selected);
        element('structure-create').hidden = !ui.allowed(structureState.me,'estructura.administrar');
        element('structure-clone').hidden = element('structure-create').hidden; element('structure-associate').hidden = element('structure-create').hidden;
        element('structure-add').hidden = !ui.allowed(structureState.me,'estructura.formar');
        await loadStructures();
    }
    async function loadStructures() {
        const rows = await ui.pages('structures'); const select = element('structure-select'); select.replaceChildren();
        rows.forEach(row => ui.option(select,row.id,row.name));
        if (structureState.id && rows.some(row => row.id === structureState.id)) select.value = structureState.id;
        structureState.id = select.value || null; structureState.offset = 0;
        if (structureState.id) await loadMembers(); else ui.message('Crea o asocia una estructura a la elección seleccionada.');
    }
    async function loadMembers() {
        const response = await ui.request(`structures/${structureState.id}?limit=25&offset=${structureState.offset}`); const data = response.data;
        structureState.members = data.members; structureState.elections = data.elections;
        element('structure-elections').textContent = `Elecciones: ${data.elections.map(election => election.name).join(' · ')}`;
        const body = element('structure-members'); body.replaceChildren();
        data.members.forEach(member => {
            const row = document.createElement('tr'); const name = document.createElement('td'); const button = ui.text('button',`${member.code} · ${member.name}`,'btn text-primary'); button.type = 'button'; button.addEventListener('click',() => ui.run(() => openMember(member.id))); name.append(button); row.append(name);
            const boss = data.members.find(candidate => candidate.id === member.responsible);
            [member.level_name || 'Sin nivel', boss?.name || (member.responsible ? 'Consultar superiores' : 'Sin responsable'), `${member.direct_count} / ${member.team_count}`, `${member.has_account ? 'Con cuenta' : 'Sin cuenta'} · ${member.active ? 'Activo' : 'Inactivo'}`].forEach(value => row.append(ui.text('td',value,'p-3'))); body.append(row);
        });
        if (!data.members.length) { const row = document.createElement('tr'); const cell = ui.text('td','Esta estructura todavía no tiene integrantes.'); cell.colSpan = 5; row.append(cell); body.append(row); }
        element('structure-previous').disabled = structureState.offset === 0; element('structure-next').disabled = !response.pagination.has_more;
        structureState.levels = {};
        for (const election of data.elections) {
            try { structureState.levels[election.id] = (await ui.request(`structure-levels?election=${election.id}`)).data; }
            catch (error) { structureState.levels[election.id] = null; }
        }
    }
    function memberOptions(select, emptyText = 'Sin responsable directo') {
        select.replaceChildren(); ui.option(select,'',emptyText);
        (structureState.allMembers || structureState.members).filter(member => member.active).forEach(member => ui.option(select,member.id,member.name));
    }
    async function allMembers() {
        const rows = []; let offset = 0;
        do { const response = await ui.request(`structures/${structureState.id}?limit=100&offset=${offset}`); rows.push(...response.data.members); if (!response.pagination.has_more) break; offset += 100; } while (true);
        structureState.allMembers = rows;
    }
    function levelFields(id, current = null) {
        const container = element(id); container.replaceChildren();
        structureState.elections.forEach(election => {
            if (structureState.levels[election.id] === null) return;
            const label = ui.text('label',`Nivel en ${election.name}`); const select = document.createElement('select'); select.className = 'form-input'; select.dataset.election = election.id; ui.option(select,'','Sin nivel');
            structureState.levels[election.id].filter(level => level.active).forEach(level => ui.option(select,level.id,level.name,current?.[election.id] === level.id)); label.append(select); container.append(label);
        });
    }
    function selectedLevels(id) { return Object.fromEntries(Array.from(element(id).querySelectorAll('select')).map(select => [select.dataset.election,select.value || null])); }
    async function containerDialog(mode) {
        structureState.mode = mode; element('structure-container-form').reset(); element('structure-container-title').textContent = mode === 'clone' ? 'Clonar estructura completa' : mode === 'associate' ? 'Asociar elección' : 'Nueva estructura';
        element('structure-container-form').elements.name.hidden = mode === 'associate'; element('structure-container-form').elements.name.required = mode !== 'associate';
        element('structure-name-field').hidden = mode === 'associate';
        const options = element('structure-election-options'); options.replaceChildren(); structureState.available.forEach(election => ui.checkbox(options,election.id,`${election.organization} · ${election.process}`,mode === 'create' && election.selected));
        element('structure-container-dialog').showModal();
    }
    async function openMember(id) {
        await allMembers(); const member = (await ui.request(`structures/${structureState.id}/members/${id}`)).data; structureState.member = member;
        element('structure-member-name').textContent = `${member.code} · ${member.name}`; element('structure-member-summary').textContent = `${member.level_name || 'Sin nivel'} · ${member.has_account ? 'Con cuenta' : 'Sin cuenta'} · Equipo directo: ${member.direct_count} · Total: ${member.team_count}`;
        element('structure-line').textContent = `Superiores: ${member.superiors.map(person => person.name).join(' → ') || 'Sin superiores visibles'}`;
        element('structure-team').textContent = `Equipo a cargo: ${member.team.map(person => person.name).join(', ') || 'Sin integrantes visibles'}`;
        structureState.coordinationOffset = 25;
        element('structure-coordination-more').hidden = !member.team_pagination.has_more && !member.superiors_pagination.has_more;
        element('structure-member-participations').textContent = `Participaciones: ${member.participations.map(election => election.name).join(' · ') || 'Sin participaciones registradas'}`;
        const admin = ui.allowed(structureState.me,'estructura.administrar'); element('structure-level-form').hidden = !admin; element('structure-state-form').hidden = !admin;
        element('structure-transfer-form').hidden = !ui.allowed(structureState.me,'estructura.transferir_integrante') && !ui.allowed(structureState.me,'estructura.transferir_equipo');
        element('structure-create-account').hidden = member.has_account || !ui.allowed(structureState.me,'usuarios.administrar');
        levelFields('structure-member-levels',member.levels);
        ['structure-transfer-form','structure-state-form'].forEach(formId => element(formId).querySelectorAll('select[name="destination"],select[name="replacement"],select[name="responsible"]').forEach(select => memberOptions(select)));
        element('structure-state-form').elements.active.value = member.active ? '0' : '1';
        element('structure-state-form').elements.responsible.value = member.responsible || '';
        element('structure-state-form').elements.responsible.disabled = !member.active;
        element('structure-member-dialog').showModal();
    }
    element('structure-select').addEventListener('change',event => ui.run(async () => { structureState.id = event.target.value; structureState.offset = 0; await loadMembers(); }));
    action('structure-create',() => containerDialog('create')); action('structure-clone',() => containerDialog('clone')); action('structure-associate',() => containerDialog('associate'));
    submit('structure-container-form',async form => {
        const elections = ui.selected(element('structure-election-options'));
        if (structureState.mode === 'associate') {
            await allMembers();
            for (const election of elections) {
                const catalog = (await ui.request(`structure-levels?election=${election}`)).data;
                const levels = {}; structureState.allMembers.forEach(member => { const same = catalog.find(level => level.active && level.order === member.level_order); if (same) levels[member.id] = same.id; });
                await ui.request(`structures/${structureState.id}/elections`,'POST',{election,levels});
            }
        } else {
            const path = structureState.mode === 'clone' ? `structures/${structureState.id}/clone` : 'structures';
            structureState.id = (await ui.request(path,'POST',{name:form.get('name'),elections})).data.id;
        }
        element('structure-container-dialog').close(); await loadStructures(); ui.message('Estructura guardada.');
    });
    action('structure-add',async () => {
        if (!structureState.id) return; await allMembers(); element('structure-add-form').reset(); element('structure-person').replaceChildren(); ui.option(element('structure-person'),'','Selecciona persona existente o realiza alta rápida'); element('structure-duplicates').replaceChildren(); memberOptions(element('structure-add-form').elements.responsible); levelFields('structure-add-levels');
        const choices = element('structure-participations'); choices.replaceChildren(); structureState.elections.forEach(election => ui.checkbox(choices,election.id,election.name)); element('structure-add-dialog').showModal();
    });
    action('structure-person-find',async () => { const data = (await ui.request(`persons?limit=100&search=${encodeURIComponent(element('structure-person-search').value)}`)).data; const select = element('structure-person'); select.replaceChildren(); ui.option(select,'','Alta rápida'); data.persons.forEach(person => ui.option(select,person.id,`${person.code} · ${person.name}`)); });
    submit('structure-add-form',async form => {
        const newPerson = {name:form.get('new_name'),last_name:form.get('new_last_name'),phone:form.get('new_phone'),confirm_duplicate:form.get('confirm_duplicate') ? 1 : 0};
        if (!element('structure-person').value) {
            const organizations = await ui.pages('organizations','organizations'); const selected = organizations.find(organization => organization.organization === structureState.selected?.organization) || organizations[0];
            newPerson.organization = selected.id; const inspection = (await ui.request('persons/duplicates','POST',newPerson)).data;
            if (inspection.blocked || (inspection.matches.length && !newPerson.confirm_duplicate)) {
                const matches = element('structure-duplicates'); matches.replaceChildren(ui.text('p','Revisa estas coincidencias antes de continuar:')); inspection.matches.forEach(person => { const button = ui.text('button',`${person.code} · ${person.name}`,'btn text-primary'); button.type='button'; button.addEventListener('click',() => { ui.option(element('structure-person'),person.id,person.name,true); }); matches.append(button); }); return;
            }
        }
        await ui.request(`structures/${structureState.id}/members`,'POST',{person:element('structure-person').value || null,new_person:newPerson,responsible:form.get('responsible') || null,levels:selectedLevels('structure-add-levels'),participations:ui.selected(element('structure-participations'))});
        element('structure-add-dialog').close(); await loadMembers(); ui.message('Persona incorporada.');
    });
    submit('structure-level-form',async () => { await ui.request(`structures/${structureState.id}/members/${structureState.member.id}/level`,'PUT',{levels:selectedLevels('structure-member-levels')}); element('structure-member-dialog').close(); await loadMembers(); });
    submit('structure-transfer-form',async form => { const result = (await ui.request(`structures/${structureState.id}/members/${structureState.member.id}/transfers`,'POST',Object.fromEntries(form))).data; element('structure-member-dialog').close(); await loadMembers(); ui.message(result.state === 'pendiente' ? 'Solicitud enviada para aprobación.' : 'Transferencia aplicada.'); });
    submit('structure-state-form',async form => { await ui.request(`structures/${structureState.id}/members/${structureState.member.id}/state`,'PUT',{...Object.fromEntries(form),levels:selectedLevels('structure-member-levels')}); element('structure-member-dialog').close(); await loadMembers(); });
    action('structure-create-account',async () => { const select = element('structure-account-form').elements.type; select.replaceChildren(); const rows = (await ui.request('users-types')).data.users_types; rows.forEach(role => ui.option(select,role.id,role.type)); element('structure-account-dialog').showModal(); });
    action('structure-coordination-more',async () => {
        const data = (await ui.request(`structures/${structureState.id}/members/${structureState.member.id}?limit=25&offset=${structureState.coordinationOffset}`)).data;
        if (data.superiors.length) element('structure-line').textContent += ` → ${data.superiors.map(person => person.name).join(' → ')}`;
        if (data.team.length) element('structure-team').textContent += `, ${data.team.map(person => person.name).join(', ')}`;
        structureState.coordinationOffset += 25; element('structure-coordination-more').hidden = !data.team_pagination.has_more && !data.superiors_pagination.has_more;
    });
    submit('structure-account-form',async form => {
        const person = (await ui.request(`persons/${structureState.member.person}`)).data;
        await ui.request('users','POST',{...Object.fromEntries(form),person:person.id,organization:person.organization,name:person.name,last_name:person.last_name,second_last_name:person.last_name_2,process:structureState.selected.id,status:1});
        element('structure-account-dialog').close(); element('structure-member-dialog').close(); await loadMembers(); ui.message('Cuenta vinculada a la persona existente.');
    });
    async function listRecords(reset = false) {
        if (reset) { structureState.listOffset = 0; element('structure-list-content').replaceChildren(); }
        const response = await ui.request(`structures/${structureState.id}/${structureState.listMode}?limit=25&offset=${structureState.listOffset}`);
        element('structure-list-title').textContent = structureState.listMode === 'history' ? 'Historial de la estructura' : 'Solicitudes de transferencia';
        const target = element('structure-list-content');
        let body = target.querySelector('tbody');
        if (!body && response.data.length) {
            const table = document.createElement('table'); const header = document.createElement('tr');
            ['Fecha','Actividad','Estado','Motivo','Acciones'].forEach(label => header.append(ui.text('th',label)));
            body = document.createElement('tbody'); table.append(header,body); target.append(table);
        }
        if (!response.data.length && reset) target.append(ui.text('p','No hay movimientos visibles.','ux-help'));
        response.data.forEach(row => {
            const line = document.createElement('tr'); const history = structureState.listMode === 'history';
            line.append(ui.text('td',history ? row.fecha : row.date),ui.text('td',history ? window.SicratUI.label(row.accion) : row.mode === 'equipo' ? 'Transferir integrante y equipo' : 'Transferir integrante'));
            const status = document.createElement('td'); if (!history) status.append(window.SicratUI.badge(window.SicratUI.label(row.state),row.state === 'aprobada'));
            line.append(status,ui.text('td',(history ? row.motivo : row.reason) || '—'));
            const actions = document.createElement('td');
            if (row.state === 'pendiente' && row.can_resolve) [1,0].forEach(approved => {
                const button = ui.text('button',approved ? 'Aprobar' : 'Rechazar',approved ? 'btn bg-primary text-white' : 'btn bg-danger text-white'); button.type='button';
                button.addEventListener('click',() => ui.run(async () => { const reason=window.prompt('Motivo de la resolución'); if (!reason) return; await ui.request(`structures/${structureState.id}/transfers/${row.id}`,'PUT',{approved,reason}); await listRecords(true); await loadMembers(); })); actions.append(button);
            }); line.append(actions); body.append(line);
        });
        element('structure-list-more').hidden = !response.pagination.has_more; structureState.listOffset += 25;
    }
    action('structure-requests',async () => { structureState.listMode='transfers'; await listRecords(true); element('structure-list-dialog').showModal(); });
    action('structure-history',async () => { structureState.listMode='history'; await listRecords(true); element('structure-list-dialog').showModal(); }); action('structure-list-more',() => listRecords());
    action('structure-previous',async () => { structureState.offset = Math.max(0,structureState.offset-25); await loadMembers(); }); action('structure-next',async () => { structureState.offset += 25; await loadMembers(); });
    ui.run(initialize);
}
