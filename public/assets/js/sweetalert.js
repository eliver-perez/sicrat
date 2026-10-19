'use strict';

// Única configuración de SweetAlert. Conserva las firmas de los helpers existentes.
const SicratFeedback = (() => {
    const running = new WeakSet();
    const pendingDisabled = new WeakMap();
    const target = () => document.activeElement?.closest?.('dialog[open]')
        || Array.from(document.querySelectorAll('dialog[open]')).at(-1) || document.body;
    const fire = options => Swal.fire({target: target(), buttonsStyling: false,
        ...options, customClass: {confirmButton: 'btn bg-primary text-white', cancelButton: 'btn bg-default-200 text-default-800', actions: 'ux-actions', input:'form-input', ...options.customClass}});
    const toast = (message, type = 'success') => fire({toast: true,
        icon: ['success','warning','error','info'].includes(type) ? type : 'info',
        titleText: String(message), position: 'top-end', showConfirmButton: false,
        timer: type === 'error' ? 6000 : 3500, timerProgressBar: true,
        customClass: {container: 'toast-container-top'},
        didOpen: element => { element.addEventListener('mouseenter', Swal.stopTimer); element.addEventListener('mouseleave', Swal.resumeTimer); }});
    const error = value => toast(value.message || 'No fue posible completar la operación.',
        [403,409,422].includes(value.status) ? 'warning' : 'error');
    const confirm = async (message, options = {}) => (await fire({icon: 'warning',
        titleText: options.title || 'Confirmar operación', text: String(message),
        showCancelButton: true, confirmButtonText: options.confirm || 'Confirmar',
        cancelButtonText: options.cancel || 'Volver', allowOutsideClick: false,
        allowEscapeKey: true, ...options})).isConfirmed === true;
    async function execute(control, work, label = 'Guardando…') {
        if (!control) return work();
        if (running.has(control)) return;
        running.add(control);
        const buttons = control.tagName === 'FORM'
            ? Array.from(control.querySelectorAll('button[type=submit],button:not([type])')).filter(button => !button.disabled)
            : control.tagName === 'BUTTON' ? [control] : [];
        const previous = buttons.map(button => ({button, disabled: button.disabled, text: button.textContent, nodes: button.childNodes ? Array.from(button.childNodes) : null}));
        control.setAttribute('aria-busy', 'true');
        previous.forEach(({button,disabled}) => { pendingDisabled.set(button,disabled);button.disabled = true; button.textContent = control.dataset?.busyText || label; });
        try { return await work(); }
        finally {
            previous.forEach(({button,text,nodes}) => { button.disabled = pendingDisabled.get(button);pendingDisabled.delete(button);if(nodes && button.replaceChildren) button.replaceChildren(...nodes);else button.textContent = text; });
            control.removeAttribute('aria-busy'); running.delete(control);
        }
    }
    const guard = work => async event => {
        event?.preventDefault();
        try { return await execute(event?.currentTarget, () => work(event)); }
        catch (failure) { error(failure); }
    };
    async function reason(message, options = {}) {
        const result = await fire({icon:'warning', titleText:options.title || 'Confirmar operación', text:String(message),
            input:'textarea', inputLabel:'Motivo de la operación', inputAttributes:{maxlength:'500'},
            inputValidator:value => !value?.trim() ? 'Escribe el motivo de la operación.' : value.length>500 ? 'El motivo admite hasta 500 caracteres.' : undefined,
            showCancelButton:true, confirmButtonText:options.confirm || 'Confirmar', cancelButtonText:'Volver',allowOutsideClick:false,...options});
        return result.isConfirmed ? result.value.trim() : null;
    }
    const setDisabled = (control, value) => { if(pendingDisabled.has(control)) pendingDisabled.set(control,Boolean(value)); else control.disabled=Boolean(value); };
    return {fire, toast, error, confirm, reason, execute, guard, setDisabled};
})();
window.SicratFeedback = SicratFeedback;

function ShowSweetAlert(type, title, message, confirmButton) {
    return SicratFeedback.fire({icon: type, titleText: title, text: message, confirmButtonText: confirmButton || 'Aceptar'});
}
function ShowSweetAlertConfirmCallback(type, title, message, confirmButton, callback) {
    return SicratFeedback.fire({icon: type, titleText: title, text: message, confirmButtonText: confirmButton,
        allowOutsideClick: false, allowEscapeKey: false}).then(callback);
}
function ShowSweetAlertConfirmCancelCallback(type, title, message, confirmButton, cancelButton, callback) {
    return SicratFeedback.fire({icon: type, titleText: title, text: message, confirmButtonText: confirmButton,
        cancelButtonText: cancelButton, showCancelButton: true, allowOutsideClick: false}).then(callback);
}
function ShowToastMessage(message, type) { return SicratFeedback.toast(message, type); }
