// JavaScript Document

var homeURL;
var selected_organization = '';
var new_section = false, modify_section = false, registering_section = false, modifying_section = false;
var defaultSelectedState = 28;
var defaultSelectedMunicipality = 1960;

function InitializeValues(home) {
	homeURL = home;
	$('#btn-sections-new').on('click', NewSection);
	$('#btn-sections-cancel').on('click', CancelSection);
	$('#select-sections-state').on('change', GetMunicipalities);
	GetSections();
	GetStates();
}

function EnableSectionFields(v) {
	$(`#field-sections-section`).attr('readonly', v);
	$(`#select-sections-state`).attr('disabled', v);
	$(`#select-sections-municipality`).attr('disabled', v);
}

function NewSection() {
	if(!new_section && !modify_section) {
		new_section = true;
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
		new_section = false;
		modify_section = false;
		if($('#btn-sections-new').hasClass('hidden'))
			$('#btn-sections-new').removeClass('hidden');
		if(!$('#btn-sections-register').hasClass('hidden'))
			$('#btn-sections-register').addClass('hidden');
		if(!$('#btn-sections-cancel').hasClass('hidden'))
			$('#btn-sections-cancel').addClass('hidden');
		EnableSectionFields(true);
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
			if(defaultSelectedState != null) {
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
				if(defaultSelectedMunicipality != null)
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
			search: ''
		},
		dataType: "json",
		success: function(response) {
			var rows = '';
			console.log(response);
			$.each(response.data.sections, function(k, v) {
				rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${v.id}">
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.section}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.state}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.municipality}</td>
							</tr>`;
			});
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
		registering_section = true;
		$.ajax({
				url: `${homeURL}/api/sections`,
				type: 'post',
				data: {
					section: $('#field-sections-section').val(),
					state: $(`#select-sections-state`).val(),
					municipality: $(`#select-sections-municipality`).val(),
				},
				dataType: "json",
				success: function(response) {
					registering_section = false;
					console.log(response);
					if(response.success) {
						CancelSection();
						GetSections();
						ShowToastMessage(response.message, 'success');
					} else {
						ShowToastMessage(response.message, 'error');
					}
				},
				error: function(XMLHttpRequest, textStatus, errorThrown) { 
					registering_section = false;
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