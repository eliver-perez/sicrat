let homeURL;
function InitializeValues(url) {
    homeURL = url; const ui = window.SicratPhase3; ui.base = url;
    const element = id => document.getElementById(id); const form = id => element(id).elements;
    const state = {me: null, election: null, organizations: []};
    const submit = (id, work) => element(id).addEventListener('submit', event => { event.preventDefault(); ui.run(() => work(new FormData(event.target))); });
    const action = (id, work) => element(id).addEventListener('click',() => ui.run(work));
    async function modules() {
        const organization = element('module-organization').value; if (!organization) return;
        const election = element('module-context').value === 'election' ? state.election?.id : null;
        const rows = (await ui.request(`organizations/${organization}/modules${election ? '?election=' + election : ''}`)).data; element('module-list').replaceChildren();
        rows.forEach(module => {
            const row = ui.text('div',`${module.nombre}${module.esencial ? ' · Esencial' : module.implementado ? '' : ' · Disponible para una fase posterior'}`,'card p-3 my-3');
            if (module.esencial) row.append(ui.text('p','Siempre habilitado'));
            else {
                if (election) row.append(ui.text('p',`Disponible en la organización: ${Number(module.autorizacion_organizacion) ? 'Sí' : 'No'}`));
                const authorized = document.createElement('input'); authorized.type = 'checkbox'; authorized.checked = Boolean(Number(module[election ? 'autorizacion_eleccion' : 'autorizacion_organizacion'])); authorized.disabled = !state.me.is_superadmin;
                const selected = document.createElement('input'); selected.type = 'checkbox'; selected.checked = Boolean(Number(module[election ? 'seleccion_eleccion' : 'seleccion_organizacion']));
                const authorizationLabel = ui.text('label',election ? ' Autorizado en esta elección ' : ' Autorizado en la organización '); authorizationLabel.prepend(authorized); const selectionLabel = ui.text('label',' Seleccionado '); selectionLabel.prepend(selected); row.append(authorizationLabel,selectionLabel);
                const save = ui.text('button','Guardar','btn'); save.type='button'; save.addEventListener('click',() => ui.run(async () => { const data={selected:selected.checked ? 1 : 0}; if (election) data.election=election; if (state.me.is_superadmin) data.authorized=authorized.checked ? 1 : 0; await ui.request(`organizations/${organization}/modules/${module.codigo}`,'PUT',data); await modules(); ui.message('Configuración del módulo guardada.'); })); row.append(save);
            }
            element('module-list').append(row);
        });
    }
    async function levels() {
        const rows = (await ui.request('structure-levels')).data; element('level-list').replaceChildren();
        rows.forEach(level => { const button = ui.text('button',`${level.order}. ${level.name} · ${level.active ? 'Activo' : 'Inactivo'}`,'btn block text-primary'); button.type='button'; button.addEventListener('click',() => { const fields=form('level-form'); fields.id.value=level.id; fields.name.value=level.name; fields.order.value=level.order; ['active','can_recruit','same_level'].forEach(key => fields[key].checked=Boolean(level[key])); }); element('level-list').append(button); });
        const config=(await ui.request('structure-config')).data; ['auto_promote','approval_required'].forEach(key => form('structure-config-form')[key].checked=Boolean(config[key])); form('structure-config-form').approver.value=config.approver || '';
    }
    async function roles() {
        const rows=(await ui.request('roles')).data; form('permission-form').role.replaceChildren(); rows.forEach(role => ui.option(form('permission-form').role,role.id,role.name));
    }
    async function initialize() {
        state.me=(await ui.request('auth/me')).data; state.election=(await ui.pages('auth/elections','electoral_processes')).find(election => election.selected);
        element('administration-modules').hidden=!ui.allowed(state.me,'modulos.seleccionar'); element('administration-levels').hidden=!ui.allowed(state.me,'niveles.administrar'); element('administration-permissions').hidden=!ui.allowed(state.me,'permisos.administrar');
        if (!element('administration-modules').hidden) {
            state.organizations=await ui.pages('organizations','organizations'); state.organizations.forEach(organization => ui.option(element('module-organization'),organization.id,organization.organization,organization.organization === state.election?.organization)); await modules();
        }
        const canManageUsers=ui.allowed(state.me,'usuarios.administrar'); let users=[];
        if (canManageUsers) users=await ui.pages('users','users');
        [form('permission-form').user,form('territory-form').user,form('structure-config-form').approver].forEach(select => users.forEach(user => ui.option(select,user.id,`${user.username} · ${user.name}`)));
        if (!state.election) { ui.message('Selecciona una elección de trabajo para configurar niveles y permisos electorales.'); return; }
        if (!element('administration-levels').hidden) await ui.run(levels);
        if (!element('administration-permissions').hidden) {
            await roles(); const catalog=(await ui.request('permissions')).data; catalog.permissions.forEach(permission => ui.option(form('permission-form').permission,permission.id,`${permission.id} · ${permission.name}`));
            const names={propio:'Información propia',directos:'Equipo directo',descendientes:'Equipo a cargo completo',territorio:'Territorio autorizado',eleccion:'Toda la elección',organizacion:'Toda la organización'}; catalog.scopes.forEach(scope => ui.option(form('permission-form').scope,scope,names[scope]));
            element('permission-global').hidden=!state.me.is_superadmin;
            const sections=await ui.pages('sections','sections'); sections.forEach(section => ui.option(form('territory-form').sections,section.id,String(section.section)));
        }
    }
    element('module-organization').addEventListener('change',() => ui.run(modules)); element('module-context').addEventListener('change',() => ui.run(modules));
    action('level-new',() => { element('level-form').reset(); form('level-form').id.value=''; });
    submit('level-form',async values => { const data=Object.fromEntries(values); ['active','can_recruit','same_level'].forEach(key => data[key]=values.has(key) ? 1 : 0); await ui.request(`structure-levels${data.id ? '/' + data.id : ''}`,data.id ? 'PUT' : 'POST',data); await levels(); ui.message('Nivel guardado.'); });
    submit('structure-config-form',async values => { await ui.request('structure-config','PUT',{auto_promote:values.has('auto_promote') ? 1 : 0,approval_required:values.has('approval_required') ? 1 : 0,approver:values.get('approver') || null}); ui.message('Reglas guardadas.'); });
    submit('role-form',async values => { await ui.request('roles','POST',Object.fromEntries(values)); await roles(); ui.message('Rol creado.'); });
    function permissionPath(values) { return values.get('target') === 'role' ? `roles/${values.get('role')}/permissions` : `users/${values.get('user')}/permissions`; }
    submit('permission-form',async values => { const data=Object.fromEntries(values); if (data.context !== 'global') data.election=state.election.id; await ui.request(permissionPath(values),'PUT',data); ui.message('Permiso guardado. Las revocaciones individuales prevalecen.'); });
    action('permission-inspect',async () => { const values=new FormData(element('permission-form')); const rows=(await ui.request(permissionPath(values))).data; element('permission-list').replaceChildren(); rows.forEach(row => element('permission-list').append(ui.text('p',`${row.permission} · ${row.value ? 'Concedido' : 'Revocado'} · ${row.scope} · ${row.proceso === null ? 'Base o global' : 'Elección'}`))); });
    submit('territory-form',async values => { await ui.request(`users/${values.get('user')}/territories`,'PUT',{sections:values.getAll('sections')}); ui.message('Territorios guardados.'); });
    ui.run(initialize);
}
