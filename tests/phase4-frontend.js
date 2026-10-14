const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

let checks = 0;
class Element {
	constructor(tag = 'div') { this.tagName = tag; this.children = []; this.listeners = {}; this.elements = new Proxy({}, {get: (target,key) => target[key] || (target[key] = new Element())}); this.dataset = {}; this.style = {}; this.hidden = true; this.value = ''; this._text = ''; this.classList = {toggle() {},add() {}};this.selectedOptions=[];this.parentElement=new Proxy({}, {get:(_,key)=>key==='append' ? ()=>{} : undefined}); }
	set textContent(value) { this._text = String(value); this.children = []; }
	get textContent() { return this._text + this.children.map(child => child.textContent || '').join(''); }
	append(...children) { this.children.push(...children); }
	replaceChildren(...children) { this._text = ''; this.children = children; }
	setAttribute(name, value) { this[name] = value; }
	querySelectorAll() { return []; }
	closest() { return null; }
	addEventListener(name, callback) { this.listeners[name] = callback; }
	set innerHTML(value) { throw new Error('No se permite insertar HTML con datos del servicio.'); }
}
const nodes = new Map();
const node = id => { if (!nodes.has(id)) nodes.set(id, new Element()); if (id.startsWith('services-tab-')) nodes.get(id).dataset.service = id.replace('services-tab-',''); return nodes.get(id); };
const calls = [];
const pager = new Element();
const document = {
	getElementById: node,
	addEventListener() {},
	createElement: tag => new Element(tag),
	querySelectorAll: () => [],
	querySelector: selector => selector.startsWith('[data-pager=') ? pager : null
};
const context = vm.createContext({
	document, location: { origin: 'http://localhost' }, URL, URLSearchParams, FormData: class {},
	window: { SicratPhase3: {
		message: () => {},
		request: async (path, method, data) => {
			calls.push({ path, method, data });
			if (path === 'auth/me') return { data: { is_superadmin: false, rights: [], csrf_token: 'test' } };
			if (path === 'notifications/read') return { data: { updated: 1 } };
			return { data: { rows: [{ titulo: '<img src=x onerror=alert(1)>', resumen: 'Seguro', fecha: '2026-10-12', uuid: 'opaque', leida_en: null, enlace: 'javascript:alert(1)' }], pagination: { has_more: false } } };
		}, pages: async () => []
	} }
});
vm.runInContext(fs.readFileSync('public/assets/js/ux.js', 'utf8'), context);
vm.runInContext(fs.readFileSync('public/assets/js/services/index.js', 'utf8'), context);
assert.equal(calls.length, 0); checks++;
assert.equal(typeof context.InitializeValues, 'function'); checks++;
context.InitializeValues();
async function main() {
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(calls[0].path, 'auth/me'); checks++;
	assert.ok(calls[1].path.startsWith('notifications?')); checks++;
	assert.ok(node('notifications-list').textContent.includes('<img src=x onerror=alert(1)>')); checks++;
	assert.equal(node('services-backups').hidden, true); checks++;
	assert.equal(node('services-audit').hidden, true); checks++;
	assert.equal(calls.some(call => call.path.startsWith('backups')), false); checks++;
	assert.equal(calls.some(call => call.path.startsWith('notification-config')), false); checks++;
	const visit = element => [element, ...element.children.flatMap(visit)];
	assert.equal(visit(node('notifications-list')).some(element => element.tagName === 'a'), false); checks++;
	await node('notifications-read-all').listeners.click({ preventDefault() {} });
	assert.equal(calls.find(call => call.path === 'notifications/read').method, 'POST'); checks++;
	assert.equal(calls.find(call => call.path === 'notifications/read').data.all, 1); checks++;
	assert.equal(node('services-message').textContent, ''); checks++;
	console.log(`OK: ${checks} comprobaciones JavaScript de Fase 4.`);
}
main().catch(error => { console.error(error); process.exitCode = 1; });
