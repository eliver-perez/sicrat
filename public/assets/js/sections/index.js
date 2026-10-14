// JavaScript Document

var homeURL;
var sectionsPagination;
var selected_organization = '';
var selectedSection = null, sectionWritable = false, sectionSuper = false, sectionHasElection = false;
var new_section = false, modify_section = false, registering_section = false, modifying_section = false;
var defaultSelectedState = 28;
var defaultSelectedMunicipality = 1960;

function InitializeValues(home) {
	homeURL = home;
	sectionsPagination = createPagination('#table-sections', () => GetSections());
	$('#btn-sections-new').on('click', NewSection);
	$('#btn-sections-cancel').on('click', CancelSection);
	$('#select-sections-state').on('change', GetMunicipalities);
	GetSections();
	GetStates();
	$('#table-sections').on('click', '[data-section-open]', function(){ OpenSection(this.dataset.sectionOpen); });
	$.getJSON(`${homeURL}/api/auth/me`).done(function(response){ const me=response.data;sectionSuper=me.is_superadmin;sectionHasElection=Boolean(me.process_id);sectionWritable=me.is_superadmin || me.rights.includes('secciones.administrar');$('#btn-sections-new').toggleClass('hidden',!sectionWritable || !me.process_id); });
}

function EnableSectionFields(v) {
	$(`#field-sections-section`).attr('readonly', v);
	$(`#select-sections-state`).attr('disabled', v);
	$(`#select-sections-municipality`).attr('disabled', v);
}

function NewSection() {
	if(!new_section && !modify_section) {
		selectedSection=null;new_section = true;
		if(!$('#btn-sections-new').hasClass('hidden'))
			$('#btn-sections-new').addClass('hidden');
		if($('#btn-sections-register').hasClass('hidden'))
			$('#btn-sections-register').removeClass('hidden');
		if($('#btn-sections-cancel').hasClass('hidden'))
			$('#btn-sections-cancel').removeClass('hidden');
		ClearSection();
		EnableSectionFields(false);
	}
}

function CancelSection() {
	if(new_section || modify_section) {
		selectedSection=null;new_section = false;
		modify_section = false;
		if($('#btn-sections-new').hasClass('hidden'))
			$('#btn-sections-new').removeClass('hidden');
		if(!$('#btn-sections-register').hasClass('hidden'))
			$('#btn-sections-register').addClass('hidden');
		if(!$('#btn-sections-cancel').hasClass('hidden'))
			$('#btn-sections-cancel').addClass('hidden');
		EnableSectionFields(true);$('#btn-sections-new').toggleClass('hidden',!sectionWritable);
	}
}

function ClearSection() {
	$(`#field-sections-section`).val('');
}

function GetStates() {
	$.ajax({
        url: `${homeURL}/api/states`,
		type: 'get',
		dataType: "json",
		success: function(response) {
			$.each(response.data.states, function(k, v) {
				$('#select-sections-state').append($('<option>', {
					value: v.id,
					text: v.estado
				}));
			});
			if(defaultSelectedState != null && response.data.states.some(state=>Number(state.id)===defaultSelectedState)) {
				$('#select-sections-state').val(defaultSelectedState);
			}
			$('#select-sections-state').trigger('change');
		},
		error: function(XMLHttpRequest, textStatus, errorThrown) { 
			try {
				let response = JSON.parse(XMLHttpRequest.responseText);
				ShowToastMessage(response.message, 'error')
			} catch(e) {
				ShowToastMessage(XMLHttpRequest.responseText, 'error');
			}
		}  
	});
}

