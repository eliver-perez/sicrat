'use strict';
function InitializeValues(home) {
 window.homeURL=(home || '').replace(/\/$/,'');window.SicratPhase3.base=window.homeURL;
 const app=window.SicratOperations;window.SicratPhase3.closeButtons();let writable=false,pager;
 const open=async uuid=>{
  const row=uuid ? await app.api('polling-booths/'+uuid) : null,target=app.node('booths-form');target.replaceChildren();
  app.node('booths-title').textContent=row ? 'Detalle de casilla' : 'Nueva casilla';
  if(row && (!app.me.process_id || !writable || (!app.me.is_superadmin && row.shared) || row.history_locked)) {
   app.description(target,{Casilla:row.casilla,Tipo:row.tipo,Sección:row.numero_seccion,Ubicación:row.ubicacion,Domicilio:row.domicilio});
   target.append(app.text('p',!app.me.process_id ? 'Selecciona una elección de trabajo para editar.' : row.history_locked ? 'La configuración conserva los datos de las actas existentes.' : row.shared ? 'Los datos físicos de esta casilla se comparten entre elecciones. Solicita al Super Administrador su modificación.' : 'Consulta disponible según tus permisos.','ux-help'));
  }else{
   let section;const types=Object.fromEntries((await app.api('polling-booth-types')).map(type=>[type.codigo,type.nombre]));
   const form=app.form(target,row ? 'Editar casilla' : 'Registrar casilla',form=>{
    app.field(form,'name','Nombre de la casilla','text',row?.casilla || '',true).maxLength=60;
    app.field(form,'type','Tipo','select',row?.tipo || '',true,types);
    app.field(form,'location','Ubicación','text',row?.ubicacion || '').maxLength=255;
    app.field(form,'address','Domicilio','text',row?.domicilio || '').maxLength=255;
   },async values=>{await app.api('polling-booths'+(row ? '/'+row.uuid : ''),row ? 'PUT' : 'POST',{...values,section:section.values()});app.node('booths-dialog').close();await pager.load();app.notice('Casilla guardada correctamente.');});
   section=await app.picker(form,'Sección','sections',false,row ? [{uuid:row.section_uuid,label:'Sección '+row.numero_seccion}] : [],true);
  }
  app.node('booths-dialog').showModal();
 };
 app.node('booths-new').addEventListener('click',app.guard(()=>open(null)));
 app.guard(async()=>{
  app.me=await app.api('auth/me');writable=app.can('casillas.administrar');app.node('booths-new').hidden=!writable || !app.me.process_id;
  pager=app.pager(app.node('booths-list'),()=> 'polling-booths?'+new URLSearchParams(new FormData(app.node('booths-filter'))),[['Sección','seccion'],['Casilla','casilla'],['Tipo','tipo'],['Ubicación','ubicacion']],(cell,row)=>cell.append(app.button('Ver detalle',()=>open(row.uuid))));
  app.node('booths-filter').addEventListener('submit',app.guard(()=>pager.reset()));await pager.load();app.node('booths-message').textContent=app.me.is_superadmin ? 'Catálogo global. Selecciona una elección para registrar sus casillas.' : 'Las casillas disponibles corresponden a tu elección de trabajo.';
  window.SicratBooths={initialized:true};
 })();
}
