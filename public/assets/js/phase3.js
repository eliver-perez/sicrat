window.SicratPhase3 = {
    base: '',
    message(text, type = 'success') {
        if (window.SicratFeedback) { window.SicratFeedback.toast(text,type); return; }
        this.state(text);
    },
    state(text) {
        const element = document.getElementById('phase3-message'); if (element) element.textContent = text;
        const dialog = document.querySelector('dialog[open]');
        if (dialog) {
            let status = dialog.querySelector('[data-phase3-message]');
            if (!status) { status = this.text('p','','text-primary mb-3'); status.dataset.phase3Message = '1'; status.setAttribute('role','status'); dialog.prepend(status); }
            status.textContent = text;
        }
    },
    async request(path, method = 'GET', data = null) {
        const target = new URL(`${this.base}/api/${path}`, window.location.href);
        if (target.origin !== window.location.origin) throw new Error('La petición debe pertenecer al mismo sitio.');
        const headers = {Accept: 'application/json'};
        if (method !== 'GET') { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = document.querySelector('meta[name="csrf-token"]').content; }
        const response = await fetch(target.href, {method, headers, credentials: 'same-origin', body: data === null ? undefined : JSON.stringify(data)});
        const result = await response.json();
        if (!response.ok) { const error = new Error(result.message || 'No se pudo completar la operación.'); error.status = response.status; throw error; }
        return result;
    },
    async pages(path, key = null) {
        const rows = []; let offset = 0;
        for (;;) {
            const response = await this.request(`${path}${path.includes('?') ? '&' : '?'}limit=100&offset=${offset}`);
            rows.push(...(key ? response.data[key] : response.data));
            if (!response.pagination?.has_more) return rows;
            offset += 100;
        }
    },
    option(select, value, text, selected = false) { const option = document.createElement('option'); option.value = value; option.textContent = text; option.selected = selected; select.append(option); },
    text(tag, value, className = '') { const element = document.createElement(tag); element.textContent = value; element.className = className; return element; },
    checkbox(container, value, label, checked = false) {
        const wrapper = document.createElement('label'); wrapper.className = 'block mb-2'; const field = document.createElement('input'); field.type = 'checkbox'; field.value = value; field.checked = checked;
        wrapper.append(field,document.createTextNode(` ${label}`)); container.append(wrapper);
    },
    selected(container) { return Array.from(container.querySelectorAll('input:checked')).map(input => input.value); },
    async run(work, control = null) {
        try { return window.SicratFeedback ? await window.SicratFeedback.execute(control,work) : await work(); }
        catch (error) { if (window.SicratFeedback) window.SicratFeedback.error(error); else this.message(error.message,'error'); }
    },
    closeButtons() {
        document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
        document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('close',() => dialog.querySelector('[data-phase3-message]')?.remove()));
    },
    allowed(me, permission) { return me.is_superadmin || me.rights.includes(permission); }
};
