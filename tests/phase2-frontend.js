const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
let checks = 0;
function verify(value, message) { assert.ok(value, message); checks++; }
const payload = '<img src=x onerror=alert(1)>"\'&';
const elements = new Map();
const ready = [];
const requests = [];
class Element {
    constructor(tag = 'div', attributes = {}) { this.tag = tag; this.attributes = attributes; this.children = []; this.events = {}; this.value = attributes.value ?? ''; this.textValue = ''; this.properties = {}; }
    prepend(...values) { this.children.unshift(...values); return this; }
    append(...values) { this.children.push(...values); return this; }
    empty() { this.children = []; return this; }
    text(value) { this.textValue = value; return this; }
    val(value) { if (value === undefined) return this.value; this.value = value; return this; }
    attr(name, value) { if (typeof name === 'object') Object.assign(this.attributes, name); else if (value === undefined) return this.attributes[name]; else this.attributes[name] = value; return this; }
    prop(name, value) { this.properties[name] = value; return this; }
    on(name, callback) { this.events[name] = callback; return this; }
    off(name) { delete this.events[name]; return this; }
    removeClass() { return this; }
    addClass() { return this; }
    each(callback) { descendants(this).forEach((element, index) => callback.call(element, index, element)); return this; }
    ready(callback) { ready.push(callback); }
}
function descendants(element) { return element.children.flatMap(child => child instanceof Element ? [child, ...descendants(child)] : []); }
function jquery(selector, attributes) {
    if (selector instanceof Element) return selector;
    if (typeof selector === 'string' && selector.startsWith('<')) return new Element(selector.slice(1, selector.indexOf('>')), attributes);
    if (typeof selector === 'string' && selector.includes('.import-target[data-row=')) {
        const number = Number(selector.match(/data-row="(\d+)"/)[1]);
        return descendants(elements.get('#person-import-preview')).find(element => element.attributes.class?.includes('import-target') && element.attributes['data-row'] === number);
    }
    if (selector === '#person-import-preview .import-action') {
        return {each(callback) { descendants(elements.get('#person-import-preview')).filter(element => element.attributes.class?.includes('import-action')).forEach(element => callback.call(element)); }};
    }
    if (!elements.has(selector)) elements.set(selector, new Element());
    return elements.get(selector);
}
let response;
jquery.getJSON = (url, callback) => { requests.push({url: url, type: 'get'}); callback({data: {users_types: []}}); return {fail() {}}; };
jquery.ajax = options => {
    requests.push(options);
    return {done(callback) { callback(response(options)); return this; }, fail() { return this; }, always(callback) { callback(); return this; }};
};
const dialogs = new Map();
const scope = {console, $: jquery, homeURL: 'http://localhost', document: {getElementById(id) { if (!dialogs.has(id)) dialogs.set(id, {open: false, showModal() { this.open = true; }}); return dialogs.get(id); }}, window: {location: {}}, ShowToastMessage() {}, GetPersons() {}};
scope.loadOptions = (options) => { requests.push(options); options.success(response(options)); };
const beforeJquery = vm.createContext({});
for (const file of ['persons/identity.js', 'persons/import.js', 'users/accesses.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/assets/js', file), 'utf8'), beforeJquery);
verify(true, 'Scripts incluidos antes del footer esperan a la inicialización y no utilizan jQuery prematuramente.');
vm.createContext(scope);
for (const file of ['persons/identity.js', 'persons/import.js', 'users/accesses.js', 'electoral-context.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/assets/js', file), 'utf8'), scope);

scope.showPersonDuplicates({blocked: true, matches: [{id: 'uuid', code: payload, name: payload}]}, () => assert.fail('No permite continuar'));
let box = elements.get('#person-duplicates');
verify(descendants(box).some(element => element.textValue.includes(payload)), 'Advertencia conserva texto de coincidencia.');
verify(!box.children.some(child => typeof child === 'string'), 'Advertencia utiliza nodos y texto seguro.');
verify(!descendants(box).some(element => element.textValue === 'Crear persona nueva'), 'Regla estricta no ofrece crear duplicado.');
scope.showPersonDuplicates({blocked: false, matches: [{id: 'uuid', code: 'CODE', name: payload}]}, () => {});
verify(descendants(box).some(element => element.textValue === 'Crear persona nueva'), 'Advertencia no estricta permite continuar explícitamente.');
response = options => options.url.includes('/auth/elections') ? {data: {electoral_processes: [{id: 'election', organization: payload, process: payload, selected: false}]}} : {data: {code: 'CODE', name: payload, organization: 'org', participations: [{id: 'election', process: payload, active: 1}]}};
scope.openPerson('uuid');
verify(elements.get('#person-detail-title').textValue === `CODE · ${payload}`, 'Ficha presenta nombre mediante texto.');
verify(dialogs.get('person-detail').open, 'Ficha abre el diálogo actual.');
scope.InitializePersonIdentity();
scope.InitializePersonImport();
verify(elements.get('#person-edit-form').events.submit && elements.get('#person-import-upload').events.submit, 'Formularios se inicializan después de cargar jQuery.');
verify(descendants(elements.get('#person-participations')).some(element => element.textValue.includes(payload)), 'Participación escapa contenido al usar texto.');

response = () => ({data: {rows: [{row: 1, data: {name: payload}, state: 'decision', review: {blocked: true, matches: [{id: 'uuid', code: 'CODE', name: payload}], file_matches: []}}], next_after: 1, review_complete: true, summary: {decision: 1}}});
scope.importPreview();
box = elements.get('#person-import-preview');
const action = descendants(box).find(element => element.attributes.class?.includes('import-action'));
verify(!action.children.some(option => option.attributes.value === 'new'), 'Importación no permite nueva persona con identificador estricto repetido.');
verify(descendants(box).some(element => element.textValue.includes(payload)), 'Vista previa presenta contenido como texto.');
verify(requests.at(-1).type === 'post', 'La revisión que guarda resultados utiliza POST protegido con CSRF.');
assert.throws(() => scope.collectImportDecisions(), /Decide/); checks++;
action.val('fill');
const target = descendants(box).find(element => element.attributes.class?.includes('import-target'));
target.val('person:uuid');
const decisions = scope.collectImportDecisions();
verify(decisions[0].action === 'fill' && decisions[0].person === 'uuid', 'Completar requiere una elección explícita de registro existente.');

response = () => ({data: {electoral_processes: [{id: 'election', process: payload, organization: payload, type: 'Captura', selected: true}]}});
ready.at(-1)();
const selector = elements.get('#electoral-context-select');
verify(selector.children.some(option => option.attributes.text?.includes(payload)), 'Selector electoral presenta nombres como texto de opciones.');
selector.val('election');
selector.events.change();
verify(requests.at(-1).type === 'post' && requests.at(-1).data.process === 'election', 'Cambio electoral utiliza API y solo transmite elección.');
console.log(`OK: ${checks} comprobaciones JavaScript de Fase 2.`);
