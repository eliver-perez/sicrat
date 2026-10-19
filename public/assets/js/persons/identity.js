const personLabels = {name: 'Nombre', last_name: 'Apellido paterno', last_name_2: 'Apellido materno', birth_date: 'Fecha de nacimiento', gender: 'Género', phone: 'Teléfono', alternate_phone: 'Teléfono alternativo', email: 'Correo', curp: 'CURP', elector_key: 'Clave de elector', ocr: 'OCR', section: 'Sección', street: 'Calle', exterior_number: 'Número exterior', interior_number: 'Número interior', postal_code: 'Código postal', references: 'Referencias', neighborhood: 'Colonia', latitude: 'Latitud', longitude: 'Longitud'};
let selectedPerson = null;
let detailOrganization = null;
let personEditable = false;
function personCan(action) { return window.SicratPersonCapabilities?.[action] === true; }

function identityRequest(path, method, data) {
    return $.ajax({url: `${homeURL}/api/${path}`, type: method, data: JSON.stringify(data), contentType: 'application/json', dataType: 'json'});
}
function identityError(xhr) { ShowToastMessage(xhr.responseJSON?.message || 'No se pudo completar la operación.', [403,409,422].includes(xhr.status) ? 'warning' : 'error'); }

function showPersonDuplicates(data, continueNew) {
    const box = $('#person-duplicates').empty().removeClass('hidden');
    box.append($('<p>').text(data.blocked ? 'Hay un identificador repetido. Selecciona una ficha existente.' : 'Posibles coincidencias. Revisa la ficha o confirma una persona nueva.'));
    data.matches.forEach(function (person) {
        box.append($('<button>', {type: 'button', class: 'btn text-primary person-open', 'data-id': person.id}).text(`${person.code} · ${person.name}`).on('click', function () { box.addClass('hidden'); CancelPerson(); }));
    });
    if (!data.blocked) box.append($('<button>', {type: 'button', class: 'btn bg-primary text-white'}).text('Crear persona nueva').on('click', function () { box.addClass('hidden'); continueNew(); }));
}

function openPerson(uuid) {
    identityRequest(`persons/${uuid}`, 'get').done(function (response) {
        const person = response.data;
        selectedPerson = uuid;
        personEditable = person.editable && personCan('edit');
        $('#person-edit-form button[type=submit]').prop('hidden', !personEditable);
        detailOrganization = person.organization;
        $('#person-detail-title').text(`${person.code} · ${person.name}`);
        const fields = $('#person-edit-fields').empty();
        const groups = {};
        const categories = {personal:'Datos personales',contact:'Contacto',address:'Domicilio'};
        Object.entries(categories).forEach(function ([key, title]) {
            const section = $('<fieldset>', {class:'ux-section'}); section.append($('<legend>').text(title));
            groups[key] = $('<div>', {class:'ux-form-grid'}); section.append(groups[key]); fields.append(section);
        });
        Object.entries(personLabels).forEach(function ([key, label]) {
            const group = ['phone','alternate_phone','email'].includes(key) ? groups.contact : ['section','street','exterior_number','interior_number','postal_code','references','neighborhood','latitude','longitude'].includes(key) ? groups.address : groups.personal;
            const control = key === 'references' ? $('<textarea>', {rows:3}) : ['section', 'neighborhood', 'gender'].includes(key) ? $('<select>') : $('<input>', {type: key === 'birth_date' ? 'date' : key === 'email' ? 'email' : ['phone','alternate_phone'].includes(key) ? 'tel' : 'text'});
            control.attr({name: key, class: 'form-input'}).val(person[key] ?? '').prop('disabled',!personEditable);
            if (['latitude','longitude'].includes(key)) control.attr({type:'number',step:'any',min:key==='latitude'?-90:-180,max:key==='latitude'?90:180}).prop('required',false);
            if (key === 'name') control.prop('required', true);
            if (key === 'section') {
                control.append($('<option>', {value: '', text: 'Sin sección'}));
                $('#select-persons-section option').each(function () { if (this.value) control.append($('<option>', {value: this.value, text: this.textContent})); });
                control.val(person.section || '');
            }
            if (key === 'gender') {
                control.append($('<option>', {value: '', text: 'Sin género'}));
                $('#select-persons-gender option').each(function () { if (this.value) control.append($('<option>', {value: this.value, text: this.textContent})); });
                control.val(person.gender || '');
            }
            if (key === 'neighborhood') {
                control.append($('<option>', {value: '', text: 'Sin colonia'}));
                if (person.neighborhood) control.append($('<option>', {value: person.neighborhood, text: person.location?.neighborhood || 'Colonia registrada', selected: true}));
                control.prop('hidden', true);
                const location = $('<span>', {class:'block'}).text(person.location ? `${person.location.neighborhood} · ${person.location.municipio}, ${person.location.estado}` : 'Sin colonia');
                const choose = $('<button>', {type: 'button', class: 'btn text-primary'}).text(person.neighborhood ? 'Cambiar colonia' : 'Seleccionar colonia');
                choose.on('click', function () { choosePersonNeighborhood(control, location, person.location); });
                const field = $('<div>').append($('<span>', {class:'block'}).text('Colonia'),control,location);
                if (personEditable) field.append(choose);
                group.append(field);
                return;
            }
            group.append($('<label>').text(label).append(control));
        });
        window.SicratPersonMap.mount(groups.address[0],personEditable,person.organization);
        renderPersonParticipations(person);
        showPersonStructure(person);
        const selector = $('#person-election-select').empty();
        person.participation_choices.forEach(election => selector.append($('<option>', {value:election.id,text:election.process})));
        selector.closest('label').prop('hidden',!personCan('participate') || !person.participation_choices.length);
        $('#person-participate').prop('hidden',!personCan('participate') || !person.participation_choices.length);
        const dialog = document.getElementById('person-detail');
        if (!dialog.open) dialog.showModal();
        if (typeof window.InitializePersonOperations==='function') window.InitializePersonOperations(uuid,homeURL);
    }).fail(identityError);
}

