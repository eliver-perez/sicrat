window.addEventListener('load', function() {
	// Fetch all the forms we want to apply custom Bootstrap validation styles to
	 var forms = document.forms;
	// Loop over them and prevent submission
	var validation = Array.prototype.filter.call(forms, function(form) {
		form.addEventListener('submit', function(event) {
			if (form.checkValidity() === false) {
				event.preventDefault();
				event.stopPropagation();
			}
			form.classList.add('was-validated');
			bsSelectValidation(form.id);
		}, false);
	});
}, false);
		
function bsSelectValidation(form) {
	if ($("#" + form).hasClass('was-validated')) {
		$("select").each(function (i, el) {
			if ($(el).is(":invalid")) {
				$(el).closest(".form-group").find(".valid-feedback").removeClass("d-block");
				$(el).closest(".form-group").find(".invalid-feedback").addClass("d-block");
			}
			else {
				$(el).closest(".form-group").find(".invalid-feedback").removeClass("d-block");
				$(el).closest(".form-group").find(".valid-feedback").addClass("d-block");
			}
		});
	}
}