$(document).ready(function () {
    let selected = '';
    const status = $('#electoral-context-status');
    const selector = $('#electoral-context-select');
    loadOptions({url: `${homeURL}/api/auth/elections`, type: 'get', dataType: 'json', success: function (response) {
        response.data.electoral_processes.forEach(function (election) {
            selector.append($('<option>', {value: election.id, text: `${election.process} · ${election.organization}`, selected: election.selected}));
        });
        const elections = response.data.electoral_processes;
        selected = selector.val();
        if (elections.length === 0) {
            selector.find('option').text('Sin elecciones disponibles');
            selector.prop('disabled', true);
            status.text('Sin elecciones disponibles. Puedes usar los módulos generales autorizados.');
        } else if (elections.length === 1 && elections[0].selected) {
            selector.prop('disabled', true);
        }
        selector.attr('title', selector.find('option:selected').text());
        if (status.text() && elections.length > 1 && !selected) ShowToastMessage(status.text(), 'warning');
    }}, 'electoral_processes');
    selector.on('change', function () {
        if (!selector.val()) return;
        selector.prop('disabled', true);
        $('#electoral-context-status').text('Cambiando elección…');
        $.ajax({url: `${homeURL}/api/auth/election`, type: 'post', data: {process: selector.val()}, success: function () { window.location.href = homeURL; },
            error: function (xhr) { selector.val(selected); selector.prop('disabled', false); status.text('No se pudo cambiar la elección.'); ShowToastMessage(xhr.responseJSON?.message || 'No se pudo cambiar la elección.', 'error'); }});
    });
});