function renderPersonParticipations(person) {
    const current=document.getElementById('electoral-context-select')?.value;
    const otherParticipations=person.participations.filter(item=>!person.structure?.available || item.id!==current);
        const participations = $('#person-participations').empty();
        const table = $('<table>'); table.append($('<thead>').append($('<tr>').append($('<th>').text('Elección'),$('<th>').text('Estado'),$('<th>').text('Acción'))));
        const rows = $('<tbody>'); table.append(rows); if (otherParticipations.length) participations.append(table);

        otherParticipations.forEach(function (participation) {
            const line = $('<tr>').append($('<td>').text(participation.process),$('<td>').append($('<span>', {class:'ux-badge ' + (participation.active ? 'ux-badge-success' : 'ux-badge-neutral')}).text(participation.active ? 'Activa' : 'Retirada')));
            const button = $('<button>', {type: 'button', class: 'btn text-primary'}).text(participation.active ? 'Retirar' : 'Reactivar');
            button.on('click', async function () {
                if (!personCan('participate') || !participation.can_participate) return;
                if (participation.active && !await window.SicratFeedback.confirm('¿Retirar la participación de esta elección?',{confirm:'Retirar participación'})) return;
                window.SicratFeedback.execute(this,()=>identityRequest(`persons/${person.id}/participations`, 'post', {processes:[participation.id],active:participation.active ? 0 : 1}).done(()=>openPerson(person.id))).catch(identityError);
            });
            line.append($('<td>').append(participation.can_participate && personCan('participate') ? button : document.createTextNode('Sólo consulta'))); rows.append(line);
        });
}

