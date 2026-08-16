// JavaScript Document

var homeURL;
var selected_organization = '';
var new_organization = false, modify_organization = false, registering_organization = false, modifying_organization = false;
var new_electoral_process = false, modify_electoral_process = false, registering_electoral_process = false, modifying_electoral_process = false;

function InitializeValues(home) {
	homeURL = home;
	$('#btn-organization-new').on('click', NewOrganization);
	$('#btn-organization-cancel').on('click', CancelOrganization);
	$('#btn-electoral-process-new').on('click', NewElectoralProcess);
	$('#btn-electoral-process-cancel').on('click', CancelElectoralProcess);
	GetOrganizations();
	$(document).on('click', '.organization-tr', function(event) {
		event.preventDefault();

		GetOrganizationData($(this).data('id'));
	});
	GetProcessTypes();
	GetProcessCharacters();
}

function EnableOrganizationFields(v) {
	$(`#field-organization`).attr('readonly', v);
	$(`#field-contact`).attr('readonly', v);
	$(`#field-phone`).attr('readonly', v);
	$(`#field-email`).attr('readonly', v);
	$(`#chk-active`).attr('disabled', v);
}

function EnableElectoralProcessFields(v) {
	$(`#field-electoral-process`).attr('readonly', v);
	$(`#select-electoral-process-type`).attr('disabled', v);
	$(`#select-electoral-process-character`).attr('disabled', v);
	$(`#chk-electoral-process-status`).attr('disabled', v);
}

function NewOrganization() {
	if(!new_organization && !modify_organization) {
		new_organization = true;
		if(!$('#btn-organization-new').hasClass('hidden'))
			$('#btn-organization-new').addClass('hidden');
		if($('#btn-organization-register').hasClass('hidden'))
			$('#btn-organization-register').removeClass('hidden');
		if($('#btn-organization-cancel').hasClass('hidden'))
			$('#btn-organization-cancel').removeClass('hidden');
		ClearOrganization();
		EnableOrganizationFields(false);
	}
}

function CancelOrganization() {
	if(new_organization || modify_organization) {
		new_organization = false;
		modify_organization = false;
		if($('#btn-organization-new').hasClass('hidden'))
			$('#btn-organization-new').removeClass('hidden');
		if(!$('#btn-organization-register').hasClass('hidden'))
			$('#btn-organization-register').addClass('hidden');
		if(!$('#btn-organization-cancel').hasClass('hidden'))
			$('#btn-organization-cancel').addClass('hidden');
		EnableOrganizationFields(true);
		if(selected_organization != '') {
			GetOrganizationData(selected_organization);
		}
	}
}

function NewElectoralProcess() {
	if(!new_electoral_process && !modify_electoral_process && selected_organization != '') {
		new_electoral_process = true;
		if(!$('#btn-electoral-process-new').hasClass('hidden'))
			$('#btn-electoral-process-new').addClass('hidden');
		if($('#btn-electoral-process-register').hasClass('hidden'))
			$('#btn-electoral-process-register').removeClass('hidden');
		if($('#btn-electoral-process-cancel').hasClass('hidden'))
			$('#btn-electoral-process-cancel').removeClass('hidden');
		ClearElectoralProcess();
		EnableElectoralProcessFields(false);
	}
}

function CancelElectoralProcess() {
	if(new_electoral_process || modify_electoral_process) {
		new_electoral_process = false;
		modify_electoral_process = false;
		if($('#btn-electoral-process-new').hasClass('hidden'))
			$('#btn-electoral-process-new').removeClass('hidden');
		if(!$('#btn-electoral-process-register').hasClass('hidden'))
			$('#btn-electoral-process-register').addClass('hidden');
		if(!$('#btn-electoral-process-cancel').hasClass('hidden'))
			$('#btn-electoral-process-cancel').addClass('hidden');
		EnableElectoralProcessFields(true);
	}
}

