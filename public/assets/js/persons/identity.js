const personLabels = {name: 'Nombre', last_name: 'Apellido paterno', last_name_2: 'Apellido materno', birth_date: 'Fecha de nacimiento', gender: 'Género', phone: 'Teléfono', alternate_phone: 'Teléfono alternativo', email: 'Correo', curp: 'CURP', elector_key: 'Clave de elector', ocr: 'OCR', section: 'Sección', street: 'Calle', exterior_number: 'Número exterior', interior_number: 'Número interior', postal_code: 'Código postal', references: 'Referencias', neighborhood: 'Colonia', latitude: 'Latitud', longitude: 'Longitud'};
let selectedPerson = null;
let detailOrganization = null;

function identityRequest(path, method, data) {
    return $.ajax({url: `${homeURL}/api/${path}`, type: method, data: JSON.stringify(data), contentType: 'application/json', dataType: 'json'});
}
function identityError(xhr) { ShowToastMessage(xhr.responseJSON?.message || 'No se pudo completar la operación.', 'error'); }

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
            control.attr({name: key, class: 'form-input'}).val(person[key] || '');
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
                if (person.neighborhood) control.append($('<option>', {value: person.neighborhood, text: 'Colonia registrada', selected: true}));
                const choose = $('<button>', {type: 'button', class: 'btn text-primary'}).text('Elegir colonia');
                choose.on('click', function () { choosePersonNeighborhood(control); });
                group.append($('<div>', {class:'ux-actions'}).append(choose));
            }
            group.append($('<label>').text(label).append(control));
        });
        const participations = $('#person-participations').empty();
        const table = $('<table>'); table.append($('<thead>').append($('<tr>').append($('<th>').text('Elección'),$('<th>').text('Estado'),$('<th>').text('Acción'))));
        const rows = $('<tbody>'); table.append(rows); if (person.participations.length) participations.append(table);
        if (!person.participations.length) participations.text('Sin participaciones electorales visibles.');
        person.participations.forEach(function (participation) {
            const line = $('<tr>').append($('<td>').text(participation.process),$('<td>').append($('<span>', {class:'ux-badge ' + (participation.active ? 'ux-badge-success' : 'ux-badge-neutral')}).text(participation.active ? 'Activa' : 'Retirada')));
            const button = $('<button>', {type: 'button', class: 'btn text-primary'}).text(participation.active ? 'Retirar' : 'Reactivar');
            button.on('click', function () { identityRequest(`persons/${uuid}/participations`, 'post', {processes: [participation.id], active: participation.active ? 0 : 1}).done(function () { openPerson(uuid); }).fail(identityError); });
            line.append($('<td>').append(button)); rows.append(line);
        });
        const selector = $('#person-election-select').empty();
        loadOptions({url: `${homeURL}/api/auth/elections`, type: 'get', dataType: 'json', success: function (elections) {
            // El backend verifica la organización aun si se manipula el selector.
            elections.data.electoral_processes.forEach(function (election) { selector.append($('<option>', {value: election.id, text: `${election.organization} — ${election.process}`})); });
        }}, 'electoral_processes');
        const dialog = document.getElementById('person-detail');
        if (!dialog.open) dialog.showModal();
        if (typeof window.InitializePersonOperations==='function') window.InitializePersonOperations(uuid,homeURL);
    }).fail(identityError);
}

function choosePersonNeighborhood(target) {
    const box = $('#person-neighborhood-picker').empty().removeClass('hidden');
    const state = $('<select>', {class: 'form-input', 'aria-label': 'Estado'});
    const municipality = $('<select>', {class: 'form-input', 'aria-label': 'Municipio'});
    const locality = $('<select>', {class: 'form-input', 'aria-label': 'Localidad'});
    const neighborhood = $('<select>', {class: 'form-input', 'aria-label': 'Colonia'});
    box.append($('<p>').text('Selecciona estado, municipio, localidad y colonia.'), state, municipality, locality, neighborhood);
    function options(path, key, label, select) {
        select.empty().append($('<option>', {value: '', text: 'Selecciona'}));
        $.getJSON(`${homeURL}/api/${path}`, function (response) {
            response.data[key].forEach(item => select.append($('<option>', {value: item.id, text: item[label]})));
        }).fail(identityError);
    }
    options('states', 'states', 'estado', state);
    state.on('change', function () { municipality.empty(); locality.empty(); neighborhood.empty(); if (state.val()) options(`states/${state.val()}/municipalities`, 'municipalities', 'municipio', municipality); });
    municipality.on('change', function () { locality.empty(); neighborhood.empty(); if (municipality.val()) options(`municipalities/${municipality.val()}/localities`, 'localities', 'localidad', locality); });
    locality.on('change', function () { neighborhood.empty(); if (locality.val()) options(`localities/${locality.val()}/neighborhoods`, 'neighborhoods', 'colonia', neighborhood); });
    neighborhood.on('change', function () { if (neighborhood.val()) { target.empty().append($('<option>', {value: '', text: 'Sin colonia'}), $('<option>', {value: neighborhood.val(), text: neighborhood.find(':selected').text(), selected: true})); box.addClass('hidden'); } });
}

function InitializePersonIdentity() {
    $(document).on('click', '.person-open', function () { openPerson($(this).attr('data-id')); });
    $('#person-edit-form').on('submit', function (event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(this));
        identityRequest(`persons/${selectedPerson}`, 'put', data).done(function (response) { ShowToastMessage(response.data.identity_warnings?.length ? 'Información guardada. Hay coincidencias de identidad en otras fichas.' : 'Información guardada.', 'success'); GetPersons(true); openPerson(selectedPerson); }).fail(identityError);
    });
    $('#person-participate').on('click', function () {
        identityRequest(`persons/${selectedPerson}/participations`, 'post', {processes: $('#person-election-select').val() || [], active: 1}).done(function () { openPerson(selectedPerson); }).fail(identityError);
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
