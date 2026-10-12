$(document).ready(function () {
    const container = $('#electoral-context');
    const selector = $('#electoral-context-select');
    loadOptions({url: `${homeURL}/api/auth/elections`, type: 'get', dataType: 'json', success: function (response) {
        response.data.electoral_processes.forEach(function (election) {
            selector.append($('<option>', {value: election.id, text: `${election.organization} — ${election.process}${election.type ? ' · ' + election.type : ''}`, selected: election.selected}));
        });
        if (response.data.electoral_processes.length === 0) { selector.prop('disabled', true); $('#electoral-context-status').text('Sin elecciones activas disponibles.'); }
    }}, 'electoral_processes');
    selector.on('change', function () {
        if (!selector.val()) return;
        selector.prop('disabled', true);
        $('#electoral-context-status').text('Cambiando elección…');
        $.ajax({url: `${homeURL}/api/auth/election`, type: 'post', data: {process: selector.val()}, success: function () { window.location.href = homeURL; },
            error: function (xhr) { selector.prop('disabled', false); ShowToastMessage(xhr.responseJSON?.message || 'No se pudo cambiar la elección.', 'error'); }});
    });
});
