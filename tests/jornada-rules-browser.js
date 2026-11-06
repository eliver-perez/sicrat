'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

module.exports = async function ({ directory, call, evaluate, waitFor, navigate }) {
    const fixture = JSON.parse(fs.readFileSync(path.join(directory, 'jornada-rules.json'), 'utf8'));
    let checks = 0;
    const check = (condition, message) => { assert.ok(condition, message); checks++; };
    async function api(route, method = 'GET', data = null) {
        return evaluate(`(async () => {
            const response = await fetch('/api/' + ${JSON.stringify(route)}, {
                method: ${JSON.stringify(method)},
                headers: {'Content-Type':'application/json', 'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},
                body: ${data === null ? 'undefined' : JSON.stringify(JSON.stringify(data))}
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message);
            return result.data;
        })()`);
    }
    const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    async function open(booth) {
        await evaluate(`Swal.close(); SicratJornada.open(${JSON.stringify(booth)})`);
        await waitFor('document.getElementById("jornada-booth-dialog").open && document.querySelector("#jornada-booth-content table button")');
    }
    async function decidePending() {
        await evaluate(`Array.from(document.querySelectorAll('#jornada-booth-content tbody tr'))
            .find(row => row.textContent.includes('Pendiente')).querySelector('button').click()`);
        await waitFor('document.getElementById("jornada-version-dialog").open && document.getElementById("jornada-decision-form")');
    }
    async function finishSubmit(form, toast) {
        await evaluate('Swal.close()');
        await waitFor('!Swal.isVisible()');
        await evaluate(`document.querySelector(${JSON.stringify(form)}).requestSubmit()`);
        await waitFor('Swal.isVisible() && Swal.getConfirmButton()?.offsetHeight');
        await evaluate('Swal.getConfirmButton().click()');
        await waitFor(`Swal.getTitle()?.textContent.includes(${JSON.stringify(toast)})`);
        checks++;
    }
    await navigate('/autenticacion/');
    await api('auth/login', 'POST', {username:'administrador', password:' nueva contraseña '});
    await navigate('/');
    await api('auth/election', 'POST', {process:fixture.election});
    await navigate('/jornada/');
    await waitFor('window.SicratJornada?.initialized');

    await open(fixture.booths[0]);
    check(await evaluate('!document.querySelector("[data-capture-form]")'), 'Con historial no ofrece segunda captura inicial');
    await decidePending();
    check(await evaluate(`(() => {
        const field = document.getElementById('jornada-decision-form').elements.motivo;
        return !field.required && field.closest('label').textContent.trim() === 'Observaciones';
    })()`), 'Aprobación muestra Observaciones opcionales');
    await finishSubmit('#jornada-decision-form', 'Decisión registrada');
    await waitFor('!document.getElementById("jornada-version-dialog").open');
    const current = await api('jornada/booths/' + fixture.booths[0]);
    check(current.vigente.estado === 'validada', 'Aprobación sin observaciones persistida');
    await evaluate('document.getElementById("jornada-booth-dialog").close()');

    // El formulario inicial es el único disponible en una casilla que no tiene historial.
    await evaluate(`SicratJornada.open(${JSON.stringify(fixture.booths[9])})`);
    await waitFor('document.querySelector("[data-capture-form]")');
    check(await evaluate(`!Array.from(document.querySelectorAll('#jornada-booth-content button'))
        .some(button => button.textContent === 'Crear corrección con historial')`), 'Sin historial no aparece Crear corrección');
    await finishSubmit('[data-capture-form]', 'Versión 1 registrada');
    await waitFor('!document.querySelector("[data-capture-form]")');
    check((await api('jornada/booths/' + fixture.booths[9] + '/history')).rows[0].origen === 'captura', 'Primera versión originada como captura');
    await evaluate(`Array.from(document.querySelectorAll('#jornada-booth-content button'))
        .find(button => button.textContent === 'Crear corrección con historial').click()`);
    await waitFor('document.querySelector("[data-capture-form] [name=motivo]")');
    check(await evaluate('document.querySelector("[data-capture-form] [name=motivo]").required'), 'Corrección exige motivo');
    check(await evaluate('!document.querySelector("[data-capture-form]").checkValidity()'), 'Corrección vacía no se puede enviar');
    await evaluate('document.querySelector("[data-capture-form] [name=motivo]").value="Ajuste propuesto desde Chrome"');
    await finishSubmit('[data-capture-form]', 'Versión 2 registrada');
    await decidePending();
    await evaluate(`(() => {
        const decision = document.getElementById('jornada-decision-form').elements.aprobar;
        decision.value = '0';
        decision.dispatchEvent(new Event('change'));
    })()`);
    check(await evaluate(`(() => {
        const field = document.getElementById('jornada-decision-form').elements.motivo;
        return field.required && field.closest('label').textContent.trim() === 'Motivo del rechazo';
    })()`), 'Rechazo muestra Motivo del rechazo obligatorio');
    check(await evaluate('!document.getElementById("jornada-decision-form").checkValidity()'), 'Rechazo sin motivo bloqueado en frontend');
    await evaluate('document.getElementById("jornada-decision-form").elements.motivo.value="Se conserva la captura inicial"');
    await finishSubmit('#jornada-decision-form', 'Decisión registrada');
    const history = (await api('jornada/booths/' + fixture.booths[9] + '/history')).rows;
    check(history.some(row => row.origen === 'correccion' && row.estado === 'rechazada'),
        'Rechazo y corrección quedan en historial: ' + JSON.stringify(history.map(row => [row.numero, row.origen, row.estado])));
    await evaluate('document.getElementById("jornada-booth-dialog").close()');

    await click('#jornada-tab-configuration');
    await waitFor('document.getElementById("jornada-physical-create-form")');
    check(await evaluate('!document.getElementById("jornada-config-form")'), 'El acta continúa protegida pese a permitir nuevas casillas');
    check(await evaluate('document.getElementById("jornada-settings-physical-booths").textContent.includes("Puedes incorporar nuevas casillas")'), 'Texto de protección diferencia historial de nuevas incorporaciones');
    await click('#jornada-physical-create > summary');
    const before = await api('jornada/summary?modo=validado');
    await evaluate(`(() => {
        const form = document.getElementById('jornada-physical-create-form');
        form.elements.nombre.value = 'Casilla agregada desde Chrome';
        form.elements.tipo.value = 'BASICA';
        const select = form.querySelector('.op-picker select');
        select.value = Array.from(select.options).find(option => option.value).value;
        select.dispatchEvent(new Event('change'));
        Swal.close();
    })()`);
    await waitFor('!Swal.isVisible()');
    await evaluate('document.getElementById("jornada-physical-create-form").requestSubmit()');
    await waitFor('Swal.isVisible() && Swal.getConfirmButton()?.offsetHeight');
    check(await evaluate('Swal.getTitle().textContent === "Agregar casilla a la Jornada"'), 'Confirmación clara durante Jornada iniciada');
    await evaluate('Swal.getCancelButton().click()');
    await waitFor('!Swal.isVisible()');
    check((await api('jornada/summary?modo=validado')).progreso.esperadas === before.progreso.esperadas, 'Cancelar no incorpora la casilla');
    await finishSubmit('#jornada-physical-create-form', 'Casilla asociada correctamente');
    const after = await api('jornada/summary?modo=validado');
    check(after.progreso.esperadas === before.progreso.esperadas + 1, 'La casilla se incorpora inmediatamente al seguimiento');
    check(after.total_acta === before.total_acta, 'Agregar una casilla no cambia los votos existentes');
    check(await evaluate(`SicratJornada.summary.progreso.esperadas === ${after.progreso.esperadas}`), 'Dashboard actualizado en la misma pantalla');
    check(await evaluate(`document.querySelector('#jornada-assign-form select[aria-label="Casillas por sección"]').textContent.includes('Casilla agregada desde Chrome')`), 'Disponible inmediatamente para asignar capturistas');

    for (const width of [1440, 390]) {
        await call('Emulation.setDeviceMetricsOverride', {width, height:900, deviceScaleFactor:1, mobile:width<500});
        check(await evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'Sin desbordamiento: ' + width);
    }
    console.log(`Reglas Jornada: ${checks} comprobaciones Chrome de captura, decisiones y nuevas casillas.`);
    return checks;
};
