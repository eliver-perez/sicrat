let selectedAccessUser = null;
function userAccessRequest(path, method, data) {
    return $.ajax({url: `${homeURL}/api/${path}`, type: method, data: JSON.stringify(data), contentType: 'application/json', dataType: 'json'});
}
function userAccessError(xhr) { ShowToastMessage(xhr.responseJSON?.message || 'No se pudo completar la operación.', 'error'); }
function loadUserAccesses(uuid) {
    selectedAccessUser = uuid;
    userAccessRequest(`users/${uuid}/accesses`, 'get').done(function (response) {
        const list = $('#user-access-list').empty();
        response.data.accesses.forEach(function (access) {
            list.append($('<button>', {class: 'btn block text-primary', type: 'button'}).text(`${access.name} · ${access.type_name} · ${access.active ? 'Activo' : 'Inactivo'}`).on('click', function () {
                $('#user-access-process').val(access.process); $('#user-access-type').val(String(access.type)); $('#user-access-active').prop('checked', Boolean(access.active));
            }));
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
    $('#user-access-form').on('submit', function (event) {
        event.preventDefault();
        userAccessRequest(`users/${selectedAccessUser}/accesses`, 'post', {process: processes.val(), type: $('#user-access-type').val(), active: $('#user-access-active').is(':checked') ? 1 : 0}).done(function () { loadUserAccesses(selectedAccessUser); }).fail(userAccessError);
    });
    $('#user-person-find').on('click', function () {
        $.getJSON(`${homeURL}/api/persons`, {search: $('#user-person-search').val(), limit: 100}, function (response) {
            const selector = $('#user-person-select').empty().append($('<option>', {value: '', text: 'Sin persona vinculada'}));
            response.data.persons.forEach(person => selector.append($('<option>', {value: person.id, text: `${person.code} · ${person.name}`})));
        }).fail(userAccessError);
    });
    $('#user-person-form').on('submit', function (event) {
        event.preventDefault();
        userAccessRequest(`users/${selectedAccessUser}/person`, 'put', {person: $('#user-person-select').val() || null}).done(function () { loadUserAccesses(selectedAccessUser); }).fail(userAccessError);
    });
}
