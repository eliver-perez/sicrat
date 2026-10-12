$(document).ready(function () {
    const container = $('<div>', {class: 'card p-4 mb-4 flex gap-3 items-center'});
    const selector = $('<select>', {class: 'form-input', 'aria-label': 'Elección de trabajo'});
    container.append($('<label>').text('Elección de trabajo'), selector);
    $('main').prepend(container);
    selector.append($('<option>', {value: '', text: 'Selecciona una elección'}));
    loadOptions({url: `${homeURL}/api/auth/elections`, type: 'get', dataType: 'json', success: function (response) {
        response.data.electoral_processes.forEach(function (election) {
            selector.append($('<option>', {value: election.id, text: `${election.organization} — ${election.process}${election.type ? ' · ' + election.type : ''}`, selected: election.selected}));
        });
        if (response.data.electoral_processes.length === 0) container.append($('<span>').text('Sin elecciones activas disponibles.'));
    }}, 'electoral_processes');
    selector.on('change', function () {
        if (!selector.val()) return;
        selector.prop('disabled', true);
        $.ajax({url: `${homeURL}/api/auth/election`, type: 'post', data: {process: selector.val()}, success: function () { window.location.href = homeURL; },
            error: function (xhr) { selector.prop('disabled', false); ShowToastMessage(xhr.responseJSON?.message || 'No se pudo cambiar la elección.', 'error'); }});
    });
});
