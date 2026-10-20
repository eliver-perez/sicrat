'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

module.exports = async function ({ directory, call, evaluate, waitFor, navigate }) {
    const fixture = JSON.parse(fs.readFileSync(path.join(directory, 'jornada-interface.json'), 'utf8'));
    let checks = 0;
    const check = (condition, message) => {
        assert.ok(condition, message);
        checks++;
    };

    async function api(route, method = 'GET', data = null) {
        return evaluate(`(async () => {
            const response = await fetch('/api/' + ${JSON.stringify(route)}, {
                method: ${JSON.stringify(method)},
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: ${data === null ? 'undefined' : JSON.stringify(JSON.stringify(data))}
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message);
            return result.data;
        })()`);
    }

    async function click(selector) {
        await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    }

    async function fill(selector, values) {
        await evaluate(`(() => {
            const form = document.querySelector(${JSON.stringify(selector)});
            Object.entries(${JSON.stringify(values)}).forEach(([name, value]) => {
                const field = form.elements.namedItem(name);
                if (field.type === 'checkbox') field.checked = Boolean(value);
                else field.value = value;
                field.dispatchEvent(new Event('change', { bubbles: true }));
            });
        })()`);
    }

    async function select(selector, values) {
        await evaluate(`(() => {
            const select = document.querySelector(${JSON.stringify(selector)});
            for (const value of ${JSON.stringify(values)}) {
                if (!Array.from(select.options).some(option => option.value === value)) {
                    throw new Error('Opción no disponible en ' + ${JSON.stringify(selector)} + ': ' + value);
                }
            }
            for (const option of select.options) option.selected = ${JSON.stringify(values)}.includes(option.value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        })()`);
    }

    async function submit(selector, message, confirm = false) {
        check(await evaluate(`document.querySelector(${JSON.stringify(selector)}).checkValidity()`), 'Formulario válido: ' + selector);
        await evaluate('Swal.close()');
        await waitFor('!document.querySelector(".swal2-container")');
        await evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
        if (confirm) {
            await waitFor('document.querySelector(".swal2-confirm")');
            await click('.swal2-confirm');
        }
        await waitFor(`document.querySelector('.swal2-title')?.textContent === ${JSON.stringify(message)}`);
        checks++;
    }

    async function editor(type, name = null) {
        if (name) {
            await waitFor(`Array.from(document.querySelectorAll('[data-catalog="${type}"] tbody tr'))
                .some(row => row.textContent.includes(${JSON.stringify(name)}))`);
            await evaluate(`(() => {
                const row = Array.from(document.querySelectorAll('[data-catalog="${type}"] tbody tr'))
                    .find(row => row.textContent.includes(${JSON.stringify(name)}));
                row.querySelector('button[aria-label="Editar"]').click();
            })()`);
        } else {
            await click(`[data-catalog-add="${type}"]`);
        }
        await waitFor(`document.querySelector('[data-catalog="${type}"] .jornada-catalog-editor form')`);
        return `[data-catalog="${type}"] .jornada-catalog-editor form`;
    }

    await navigate('/autenticacion/');
    await api('auth/login', 'POST', { username: 'administrador', password: ' nueva contraseña ' });
    await navigate('/');
    await api('auth/election', 'POST', { process: fixture.election });
    await call('Emulation.setDeviceMetricsOverride', { width: 1600, height: 1100, deviceScaleFactor: 1, mobile: false });
    await navigate('/jornada/');
    await waitFor('window.SicratJornada?.initialized');
    await click('#jornada-tab-configuration');
    await waitFor('document.getElementById("jornada-config-form")');
    await fill('#jornada-config-form', {
        fecha_eleccion: '2027-06-01',
        zona: 'America/Monterrey',
        captura_local: '2027-06-01T08:00',
        politica_inicial: 'validar',
        denominador: 'total_acta',
        foto_obligatoria: false
    });
    await submit('#jornada-config-form', 'Configuración guardada correctamente.');
    await waitFor('document.getElementById("jornada-notification-form")');
    const sections = await evaluate(`Array.from(document.querySelectorAll('.jornada-settings-card'))
        .map(card => ({ title: card.querySelector('header h6').textContent, help: card.querySelector('header p').textContent }))`);
    check(sections.length === 8 && sections.every(section => section.help.length > 15), 'Ocho secciones con título y descripción');
    check(!await evaluate('document.querySelector(".jornada-catalog-editor")'), 'Los listados no muestran formularios de alta hasta solicitarlos');
    check((await api('jornada/configuration')).configuration.politica_inicial === 'validar', 'Fecha y política persistidas');

    for (const [name, initials] of [['Partido interfaz A', 'PIA'], ['Partido interfaz B', 'PIB']]) {
        const selector = await editor('partidos');
        await fill(selector, { nombre: name, siglas: initials });
        await submit(selector, 'Catálogo guardado correctamente.');
    }
    let selector = await editor('partidos', 'Partido interfaz A');
    check(await evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('h6').textContent.startsWith('Editar partido')`), 'Edición identificada');
    await fill(selector, { nombre: 'Partido interfaz A editado' });
    await submit(selector, 'Catálogo guardado correctamente.');

    selector = await editor('candidaturas');
    await fill(selector, { nombre: 'Candidatura interfaz', cargo: 'Cargo interfaz' });
    await submit(selector, 'Catálogo guardado correctamente.');
    selector = await editor('candidaturas', 'Candidatura interfaz');
    await fill(selector, { cargo: 'Cargo editado' });
    await submit(selector, 'Catálogo guardado correctamente.');
    let config = await api('jornada/configuration');
    check(config.catalogs.partidos[0].nombre.endsWith('editado'), 'Partido editado sin pérdida de datos');
    check(config.catalogs.candidaturas[0].cargo === 'Cargo editado', 'Candidatura editada');

    selector = await editor('coaliciones');
    await fill(selector, {
        nombre: 'Coalición interfaz',
        candidatura: config.catalogs.candidaturas[0].uuid,
        ...Object.fromEntries(config.catalogs.partidos.map(party => ['party_' + party.uuid, true]))
    });
    await submit(selector, 'Catálogo guardado correctamente.');
    selector = await editor('coaliciones', 'Coalición interfaz');
    await fill(selector, { nombre: 'Coalición interfaz editada' });
    await submit(selector, 'Catálogo guardado correctamente.');
    config = await api('jornada/configuration');
    check(config.catalogs.coaliciones[0].partidos.length === 2, 'Coalición conserva selección de partidos');

    selector = await editor('opciones');
    await fill(selector, {
        nombre: 'PIA + PIB', tipo: 'combinacion', orden: 1,
        candidatura: config.catalogs.candidaturas[0].uuid,
        coalicion: config.catalogs.coaliciones[0].uuid,
        suma_candidatura: true
    });
    check(await evaluate(`Array.from(document.querySelectorAll(${JSON.stringify(selector + ' legend')})).map(node => node.textContent).join('|') === 'Datos principales|Referencias|Comportamiento'`), 'Opción del acta agrupada en tres bloques');
    await submit(selector, 'Catálogo guardado correctamente.');
    selector = await editor('opciones', 'PIA + PIB');
    await fill(selector, { nombre: 'Fila exacta editada' });
    await submit(selector, 'Catálogo guardado correctamente.');
    check(await evaluate('document.getElementById("jornada-acta-preview").textContent.includes("1. Fila exacta editada")'), 'Vista previa refleja etiqueta y orden guardados');

    await select('#jornada-physical-create-form .op-picker select', [fixture.section]);
    await fill('#jornada-physical-create-form', { nombre: 'Casilla interfaz nueva', tipo: 'BASICA', ubicacion: 'Ubicación interfaz', domicilio: 'Domicilio interfaz' });
    await submit('#jornada-physical-create-form', 'Casilla asociada correctamente.');
    await fill('#jornada-physical-associate-form', {});
    await select('#jornada-physical-associate-form .op-picker select', [fixture.physical]);
    await submit('#jornada-physical-associate-form', 'Casilla existente asociada.');
    const booths = (await api('jornada/booths?administrar=1')).rows;
    check(booths.length === 2, 'Registro y asociación de casillas conservados');
    await evaluate(`(() => {
        const input = document.querySelector('#jornada-physical-associate-form input[type=search]');
        input.value = 'Casilla interfaz nueva';
        input.closest('.ux-search-row').querySelector('button').click();
    })()`);
    await waitFor(`document.querySelector('#jornada-physical-associate-form .op-picker select').options.length === 2`);
    check(await evaluate('document.querySelector("#jornada-physical-associate-form .op-picker select").textContent.includes("Casilla interfaz nueva")'), 'Buscador de casillas funciona');

    async function chooseAssignments(accounts) {
        await select('#jornada-assign-form .op-picker:nth-child(1) select[multiple]', accounts);
        await select('#jornada-assign-form .op-picker:nth-child(2) select[multiple]', booths.map(booth => booth.uuid));
    }
    await chooseAssignments(fixture.accounts);
    check(await evaluate('document.querySelector(".jornada-selection-summary").textContent.includes("2 capturistas y 2 casillas")'), 'Resumen de múltiples seleccionados');
    await submit('#jornada-assign-form', 'Asignaciones actualizadas correctamente.');
    check((await api('jornada/booths/' + booths[0].uuid)).asignaciones.filter(row => Number(row.activo)).length === 2, 'Asignación múltiple persistida');
    await chooseAssignments([fixture.accounts[0]]);
    await fill('#jornada-assign-form', { activo: '0' });
    await submit('#jornada-assign-form', 'Asignaciones actualizadas correctamente.', true);
    check((await api('jornada/booths/' + booths[0].uuid)).asignaciones.filter(row => Number(row.activo)).length === 1, 'Retirada confirmada conserva la otra asignación');

    await click('#jornada-settings-notifications summary');
    await select('#jornada-notification-form .op-picker select', [fixture.recipient]);
    await fill('#jornada-notification-form', { frecuencia: '45' });
    await submit('#jornada-notification-form', 'Aviso administrativo configurado.');
    const notices = (await api('jornada/notifications')).rows;
    check(notices.length === 1 && Number(notices[0].frecuencia) === 45, 'Resumen administrativo configurado con validaciones existentes');

    for (const [width, height] of [[1600, 1100], [820, 1024], [390, 844]]) {
        await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 500 });
        const layout = await evaluate(`(() => {
            const section = document.getElementById('jornada-configuration');
            const forms = Array.from(section.querySelectorAll('form'));
            return {
                overflow: document.documentElement.scrollWidth > innerWidth + 2,
                buttons: forms.flatMap(form => Array.from(form.querySelectorAll('button[type=submit]')).map(button => ({ width: button.getBoundingClientRect().width, form: form.getBoundingClientRect().width }))),
                columns: getComputedStyle(document.getElementById('jornada-config-form')).gridTemplateColumns.split(' ').length,
                policyControls: Array.from(document.querySelectorAll('#jornada-config-form > label > .form-input'))
                    .map(control => control.getBoundingClientRect().top),
                searchRows: Array.from(section.querySelectorAll('.ux-search-row'))
                    .filter(row => row.getBoundingClientRect().height > 0)
                    .map(row => {
                        const input = row.querySelector('input').getBoundingClientRect();
                        const button = row.querySelector('button').getBoundingClientRect();
                        return Math.abs(input.bottom - button.bottom);
                    }),
                icons: Array.from(section.querySelectorAll('button[type=submit]')).every(button => button.querySelector('svg')),
                labels: Array.from(section.querySelectorAll('.jornada-form input:not([type=checkbox]):not([type=hidden]), .jornada-form select:not([multiple])'))
                    .filter(control => control.getBoundingClientRect().height > 0 && !control.closest('.op-picker'))
                    .every(control => control.closest('label')),
                actions: Array.from(section.querySelectorAll('.jornada-table-actions')).every(group => Array.from(group.querySelectorAll('button')).every(button => button.querySelector('svg') && button.title))
            };
        })()`);
        check(!layout.overflow, 'Sin desbordamiento horizontal: ' + width);
        check(layout.buttons.every(button => button.width <= 250 && button.width < button.form * .8), 'Botones compactos: ' + width);
        check(layout.columns === (width < 640 ? 1 : 2), 'Grid responsive: ' + width);
        if (width >= 640) {
            check(Math.abs(layout.policyControls[0] - layout.policyControls[1]) <= 1, 'Primera fila alineada: ' + width);
            check(Math.abs(layout.policyControls[2] - layout.policyControls[3]) <= 1, 'Segunda fila alineada: ' + width);
        }
        check(layout.searchRows.every(difference => difference <= 1), 'Buscadores y botones alineados: ' + width);
        check(layout.icons && layout.labels && layout.actions, 'Labels y acciones Lucide consistentes: ' + width);
        await evaluate('document.getElementById("jornada-settings-partidos").scrollIntoView({ block: "start" })');
        const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        fs.writeFileSync(path.join(directory, 'jornada-interface-' + width + '.png'), Buffer.from(screenshot.data, 'base64'));
    }
    check((await api('jornada/configuration')).configuration.estado === 'preparacion', 'La reorganización no abre ni altera el estado de Jornada');
    console.log(`Interfaz Jornada: ${checks} comprobaciones Chrome de formularios, asignaciones y responsive.`);
    return checks;
};