function showPersonStructure(person) {
    const box=$('#person-structure').empty(),context=person.structure;
    const card=$('<div>',{class:'person-structure-card'});box.append(card);
    if(!context?.available){card.append($('<p>',{class:'ux-help'}).text(context?.message || 'Selecciona una elección.'));return;}
    card.append($('<h6>').text(context.election));
    const badges=$('<div>',{class:'ux-actions my-3'}).append(SicratUI.badge(context.participates?'Participación electoral activa':'Sin participación electoral activa',context.participates));card.append(badges);
    const current=document.getElementById('electoral-context-select')?.value;
    const participation=person.participations.find(item=>item.id===current);
    if(participation?.can_participate && personCan('participate')){
        const action=$('<button>',{type:'button',class:'btn'}).text(participation.active?'Retirar participación':'Reactivar participación');
        action.on('click',async function(){if(participation.active&&!await SicratFeedback.confirm('¿Retirar la participación de esta elección?',{confirm:'Retirar participación'}))return;SicratFeedback.execute(this,async()=>{await identityRequest(`persons/${person.id}/participations`,'post',{processes:[current],active:participation.active?0:1});const fresh=await identityRequest(`persons/${person.id}`,'get');showPersonStructure(fresh.data);renderPersonParticipations(fresh.data);}).catch(identityError);});badges.append(action);
    }
    const active=context.members.filter(member=>member.active);
    if(!active.length)card.append($('<strong>').text('Sin estructura asignada'),$('<p>',{class:'ux-help'}).text('Esta persona todavía no pertenece a un equipo dentro de esta elección.'));
    context.members.forEach(member=>{
        const section=$('<div>',{class:'person-structure-member'});section.append(SicratUI.badge(member.active?'Integrante activo':'Integrante inactivo',member.active));
        const list=$('<dl>',{class:'person-structure-data'});[['Nivel',member.level_name || 'Sin nivel'],['Responsable',member.responsible || 'Sin responsable directo'],['Estructura',member.name]].forEach(([label,value])=>list.append($('<div>').append($('<dt>').text(label),$('<dd>').text(value))));
        const open=$('<a>',{class:'btn',href:`${homeURL}/structure/?structure=${encodeURIComponent(member.structure)}&member=${encodeURIComponent(member.id)}`}).append(SicratUI.icon('network'),document.createTextNode('Abrir estructura'));section.append(list,open);card.append(section);
    });
    if(!active.length&&context.can_form){const assign=$('<button>',{id:'person-assign-structure',type:'button',class:'btn bg-primary text-white mt-3'}).append(SicratUI.icon('users'),document.createTextNode('Asignar a estructura'));assign.on('click',()=>SicratFeedback.execute(assign[0],()=>AssignPersonStructure(person)).catch(identityError));card.append(assign);}
}

function choosePersonNeighborhood(target, summary, current) {
    const dialog = $('<dialog>', {id:'person-neighborhood-dialog',class:'card p-5 w-full max-w-xl', 'aria-label':'Seleccionar colonia'});
    const form = $('<form>', {class:'ux-form-grid'});
    dialog.append($('<h6>', {class:'card-title mb-4'}).text('Seleccionar colonia'),form);
    const fields = ['Estado','Municipio','Localidad','Colonia'].map(label => {
        const select = $('<select>', {class:'form-input', 'aria-label':label,required:true,disabled:true});
        form.append($('<label>').text(label).append(select)); return select;
    });
    const [state, municipality, locality, neighborhood] = fields;
    const confirm = $('<button>', {type:'submit',class:'btn bg-primary text-white',disabled:true}).text('Usar esta colonia');
    const cancel = $('<button>', {type:'button',class:'btn'}).text('Cancelar').on('click',()=>dialog[0].close());
    const status = $('<p>', {class:'ux-full ux-help',role:'status'});
    form.append(status,$('<div>', {class:'ux-actions ux-full'}).append(confirm,cancel));
    $('body').append(dialog); dialog[0].showModal();
    const requests = new Map(); let changed = false;
    function clearFrom(index) {
        for (let i=index;i<fields.length;i++) {
            requests.get(i)?.abort(); requests.delete(i);
            fields[i].empty().append($('<option>', {value:'',text:'Selecciona'})).prop('disabled',true);
        }
        confirm.prop('disabled',true);
    }
    function options(index,path,key,label,selected) {
        clearFrom(index); status.text('Cargando opciones…');
        const request = $.getJSON(`${homeURL}/api/${path}`); requests.set(index,request);
        return request.then(response => {
            if (!dialog[0].open || requests.get(index)!==request) return false;
            const rows = response.data[key];
            rows.forEach(item => fields[index].append($('<option>', {value:item.id,text:item[label] + (item.codigo_postal ? ` · CP ${item.codigo_postal}` : '')})));
            fields[index].prop('disabled',!rows.length);
            if (selected) fields[index].val(String(selected));
            status.text(rows.length ? '' : 'No hay opciones registradas para esta selección.');
            confirm.prop('disabled',!neighborhood.val()); return true;
        },xhr => { if (xhr.statusText!=='abort' && dialog[0].open) { status.text('No fue posible cargar las opciones. Vuelve a seleccionar o abre el selector de nuevo.'); identityError(xhr); } return false; });
    }
    state.on('change',()=>{changed=true;clearFrom(1);if(state.val())options(1,`states/${state.val()}/municipalities`,'municipalities','municipio');});
    municipality.on('change',()=>{changed=true;clearFrom(2);if(municipality.val())options(2,`municipalities/${municipality.val()}/localities`,'localities','localidad');});
    locality.on('change',()=>{changed=true;clearFrom(3);if(locality.val())options(3,`localities/${locality.val()}/neighborhoods`,'neighborhoods','colonia');});
    neighborhood.on('change',()=>confirm.prop('disabled',!neighborhood.val()));
    form.on('submit',event=>{
        event.preventDefault(); if (!neighborhood.val() || confirm.prop('disabled')) return;
        target.empty().append($('<option>', {value:neighborhood.val(),text:neighborhood.find(':selected').text(),selected:true}));
        summary.text(`${neighborhood.find(':selected').text()} · ${municipality.find(':selected').text()}, ${state.find(':selected').text()}`);
        current = {state:state.val(),municipality:municipality.val(),locality:locality.val()};
        target.data('location',current); dialog[0].close();
    });
    dialog.on('close',()=>{requests.forEach(request=>request.abort());dialog.remove();});
    (async()=>{
        const location = target.data('location') || current;
        await options(0,'states','states','estado',location?.state);
        if (changed || !dialog[0].open || !state.val()) return;
        await options(1,`states/${state.val()}/municipalities`,'municipalities','municipio',location?.municipality);
        if (changed || !dialog[0].open || !municipality.val()) return;
        await options(2,`municipalities/${municipality.val()}/localities`,'localities','localidad',location?.locality);
        if (changed || !dialog[0].open || !locality.val()) return;
        await options(3,`localities/${locality.val()}/neighborhoods`,'neighborhoods','colonia',target.val());
    })();
}

