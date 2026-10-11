const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
let checks = 0;
function verify(condition, description) {
	assert.ok(condition, description);
	checks++;
}

function context() {
	const rendered = {};
	const controls = [];
	const element = () => ({
		children: [], events: {}, disabled: false,
		append(...children) { this.children.push(...children); },
		addEventListener(event, callback) { this.events[event] = callback; }
	});
	const jquery = selector => ({
		empty() { rendered[selector] = ''; return this; },
		append(value) { rendered[selector] = (rendered[selector] || '') + value; return this; },
		val() { return selector.includes('section') ? 'N/A' : ''; },
		text(value) { rendered[selector] = value; return this; }
	});
	jquery.each = (rows, callback) => rows.forEach((row, index) => callback(index, row));
	const scope = {
		console: { log() {} }, URL, navigator: {}, $: jquery,
		window: { addEventListener() {}, location: { href: 'http://localhost/', origin: 'http://localhost' } },
		document: {
			createElement: element,
			querySelector(selector) {
				if (selector === 'meta[name="csrf-token"]') return { content: 'valid-token' };
				return { closest() { return this; }, insertAdjacentElement(position, control) { assert.equal(position, 'afterend'); controls.push(control); } };
			}
		}
	};
	vm.createContext(scope);
	vm.runInContext(fs.readFileSync(path.join(root, 'public/assets/js/global.js'), 'utf8'), scope);
	return { scope, rendered, controls };
}

const payload = '<img src=x onerror=alert(1)>"\'&';
for (const [module, method, key, table] of [
	['users', 'GetUsers', 'users', 'users'],
	['persons', 'GetPersons', 'persons', 'persons'],
	['organizations', 'GetOrganizations', 'organizations', 'organizations'],
	['electoral-process', 'GetElectoralProcess', 'processes', 'electoral-process'],
	['sections', 'GetSections', 'sections', 'sections'],
]) {
	const { scope, rendered } = context();
	const row = {};
	for (const field of ['id', 'name', 'username', 'email', 'type', 'section', 'organization', 'contact', 'state', 'municipality', 'process', 'character', 'registered_date', 'last_active_date']) row[field] = payload;
	row.active = row.status = 1;
	scope.$.ajax = options => options.success({ data: { [key]: [row] }, pagination: { has_more: false } });
	vm.runInContext(fs.readFileSync(path.join(root, `public/assets/js/${module}/index.js`), 'utf8'), scope);
	scope[`${key}Pagination`] = { params: () => ({ limit: 25, offset: 0 }), update() {} };
	scope[method](true);
	const html = rendered[`#table-${table} tbody`];
	verify(typeof html === 'string', `Se presenta el listado ${module}.`);
	verify(!html.includes('<img src=x'), `El listado ${module} no inserta HTML del dato.`);
	verify(html.includes('&lt;img'), `El listado ${module} conserva el texto escapado.`);
}

{
	const { scope, rendered } = context();
	vm.runInContext(fs.readFileSync(path.join(root, 'public/assets/js/organizations/index.js'), 'utf8'), scope);
	scope.organizationProcessesPagination = { update() {} };
	scope.RenderOrganizationProcesses([{ id: 'id', process: payload, type: payload, character: payload, registered_date: payload, status: 1 }], {});
	verify(!rendered['#table-electoral-process tbody'].includes('<img src=x'), 'Los procesos del detalle no insertan HTML del dato.');
}

{
	const { scope, rendered } = context();
	scope.$.ajax = options => options.success({ success: true, data: { name: payload, type: payload, email: payload } });
	vm.runInContext(fs.readFileSync(path.join(root, 'public/assets/js/profile/index.js'), 'utf8'), scope);
	scope.user_id = 'uuid';
	scope.GetUserInfo();
	verify(rendered['#field-profile-name'] === payload, 'El perfil utiliza texto, no HTML.');
}

{
	const { scope, controls } = context();
	let changes = 0;
	const pager = scope.createPagination('#table-users', () => changes++);
	pager.update({ has_more: true });
	const [previous, label, next] = controls[0].children;
	verify(previous.disabled && !next.disabled, 'La primera página permite avanzar.');
	next.events.click();
	verify(pager.offset === 25 && changes === 1, 'Avanzar cambia el desplazamiento y solicita filas.');
	pager.update({ has_more: false });
	verify(next.disabled && !previous.disabled && label.textContent === 'Página 2', 'La última página permite regresar.');
	previous.events.click();
	verify(pager.offset === 0 && changes === 2, 'Regresar recupera la primera página.');
}

{
	const { scope } = context();
	let prefilter;
	scope.$.ajaxPrefilter = callback => { prefilter = callback; };
	vm.runInContext(fs.readFileSync(path.join(root, 'public/assets/js/security.js'), 'utf8'), scope);
	const headers = {};
	const request = { setRequestHeader(name, value) { headers[name] = value; } };
	prefilter({ url: '/api/persons', type: 'POST' }, {}, request);
	verify(headers['X-CSRF-Token'] === 'valid-token', 'AJAX propio envía CSRF.');
	delete headers['X-CSRF-Token'];
	prefilter({ url: '/api/persons', type: 'GET' }, {}, request);
	verify(!headers['X-CSRF-Token'], 'GET no requiere token.');
	prefilter({ url: 'https://other.example/api', type: 'POST' }, {}, request);
	verify(!headers['X-CSRF-Token'], 'No se filtra CSRF a otro origen.');
}

{
	const { scope } = context();
	let result;
	scope.$.ajax = options => options.success({ data: { organizations: [options.data.offset] }, pagination: { has_more: options.data.offset === 0 } });
	scope.loadOptions({ url: '/api/organizations', success(response) { result = response.data.organizations; } }, 'organizations');
	verify(result.length === 2 && result[0] === 0 && result[1] === 100, 'Los selectores recorren páginas sin perder opciones.');
}

console.log(`OK: ${checks} comprobaciones JavaScript.`);
