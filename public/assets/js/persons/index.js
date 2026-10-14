// JavaScript Document

var homeURL;
var personsPagination;
var selected_person = '';
var new_person = false, modify_person = false, registering_person = false, modifying_person = false;
var defaultSelectedState = 28;
var defaultSelectedMunicipality = 1960;

let searching = false;
let lastSearchText = '';
let lastSearchSection = '';

function InitializeValues(home) {
	homeURL = home;
	personsPagination = createPagination('#table-persons', () => GetPersons(true));
	$('#btn-persons-new').on('click', NewPerson);
	$('#btn-persons-cancel').on('click', CancelPerson);
	$('#select-filter-section').on('change', () => { personsPagination.reset(); GetPersons(true); });
	$('#field-filter-search').on('keydown', function(event) {
        if (event.which === 13) {
            event.preventDefault();
            
            personsPagination.reset();
            GetPersons(true);
        }
    });
	GetOrganizations();
	GetSections();
	GetGenders();
	$('#btn-filter-search').on('click', () => { personsPagination.reset(); GetPersons(true); });
	InitializePersonIdentity();
	InitializePersonImport();
}

function EnablePersonFields(v) {
	$('.person-quick-optional').prop('readonly', v);
	$(`#select-persons-organization`).attr('disabled', v);
	$(`#select-persons-section`).attr('disabled', v);
	$(`#select-persons-gender`).attr('disabled', v);
	$(`#field-persons-name`).attr('readonly', v);
	$(`#field-persons-last-name`).attr('readonly', v);
	$(`#field-persons-last-name-2`).attr('readonly', v);
}

function NewPerson() {
	if (!personCan('create')) return;
	if(!new_person && !modify_person) {
		new_person = true;
		if(!$('#btn-persons-new').hasClass('hidden'))
			$('#btn-persons-new').addClass('hidden');
		if($('#btn-persons-register').hasClass('hidden'))
			$('#btn-persons-register').removeClass('hidden');
		if($('#btn-persons-cancel').hasClass('hidden'))
			$('#btn-persons-cancel').removeClass('hidden');
		ClearPerson();
		EnablePersonFields(false);
	}
}

function CancelPerson() {
	if(new_person || modify_person) {
		new_person = false;
		modify_person = false;
		if($('#btn-persons-new').hasClass('hidden'))
			$('#btn-persons-new').removeClass('hidden');
		if(!$('#btn-persons-register').hasClass('hidden'))
			$('#btn-persons-register').addClass('hidden');
		if(!$('#btn-persons-cancel').hasClass('hidden'))
			$('#btn-persons-cancel').addClass('hidden');
		EnablePersonFields(true);
	}
}

function ClearPerson() {
	$('.person-quick-optional').val('');
	$('#select-persons-section, #select-persons-gender').val('');
	$('#person-duplicates').empty().addClass('hidden');
	$(`#field-persons-name`).val('');
	$(`#field-persons-last-name`).val('');
	$(`#field-persons-last-name-2`).val('');
}

