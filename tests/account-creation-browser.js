const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

module.exports = async function ({ directory, call, evaluate, waitFor, navigate }) {
    const fixture = JSON.parse(fs.readFileSync(path.join(directory, 'account-creation.json'), 'utf8'));
    let checks = 0;

    function check(condition, message) {
        assert.ok(condition, message);
        checks++;
    }

    async function api(url, method = 'GET', data = null) {
        return evaluate(`(async () => {
            const response = await fetch('/api/' + ${JSON.stringify(url)}, {
                method: ${JSON.stringify(method)},
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: ${data === null ? 'undefined' : JSON.stringify(JSON.stringify(data))}
            });
            return { ...await response.json(), status: response.status };
        })()`);
    }

    await navigate('/autenticacion/');
    const login = await api('auth/login', 'POST', {
        username: 'cuentas-integrantes',
        password: 'Cuentas integrantes seguras'
    });
    check(login.status === 200, 'Login del creador con permisos limitados');
    await navigate('/');
    check((await api('auth/election', 'POST', { process: fixture.election })).status === 200, 'Elección autorizada');

    for (const mobile of [false, true]) {
        await call('Emulation.setDeviceMetricsOverride', {
            width: mobile ? 390 : 1440,
            height: mobile ? 844 : 1080,
            deviceScaleFactor: 1,
            mobile
        });
        await navigate(`/structure/?structure=${fixture.structure}&member=${fixture.member}`);
        await waitFor('document.getElementById("structure-member-dialog").open');
        await evaluate('document.getElementById("structure-administration").open = true');

        const state = await evaluate(`(() => {
            const select = document.querySelector('#structure-state-form [name=active]');
            const label = select.closest('label');
            const controls = Array.from(document.querySelectorAll('#structure-state-form select'));
            return {
                label: Array.from(label.childNodes).filter(node => node.nodeType === Node.TEXT_NODE)
                    .map(node => node.textContent).join('').trim(),
                labelTop: label.getBoundingClientRect().top,
                selectTop: select.getBoundingClientRect().top,
                controls: controls.map(control => {
                    const rect = control.getBoundingClientRect();
                    return { top: rect.top, height: rect.height };
                })
            };
        })()`);
        check(state.label === 'Estado' && state.labelTop < state.selectTop, 'Label Estado sobre el selector');
        if (!mobile) {
            check(Math.abs(state.controls[0].top - state.controls[1].top) <= 1, 'Selects de la misma fila alineados');
            check(Math.abs(state.controls[0].height - state.controls[1].height) <= 1, 'Selects con alturas compatibles');
        }

        await evaluate('document.getElementById("structure-create-account").click()');
        await waitFor('document.getElementById("structure-account-dialog").open');
        const presentation = await evaluate(`(() => {
            const form = document.getElementById('structure-account-form');
            const fields = [form.elements.username, form.elements.password, document.getElementById('structure-account-role')];
            const footer = form.querySelector('.ux-modal-footer');
            const bounds = node => {
                const rect = node.getBoundingClientRect();
                return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, height: rect.height };
            };
            return {
                text: form.innerText,
                roleLabel: document.getElementById('structure-account-role').previousElementSibling.textContent,
                role: document.getElementById('structure-account-role').textContent,
                selectors: form.querySelectorAll('select').length,
                fields: fields.map(bounds),
                buttons: Array.from(footer.querySelectorAll('button')).map(button => ({ text: button.textContent, ...bounds(button) })),
                dialog: bounds(document.getElementById('structure-account-dialog')),
                viewport: innerWidth
            };
        })()`);
        check(presentation.roleLabel === 'Rol' && presentation.role === 'Rol cuenta preconfigurado', 'Sólo Rol y nombre, sin explicación técnica');
        check(!/asociado al nivel|resuelto por configuración|derivado del rol/i.test(presentation.text), 'Sin textos de implementación');
        check(presentation.selectors === 0, 'Rol sólo lectura, sin selector');
        const [username, password, role] = presentation.fields;
        check(username.bottom < password.top && password.bottom < role.top, 'Usuario, contraseña y rol en orden vertical');
        check(Math.abs(username.left - password.left) <= 1 && Math.abs(password.left - role.left) <= 1, 'Campos y rol alineados a la izquierda');
        check(Math.abs(username.right - password.right) <= 1, 'Inputs con el mismo ancho');
        check(presentation.buttons.map(button => button.text).join('|') === 'Cancelar|Crear y vincular', 'Footer en el orden solicitado');
        const [cancel, create] = presentation.buttons;
        check(Math.abs(cancel.top - create.top) <= 1 && Math.abs(cancel.height - create.height) <= 1, 'Botones alineados con la misma altura');
        check(cancel.right < create.left, 'Separación entre acciones');
        check(presentation.dialog.left >= 0 && presentation.dialog.right <= presentation.viewport + 1, 'Modal dentro de la pantalla');
        const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        fs.writeFileSync(
            path.join(directory, `account-creation-${mobile ? 'mobile' : 'desktop'}.png`),
            Buffer.from(screenshot.data, 'base64')
        );

        if (!mobile) {
            await evaluate('document.getElementById("structure-account-cancel").click()');
            check(await evaluate('!document.getElementById("structure-account-dialog").open'), 'Cancelar cierra el modal');
            continue;
        }

        await evaluate(`(() => {
            const form = document.getElementById('structure-account-form');
            form.elements.username.value = 'cuenta-automatica-interfaz';
            form.elements.password.value = 'Cuenta interfaz segura';
            form.requestSubmit();
        })()`);
        await waitFor('!document.getElementById("structure-account-dialog").open');
        await waitFor('document.querySelector(".swal2-title")?.textContent === "Cuenta vinculada a la persona existente."');
        checks++;
    }

    // El Super Administrador comprueba la asignación; el creador conserva su alcance limitado.
    const adminLogin = await api('auth/login', 'POST', {
        username: 'administrador',
        password: ' nueva contraseña '
    });
    check(adminLogin.status === 200, 'Login administrador para verificar la cuenta');
    await navigate('/');
    check((await api('auth/election', 'POST', { process: fixture.election })).status === 200, 'Contexto de verificación');
    const memberResponse = await api(`structures/${fixture.structure}/members/${fixture.member}`);
    check(memberResponse.status === 200, 'Ficha accesible al administrador');
    const member = memberResponse.data;
    check(member.has_account, 'La cuenta creada desde el modal está vinculada');
    const accesses = (await api(`users/${member.account}/accesses`)).data.accesses;
    check(accesses.find(access => access.process === fixture.election).type === fixture.role, 'La cuenta conserva exactamente el rol configurado');
    await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });
    console.log(`Crear cuenta: ${checks} comprobaciones Chrome de permisos, presentación y alineación.`);
    return checks;
};