function GetOrganizations() {
	$('#table-organizations tbody').empty();
	$.ajax({
        url: `${homeURL}/api/organizations`,
		type: 'get',
		data: {
			search: ''
		},
		dataType: "json",
		success: function(response) {
			var rows = '';
			console.log(response);
			$.each(response.data.organizations, function(k, v) {
				rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${v.id}">
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.organization}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.contact}</td>
                                <td class="px-3.5 py-2.5 whitespace-nowrap">
									<span class="py-0.5 px-2.5 inline-flex items-center gap-x-1 text-xs font-medium bg-${v.active == 1 ? 'primary' : 'danger'}/15 text-${v.active == 1 ? 'primary' : 'danger'} rounded">
										${v.active == 1 ? 'Activo' : 'Inactivo'}
									</span>
								</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.registered_date}</td>
							</tr>`;
			});
			$('#table-organizations tbody').append(rows);
		},
		error: function(XMLHttpRequest, textStatus, errorThrown) { 
			console.log(XMLHttpRequest.responseText);
			let response = JSON.parse(XMLHttpRequest.responseText);
			ShowToastMessage(response.message, 'error')
		}  
	});
}

function RegisterOrganization() {
	if(!registering_organization) {
		registering_organization = true;
		$.ajax({
				url: `${homeURL}/api/organizations`,
				type: 'post',
				data: {
					organization: $(`#field-organization`).val(),
					contact: $(`#field-contact`).val(),
					phone: $(`#field-phone`).val(),
					email: $(`#field-email`).val(),
					active: $(`#chk-active`).is(':checked') ? 1 : 0,
				},
				dataType: "json",
				success: function(response) {
					registering_organization = false;
					console.log(response);
					if(response.success) {
						ClearOrganization();
						GetOrganizations();
						ShowToastMessage(response.message, 'success');
					} else {
						ShowToastMessage(response.message, 'error');
					}
				},
				error: function(XMLHttpRequest, textStatus, errorThrown) { 
					registering_organization = false;
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

function ClearOrganization() {
	$(`#field-organization`).val('');
	$(`#field-contact`).val('');
	$(`#field-phone`).val('');
	$(`#field-email`).val('');
	$(`#chk-active`).prop('checked', true);
	$('#table-electoral-process tbody').empty();
}

function ClearElectoralProcess() {
	$(`#field-electoral-process`).val('');
	$(`#chk-electoral-process-status`).prop('checked', true);
}

function GetProcessTypes() {
	$.ajax({
        url: `${homeURL}/api/electoral-process-type`,
		type: 'get',
		dataType: "json",
		success: function(response) {
			$.each(response.data.types, function(k, v) {
				$('#select-electoral-process-type').append($('<option>', {
					value: v.id,
					text: v.type
				}));
			});
        	// refreshSelectOption('select-electoral-process-type');
			$('#select-electoral-process-type').trigger('change');
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

function GetProcessCharacters() {
	$.ajax({
        url: `${homeURL}/api/electoral-process-character`,
		type: 'get',
		dataType: "json",
		success: function(response) {
			$.each(response.data.characters, function(k, v) {
				$('#select-electoral-process-character').append($('<option>', {
					value: v.id,
					text: v.character
				}));
			});
        	// refreshSelectOption('select-electoral-process-character');
			$('#select-electoral-process-character').trigger('change');
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

function GetOrganizationData(id) {
	ClearOrganization();
	selected_organization = id;
	$.ajax({
        url: `${homeURL}/api/organizations/${id}`,
		type: 'get',
		processData: false,
		contentType: false,
		dataType: "json",
		success: function(response) {
			var rows = '';
			console.log(response);
			if(response.success) {
				$(`#field-organization`).val(response.data.organization);
				$(`#field-contact`).val(response.data.contact);
				$(`#field-phone`).val(response.data.phone);
				$(`#field-email`).val(response.data.email);
				$(`#chk-active`).prop('checked', response.data.active == 1 ? true : false);

				var rows = '';
				$.each(response.data.processes, function(k, v) {
					rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${v.id}">
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.process}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.type}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.character}</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap">
										<span class="py-0.5 px-2.5 inline-flex items-center gap-x-1 text-xs font-medium bg-${v.status == 1 ? 'primary' : 'danger'}/15 text-${v.status == 1 ? 'primary' : 'danger'} rounded">
											${v.status == 1 ? 'Si' : 'No'}
										</span>
									</td>
									<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.registered_date}</td>
								</tr>`;
				});
				$('#table-electoral-process tbody').append(rows);
			} else {
				ShowToastMessage(response.message, 'error');
			}
		},
		error: function(XMLHttpRequest, textStatus, errorThrown) { 
			try {
				var response = JSON.parse(XMLHttpRequest.responseText);
				ShowToastMessage(response.message, 'error');
				
			} catch (e) {
				ShowToastMessage(XMLHttpRequest.responseText, 'error');
			}
		}  
	});
}

function RegisterElectoralProcess() {
	if(!registering_electoral_process && selected_organization != '') {
		registering_electoral_process = true;
		$.ajax({
				url: `${homeURL}/api/organizations/${selected_organization}/electoral-process`,
				type: 'post',
				data: {
					organization: selected_organization,
					process: $(`#field-electoral-process`).val(),
					type: $(`#select-electoral-process-type`).val(),
					character: $(`#select-electoral-process-character`).val(),
					status: $(`#chk-electoral-process-status`).is(':checked') ? 1 : 0,
				},
				dataType: "json",
				success: function(response) {
					registering_electoral_process = false;
					console.log(response);
					if(response.success) {
						ClearElectoralProcess();
						GetOrganizationData(response.data.organization);
						ShowToastMessage(response.message, 'success');
					} else {
						ShowToastMessage(response.message, 'error');
					}
				},
				error: function(XMLHttpRequest, textStatus, errorThrown) { 
					registering_electoral_process = false;
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