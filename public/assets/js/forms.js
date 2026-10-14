'use strict';
// Validación nativa común, limitada al formulario que se está enviando.
document.addEventListener('submit',event=>{
 const form=event.target;if(!(form instanceof HTMLFormElement))return;
 if(!form.checkValidity()){event.preventDefault();event.stopImmediatePropagation();form.reportValidity();}
 form.classList.add('was-validated');bsSelectValidation(form.id);
},true);
function bsSelectValidation(id){
 const form=document.getElementById(id);if(!form?.classList.contains('was-validated'))return;
 form.querySelectorAll('select').forEach(select=>{
  const invalid=!select.validity.valid;select.setAttribute('aria-invalid',String(invalid));
  const group=select.closest('.form-group');group?.querySelector('.invalid-feedback')?.classList.toggle('hidden',!invalid);group?.querySelector('.valid-feedback')?.classList.toggle('hidden',invalid);
 });
}

// Adaptador de formularios heredados: conserva sus funciones y espera los AJAX reales.
document.addEventListener('submit',event=>{
 const form=event.target,match=/^javascript:([A-Za-z_$][\w$]*)\(\)$/.exec(form.getAttribute?.('action') || '');
 if(!match || typeof window[match[1]]!=='function' || !window.SicratFeedback)return;
 event.preventDefault();window.SicratFeedback.execute(form,async()=>{
  const requests=[];window.SicratSecurity.pendingAjax=requests;
  let result;try{result=window[match[1]]();}finally{window.SicratSecurity.pendingAjax=null;}
  await result;await Promise.all(requests.map(request=>new Promise(resolve=>request.always(()=>resolve()))));
 }).catch(failure=>window.SicratFeedback.error(failure));
});
