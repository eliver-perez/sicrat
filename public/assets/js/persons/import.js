let personImportId = null;
let personImportAfter = 0;
let personImportReviewComplete = false;
let personImportRows = [];
let personImportBusy = false;
let personImportResultAfter = 0;
const importStateLabels = {nuevo: 'Nueva persona', vinculado: 'Registro existente utilizado', completado: 'Datos faltantes completados', omitido: 'Omitida', rechazado: 'Rechazada', error: 'Error'};
function importPlan(summary) {
    if (!summary) return;
    const labels = {new: 'por crear', existing: 'por utilizar existentes', fill: 'por completar', skip: 'por omitir', rejected: 'inválidas', pending: 'por revisar', unresolved: 'por decidir'};
    $('#person-import-planned').text('Antes de confirmar: ' + Object.entries(summary).map(([key, count]) => `${count} ${labels[key] || key}`).join(' · '));
}
function showImportResults(after) {
    identityRequest(`person-imports/${personImportId}/results?after=${after}`, 'get').done(function (response) {
        personImportResultAfter = response.data.next_after;
        const box = $('#person-import-preview').empty();
        response.data.rows.forEach(row => box.append($('<p>').text(`Fila ${row.row}: ${importStateLabels[row.state] || row.state} ${row.result?.code || ''} ${row.result?.message || ''}`)));
        $('#person-import-results-next').prop('disabled', !response.data.has_more);
    }).fail(identityError);
}

function importSummary(summary) {
    const labels = {pendiente: 'por revisar', listo: 'listos', decision: 'por decidir', invalido: 'inválidos', nuevo: 'nuevos', vinculado: 'vinculados', completado: 'completados', omitido: 'omitidos', rechazado: 'rechazados', error: 'errores'};
    $('#person-import-summary').text(Object.entries(summary).map(([key, count]) => `${count} ${labels[key] || key}`).join(' · '));
}
function importBusy(busy) {
    personImportBusy = busy;
    $('#person-import button[type="submit"], #person-import-next, #person-import-confirm').prop('disabled', busy);
}
function importPreview() {
    importBusy(true);
    identityRequest(`person-imports/${personImportId}/preview`, 'post', {after: personImportAfter}).done(function (response) {
        personImportRows = response.data.rows;
        personImportAfter = response.data.next_after;
        personImportReviewComplete = response.data.review_complete;
        const box = $('#person-import-preview').empty();
        personImportRows.forEach(function (row) {
            const line = $('<div>', {class: 'p-3 border border-default-200 mb-3'});
            line.append($('<strong>').text(`Fila ${row.row}`));
            line.append($('<p>').text(Object.entries(row.data).map(([key, value]) => `${personLabels[key] || key}: ${value || ''}`).join(' · ')));
            if (row.review.error) line.append($('<p>', {class: 'text-danger'}).text(row.review.error));
            else {
                const action = $('<select>', {class: 'form-input import-action', 'data-row': row.row});
                if (row.state === 'decision') action.append($('<option>', {value: '', text: 'Selecciona qué hacer con esta coincidencia'}));
                if (!row.review.blocked) action.append($('<option>', {value: 'new', text: 'Crear persona nueva'}));
                action.append($('<option>', {value: 'skip', text: 'Omitir'}));
                if (row.review.matches.length || row.review.file_matches.length) {
                    action.append($('<option>', {value: 'existing', text: 'Utilizar existente'}), $('<option>', {value: 'fill', text: 'Completar únicamente datos faltantes'}));
                    const target = $('<select>', {class: 'form-input import-target', 'data-row': row.row});
                    row.review.matches.forEach(function (person) { target.append($('<option>', {value: `person:${person.id}`, text: `${person.code} · ${person.name}`})); });
                    row.review.file_matches.forEach(function (match) { target.append($('<option>', {value: `row:${match.fila}`, text: `Fila anterior ${match.fila} · ${match.nombre_completo}`})); });
                    line.append($('<p>').text(row.review.blocked ? 'Identificador repetido: no se permite crear una persona nueva.' : 'Posibles coincidencias. No se fusionará automáticamente.'));
                    line.append(target);
                }
                if (row.decision) action.val(row.decision.action);
                line.append(action);
            }
            box.append(line);
        });
        importSummary(response.data.summary);
        importPlan(response.data.planned);
        $('#person-import-next').removeClass('hidden').text(personImportReviewComplete ? 'Guardar decisiones y mostrar resumen' : 'Guardar decisiones y revisar siguiente lote');
    }).fail(identityError).always(function () { importBusy(false); });
}
function collectImportDecisions() {
    const decisions = [];
    $('#person-import-preview .import-action').each(function () {
        const action = $(this).val();
        if (!action) throw new Error('Decide qué hacer con todas las coincidencias del lote.');
        const row = Number($(this).attr('data-row'));
        const decision = {row: row, action: action};
        if (action === 'existing' || action === 'fill') {
            const target = $(`#person-import-preview .import-target[data-row="${row}"]`).val();
            if (!target) throw new Error('Selecciona una coincidencia.');
            if (target.startsWith('person:')) decision.person = target.slice(7);
            else decision.source_row = Number(target.slice(4));
        }
        decisions.push(decision);
    });
    return decisions;
}
function applyPersonImport() {
    identityRequest(`person-imports/${personImportId}/apply`, 'post', {}).done(function (response) {
        importSummary(response.data.summary);
        const box = $('#person-import-preview').empty();
        response.data.rows.forEach(function (row) { box.append($('<p>').text(`Fila ${row.row}: ${importStateLabels[row.state] || row.state} ${row.code || ''} ${row.message || ''}`)); });
        if (response.data.complete) {
            importBusy(false);
            $('#person-import-confirm').addClass('hidden');
            ShowToastMessage('Importación finalizada. Revisa el resumen y las filas rechazadas.', 'success');
            $('#person-import-result-pages').removeClass('hidden');
            $('#person-import-planned').empty();
            showImportResults(0);
            GetPersons(true);
        } else setTimeout(applyPersonImport, 0);
    }).fail(function (xhr) { importBusy(false); identityError(xhr); $('#person-import-confirm').text('Continuar importación').off('click').on('click', function () { importBusy(true); applyPersonImport(); }); });
}

