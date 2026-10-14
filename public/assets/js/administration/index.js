let homeURL;
window.SicratModuleVisibility = (module, superadmin, election) => superadmin || Boolean(Number(module.esencial))
    || Boolean(Number(module.autorizacion_organizacion) && (!election || Number(module.autorizacion_eleccion)));
function InitializeValues(url) {
    homeURL = url; const ui = window.SicratPhase3; ui.base = url;
    const element = id => document.getElementById(id); const form = id => element(id).elements;
    const state = {me: null, election: null, organizations: [], permissions: [], modules: [], changes: new Map(), territories: new Map(), sectionOffset: 0, sectionRequest: 0, territoryRequest: 0};
    const visual = window.SicratUI;
    const scopes = {propio:'Información propia',directos:'Equipo directo',descendientes:'Equipo a cargo completo',territorio:'Territorio autorizado',eleccion:'Esta elección',organizacion:'Toda la organización'};
    const submit = (id, work) => element(id).addEventListener('submit', event => { event.preventDefault(); const values = new FormData(event.target); ui.run(() => work(values),event.currentTarget); });
    const action = (id, work) => element(id).addEventListener('click',event => ui.run(work,id === 'module-save' ? null : event.currentTarget));
    async function modules() {
        const organization = element('module-organization').value; if (!organization) return;
        const sameOrganization = state.election && state.organizations.find(item => item.id === organization)?.organization === state.election.organization;
        element('module-context').querySelector('option[value=election]').disabled = !sameOrganization;
        if (!sameOrganization) element('module-context').value = 'organization';
        const election = element('module-context').value === 'election' ? state.election?.id : null;
        const rows = (await ui.request(`organizations/${organization}/modules${election ? '?election=' + election : ''}`)).data;
        state.modules = rows; state.changes.clear();
        element('module-list').replaceChildren(); element('module-essential').replaceChildren();
        element('module-save').disabled = true; element('module-save-status').textContent = 'Sin cambios pendientes';
        rows.forEach(module => {
            if (Number(module.esencial)) { element('module-essential').append(visual.badge(module.nombre + ' ✓', true)); return; }
            if (!window.SicratModuleVisibility(module,state.me.is_superadmin,election)) return;
            const row = ui.text('div','','card ux-module-card'); row.append(ui.text('h6',module.nombre));
            const implemented = Boolean(Number(module.implementado));
            const parentAuthorized = !election || Boolean(Number(module.autorizacion_organizacion));
            const authorized = document.createElement('input'); authorized.type = 'checkbox'; authorized.setAttribute('role','switch');
            authorized.checked = Boolean(Number(module[election ? 'autorizacion_eleccion' : 'autorizacion_organizacion'])); authorized.disabled = !state.me.is_superadmin || !parentAuthorized;
            const selected = document.createElement('input'); selected.type = 'checkbox'; selected.setAttribute('role','switch'); selected.checked = Boolean(Number(module[election ? 'seleccion_eleccion' : 'seleccion_organizacion']));
            const badge = visual.badge(''); row.append(badge);
            const update = () => {
                selected.disabled = !implemented || !parentAuthorized || !authorized.checked;
                badge.textContent = !implemented ? 'Próximamente' : !parentAuthorized || !authorized.checked ? 'No autorizado' : selected.checked ? 'Activo' : 'Disponible';
                badge.className = 'ux-badge ' + (implemented && authorized.checked && selected.checked ? 'ux-badge-success' : 'ux-badge-neutral');
            };
            const authorizationLabel = ui.text('label','Autorizado'); authorizationLabel.append(authorized);
            const selectionLabel = ui.text('label',election ? 'Utilizar en esta elección' : 'Utilizar en la organización'); selectionLabel.append(selected);
            if (state.me.is_superadmin) row.append(authorizationLabel);
            row.append(selectionLabel); update();
            const changed = () => {
                update(); const data = {selected:selected.checked ? 1 : 0}; if (election) data.election = election;
                if (state.me.is_superadmin) data.authorized = authorized.checked ? 1 : 0;
                state.changes.set(module.codigo,data); element('module-save').disabled = false; element('module-save-status').textContent = state.changes.size + ' cambios pendientes';
            };
            authorized.addEventListener('change',() => { if (!authorized.checked) selected.checked = false; changed(); }); selected.addEventListener('change',changed);
            element('module-list').append(row);
        });
    }
    action('module-save',async () => {
        if (element('module-save').disabled) return;
        const button = element('module-save'); button.disabled = true;
        element('module-save-status').textContent = 'Guardando…';
        const controls = Array.from(element('administration-modules').querySelectorAll('input,select'));
        const disabled = controls.map(control => control.disabled); controls.forEach(control => control.disabled = true);
        try {
            for (const [code,data] of state.changes) {
                await ui.request(`organizations/${element('module-organization').value}/modules/${code}`,'PUT',data); state.changes.delete(code);
            }
            await modules(); ui.message('Módulos actualizados correctamente.');
        } catch (error) {
            button.disabled = !state.changes.size; element('module-save-status').textContent = 'Error: quedan ' + state.changes.size + ' cambios sin guardar. ' + error.message; throw error;
        } finally { controls.forEach((control,index) => control.disabled = disabled[index]); }
    });
    async function levels() {
        const rows = (await ui.request('structure-levels')).data; element('level-list').replaceChildren();
        rows.forEach(level => { const button = ui.text('button',`${level.order}. ${level.name} · ${level.active ? 'Activo' : 'Inactivo'}`,'btn block text-primary'); button.type='button'; button.addEventListener('click',() => { const fields=form('level-form'); fields.id.value=level.id; fields.name.value=level.name; fields.order.value=level.order; ['active','can_recruit','same_level'].forEach(key => fields[key].checked=Boolean(level[key])); }); element('level-list').append(button); });
        const config=(await ui.request('structure-config')).data; ['auto_promote','approval_required'].forEach(key => form('structure-config-form')[key].checked=Boolean(config[key])); const approver=form('structure-config-form').approver;if(config.approver && !Array.from(approver.options).some(option=>option.value===config.approver))ui.option(approver,config.approver,config.approver_name || 'Cuenta que autoriza las transferencias');approver.value=config.approver || '';
    }
    async function roles() {
        const rows=(await ui.request('roles')).data; form('permission-form').role.replaceChildren(); element('role-list').replaceChildren(); rows.forEach(role => { ui.option(form('permission-form').role,role.id,role.name); element('role-list').append(visual.badge(role.name)); });
    }
    async function initialize() {
        state.me=(await ui.request('auth/me')).data; state.election=(await ui.pages('auth/elections','electoral_processes')).find(election => election.selected);
        element('module-description').textContent = state.me.is_superadmin
            ? 'Autoriza los módulos disponibles para cada organización y elección.'
            : 'Selecciona los módulos que deseas utilizar en esta elección.';
        element('module-heading').textContent = state.me.is_superadmin ? 'Módulos de la organización o elección' : 'Módulos disponibles para esta elección';
        if (!state.me.is_superadmin) { element('module-organization-field').hidden = true; element('module-context-field').hidden = true; }
        element('administration-modules').hidden=!ui.allowed(state.me,'modulos.seleccionar'); element('administration-levels').hidden=!ui.allowed(state.me,'niveles.administrar'); element('administration-permissions').hidden=!ui.allowed(state.me,'permisos.administrar');
        if (!state.me.is_superadmin && !state.election) element('administration-modules').hidden = true;
        if (!element('administration-modules').hidden) {
            state.organizations=await ui.pages('organizations','organizations'); state.organizations.forEach(organization => ui.option(element('module-organization'),organization.id,organization.organization,organization.organization === state.election?.organization)); await modules(); previousOrganization = element('module-organization').value;
        }
        const canManageUsers=ui.allowed(state.me,'usuarios.administrar'); let users=[];
        if (canManageUsers) users=(await ui.request('users?limit=25')).data.users;
        [form('permission-form').user,form('territory-form').user,form('structure-config-form').approver].forEach(select => {
            users.forEach(user => ui.option(select,user.id,`${user.username} · ${user.name}`));
            if(!canManageUsers)return;const find=ui.text('button','Buscar cuenta','btn');find.type='button';find.className='btn ux-account-find';select.after(find);find.addEventListener('click',()=>ui.run(()=>visual.entitySelector({title:'Seleccionar cuenta',selected:select.value,load:async(search,offset)=>{const response=await ui.request('users?'+new URLSearchParams({search,offset,limit:25}));return {rows:response.data.users.map(user=>({value:user.id,label:user.username+' · '+user.name})),pagination:response.pagination};},choose:user=>{if(!Array.from(select.options).some(option=>option.value===user.value))ui.option(select,user.value,user.label);select.value=user.value;select.dispatchEvent(new Event('change'));}}),find));
        });
        const activate = visual.tabs(element('administration-tabs'));
        ['modules','levels','permissions'].forEach(key => element('administration-tab-' + key).hidden = !ui.allowed(state.me,{modules:'modulos.seleccionar',levels:'niveles.administrar',permissions:'permisos.administrar'}[key]));
        const initial = element('administration-tabs').querySelector('button:not([hidden])'); if (initial) activate(initial);
        if (!state.election) { ui.state('Selecciona una elección de trabajo para configurar niveles y permisos electorales.'); return; }
        if (ui.allowed(state.me,'niveles.administrar')) {
            const capabilities=(await ui.request('operations/capabilities')).data;
            if(capabilities.modules.some(module=>module.code==='estructura' && module.enabled))await levels();
            else {element('level-list').textContent='Habilita Estructura organizacional para configurar sus niveles y reglas.';element('level-form').hidden=true;element('structure-config-form').hidden=true;}
        }
        if (ui.allowed(state.me,'permisos.administrar')) {
            await roles(); const catalog=(await ui.request('permissions')).data; state.permissions = catalog.permissions; permissionOptions();
            const names={propio:'Información propia',directos:'Equipo directo',descendientes:'Equipo a cargo completo',territorio:'Territorio autorizado',eleccion:'Toda la elección',organizacion:'Toda la organización'}; catalog.scopes.forEach(scope => ui.option(form('permission-form').scope,scope,names[scope]));
            element('permission-global').hidden=!state.me.is_superadmin;
            await loadTerritories();
        }
    }
    let previousOrganization; let previousContext = 'election';
    async function switchModules() {
        if (state.changes.size && !await window.SicratFeedback.confirm('Hay cambios sin guardar. ¿Quieres descartarlos y cambiar de ámbito?',{confirm:'Descartar cambios'})) { element('module-organization').value = previousOrganization; element('module-context').value = previousContext; return; }
        previousOrganization = element('module-organization').value; previousContext = element('module-context').value; ui.run(modules);
    }
    element('module-organization').addEventListener('change',switchModules); element('module-context').addEventListener('change',switchModules);
    action('level-new',() => { element('level-form').reset(); form('level-form').id.value=''; });
    submit('level-form',async values => { const data=Object.fromEntries(values); ['active','can_recruit','same_level'].forEach(key => data[key]=values.has(key) ? 1 : 0); await ui.request(`structure-levels${data.id ? '/' + data.id : ''}`,data.id ? 'PUT' : 'POST',data); await levels(); ui.message('Nivel guardado.'); });
    submit('structure-config-form',async values => { await ui.request('structure-config','PUT',{auto_promote:values.has('auto_promote') ? 1 : 0,approval_required:values.has('approval_required') ? 1 : 0,approver:values.get('approver') || null}); ui.message('Reglas guardadas.'); });
    submit('role-form',async values => { await ui.request('roles','POST',Object.fromEntries(values)); await roles(); ui.message('Rol creado.'); });
    function permissionPath(values) { return values.get('target') === 'role' ? `roles/${values.get('role')}/permissions` : `users/${values.get('user')}/permissions`; }
    submit('permission-form',async values => { const data=Object.fromEntries(values); if (data.value === '0' && !await window.SicratFeedback.confirm('¿Revocar este permiso en el ámbito seleccionado?',{confirm:'Revocar permiso'})) return; if (data.context !== 'global') data.election=state.election.id; await ui.request(permissionPath(values),'PUT',data); ui.message(data.value === '0' ? 'Permiso revocado correctamente.' : 'Permiso agregado correctamente.'); await inspectPermissions(); });
    let configured = [];
    function permissionOptions() {
        const fields = form('permission-form'); const previous = fields.permission.value; fields.permission.replaceChildren();
        const groups = new Map();
        state.permissions.filter(permission => fields.context.value === 'global' ? permission.scope !== 'electoral' : permission.scope !== 'global').forEach(permission => {
            const category = visual.label(permission.module || permission.id.split('.')[0]);
            if (!groups.has(category)) { const group = document.createElement('optgroup'); group.label = category; fields.permission.append(group); groups.set(category,group); }
            ui.option(groups.get(category),permission.id,permission.name || visual.label(permission.id));
        });
        if (Array.from(fields.permission.options).some(option => option.value === previous)) fields.permission.value = previous;
    }
    function decisionHelp() {
        element('permission-decision-help').textContent = form('permission-form').value.value === '0'
            ? 'Impide este permiso en el ámbito elegido, aunque esté heredado del rol.'
            : form('permission-form').target.value === 'user' ? 'Agrega este permiso para la cuenta aunque su rol no lo incluya.' : 'Concede este permiso a las cuentas que utilizan el rol.';
    }
    function renderPermissions() {
        const target = element('permission-list'); target.replaceChildren();
        const search = element('permission-search').value.toLocaleLowerCase(); const value = element('permission-state-filter').value;
        const title = code => state.permissions.find(item => item.id === code)?.name || visual.label(code);
        const rows = configured.filter(row => title(row.permission).toLocaleLowerCase().includes(search) && (value === '' || String(row.value) === value));
        if (!rows.length) { target.append(ui.text('p','Sin permisos configurados que coincidan con la búsqueda.','ux-help')); return; }
        const table = document.createElement('table'); const head = document.createElement('tr');
        ['Permiso','Estado','Alcance','Origen'].forEach(label => head.append(ui.text('th',label))); table.append(head);
        rows.forEach(row => {
            const line = document.createElement('tr'); line.append(ui.text('td',title(row.permission)));
            const status = document.createElement('td'); status.append(visual.badge(Number(row.value) ? 'Concedido' : 'Revocado',Boolean(Number(row.value)))); line.append(status,ui.text('td',scopes[row.scope] || visual.label(row.scope)));
            const isRole = form('permission-form').target.value === 'role';
            const role = form('permission-form').role.selectedOptions[0]?.textContent || '';
            line.append(ui.text('td',(isRole ? 'Rol ' + role : 'Excepción individual') + ' · ' + (row.proceso === null ? (isRole && row.contexto !== 'global' ? 'Heredado del rol' : 'Configuración global') : 'Configurado para esta elección'))); table.append(line);
        }); target.append(table);
    }
    async function inspectPermissions() {
        const request=state.permissionRequest=(state.permissionRequest || 0)+1;
        configured=[];renderPermissions();element('permission-list').textContent='Cargando permisos…';
        const values = new FormData(element('permission-form'));const context=values.get('context');
        if (!(values.get('target') === 'role' ? values.get('role') : values.get('user'))) { element('permission-list').textContent='Selecciona un rol o usuario para consultar sus permisos.';return; }
        const result=(await ui.request(permissionPath(values))).data;
        if(request!==state.permissionRequest)return;
        configured=context==='global' ? result.filter(row=>values.get('target')==='role' ? row.contexto==='global' : row.proceso===null) : result;renderPermissions();
    }
    function clearPermissions() {state.permissionRequest=(state.permissionRequest || 0)+1;configured=[];renderPermissions();}
    [form('permission-form').role,form('permission-form').user,form('permission-form').context].forEach(control=>control.addEventListener('change',clearPermissions));
    element('electoral-context-select')?.addEventListener('change',clearPermissions);
    action('permission-inspect',inspectPermissions);
    element('permission-search').addEventListener('input',renderPermissions); element('permission-state-filter').addEventListener('change',renderPermissions);
    form('permission-form').context.addEventListener('change',permissionOptions); form('permission-form').value.addEventListener('change',decisionHelp);
    visual.tabs(element('permission-tabs'),button => {
        const target = button.dataset.target; const editor = element('permission-editor'); editor.hidden = !['role','user'].includes(target);
        if (!editor.hidden) {
            element('permission-panel-' + target).append(editor); form('permission-form').target.value = target;
            element('permission-role-field').hidden = target !== 'role'; element('permission-user-field').hidden = target !== 'user';
            form('permission-form').role.disabled = target !== 'role'; form('permission-form').user.disabled = target !== 'user';
            clearPermissions(); decisionHelp();
        }
    })(element('permission-tab-roles'));
    let sectionTimer;
    async function sections() {
        const request = ++state.sectionRequest;
        const response = await ui.request('sections?' + new URLSearchParams({limit:25,offset:state.sectionOffset,search:element('territory-search').value}));
        if (request !== state.sectionRequest) return;
        const list = element('territory-options'); list.replaceChildren();
        response.data.sections.forEach(section => {
            const label = ui.text('label',''); const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.value = section.id; checkbox.checked = state.territories.has(section.id);
            label.append(checkbox,ui.text('span',String(section.section) + (section.municipality ? ' · ' + section.municipality : ''))); list.append(label);
            checkbox.addEventListener('change',() => { if (checkbox.checked) state.territories.set(section.id,section.section); else state.territories.delete(section.id); territoryCount(); });
        });
        if (!response.data.sections.length) list.append(ui.text('p','No se encontraron secciones.','ux-help'));
        element('territory-previous').disabled = state.sectionOffset === 0; element('territory-next').disabled = !response.pagination.has_more; territoryCount();
    }
    function territoryCount() { element('territory-count').textContent = state.territories.size + ' seleccionadas (máximo 100)'; }
    async function loadTerritories() {
        const user = form('territory-form').user.value; const request = ++state.territoryRequest;
        const save = element('territory-form').querySelector('button:not([type])'); save.disabled = true;
        const rows = user ? (await ui.request(`users/${user}/territories`)).data : [];
        if (request !== state.territoryRequest) return;
        state.territories.clear(); rows.forEach(section => state.territories.set(section.id,section.section));
        state.sectionOffset = 0; await sections(); if (request === state.territoryRequest) save.disabled = !user;
    }
    form('territory-form').user.addEventListener('change',() => ui.run(loadTerritories));
    element('territory-search').addEventListener('input',() => { clearTimeout(sectionTimer); sectionTimer = setTimeout(() => { state.sectionOffset=0; ui.run(sections); },250); });
    action('territory-previous',async () => { state.sectionOffset=Math.max(0,state.sectionOffset-25); await sections(); });
    action('territory-next',async () => { state.sectionOffset+=25; await sections(); });
    action('territory-clear',async () => { state.territories.clear(); await sections(); });
    submit('territory-form',async values => { await ui.request(`users/${values.get('user')}/territories`,'PUT',{sections:Array.from(state.territories.keys())}); ui.message('Territorios guardados.'); });
    ui.run(initialize);
}
