// JavaScript Document

var homeURL;
var selected_person = '';
var new_person = false, modify_person = false, registering_person = false, modifying_person = false;
var defaultSelectedState = 28;
var defaultSelectedMunicipality = 1960;

let searching = false;
let lastSearchText = '';
let lastSearchSection = '';

function InitializeValues(home) {
	homeURL = home;
	$('#btn-persons-new').on('click', NewPerson);
	$('#btn-persons-cancel').on('click', CancelPerson);
	$('#select-filter-section').on('change', GetPersons);
	$('#field-filter-search').on('keydown', function(event) {
        if (event.which === 13) {
            event.preventDefault();
            
            GetPersons();
        }
    });
	GetOrganizations();
	GetSections();
	GetGenders();
	$('#btn-filter-search').on('click', GetPersons);
}

function EnablePersonFields(v) {
	$(`#select-persons-organization`).attr('disabled', v);
	$(`#select-persons-section`).attr('disabled', v);
	$(`#select-persons-gender`).attr('disabled', v);
	$(`#field-persons-name`).attr('readonly', v);
	$(`#field-persons-last-name`).attr('readonly', v);
	$(`#field-persons-last-name-2`).attr('readonly', v);
}

function NewPerson() {
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
	$(`#field-persons-name`).val('');
	$(`#field-persons-last-name`).val('');
	$(`#field-persons-last-name-2`).val('');
}

function GetGenders() {
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
	$.ajax({
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
	});
}

function GetSections() {
	$('#select-filter-section').empty();
	$('#select-persons-section').empty();
	$.ajax({
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
				$('#select-filter-section').val(selected);
				$('#select-persons-section').val(selected);
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
	});
}

function GetPersons() {
	if(!searching && (lastSearchText != $('#field-filter-search').val() || lastSearchSection != $('#select-filter-section').val())) {
		searching = true;
		lastSearchText = $('#field-filter-search').val();
		lastSearchSection = $('#select-filter-section').val();
		$('#table-persons tbody').empty();
		$.ajax({
			url: `${homeURL}/api/persons`,
			type: 'get',
			data: {
				search: $('#field-filter-search').val(),
				section: $('#select-filter-section').val()
			},
			dataType: "json",
			success: function(response) {
				searching = false;
				var rows = '';
				$.each(response.data.persons, function(k, v) {
					rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${v.id}">
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.section}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.name}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"></td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"></td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"></td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"></td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"></td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm"></td>
								</tr>`;
				});
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

function RegisterPerson() {
	if(!registering_person) {
		registering_person = true;
		$.ajax({
				url: `${homeURL}/api/persons`,
				type: 'post',
				data: {
					organization: $('#select-persons-organization').val(),
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
						GetPersons();
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
						
					} catch (e) {
						ShowToastMessage(XMLHttpRequest.responseText, 'error');
					}
				}  
		});
	}
}