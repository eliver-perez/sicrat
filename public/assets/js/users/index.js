// JavaScript Document

var homeURL;
var selected_user = '';
var new_user = false, modify_user = false, registering_user = false, modifying_user = false;
var defaultSelectedState = 28;
var defaultSelectedMunicipality = 1960;

function InitializeValues(home) {
	homeURL = home;
	$('#btn-users-new').on('click', NewUser);
	$('#btn-users-cancel').on('click', CancelUser);
	$('#select-users-organization').on('change', GetProcesses);
	GetUsersTypes();
	GetUsers();
	GetOrganizations();
}

function EnableUsersFields(v) {
	$(`#field-users-username`).attr('readonly', v);
	$(`#field-users-password`).attr('readonly', v);
	$(`#select-users-type`).attr('disabled', v);
	$(`#select-users-organization`).attr('disabled', v);
	$(`#select-users-process`).attr('disabled', v);
	$(`#field-users-email`).attr('readonly', v);
	$(`#field-users-name`).attr('readonly', v);
	$(`#field-users-last-name`).attr('readonly', v);
	$(`#field-users-last-name-2`).attr('readonly', v);
	$(`#chk-users-status`).attr('disabled', v);
}

function NewUser() {
	if(!new_user && !modify_user) {
		new_user = true;
		if(!$('#btn-users-new').hasClass('hidden'))
			$('#btn-users-new').addClass('hidden');
		if($('#btn-users-register').hasClass('hidden'))
			$('#btn-users-register').removeClass('hidden');
		if($('#btn-users-cancel').hasClass('hidden'))
			$('#btn-users-cancel').removeClass('hidden');
		ClearUser();
		EnableUsersFields(false);
	}
}

function CancelUser() {
	if(new_user || modify_user) {
		new_user = false;
		modify_user = false;
		if($('#btn-users-new').hasClass('hidden'))
			$('#btn-users-new').removeClass('hidden');
		if(!$('#btn-users-register').hasClass('hidden'))
			$('#btn-users-register').addClass('hidden');
		if(!$('#btn-users-cancel').hasClass('hidden'))
			$('#btn-users-cancel').addClass('hidden');
		EnableUsersFields(true);
	}
}

function ClearUser() {
	$(`#field-users-username`).val('');
	$(`#field-users-password`).val('');
	$(`#field-users-email`).val('');
	$(`#field-users-name`).val('');
	$(`#field-users-last-name`).val('');
	$(`#field-users-last-name-2`).val('');
	$(`#chk-users-active`).prop('checked', true);
}

function GetOrganizations() {
	$.ajax({
        url: `${homeURL}/api/organizations`,
		type: 'get',
		dataType: "json",
		success: function(response) {
			var selected = null;
			$('#select-users-organization').append($('<option>', {
				value: 'N/A',
				text: 'Sin Organización'
			}));
			$.each(response.data.organizations, function(k, v) {
				if(selected == null)
					selected = v.id;
				$('#select-users-organization').append($('<option>', {
					value: v.id,
					text: v.organization
				}));
			});
			$('#select-users-organization').val(selected);
			$('#select-users-organization').trigger('change');
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

function GetProcesses() {
	$('#select-users-process').empty();
	if($('#select-users-organization').val() != null && $('#select-users-organization').val() != 'N/A') {
		$.ajax({
			url: `${homeURL}/api/organizations/${$('#select-users-organization').val()}/electoral-process`,
			type: 'get',
			dataType: "json",
			success: function(response) {
				console.log(response);
				$.each(response.data.electoral_processes, function(k, v) {
					$('#select-users-process').append($('<option>', {
						value: v.id,
						text: v.process
					}));
				});
				$('#select-users-process').trigger('change');
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

function GetUsersTypes() {
	$.ajax({
        url: `${homeURL}/api/users-types`,
		type: 'get',
		dataType: "json",
		success: function(response) {
			$.each(response.data.users_types, function(k, v) {
				$('#select-users-type').append($('<option>', {
					value: v.id,
					text: v.type,
					'data-tipo': v.code
				}));
			});
			$('#select-users-type').trigger('change');
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

function GetUsers() {
	$('#table-users tbody').empty();
	$.ajax({
        url: `${homeURL}/api/users`,
		type: 'get',
		data: {
			search: ''
		},
		dataType: "json",
		success: function(response) {
			var rows = '';
			console.log(response);
			$.each(response.data.users, function(k, v) {
				rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${v.id}">
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.name}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.username}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.email}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.type}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap">
									<span class="py-0.5 px-2.5 inline-flex items-center gap-x-1 text-xs font-medium bg-${v.active == 1 ? 'primary' : 'danger'}/15 text-${v.active == 1 ? 'primary' : 'danger'} rounded">
										${v.active == 1 ? 'Activo' : 'Inactivo'}
									</span>
								</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.last_active_date}</td>
							</tr>`;
			});
			$('#table-users tbody').append(rows);
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

function RegisterUser() {
	if(!registering_user) {
		registering_user = true;
		$.ajax({
				url: `${homeURL}/api/users`,
				type: 'post',
				data: {
					username: $('#field-users-username').val(),
					password: $('#field-users-password').val(),
					type: $(`#select-users-type`).val(),
					organization: $(`#select-users-organization`).val(),
					process: $(`#select-users-process`).val(),
					email: $(`#field-users-email`).val(),
					status: $(`#chk-users-status`).is(':checked') ? 1 : 0,
					name: $(`#field-users-name`).val(),
					last_name: $(`#field-users-last-name`).val(),
					last_name_2: $(`#field-users-last-name-2`).val(),
				},
				dataType: "json",
				success: function(response) {
					registering_user = false;
					console.log(response);
					if(response.success) {
						CancelUser();
						GetUsers();
						ShowToastMessage(response.message, 'success');
					} else {
						ShowToastMessage(response.message, 'error');
					}
				},
				error: function(XMLHttpRequest, textStatus, errorThrown) { 
					registering_user = false;
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