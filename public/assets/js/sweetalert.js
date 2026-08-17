// JavaScript Document

		function ShowSweetAlert(type, title, message, confirmButton) {
			Swal.fire({
				title: title,
				text: message,
				icon: type,
				padding: '2em',
				confirmButtonText: confirmButton
			});
		}
		
		function ShowSweetAlertConfirmCallback(type, title, message, confirmButton, callback) {
			Swal.fire({
				title: title,
				text: message,
				icon: type,
				padding: '2em',
				confirmButtonText: confirmButton,
				allowOutsideClick: false,
  				allowEscapeKey: false,
			}).then(callback);
		}
		
		function ShowSweetAlertConfirmCancelCallback(type, title, message, confirmButton, cancelButton, callback) {
			Swal.fire({
				title: title,
				text: message,
				icon: type,
				padding: '2em',
				showCloseButton: true,
				showCancelButton: true,
				confirmButtonText: confirmButton,
				cancelButtonText: cancelButton,
				confirmButtonColor: "#2b7fff",
				cancelButtonColor: "#d33",
			}).then(callback);
		}
		
		function ShowToastMessage(message, type) {
			Swal.fire({
				toast: true,
				icon: type,
				title: message,
				animation: true,
				position: 'top-end',
				showConfirmButton: false,
				timer: 3000,
				timerProgressBar: true,

				customClass: {
					container: 'toast-container-top'
				},

				didOpen: (toast) => {
				  toast.addEventListener('mouseenter', Swal.stopTimer)
				  toast.addEventListener('mouseleave', Swal.resumeTimer)
				}
			})
		}