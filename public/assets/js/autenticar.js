// JavaScript Document

var homeURL;

function InitializeValues(home) {
	homeURL = home;
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js')
            .catch(error => {
                console.error('Error al registrar Service Worker:', error);
            });
    });
}

var autenticando = false;

function Authenticate() {
	try {
		if(!autenticando) {
			autenticando = true;
			$.ajax({
					url: `${homeURL}/api/auth/login`,
					type: 'post',
					data: {
						username: $('#field-username').val(),
						password: $('#field-password').val(),
						// keep_me_logged_in: $('#chk-remember').is(':checked') ? 1 : 0
					},
					success: function(data) {
						console.log(data);
						autenticando = false;
						var returnedValue = typeof data === 'string' ? JSON.parse(data) : data;
					
						if(returnedValue.status == 'OK') {
							window.location.href = `${homeURL}`;
						} else if(returnedValue.status == 'ERROR_AUTENTICACION') {
							ShowSweetAlert('error', '¡Error en Autenticación!', 'Email o contraseña incorrectas, revise los datos y vuelva a intentarlo', 'Entendido');
						} else if(returnedValue.status == 'FAIL_PENDING_ACTIVATION') {
							ShowSweetAlert('error', '¡Sin Activar!', 'No se ha activado la cuenta, revise su correo y siga las instrucciones de activación.', 'Entendido');
						} else if(returnedValue.status == 'FAIL_NOT_ACTIVE') {
							ShowSweetAlert('error', '¡Cuenta Deshabilitada!', 'La cuenta esta deshabilitada.', 'Entendido');
						} else
							ShowSweetAlert('error', '¡Ocurrio un Error!', 'Ocurrio un error al intentar realizar la autenticacion.', 'Entendido');	
					},
					error: function(XMLHttpRequest, textStatus, errorThrown) {
						autenticando = false;
						try {
							var response = JSON.parse(XMLHttpRequest.responseText);
							ShowToastMessage(response.message, 'error');
							
						} catch (e) {
							ShowToastMessage(XMLHttpRequest.responseText, 'error');
						}
					}  
			});
		}
	} catch(E) {
		alert(E.message);
	}
}