'use strict';
const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');const nodeCrypto=require('node:crypto');
let checks=0;
const create=crypto=>{const context=vm.createContext({window:{},crypto,Uint8Array,$:{ajaxPrefilter(){}}});vm.runInContext(fs.readFileSync('public/assets/js/security.js','utf8'),context);return context.window.SicratSecurity;};
const native=create({randomUUID:()=> 'backend-compatible-uuid'});assert.equal(native.uuid(),'backend-compatible-uuid');checks++;
const fallback=create({getRandomValues:bytes=>nodeCrypto.randomFillSync(bytes)});const seen=new Set();
for(let i=0;i<100;i++){const uuid=fallback.uuid();assert.match(uuid,/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);assert.ok(!seen.has(uuid));seen.add(uuid);checks+=2;}
assert.throws(()=>create({}).uuid(),/generación segura/);checks++;
for(const file of ['resources/core.js','operations/details.js','operations/index.js','jornada/index.js']){const source=fs.readFileSync('public/assets/js/'+file,'utf8');assert.ok(!source.includes('crypto.randomUUID()'));checks++;}
console.log(`OK: ${checks} comprobaciones de UUID seguro y compatibilidad sin randomUUID.`);
