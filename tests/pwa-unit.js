const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({ URL });
for (const name of ['policy', 'delta']) vm.runInContext(fs.readFileSync(`public/assets/js/pwa/${name}.js`, 'utf8'), context);
const { SicratPwaPolicy: policy, SicratPwaDelta: delta } = context;
assert.equal(policy.canonical('https://sicrat.test/api/persons?search=Ana&_=432&limit=25'), '/api/persons?limit=25&search=Ana');
for (const path of ['/api/auth/me', '/api/auth/login', '/media/private.pdf', '/api/files', '/api/users']) {
    assert.equal(policy.match(new URL(path, 'https://sicrat.test')), null);
}
const body = { data: { rows: [
    { id: 1, mensaje: 'Retirar texto sensible', eliminado: 0, attachments: [{ uuid: 'privado' }] },
    { id: 2, mensaje: 'Conservar texto autorizado', eliminado: 0, attachments: [] }
] } };
assert.equal(delta.messages(body, { message_updates: [{ id: 1, eliminado: 1 }], attachment_updates: [{ id: 2, attachments: [{ uuid: 'nuevo' }] }] }), true);
assert.equal(body.data.rows[0].mensaje, 'Mensaje retirado');
assert.equal(body.data.rows[0].attachments.length, 0);
assert.equal(body.data.rows[1].mensaje, 'Conservar texto autorizado');
assert.equal(body.data.rows[1].attachments[0].uuid, 'nuevo');
assert.equal(delta.messages(body, { message_updates: [{ id: 3, eliminado: 1 }], attachment_updates: [{ id: 3, attachments: [] }] }), false);
assert.equal(body.data.rows.length, 2);
assert.equal(policy.match(new URL('https://sicrat.test/api/dashboard')).module, 'dashboard');
assert.equal(policy.match(new URL('https://sicrat.test/api/dashboard/notes?offset=0')).strategy, 'network-first');
assert.equal(policy.match(new URL('https://sicrat.test/api/dashboard/notes/00000000-0000-0000-0000-000000000000')), null);
console.log('OK: 16 comprobaciones de políticas y deltas PWA.');
