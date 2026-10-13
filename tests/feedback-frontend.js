const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let checks = 0;
const calls = [];
let result = {isConfirmed: true};
const modal = {name: 'native-dialog'};
const document = {body: {}, activeElement: null, querySelectorAll: () => []};
const context = vm.createContext({window: {}, document, Swal: {fire: async options => {calls.push(options); return result;}, stopTimer(){}, resumeTimer(){}}, WeakSet});
vm.runInContext(fs.readFileSync('public/assets/js/sweetalert.js','utf8'),context);
vm.runInContext(fs.readFileSync('public/assets/js/administration/index.js','utf8'),context);
const feedback = context.window.SicratFeedback;
const visible = context.window.SicratModuleVisibility;
const verify = (value, title) => {assert.ok(value,title);checks++;};
async function main() {
    for (const icon of ['success','warning','error','info']) {
        await context.ShowToastMessage('<img onerror=alert(1)>',icon);
        verify(calls.at(-1).toast && calls.at(-1).icon===icon && calls.at(-1).titleText==='<img onerror=alert(1)>',icon+': toast seguro');
        verify(calls.at(-1).timer>0 && !calls.at(-1).showConfirmButton,icon+': finaliza sin decisión');
    }
    verify(await feedback.confirm('Cerrar Jornada'), 'Aceptar confirmación');
    result={isConfirmed:false}; verify(!await feedback.confirm('Cerrar Jornada'),'Cancelar no autoriza');
    verify(calls.at(-1).showCancelButton && !calls.at(-1).allowOutsideClick,'Decisión explícita');
    let callback; await context.ShowSweetAlertConfirmCancelCallback('warning','Revisar','Motivo','Sí','No',value=>callback=value);
    verify(callback.isConfirmed===false,'Callback compatible');
    document.querySelectorAll=()=>[modal]; await feedback.toast('Guardado'); verify(calls.at(-1).target===modal,'Toast visible dentro del diálogo nativo');
    const button={tagName:'BUTTON',disabled:false,textContent:'Guardar',setAttribute(){},removeAttribute(){}};
    let resolve, submissions=0;
    const work=()=>{submissions++;return new Promise(done=>resolve=done);};
    const first=feedback.execute(button,work);
    verify(button.disabled && button.textContent==='Guardando…','Estado en procesamiento');
    await feedback.execute(button,work); verify(submissions===1,'Doble ejecución bloqueada');
    resolve(); await first; verify(!button.disabled && button.textContent==='Guardar','Control recuperado');
    await assert.rejects(()=>feedback.execute(button,()=>{throw new Error('fallo');})); checks++;
    verify(!button.disabled,'Recuperación tras error');
    feedback.error(Object.assign(new Error('Sin permiso'),{status:403})); verify(calls.at(-1).icon==='warning','Permiso denegado comunica warning');
    feedback.error(new Error('Falló')); verify(calls.at(-1).icon==='error','Error AJAX comunica error');
    for (const authorizedOrganization of [0,1]) for (const authorizedElection of [0,1]) for (const selected of [0,1]) {
        const row={esencial:0,autorizacion_organizacion:authorizedOrganization,autorizacion_eleccion:authorizedElection,seleccion_eleccion:selected};
        verify(visible(row,true,true),'Super Administrador ve todos');
        verify(visible(row,false,true)===Boolean(authorizedOrganization && authorizedElection),'Cliente sólo ve autorización efectiva, independientemente de selección');
    }
    console.log(`OK: ${checks} comprobaciones SweetAlert, módulos y prevención de doble envío.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