function InitializePersonIdentity() {
    document.getElementById('person-detail').addEventListener('close',()=>window.SicratPersonMap.destroy());
    $(document).on('click', '.person-open', function () { openPerson($(this).attr('data-id')); });
    $(document).on('keydown', 'tr.person-open', function(event) { if (event.target===this && ['Enter',' '].includes(event.key)) { event.preventDefault(); openPerson($(this).attr('data-id')); } });
    $('#person-edit-form').on('submit', function (event) {
        event.preventDefault();
        if (!personCan('edit') || !personEditable) return;
        if (this.dataset.busy) return;
        const form=this;
        const data = Object.fromEntries(new FormData(this));
        window.SicratFeedback.execute(form, () => identityRequest(`persons/${selectedPerson}`, 'put', data).done(function (response) { ShowToastMessage(response.data.identity_warnings?.length ? 'Información guardada. Hay coincidencias de identidad en otras fichas.' : 'Información guardada.', 'success'); GetPersons(true); openPerson(selectedPerson); })).catch(identityError);
    });
    $('#person-participate').on('click', function () {
        if (!personCan('participate')) return;
        window.SicratFeedback.execute(this,()=>identityRequest(`persons/${selectedPerson}/participations`, 'post', {processes: $('#person-election-select').val() || [], active: 1}).done(function () { openPerson(selectedPerson); })).catch(identityError);
    });
    $('#identity-policy-open').on('click', function () {
        identityRequest(`organizations/${$('#select-persons-organization').val()}/identity-policy`, 'get').done(function (response) {
            $('#identity-curp').prop('checked', Number(response.data.curp_unica) === 1);
            $('#identity-key').prop('checked', Number(response.data.clave_elector_unica) === 1);
            document.getElementById('identity-policy').showModal();
        }).fail(identityError);
    });
    $('#identity-policy-form').on('submit', function (event) {
        event.preventDefault();
        identityRequest(`organizations/${$('#select-persons-organization').val()}/identity-policy`, 'put', {curp_unique: $('#identity-curp').is(':checked') ? 1 : 0, elector_key_unique: $('#identity-key').is(':checked') ? 1 : 0}).done(function () { document.getElementById('identity-policy').close(); ShowToastMessage('Reglas guardadas.', 'success'); }).fail(identityError);
    });
}