function GetMunicipalities() {
	$('#select-sections-municipality').empty();
	if($('#select-sections-state').val() != null) {
		$.ajax({
			url: `${homeURL}/api/states/${$('#select-sections-state').val()}/municipalities`,
			type: 'get',
			dataType: "json",
			success: function(response) {
				$.each(response.data.municipalities, function(k, v) {
					$('#select-sections-municipality').append($('<option>', {
						value: v.id,
						text: v.municipio
					}));
				});
				if(defaultSelectedMunicipality != null && response.data.municipalities.some(municipality=>Number(municipality.id)===defaultSelectedMunicipality))
					$('#select-sections-municipality').val(defaultSelectedMunicipality);
				$('#select-sections-municipality').trigger('change');
			},
			error: function(XMLHttpRequest, textStatus, errorThrown) { 
				try {
					let response = JSON.parse(XMLHttpRequest.responseText);
					ShowToastMessage(response.message, 'error')
				} catch(e) {
					ShowToastMessage(XMLHttpRequest.responseText, 'error');
				}
			}  
		});
	}
}

function GetSections() {
	$('#table-sections tbody').empty();
	$.ajax({
        url: `${homeURL}/api/sections`,
		type: 'get',
		data: {
			...sectionsPagination.params(),
			search: ''
		},
		dataType: "json",
		success: function(response) {
			sectionsPagination.update(response.pagination);
			var rows = '';

			$.each(response.data.sections, function(k, v) {
				rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${escapeHTML(v.id)}">
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.section)}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.state)}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.municipality)}</td>
                                <td><button type="button" class="btn" data-section-open="${escapeHTML(v.id)}" aria-label="Ver sección" title="Ver sección">Ver</button></td>
							</tr>`;
			});
			if (!rows) rows = '<tr><td colspan="4" class="p-4">No hay registros que coincidan con la consulta.</td></tr>';
			$('#table-sections tbody').append(rows);
		},
		error: function(XMLHttpRequest, textStatus, errorThrown) { 
			try {
				let response = JSON.parse(XMLHttpRequest.responseText);
				ShowToastMessage(response.message, 'error')
			} catch(e) {
				ShowToastMessage(XMLHttpRequest.responseText, 'error');
			}
		}  
	});
}

function RegisterSection() {
	if(!registering_section) {
		registering_section = true;$('#btn-sections-register').prop('disabled',true).text('Guardando…');
		$.ajax({
				url: `${homeURL}/api/sections${selectedSection ? '/'+selectedSection : ''}`,
				type: selectedSection ? 'put' : 'post',
				data: {
					section: $('#field-sections-section').val(),
					state: $(`#select-sections-state`).val(),
					municipality: $(`#select-sections-municipality`).val(),
				},
				dataType: "json",
				success: function(response) {
					registering_section = false;$('#btn-sections-register').prop('disabled',false).text('Guardar');

					if(response.success) {
						CancelSection();
						GetSections();
						ShowToastMessage(response.message, 'success');
					} else {
						ShowToastMessage(response.message, 'error');
					}
				},
				error: function(XMLHttpRequest, textStatus, errorThrown) { 
					registering_section = false;$('#btn-sections-register').prop('disabled',false).text('Guardar');
					try {
						var response = JSON.parse(XMLHttpRequest.responseText);
						ShowToastMessage(response.message, 'error');

					} catch (e) {
						ShowToastMessage(XMLHttpRequest.responseText, 'error');
					}
				}  
		});
	}
}
function OpenSection(uuid) {
 $.getJSON(`${homeURL}/api/sections/${uuid}`).done(function(response) {
  const row=response.data;selectedSection=uuid;new_section=false;modify_section=true;
  $('#field-sections-section').val(row.seccion);defaultSelectedMunicipality=Number(row.municipio);$('#select-sections-state').val(row.estado).trigger('change');
  const readOnly=!sectionHasElection || !sectionWritable || (!sectionSuper && row.shared) || row.history_locked;
  $('#btn-sections-new').addClass('hidden');$('#btn-sections-cancel').removeClass('hidden');$('#btn-sections-register').toggleClass('hidden',readOnly).text('Guardar');EnableSectionFields(readOnly);
  $('#sections-detail-message').text(!sectionHasElection ? 'Selecciona una elección de trabajo para editar.' : row.history_locked ? 'Se conservan los datos históricos de las actas existentes.' : row.shared ? 'Sección física compartida entre elecciones. Su modificación requiere administración global.' : '');
 }).fail(function(xhr){ShowToastMessage(xhr.responseJSON?.message || 'No fue posible consultar la sección.','error');});
}
