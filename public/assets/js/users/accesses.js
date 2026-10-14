let selectedAccessUser = null;
function userAccessRequest(path, method, data) {
    return $.ajax({url: `${homeURL}/api/${path}`, type: method, data: JSON.stringify(data), contentType: 'application/json', dataType: 'json'});
}
function userAccessError(xhr) { ShowToastMessage(xhr.responseJSON?.message || 'No se pudo completar la operación.', 'error'); }
function loadUserAccesses(uuid) {
    selectedAccessUser = uuid;
    userAccessRequest(`users/${uuid}/accesses`, 'get').done(function (response) {
        const list = $('#user-access-list').empty();
        const table = $('<table>'); table.append($('<thead>').append($('<tr>').append($('<th>').text('Elección'),$('<th>').text('Rol'),$('<th>').text('Estado'),$('<th>').text('Acción')))); const rows = $('<tbody>'); table.append(rows);
        if (response.data.accesses.length) list.append(table); else list.text('Esta cuenta no tiene accesos electorales.');
        response.data.accesses.forEach(function (access) {
            const line = $('<tr>').append($('<td>').text(access.name),$('<td>').text(access.type_name),$('<td>').append($('<span>', {class:'ux-badge ' + (access.active ? 'ux-badge-success' : 'ux-badge-neutral')}).text(access.active ? 'Activo' : 'Inactivo')));
            line.append($('<td>').append($('<button>', {class: 'btn', type: 'button'}).text('Editar acceso').on('click', function () {
                $('#user-access-editor').prop('open',true);
                $('#user-access-process').val(access.process); $('#user-access-type').val(String(access.type)); $('#user-access-active').prop('checked', Boolean(access.active));
            }))); rows.append(line);
        });
        $('#user-person-current').text(response.data.person_code ? `Persona vinculada: ${response.data.person_code}` : 'La cuenta no tiene una persona vinculada.');
        const selector = $('#user-person-select').empty().append($('<option>', {value: '', text: 'Sin persona vinculada'}));
        if (response.data.person) selector.append($('<option>', {value: response.data.person, text: response.data.person_code, selected: true}));
        const dialog = document.getElementById('user-access-dialog'); if (!dialog.open) dialog.showModal();
    }).fail(userAccessError);
}
function InitializeUserAccesses() {
    const processes = $('#user-access-process');
    loadOptions({url: `${homeURL}/api/auth/elections`, type: 'get', dataType: 'json', success: function (response) {
        response.data.electoral_processes.forEach(election => processes.append($('<option>', {value: election.id, text: `${election.organization} — ${election.process}`})));
    }}, 'electoral_processes');
    $.getJSON(`${homeURL}/api/users-types`, function (response) { response.data.users_types.forEach(type => $('#user-access-type').append($('<option>', {value: type.id, text: type.type}))); });
    $(document).on('click', '#table-users tbody tr', function () { loadUserAccesses($(this).attr('data-id')); });
    $('#user-access-form').on('submit', window.SicratFeedback.guard(async function (event) {
        await userAccessRequest(`users/${selectedAccessUser}/accesses`, 'post', {process: processes.val(), type: $('#user-access-type').val(), active: $('#user-access-active').is(':checked') ? 1 : 0}).done(function () { loadUserAccesses(selectedAccessUser);ShowToastMessage('Acceso guardado correctamente.','success'); });
    }));
    $('#user-person-find').on('click',window.SicratFeedback.guard(()=>window.SicratUI.entitySelector({title:'Seleccionar persona',selected:$('#user-person-select').val(),
        load:async(search,offset)=>{const response=await $.getJSON(`${homeURL}/api/persons`,{search:search || $('#user-person-search').val(),limit:25,offset});return {rows:response.data.persons.map(person=>({value:person.id,label:`${person.code} · ${person.name}`})),pagination:response.pagination};},
        choose:person=>{$('#user-person-select').empty().append($('<option>',{value:person.value,text:person.label,selected:true}));}
    })));
    $('#user-person-form').on('submit', window.SicratFeedback.guard(async function (event) {
        await userAccessRequest(`users/${selectedAccessUser}/person`, 'put', {person: $('#user-person-select').val() || null}).done(function () { loadUserAccesses(selectedAccessUser);ShowToastMessage('Vínculo guardado correctamente.','success'); });
    }));
}