function GetGenders() {
	$('#select-persons-gender').append($('<option>', {value: '', text: 'Sin género'}));
	$.ajax({
        url: `${homeURL}/api/genders`,
		type: 'get',
		dataType: "json",
		success: function(response) {
			$.each(response.data.genders, function(k, v) {
				$('#select-persons-gender').append($('<option>', {
					value: v.id,
					text: v.genero,
					'data-tipo': v.codigo
				}));
			});
			$('#select-persons-gender').trigger('change');
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

function GetOrganizations() {
	$('#select-persons-organization').empty();
	loadOptions({
        url: `${homeURL}/api/organizations`,
		type: 'get',
		data: {
			search: ''
		},
		dataType: "json",
		success: function(response) {
			if(response.success) {
				$.each(response.data.organizations, function(k, v) {
					$('#select-persons-organization').append($('<option>', {
						value: v.id,
						text: v.organization
					}));
				});
				$('#select-persons-organization').trigger('change');
			}
		},
		error: function(XMLHttpRequest, textStatus, errorThrown) { 
			try {
				let response = JSON.parse(XMLHttpRequest.responseText);
				ShowToastMessage(response.message, 'error')
			} catch(e) {
				ShowToastMessage(XMLHttpRequest.responseText, 'error');
			}
		}  
	}, 'organizations');
}

function GetSections() {
	$('#select-filter-section').empty();
	$('#select-persons-section').empty();
	$('#select-persons-section').append($('<option>', {value: '', text: 'Sin sección'}));
	loadOptions({
        url: `${homeURL}/api/sections`,
		type: 'get',
		data: {
			search: ''
		},
		dataType: "json",
		success: function(response) {
			if(response.success) {
				var selected = null;
				$('#select-filter-section').append($('<option>', {
					value: 'N/A',
					text: 'Ver Todo'
				}));
				$.each(response.data.sections, function(k, v) {
					if(selected == null)
						selected = v.id;
					$('#select-filter-section').append($('<option>', {
						value: v.id,
						text: v.section
					}));
					$('#select-persons-section').append($('<option>', {
						value: v.id,
						text: v.section
					}));
				});
				$('#select-filter-section').val('N/A');
				$('#select-persons-section').val('');
				$('#select-filter-section').trigger('change');
				$('#select-persons-section').trigger('change');
			}
		},
		error: function(XMLHttpRequest, textStatus, errorThrown) { 
			try {
				let response = JSON.parse(XMLHttpRequest.responseText);
				ShowToastMessage(response.message, 'error')
			} catch(e) {
				ShowToastMessage(XMLHttpRequest.responseText, 'error');
			}
		}  
	}, 'sections');
}

function GetPersons(force = false) {
	if(!searching && (force === true || lastSearchText != $('#field-filter-search').val() || lastSearchSection != $('#select-filter-section').val())) {
		searching = true;
		lastSearchText = $('#field-filter-search').val();
		lastSearchSection = $('#select-filter-section').val();
		$('#table-persons tbody').empty();
		$.ajax({
			url: `${homeURL}/api/persons`,
			type: 'get',
			data: {
				...personsPagination.params(),
				search: $('#field-filter-search').val(),
				section: $('#select-filter-section').val()
			},
			dataType: "json",
			success: function(response) {
				personsPagination.update(response.pagination);
				searching = false;
				var rows = '';
				$.each(response.data.persons, function(k, v) {
					rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${escapeHTML(v.id)}">
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.section)}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"><button type="button" class="btn person-open" data-id="${escapeHTML(v.id)}">${escapeHTML(v.code)} · ${escapeHTML(v.name)}</button></td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.birth_date)}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"></td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.gender)}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.phone)}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.alternate_phone)}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${escapeHTML(v.email)}</td>
								</tr>`;
				});
				if (!rows) rows = '<tr><td colspan="8" class="p-4">No hay registros que coincidan con la consulta.</td></tr>';
				$('#table-persons tbody').append(rows);
			},
			error: function(XMLHttpRequest, textStatus, errorThrown) { 
				searching = false;
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

function RegisterPerson(confirmDuplicate = false) {
	if (!personCan('create')) return;
	if(!registering_person) {
		registering_person = true;
		$.ajax({
				url: `${homeURL}/api/persons`,
				type: 'post',
				data: {
					organization: $('#select-persons-organization').val(),
					confirm_duplicate: confirmDuplicate ? 1 : 0,
					phone: $('#person-quick-phone').val(),
					email: $('#person-quick-email').val(),
					curp: $('#person-quick-curp').val(),
					elector_key: $('#person-quick-elector_key').val(),
					section: $(`#select-persons-section`).val(),
					gender: $(`#select-persons-gender`).val(),
					name: $(`#field-persons-name`).val(),
					last_name: $(`#field-persons-last-name`).val(),
					last_name_2: $(`#field-persons-last-name-2`).val(),
				},
				dataType: "json",
				success: function(response) {
					registering_person = false;
					if(response.success) {
						CancelPerson();
						personsPagination.reset();
						GetPersons(true);
						ShowToastMessage(response.message, 'success');
					} else {
						ShowToastMessage(response.message, 'error');
					}
				},
				error: function(XMLHttpRequest, textStatus, errorThrown) { 
					registering_person = false;
					try {
						var response = JSON.parse(XMLHttpRequest.responseText);
						ShowToastMessage(response.message, 'error');
						if (response.data?.matches) showPersonDuplicates(response.data, function () { RegisterPerson(true); });
					} catch (e) {
						ShowToastMessage(XMLHttpRequest.responseText, 'error');
					}
				}  
		});
	}
}