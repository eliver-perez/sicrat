'use strict';
const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
let checks=0;const parent={field:()=>({setAttribute(){}})};const context={window:{SicratOperations:parent,SicratUI:{label:value=>value}},BigInt,Object,Array,String};vm.createContext(context);vm.runInContext(fs.readFileSync('public/assets/js/resources/core.js','utf8'),context);const app=context.window.SicratResources;
for(const [input,expected] of [['0.01','$0.01 MXN'],['120000.99','$120,000.99 MXN'],['999999999999.99','$999,999,999,999.99 MXN'],['-10.01','−$10.01 MXN']]){assert.equal(app.moneyText(input),expected);checks++;}
for(const scale of [2,3]){const field=app.money({},'cantidad','Cantidad','',true,scale);assert.equal(field.inputMode,'decimal');checks++;for(const text of ['0','1.1','100.00']){assert.ok(new RegExp('^'+field.pattern+'$').test(text));checks++;}for(const text of ['1e3','1,000.00','-1.00','1.'+'1'.repeat(scale+1)]){assert.equal(new RegExp('^'+field.pattern+'$').test(text),false);checks++;}}
for(const [code,label] of [['validacion','Pendiente de autorización'],['vencido','Vencido'],['prometido','Prometido'],['parcial','Parcial']]){assert.equal(app.label(code),label);checks++;}
console.log('OK: '+checks+' comprobaciones frontend de moneda exacta, precisión y estados financieros.');
