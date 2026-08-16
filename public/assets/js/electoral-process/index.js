// JavaScript Document

var homeURL;
var selected_organization = '';
var new_electoral_process = false, modify_electoral_process = false, registering_electoral_process = false, modifying_electoral_process = false;

function InitializeValues(home) {
	homeURL = home;
	$('#btn-electoral-process-new').on('click', NewElectoralProcess);
	$('#btn-electoral-process-cancel').on('click', CancelElectoralProcess);
	GetElectoralProcess();
	GetProcessTypes();
	GetProcessCharacters();
}

function EnableElectoralProcessFields(v) {
	$(`#field-electoral-process`).attr('readonly', v);
	$(`#select-electoral-process-type`).attr('disabled', v);
	$(`#select-electoral-process-character`).attr('disabled', v);
	$(`#chk-electoral-process-status`).attr('disabled', v);
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

function ClearElectoralProcess() {
	$(`#field-electoral-process`).val('');
	$(`#chk-electoral-process-status`).prop('checked', true);
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

function GetElectoralProcess() {
	$('#table-electoral-process tbody').empty();
	$.ajax({
        url: `${homeURL}/api/electoral-process`,
		type: 'get',
		data: {
			search: ''
		},
		dataType: "json",
		success: function(response) {
			var rows = '';
			console.log(response);
			$.each(response.data.processes, function(k, v) {
				rows += `<tr class="text-default-800 hover:bg-default-100 organization-tr" data-id="${v.id}">
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.organization}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.process}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.type}</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.character}</td>
                                <td class="px-3.5 py-2.5 whitespace-nowrap">
									<span class="py-0.5 px-2.5 inline-flex items-center gap-x-1 text-xs font-medium bg-${v.status == 1 ? 'primary' : 'danger'}/15 text-${v.status == 1 ? 'primary' : 'danger'} rounded">
										${v.status == 1 ? 'Activo' : 'Inactivo'}
									</span>
								</td>
								<td class="px-3.5 py-2.5 whitespace-nowrap text-sm">${v.registered_date}</td>
							</tr>`;
			});
			$('#table-electoral-process tbody').append(rows);
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