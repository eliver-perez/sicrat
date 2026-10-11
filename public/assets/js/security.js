// El token pertenece a la sesión PHP y solo se envía a solicitudes del mismo origen.
$.ajaxPrefilter(function(options, originalOptions, request) {
	const target = new URL(options.url, window.location.href);
	const method = (options.type || 'GET').toUpperCase();
	if (target.origin === window.location.origin && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
		const token = document.querySelector('meta[name="csrf-token"]')?.content;
		if (token) request.setRequestHeader('X-CSRF-Token', token);
	}
});
