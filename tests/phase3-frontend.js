const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
let checks = 0;
function verify(condition, description) { assert.ok(condition, description); checks++; }
class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; this.value = ''; }
    append(...children) { this.children.push(...children); }
    querySelectorAll() { return this.children.flatMap(child => child.children || []).filter(input => input.checked); }
}
const requests = [];
const responses = [];
const message = new Element('p');
const context = {URL, window: {location: {href: 'http://localhost/', origin: 'http://localhost'}}, document: {getElementById: () => message, createElement: tag => new Element(tag), createTextNode: value => ({textContent: value}), querySelector: selector => selector.startsWith('meta') ? {content: 'csrf-prueba'} : null}, fetch: async (url, options) => { requests.push({url, options}); return responses.shift(); }};
vm.createContext(context);
const assets = path.join(__dirname, '../public/assets/js');
vm.runInContext(fs.readFileSync(path.join(assets, 'phase3.js'), 'utf8'), context);
for (const page of ['structure', 'administration']) {
    const early = vm.createContext({window: {}});
    vm.runInContext(fs.readFileSync(path.join(assets, page, 'index.js'), 'utf8'), early);
    verify(typeof early.InitializeValues === 'function', `${page}: espera el footer sin usar DOM o jQuery antes de tiempo.`);
}
const ui = context.window.SicratPhase3;
const payload = '<img src=x onerror=alert(1)>"&';
const select = new Element('select'); ui.option(select, 'uuid', payload, true);
verify(select.children[0].textContent === payload && select.children[0].selected, 'Opciones muestran contenido como texto seguro.');
verify(ui.text('p', payload).textContent === payload, 'Nombres, mensajes e historial no se interpretan como HTML.');
const choices = new Element('div'); ui.checkbox(choices, 'eleccion', payload, true);
verify(ui.selected(choices)[0] === 'eleccion', 'Elecciones compartidas conservan una selección por identidad.');
verify(!ui.allowed({is_superadmin: false, rights: ['estructura.ver']}, 'estructura.formar'), 'Consultar no muestra acciones de incorporación.');
verify(ui.allowed({is_superadmin: true, rights: []}, 'usuarios.administrar'), 'Cuenta global habilita controles autorizados.');
async function main() {
    ui.base = '/sicrat'; responses.push({ok: true, json: async () => ({data: []})});
    await ui.request('structures', 'POST', {name: payload});
    verify(requests[0].options.headers['X-CSRF-Token'] === 'csrf-prueba', 'Las escrituras envían CSRF.');
    verify(requests[0].options.credentials === 'same-origin', 'Las peticiones conservan sesiones sólo del mismo origen.');
    verify(JSON.parse(requests[0].options.body).name === payload, 'El cuerpo JSON preserva el nombre literal.');
    responses.push({ok: true, json: async () => ({data: []})}); await ui.request('structures');
    verify(!('X-CSRF-Token' in requests[1].options.headers), 'Las lecturas conservan método GET.');
    responses.push({ok: false, json: async () => ({message: 'Fuera de alcance'})});
    await assert.rejects(() => ui.request('persons/uuid'), /Fuera de alcance/); checks++;
    responses.push({ok: true, json: async () => ({data: {items: [1]}, pagination: {has_more: true}})}, {ok: true, json: async () => ({data: {items: [2]}, pagination: {has_more: false}})});
    const rows = await ui.pages('roles?search=a', 'items');
    verify(rows.join(',') === '1,2', 'Los selectores recorren páginas sin truncar elementos.');
    verify(requests.at(-1).url.endsWith('search=a&limit=100&offset=100'), 'El siguiente lote respeta parámetros existentes y offset.');
    await ui.run(async () => { throw new Error(payload); }); verify(message.textContent === payload, 'Los errores de API se presentan como texto seguro.');
    ui.base = 'https://fuera.example'; await assert.rejects(() => ui.request('structures', 'POST', {}), /mismo sitio/); checks++;
    console.log(`OK: ${checks} comprobaciones JavaScript de Fase 3.`);
}
main().catch(error => { console.error(error); process.exitCode = 1; });