function InitializePersonImport() {
    $('#person-import-results-first').on('click', function () { showImportResults(0); });
    $('#person-import-results-next').on('click', function () { showImportResults(personImportResultAfter); });
    $('#person-import-open').on('click', function () { document.getElementById('person-import').showModal(); });
    $('#person-import-upload').on('submit', function (event) {
        event.preventDefault();
        if (personImportBusy) return;
        importBusy(true);
        const data = new FormData(this);
        data.append('organization', $('#select-persons-organization').val() || '');
        $.ajax({url: `${homeURL}/api/person-imports`, type: 'post', data: data, processData: false, contentType: false, dataType: 'json'}).done(function (response) {
            personImportId = response.data.id;
            personImportAfter = 0;
            $('#person-import-preview').empty(); $('#person-import-summary').text(`${response.data.total} filas cargadas para revisión.`);
            $('#person-import-next, #person-import-confirm, #person-import-result-pages').addClass('hidden');
            $('#person-import-planned').empty();
            const box = $('#person-import-columns').empty();
            response.data.fields.forEach(function (field) {
                const selector = $('<select>', {name: field, class: 'form-input'}).append($('<option>', {value: '', text: 'No importar'}));
                response.data.headers.forEach(function (header, position) { selector.append($('<option>', {value: position, text: header || `Columna ${position + 1}`})); });
                const matched = response.data.headers.findIndex(header => header.toLowerCase().trim() === field || header.toLowerCase().trim() === (personLabels[field] || '').toLowerCase());
                if (matched >= 0) selector.val(String(matched));
                box.append($('<label>').text(personLabels[field] || field).append(selector));
            });
            $('#person-import-mapping').removeClass('hidden');
        }).fail(identityError).always(function () { importBusy(false); });
    });
    $('#person-import-mapping').on('submit', function (event) {
        event.preventDefault(); if (personImportBusy) return;
        const mapping = Object.fromEntries(new FormData(this));
        Object.keys(mapping).forEach(key => { if (mapping[key] === '') delete mapping[key]; });
        importBusy(true);
        identityRequest(`person-imports/${personImportId}/mapping`, 'put', {mapping: mapping, detect_duplicates: $('#import-detect').is(':checked') ? 1 : 0}).done(function () { $('#person-import-mapping').addClass('hidden'); importPreview(); }).fail(function (xhr) { importBusy(false); identityError(xhr); });
    });
    $('#person-import-next').on('click', function () {
        if (personImportBusy) return;
        let decisions;
        try { decisions = collectImportDecisions(); } catch (error) { ShowToastMessage(error.message, 'error'); return; }
        importBusy(true);
        identityRequest(`person-imports/${personImportId}/decisions`, 'put', {decisions: decisions}).done(function () {
            if (personImportReviewComplete) {
                identityRequest(`person-imports/${personImportId}`, 'get').done(function (response) {
                    importSummary(response.data.summary);
                    importPlan(response.data.planned);
                    $('#person-import-next').addClass('hidden');
                    $('#person-import-confirm').removeClass('hidden');
                    importBusy(false);
                }).fail(function (xhr) { importBusy(false); identityError(xhr); });
            } else importPreview();
        }).fail(function (xhr) { importBusy(false); identityError(xhr); });
    });
    $('#person-import-confirm').on('click', function () {
        if (personImportBusy) return;
        importBusy(true);
        identityRequest(`person-imports/${personImportId}/confirm`, 'post', {}).done(applyPersonImport).fail(function (xhr) { importBusy(false); identityError(xhr); });
    });
}
