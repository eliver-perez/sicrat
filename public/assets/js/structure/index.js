let homeURL;
const structureState = {id: null, member: null, offset: 0, members: [], elections: [], levels: {}, mode: 'create', listMode: '', listOffset: 0, parent: 'mine', view: 'hierarchy', trail: [], preview: null, previewOffset: 0, loadVersion: 0, previewVersion: 0};
function InitializeValues(url) {
    homeURL = url; const ui = window.SicratPhase3; ui.base = url; ui.closeButtons();
    const query = new URLSearchParams(location.search);
    structureState.id = query.get('structure');
    const element = id => document.getElementById(id);
    const action = (id, work) => element(id).addEventListener('click', event => ui.run(work,event.currentTarget));
    const submit = (id, work) => element(id).addEventListener('submit', event => { event.preventDefault();const values=new FormData(event.target); ui.run(() => work(values),event.currentTarget); });
    const visual = window.SicratUI;
    [
        ['structure-create','plus','Nueva estructura'],['structure-clone','copy','Crear copia independiente'],
        ['structure-associate','link','Asociar elección'],['structure-add','plus','Agregar integrante'],
        ['structure-requests','arrow-right-left','Solicitudes de transferencia'],['structure-history','history','Historial de la estructura']
    ].forEach(([id,icon,title]) => {const button=element(id);button.classList.add('structure-labeled-action');button.prepend(visual.icon(icon));button.title=title;button.setAttribute('aria-label',title);});
    function summary(member) {
        const target=element('structure-summary');target.replaceChildren();target.hidden=!member;
        if (!member) return;
        [
            ['Nombre',member.name,'user-round','var(--color-primary,#3b82f6)'],
            ['Nivel',member.level_name || 'Sin nivel','layers','var(--color-purple-500,#a855f7)'],
            ['Equipo directo',member.direct_count,'users','var(--color-success,#10b981)'],
            ['Tamaño de la estructura',member.team_count,'network','var(--color-warning,#f59e0b)']
        ].forEach(([label,value,icon,color],index) => {
            const card=ui.text('div','','card structure-stat'+(index<2?' structure-stat-name':''));
            const symbol=ui.text('span','','structure-stat-icon');symbol.style.setProperty('--stat-color',color);symbol.append(visual.icon(icon));
            const content=ui.text('div','','structure-stat-content');content.append(ui.text('span',label,'structure-stat-label'),ui.text('strong',String(value),'structure-stat-value'));
            card.append(symbol,content);target.append(card);
        });
    }
    async function initialize() {
        structureState.me = (await ui.request('auth/me')).data;
        structureState.available = await ui.pages('auth/elections','electoral_processes');
        structureState.selected = structureState.available.find(election => election.selected);
        element('structure-create').hidden = !ui.allowed(structureState.me,'estructura.administrar');
        element('structure-clone').hidden = element('structure-create').hidden; element('structure-associate').hidden = element('structure-create').hidden;
        element('structure-add').hidden = !ui.allowed(structureState.me,'estructura.formar');
        await loadStructures();
        if (query.get('member') && structureState.id === query.get('structure')) await openMember(query.get('member'));
        if (query.get('person') && structureState.id && ui.allowed(structureState.me,'estructura.formar')) await addDialog(query.get('person'));
    }
    async function loadStructures() {
        const rows = await ui.pages('structures'); const select = element('structure-select'); select.replaceChildren();
        rows.forEach(row => ui.option(select,row.id,row.name));
        if (!structureState.id && rows.some(row=>row.own)) select.value=rows.find(row=>row.own).id;
        if (structureState.id && rows.some(row => row.id === structureState.id)) select.value = structureState.id;
        structureState.id = select.value || null; structureState.offset = 0;
        element('structure-add').disabled=!structureState.id;
        ['structure-hierarchy','structure-mine','structure-roots','structure-all','structure-previous','structure-next','structure-history','structure-requests'].forEach(id=>element(id).disabled=!structureState.id);
        if (structureState.id) {
            if(structureState.loadedStructure && structureState.loadedStructure!==structureState.id){structureState.parent='mine';structureState.trail=[];structureState.view='hierarchy';structureState.flatSearch='';element('structure-flat-search').value='';}
            await loadMembers();
        } else {summary(null);clearPreview();element('structure-members').replaceChildren();element('structure-breadcrumb').replaceChildren();element('structure-navigation').replaceChildren(ui.text('p','No hay estructuras disponibles en esta elección. Un administrador puede crear o asociar una estructura antes de asignar responsables.','ux-help'));}
    }
    async function loadMembers() {
        const version=++structureState.loadVersion;clearPreview();element('structure-panels').inert=true;element('structure-panels').setAttribute('aria-busy','true');
        try {
            const response = await ui.request(`structures/${structureState.id}?limit=25&offset=${structureState.offset}&parent=${encodeURIComponent(structureState.parent)}&search=${encodeURIComponent(structureState.flatSearch || '')}`);if(version!==structureState.loadVersion)return;const data = response.data;
            structureState.loadedStructure=structureState.id;
            structureState.own = data.own; structureState.canAddOwn = data.can_add_to_own;
            const navigation = element('structure-navigation'); navigation.replaceChildren();
            const focus = data.focus;structureState.focus=focus;summary(focus);
            const hierarchical=structureState.view==='hierarchy';
            element('structure-flat-search-form').hidden=hierarchical;
            element('structure-panels').classList.toggle('structure-flat',!hierarchical);element('structure-preview').hidden=!hierarchical;element('structure-breadcrumb').hidden=!hierarchical;
            element('structure-hierarchy').setAttribute('aria-pressed',String(hierarchical));element('structure-all').setAttribute('aria-pressed',String(!hierarchical));
            element('structure-primary-title').textContent=focus ? `Equipo directo de ${focus.name}` : hierarchical ? 'Inicio de la estructura' : 'Todos los integrantes';
            if(hierarchical && focus){const index=structureState.trail.findIndex(item=>item.id===focus.id);structureState.trail=index<0 ? [...structureState.trail,focus] : [...structureState.trail.slice(0,index),focus];}
            renderBreadcrumb();
            navigation.append(ui.text('h6',focus ? focus.name : structureState.parent === '' ? 'Todos los integrantes' : 'Inicio de la estructura','card-title'));
            if (focus) {
                const open = ui.text('button','Abrir ficha del responsable','btn text-primary');open.type='button';open.onclick=()=>ui.run(()=>openMember(focus.id));navigation.append(open);
                if (focus.responsible_visible) { const back=ui.text('button','Subir al responsable','btn');back.type='button';back.onclick=()=>ui.run(()=>{if(!structureState.trail.some(item=>item.id===focus.responsible))structureState.trail=[];return browse(focus.responsible);});navigation.append(back); }
            } else if (!data.own) navigation.append(ui.text('p','Tu usuario no se encuentra registrado como integrante de esta estructura. Se muestran los registros de la estructura seleccionada.','ux-help'));
            element('structure-mine').hidden = !data.own;
            structureState.members = data.members; structureState.elections = data.elections;
            element('structure-elections').textContent = `Elecciones: ${data.elections.map(election => election.name).join(' · ')}`;
            renderMembers(element('structure-members'),data.members,focus);
            element('structure-previous').disabled = structureState.offset === 0; element('structure-next').disabled = !response.pagination.has_more;
            structureState.levels = {};
            for (const election of data.elections) {
                try {const levels=(await ui.request(`structure-levels?election=${election.id}`)).data;if(version!==structureState.loadVersion)return;structureState.levels[election.id]=levels;}
                catch (error) { structureState.levels[election.id] = null; }
            }
        } finally {if(version===structureState.loadVersion){element('structure-panels').inert=false;element('structure-panels').removeAttribute('aria-busy');}}
    }
    function renderBreadcrumb() {
        const trail=structureState.trail;
        window.SicratStructureBreadcrumb(element('structure-breadcrumb'),[{id:'roots',name:'Inicio de la estructura'},...trail.slice(0,-1)],trail.at(-1) || {id:'roots',name:'Inicio de la estructura'},browse);
        if(!trail.length)window.SicratStructureBreadcrumb(element('structure-breadcrumb'),[],{id:'roots',name:'Inicio de la estructura'},browse);
    }
    function clearPreview() {
        ++structureState.previewVersion;structureState.preview=null;structureState.previewOffset=0;
        element('structure-preview').removeAttribute('aria-busy');element('structure-preview-members').replaceChildren();
        element('structure-preview-title').textContent='Equipo directo';element('structure-preview-status').textContent='Selecciona un integrante para consultar su equipo directo.';
        ['structure-preview-enter','structure-preview-table','structure-preview-pages'].forEach(id=>element(id).hidden=true);
        element('structure-members').querySelectorAll('tr').forEach(row=>{row.classList.remove('structure-selected');row.removeAttribute('aria-current');});
    }
    function renderMembers(body,members,focus,preview=false) {
        body.replaceChildren();
        members.forEach(member=>{
            const row=document.createElement('tr');row.dataset.member=member.id;row.tabIndex=0;
            row.setAttribute('aria-label',`${structureState.view==='all' ? 'Ver ficha de' : 'Seleccionar a'} ${member.name}`);
            const boss=members.find(candidate=>candidate.id===member.responsible) || (focus?.id===member.responsible ? focus : null);
            [member.code,member.name,member.level_name || 'Sin nivel',boss?.name || (member.responsible ? 'Consultar superiores' : 'Sin responsable'),`${member.direct_count} / ${member.team_count}`,`${member.has_account ? 'Con cuenta' : 'Sin cuenta'} · ${member.active ? 'Activo' : 'Inactivo'}`].forEach(value=>row.append(ui.text('td',value,'p-3')));
            row.cells[1].append(ui.text('small',member.code,'structure-code-label'));
            const select=async()=>{if(structureState.view==='all')return openMember(member.id);if(preview)await browse(focus.id);await selectPreview(member.id);};
            const enter=async()=>{if(structureState.view==='all')structureState.trail=[];if(preview && !structureState.trail.some(item=>item.id===focus.id))structureState.trail.push(focus);await browse(member.id);};
            const cell=document.createElement('td'),actions=ui.text('div','','ux-actions justify-end');
            [['Ver ficha','eye',()=>openMember(member.id)],['Ver equipo','users',enter]].forEach(([title,icon,work])=>{
                const button=ui.text('button',title,'btn text-primary');button.type='button';visual.action(button,title,icon);
                button.addEventListener('click',event=>{event.stopPropagation();ui.run(work,button);});actions.append(button);
            });cell.append(actions);row.append(cell);body.append(row);
            row.addEventListener('click',event=>{if(!event.target.closest('button'))ui.run(select,row);});
            row.addEventListener('keydown',event=>{if(event.target===row && ['Enter',' '].includes(event.key)){event.preventDefault();if(!event.repeat)ui.run(select,row);}});
        });
        if(!members.length){const row=document.createElement('tr'),cell=ui.text('td','No hay integrantes en este nivel.');cell.colSpan=7;row.append(cell);body.append(row);}
    }
    async function selectPreview(id,offset=0) {
        const version=++structureState.previewVersion;
        structureState.preview=null;element('structure-preview').setAttribute('aria-busy','true');
        element('structure-preview-members').replaceChildren();element('structure-preview-status').textContent='Cargando equipo…';
        ['structure-preview-enter','structure-preview-table','structure-preview-pages'].forEach(key=>element(key).hidden=true);
        element('structure-members').querySelectorAll('tr[data-member]').forEach(row=>{const selected=row.dataset.member===id;row.classList.toggle('structure-selected',selected);if(selected)row.setAttribute('aria-current','true');else row.removeAttribute('aria-current');});
        try {
            const response=await ui.request(`structures/${structureState.id}?limit=25&offset=${offset}&parent=${encodeURIComponent(id)}`);
            if(version!==structureState.previewVersion)return;
            structureState.preview=response.data.focus;structureState.previewOffset=offset;
            summary(response.data.focus);element('structure-preview-title').textContent=`Equipo directo de ${response.data.focus.name}`;
            element('structure-preview-status').textContent=response.data.members.length ? '' : 'Esta persona no tiene integrantes en este nivel.';
            renderMembers(element('structure-preview-members'),response.data.members,response.data.focus,true);
            element('structure-preview-enter').hidden=false;element('structure-preview-table').hidden=!response.data.members.length;
            element('structure-preview-pages').hidden=offset===0 && !response.pagination.has_more;
            element('structure-preview-previous').disabled=offset===0;element('structure-preview-next').disabled=!response.pagination.has_more;
        } catch(error){if(version!==structureState.previewVersion)return;summary(structureState.focus);element('structure-preview-status').textContent='No fue posible consultar el equipo. Selecciona al integrante para reintentar.';throw error;}
        finally {if(version===structureState.previewVersion)element('structure-preview').removeAttribute('aria-busy');}
    }
    async function browse(parent) {
        if(parent===''){if(structureState.view!=='all')structureState.hierarchyParent=structureState.parent || 'mine';structureState.view='all';}
        else {structureState.view='hierarchy';if(parent==='mine'||parent==='roots')structureState.trail=[];}
        structureState.parent=parent;structureState.offset=0;await loadMembers();
    }
    action('structure-mine',()=>browse('mine'));action('structure-roots',()=>browse('roots'));action('structure-all',()=>browse(''));
    submit('structure-flat-search-form',async()=>{structureState.flatSearch=element('structure-flat-search').value.trim();structureState.offset=0;await loadMembers();});
    action('structure-hierarchy',()=>browse(structureState.view==='all' ? structureState.hierarchyParent || 'mine' : structureState.parent));
    action('structure-preview-enter',()=>structureState.preview && browse(structureState.preview.id));
    // El paginador administra su estado al recibir la página, sin restaurar botones obsoletos.
    [['structure-preview-previous',-25],['structure-preview-next',25]].forEach(([id,delta])=>element(id).addEventListener('click',()=>{if(structureState.preview)ui.run(()=>selectPreview(structureState.preview.id,Math.max(0,structureState.previewOffset+delta)));}));
    function memberOptions(select, emptyText = 'Sin responsable directo') {
        select.replaceChildren(); ui.option(select,'',emptyText);
        (structureState.allMembers || structureState.members).filter(member => member.active).forEach(member => ui.option(select,member.id,member.name));
    }
    async function allMembers() {
        const rows = []; let offset = 0;
        do { const response = await ui.request(`structures/${structureState.id}?limit=100&offset=${offset}`); rows.push(...response.data.members); if (!response.pagination.has_more) break; offset += 100; } while (true);
        structureState.allMembers = rows;
    }
    function levelFields(id, member) {
        const container = element(id); container.replaceChildren();
        structureState.elections.forEach(election => {
            const options=member.level_options[election.id];if(!options)return;
            const label=ui.text('label',`Nivel en ${election.name}`),select=document.createElement('select');select.className='form-input';select.dataset.election=election.id;select.required=!!member.active;
            if(!member.active)ui.option(select,'','Sin nivel');
            options.forEach(level=>ui.option(select,level.id,level.name));
            if(options.some(level=>level.id===member.levels[election.id]))select.value=member.levels[election.id];
            else if(!options.length&&member.active){ui.option(select,'','No hay niveles válidos disponibles');select.options[0].disabled=true;}
            label.append(select);container.append(label);
        });
    }
    function selectedLevels(id) { return Object.fromEntries(Array.from(element(id).querySelectorAll('select')).map(select => [select.dataset.election,select.value || null])); }
    async function containerDialog(mode) {
        structureState.mode = mode; element('structure-container-form').reset(); element('structure-container-title').textContent = mode === 'clone' ? 'Clonar estructura completa' : mode === 'associate' ? 'Asociar elección' : 'Nueva estructura';
        element('structure-container-form').elements.name.hidden = mode === 'associate'; element('structure-container-form').elements.name.required = mode !== 'associate';
        element('structure-name-field').hidden = mode === 'associate';
        const options = element('structure-election-options');window.SicratUI.electionChoices(options,structureState.available.map(election=>({...election,name:election.organization+' · '+election.process})),mode==='create' ? structureState.available.filter(election=>election.selected).map(election=>election.id) : []);
        element('structure-container-dialog').showModal();
    }
    function renderCoordination(member, reset) {
        const line=element('structure-line'),team=element('structure-team');
        if(reset){structureState.superiors=[];team.replaceChildren(ui.text('h6','Equipo directo'));}
        structureState.superiors.push(...member.superiors);
        window.SicratStructureBreadcrumb(line,structureState.superiors,member,openMember);
        if(reset && !member.team.length)team.append(ui.text('p','Sin integrantes a cargo.','ux-help'));
        for(const person of member.team){
            const card=ui.text('div','','structure-team-card');card.tabIndex=0;card.setAttribute('role','link');card.setAttribute('aria-label',`Abrir equipo de ${person.name}`);
            const content=ui.text('div','','structure-team-copy');content.append(ui.text('strong',person.name),ui.text('span',person.level_name || 'Sin nivel','ux-help'));
            content.append(ui.text('small',person.direct_count || person.team_count ? `${person.direct_count} ${person.direct_count===1?'integrante directo':'integrantes directos'} · ${person.team_count} en su estructura` : 'Sin integrantes a cargo'));
            const initials=ui.text('span',person.initials || person.name.charAt(0),'structure-initials');initials.setAttribute('aria-hidden','true');
            const detail=ui.text('button','Ver ficha','btn');detail.type='button';visual.action(detail,'Ver ficha','eye');detail.onclick=event=>{event.stopPropagation();ui.run(()=>openMember(person.id),detail);};
            const navigate=async()=>{element('structure-member-dialog').close();await browse(person.id);};
            card.onclick=event=>{if(!event.target.closest('button'))ui.run(navigate);};card.onkeydown=event=>{if(event.target===card && ['Enter',' '].includes(event.key)){event.preventDefault();if(!event.repeat)ui.run(navigate);}};
            card.append(initials,content,detail);team.append(card);
        }
    }
    async function openMember(id) {
        const member = (await ui.request(`structures/${structureState.id}/members/${id}`)).data; structureState.member = member;
        element('structure-member-name').textContent = `${member.code} · ${member.name}`; element('structure-member-summary').textContent = `${member.level_name || 'Sin nivel'} · ${member.has_account ? 'Con cuenta' : 'Sin cuenta'} · Equipo directo: ${member.direct_count} · Tamaño de la estructura: ${member.team_count}`;
        renderCoordination(member,true);
        element('structure-administration').open=false;
        structureState.coordinationOffset = 25;
        element('structure-coordination-more').hidden = !member.team_pagination.has_more && !member.superiors_pagination.has_more;
        element('structure-member-participations').textContent = `Participaciones: ${member.participations.map(election => election.name).join(' · ') || 'Sin participaciones registradas'}`;
        const admin = ui.allowed(structureState.me,'estructura.administrar'); element('structure-level-form').hidden = !admin; element('structure-state-form').hidden = !admin;
        element('structure-transfer-form').hidden = !ui.allowed(structureState.me,'estructura.transferir_integrante') && !ui.allowed(structureState.me,'estructura.transferir_equipo');
        element('structure-create-account').hidden = member.has_account || !ui.allowed(structureState.me,'usuarios.administrar');
        element('structure-administration').hidden = !admin && element('structure-transfer-form').hidden && element('structure-create-account').hidden;
        levelFields('structure-member-levels',member);
        ['structure-transfer-form','structure-state-form'].forEach(formId => element(formId).querySelectorAll('select[name="destination"],select[name="replacement"],select[name="responsible"]').forEach(select => memberOptions(select)));
        element('structure-state-form').elements.active.value = member.active ? '0' : '1';
        element('structure-state-form').elements.responsible.value = member.responsible || '';
        element('structure-state-form').elements.responsible.disabled = !member.active;
        if (!element('structure-member-dialog').open) element('structure-member-dialog').showModal();
        element('structure-member-dialog').scrollTop=0;
    }
    // Los candidatos administrativos se cargan sólo al abrir esas operaciones.
    element('structure-administration').addEventListener('toggle',()=>{
        if(!element('structure-administration').open)return;
        const member=structureState.member;const forms=['structure-transfer-form','structure-state-form'].map(element);
        forms.forEach(form=>form.inert=true);
        ui.run(async()=>{try{await allMembers();if(structureState.member.id!==member.id)return;
            forms.forEach(form=>form.querySelectorAll('select[name="destination"],select[name="replacement"],select[name="responsible"]').forEach(select=>memberOptions(select)));
            element('structure-state-form').elements.responsible.value=member.responsible || '';
        }finally{forms.forEach(form=>form.inert=false);}});
    });
    element('structure-select').addEventListener('change',event => ui.run(async () => { structureState.id = event.target.value; structureState.offset = 0; structureState.parent='mine';structureState.view='hierarchy';structureState.trail=[];structureState.flatSearch='';element('structure-flat-search').value=''; await loadMembers(); }));
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
    async function addDialog(personId = null) {
        if (!structureState.id) return; element('structure-add-form').reset();element('structure-person').replaceChildren();ui.option(element('structure-person'),'','Selecciona una persona');element('structure-duplicates').replaceChildren();
        structureState.assignment ||= new window.SicratAssignment({request:path=>ui.request(path),select:element('structure-add-form').elements.responsible,levels:element('structure-add-levels'),controls:element('structure-responsible-search')});
        await structureState.assignment.open(structureState.id);
        const own=structureState.assignment.data.own;
        element('structure-add-title').textContent=own ? 'Agregar a mi equipo' : 'Asignar responsable / agregar integrante';
        const choices = element('structure-participations');window.SicratUI.electionChoices(choices,structureState.elections);element('structure-quick-person').open=false;
        if (personId) {
            const person=(await ui.request(`persons/${encodeURIComponent(personId)}`)).data;
            const existing=person.structure?.members.find(member=>member.structure===structureState.id);
            if (existing) { await openMember(existing.id);return; }
            ui.option(element('structure-person'),person.id,`${person.code} · ${person.name} ${person.last_name || ''} ${person.last_name_2 || ''}`,true);
        }
        structureState.personSearchVersion=(structureState.personSearchVersion || 0)+1;structureState.personSearching=false;structureState.personOffset=0;structureState.personSearch='';
        element('structure-person-results').replaceChildren();element('structure-person-results').inert=false;element('structure-person-search-box').removeAttribute('aria-busy');
        element('structure-person-find').disabled=false;element('structure-person-find').textContent='Buscar personas';element('structure-person-previous').disabled=true;element('structure-person-next').disabled=true;
        personSelection();element('structure-add-dialog').showModal();
    }
    action('structure-add',()=>addDialog());
    function personSelection() {const select=element('structure-person');const selected=Boolean(select.value);element('structure-person-search-box').hidden=selected;element('structure-person-selected').hidden=!selected;element('structure-quick-person').hidden=selected;element('structure-person-selected').querySelector('span').textContent=selected ? select.selectedOptions[0].textContent : '';}
    element('structure-person').addEventListener('change',personSelection);
    action('structure-person-change',()=>{element('structure-person').value='';personSelection();element('structure-person-search').focus();});
    function selectPerson(person) {
        const select=element('structure-person');
        if (!Array.from(select.options).some(option=>option.value===person.id)) ui.option(select,person.id,`${person.code} · ${person.name}`);
        select.value=person.id;personSelection();element('structure-person-change').focus();
    }
    function personTable(target,persons) {
        const table=document.createElement('table');table.className='min-w-full';const head=document.createElement('thead'),header=document.createElement('tr'),body=document.createElement('tbody');
        ['Clave','Nombre','Sección','Teléfono'].forEach(label=>header.append(ui.text('th',label)));head.append(header);table.append(head,body);
        persons.forEach(person=>{
            const row=document.createElement('tr');row.className='structure-person-choice';row.tabIndex=0;row.setAttribute('aria-label',`Seleccionar ${person.name}`);
            [person.code,person.name,person.section || '—',person.phone || '—'].forEach(value=>row.append(ui.text('td',String(value),'p-3')));
            row.addEventListener('click',()=>selectPerson(person));row.addEventListener('keydown',event=>{if(event.key==='Enter' || event.key===' '){event.preventDefault();if(!event.repeat)selectPerson(person);}});body.append(row);
        });
        target.append(table);
    }
    async function searchPersons(offset = 0, search = element('structure-person-search').value.trim()) {
        if (structureState.personSearching) return;
        const version=structureState.personSearchVersion;structureState.personSearching=true;element('structure-person-search-box').setAttribute('aria-busy','true');element('structure-person-results').inert=true;
        const searchButton=element('structure-person-find'),searchLabel=searchButton.textContent;searchButton.textContent='Buscando…';const controls=['structure-person-find','structure-person-previous','structure-person-next'].map(element);const disabled=controls.map(control=>control.disabled);controls.forEach(control=>control.disabled=true);
        try {
            const response=await ui.request('persons?'+new URLSearchParams({limit:25,offset,search}));
            if(version!==structureState.personSearchVersion)return;
            structureState.personOffset=offset;structureState.personSearch=search;
            const select=element('structure-person');select.replaceChildren();ui.option(select,'','Selecciona una persona');
            response.data.persons.forEach(person=>ui.option(select,person.id,`${person.code} · ${person.name}`));
            const target=element('structure-person-results');target.replaceChildren();
            if(response.data.persons.length)personTable(target,response.data.persons);else target.append(ui.text('p','No se encontraron personas.','ux-help'));
            disabled[1]=offset===0;disabled[2]=!response.pagination.has_more;
        } finally {if(version===structureState.personSearchVersion){structureState.personSearching=false;element('structure-person-search-box').removeAttribute('aria-busy');element('structure-person-results').inert=false;searchButton.textContent=searchLabel;controls.forEach((control,index)=>control.disabled=disabled[index]);}}
    }
    [['structure-person-find',()=>searchPersons()],['structure-person-previous',()=>searchPersons(Math.max(0,structureState.personOffset-25),structureState.personSearch)],['structure-person-next',()=>searchPersons(structureState.personOffset+25,structureState.personSearch)]].forEach(([id,work])=>element(id).addEventListener('click',()=>ui.run(work)));
    element('structure-person-search').addEventListener('keydown',event=>{
        if(event.key==='Enter'){event.preventDefault();event.stopPropagation();if(!event.repeat && !event.isComposing && !structureState.personSearching)element('structure-person-find').click();}
    });
    submit('structure-add-form',async form => {
        const newPerson = {name:form.get('new_name'),last_name:form.get('new_last_name'),phone:form.get('new_phone'),confirm_duplicate:form.get('confirm_duplicate') ? 1 : 0};
        if (!element('structure-person').value) {
            const organizations = await ui.pages('organizations','organizations'); const selected = organizations.find(organization => organization.organization === structureState.selected?.organization) || organizations[0];
            newPerson.organization = selected.id; const inspection = (await ui.request('persons/duplicates','POST',newPerson)).data;
            if (inspection.blocked || (inspection.matches.length && !newPerson.confirm_duplicate)) {
                const matches = element('structure-duplicates'); matches.replaceChildren(ui.text('p','Revisa estas coincidencias antes de continuar:')); personTable(matches,inspection.matches); return;
            }
        }
        const added = await ui.request(`structures/${structureState.id}/members`,'POST',{person:element('structure-person').value || null,new_person:newPerson,...structureState.assignment.values(),participations:ui.selected(element('structure-participations'))});
        element('structure-add-dialog').close(); await loadMembers();
        ui.message(form.get('responsible') ? 'Persona incorporada a la estructura con el responsable seleccionado.' : 'Persona incorporada como raíz de la estructura, sin responsable directo.');
        if (structureState.members.some(member=>member.id===added.data.id)) await openMember(added.data.id);
    });
    submit('structure-level-form',async () => { await ui.request(`structures/${structureState.id}/members/${structureState.member.id}/level`,'PUT',{levels:selectedLevels('structure-member-levels')}); element('structure-member-dialog').close(); await loadMembers(); });
    submit('structure-transfer-form',async form => { const result = (await ui.request(`structures/${structureState.id}/members/${structureState.member.id}/transfers`,'POST',Object.fromEntries(form))).data; element('structure-member-dialog').close(); await loadMembers(); ui.message(result.state === 'pendiente' ? 'Solicitud enviada para aprobación.' : 'Transferencia aplicada.'); });
    submit('structure-state-form',async form => { await ui.request(`structures/${structureState.id}/members/${structureState.member.id}/state`,'PUT',{...Object.fromEntries(form),levels:selectedLevels('structure-member-levels')}); element('structure-member-dialog').close(); await loadMembers(); });
    action('structure-create-account', async () => {
        const response = await ui.request(
            `structures/${structureState.id}/members/${structureState.member.id}`
        );
        const member = response.data;
        const form = element('structure-account-form');

        element('structure-account-role').textContent = member.associated_role_name
            || 'No hay un rol configurado. Solicita su configuración a un administrador.';
        form.querySelector('button[type="submit"]').disabled = !member.associated_role;
        element('structure-account-dialog').showModal();
    });
    action('structure-account-cancel', () => {
        element('structure-account-dialog').close();
    });
    action('structure-coordination-more',async () => {
        const data = (await ui.request(`structures/${structureState.id}/members/${structureState.member.id}?limit=25&offset=${structureState.coordinationOffset}`)).data;
        renderCoordination(data,false);
        structureState.coordinationOffset += 25; element('structure-coordination-more').hidden = !data.team_pagination.has_more && !data.superiors_pagination.has_more;
    });
    submit('structure-account-form', async form => {
        const person = (await ui.request(`persons/${structureState.member.person}`)).data;
        await ui.request('users', 'POST', {
            ...Object.fromEntries(form),
            person: person.id,
            organization: person.organization,
            name: person.name,
            last_name: person.last_name,
            second_last_name: person.last_name_2,
            process: structureState.selected.id,
            status: 1
        });

        element('structure-account-dialog').close();
        element('structure-member-dialog').close();
        await loadMembers();
        ui.message('Cuenta vinculada a la persona existente.');
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
        if (!response.data.length && reset) target.append(ui.text('p','No hay movimientos registrados.','ux-help'));
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
    [['structure-previous',-25],['structure-next',25]].forEach(([id,delta])=>element(id).addEventListener('click',()=>{if(element('structure-panels').inert)return;ui.run(async()=>{structureState.offset=Math.max(0,structureState.offset+delta);await loadMembers();});}));
    ui.run(initialize);
}
