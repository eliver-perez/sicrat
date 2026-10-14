// El token pertenece a la sesión PHP y solo se envía a solicitudes del mismo origen.
$.ajaxPrefilter(function(options, originalOptions, request) {
	if(window.SicratSecurity?.pendingAjax)window.SicratSecurity.pendingAjax.push(request);
	const target = new URL(options.url, window.location.href);
	const method = (options.type || 'GET').toUpperCase();
	if (target.origin === window.location.origin && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
		const token = document.querySelector('meta[name="csrf-token"]')?.content;
		if (token) request.setRequestHeader('X-CSRF-Token', token);
	}
});

// UUID v4 desde el generador criptográfico del navegador, también en contextos sin randomUUID.
window.SicratSecurity = {
    uuid() {
        const source = globalThis.crypto;
        if (typeof source?.randomUUID === 'function') return source.randomUUID();
        if (typeof source?.getRandomValues !== 'function') throw new Error('El navegador no dispone de generación segura de identificadores. Actualiza el navegador para continuar.');
        const bytes = source.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const value = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return [value.slice(0,8),value.slice(8,12),value.slice(12,16),value.slice(16,20),value.slice(20)].join('-');
    }
};
