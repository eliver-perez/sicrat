'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
let sequence=0,checks=0;const timers=new Map(),listeners=new Map();const document={visibilityState:'visible',addEventListener:(event,handler)=>listeners.set(event,handler),removeEventListener:(event,handler)=>{if(listeners.get(event)===handler)listeners.delete(event);}};
const scope={window:{},document,Promise,Math,setTimeout:(work,delay)=>{const id=++sequence;timers.set(id,{work,delay});return id;},clearTimeout:id=>timers.delete(id)};vm.createContext(scope);vm.runInContext(fs.readFileSync('public/assets/js/messaging-poll.js','utf8'),scope);
const check=(value,why)=>{assert.ok(value,why);checks++;};
(async()=>{
 let calls=0,resolve;const poll=new scope.window.SicratChatPoller(()=>{calls++;return new Promise(done=>resolve=done);});
 poll.schedule();check(Array.from(timers.values())[0].delay===3000,'Consulta inicial cada tres segundos');
 const work=poll.refresh();await Promise.resolve();poll.refresh();poll.refresh();check(calls===1,'Una sola consulta concurrente incluso tras varios refresh');resolve({changed:true});await work;check(timers.size===1 && Array.from(timers.values())[0].delay===0,'Solicitudes concurrentes se agrupan en una continuación inmediata');
 document.visibilityState='hidden';listeners.get('visibilitychange')();check(timers.size===0,'Ocultar cancela temporizador');await poll.refresh();check(calls===1,'Pestaña oculta no consulta');
 document.visibilityState='visible';listeners.get('visibilitychange')();await Promise.resolve();check(calls===2,'Volver consulta inmediatamente');resolve({changed:false});await poll.running;
 poll.destroy();check(timers.size===0 && !listeners.has('visibilitychange'),'Cerrar elimina temporizador y listener');await poll.refresh();check(calls===2,'Poll destruido no reaparece');
 const errors=[];const failed=new scope.window.SicratChatPoller(()=>Promise.reject(Object.assign(new Error('red'),{status:503})),{onError:error=>errors.push(error.status)});await failed.refresh();check(Array.from(timers.values())[0].delay===6000 && errors[0]===503,'Primer error retrocede a seis segundos');await failed.refresh();check(Array.from(timers.values())[0].delay===12000,'Segundo error retrocede a doce segundos');failed.work=()=>({changed:true});await failed.refresh();check(Array.from(timers.values())[0].delay===3000 && failed.errors===0,'Recuperación vuelve a tres segundos');
 failed.work=()=>({changed:false});for(let n=0;n<42;n++)await failed.refresh();check(Array.from(timers.values())[0].delay===15000,'Inactividad prolongada reduce consultas');failed.active();check(Array.from(timers.values())[0].delay===3000,'Escribir recupera frecuencia activa');const activeTimer=Array.from(timers.keys())[0];failed.active();check(timers.has(activeTimer),'Escribir continuamente no retrasa la recepción');failed.destroy();
 let alive=true;const inactive=new scope.window.SicratChatPoller(()=>{throw Error('No debe consultar');},{alive:()=>alive});alive=false;inactive.schedule();await inactive.refresh();check(timers.size===0,'Documento desconectado no consulta');inactive.destroy();
 console.log('OK: '+checks+' comprobaciones de polling, visibilidad, concurrencia y recuperación.');
})().catch(error=>{console.error(error);process.exitCode=1;});
