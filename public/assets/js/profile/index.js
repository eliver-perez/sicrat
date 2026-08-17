// JavaScript Document

var homeURL;
var modifying_password = false;

function InitializeValues(home) {
	homeURL = home;
	GetUserInfo();
}

function ClearChangePassword() {
	$('#field-old-password').val('');
	$('#field-new-password').val('');
	$('#field-confirm-password').val('');
}

function GetUserInfo() {
	$.ajax({
		url: `${homeURL}/api/profiles/${user_id}`,
		type: 'get',
		dataType: "json",
		success: function(response) {
			console.log(response);
			if(response.success) {
				$('#field-profile-name').html(response.data.name);
				$('#field-profile-type').html(response.data.type);
				$('#field-profile-email').html(response.data.email);
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

function ChangePassword() {
	if($('#field-new-password').val() != $('#field-confirm-password').val()) {
		ShowToastMessage('Las contraseñas no coinciden', 'error');
		return;
	}

	ShowSweetAlertConfirmCancelCallback('warning',
										'Modificar Contraseña',
										`¿Deseas proceder con la modificación de la contraseña?`,
										'Si',
										'No',
										(result) => {
		if(result.isConfirmed) {
			modifying_password = true;
			$.ajax({
					url: `${homeURL}/api/profiles/${user_id}/change-password`,
					type: 'put',
					data: {
						old_password: $('#field-old-password').val(),
						new_password: $(`#field-new-password`).val(),
					},
					dataType: "json",
					success: function(response) {
						modifying_password = false;
						if(response.success) {
							ClearChangePassword();
							ShowToastMessage(response.message, 'success');
						} else {
							ShowToastMessage(response.message, 'error');
						}
					},
					error: function(XMLHttpRequest, textStatus, errorThrown) { 
						modifying_password = false;
						try {
							var response = JSON.parse(XMLHttpRequest.responseText);
							ShowToastMessage(response.message, 'error');
							
						} catch (e) {
							ShowToastMessage(XMLHttpRequest.responseText, 'error');
						}
					}  
			});
		}
	})
}