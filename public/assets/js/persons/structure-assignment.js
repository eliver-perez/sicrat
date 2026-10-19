'use strict';
window.AssignPersonStructure = async function(person) {
    const ui=window.SicratPhase3;ui.base=homeURL;
    const dialog=document.createElement('dialog');dialog.id='person-assignment-dialog';dialog.className='card p-5 w-full max-w-xl';dialog.setAttribute('aria-label','Asignar a estructura');
    const form=document.createElement('form');form.className='ux-form-grid';const title=ui.text('h6','Asignar a estructura','ux-full');
    const structure=document.createElement('select');structure.className='form-input';structure.required=true;structure.name='structure';
    const structureLabel=ui.text('label','Estructura','ux-full');structureLabel.append(structure);
    const controls=ui.text('div','','ux-full');const responsible=document.createElement('select');responsible.className='form-input';responsible.name='responsible';
    const label=ui.text('label','Responsable directo','ux-full');label.append(responsible);
    const levels=ui.text('div','','ux-form-grid ux-full');levels.id='person-assignment-levels';const participations=ui.text('div','','ux-full');
    const status=ui.text('p','Cargando estructuras…','ux-help ux-full');status.setAttribute('role','status');
    const save=ui.text('button','Asignar','btn bg-primary text-white');save.type='submit';save.disabled=true;save.prepend(SicratUI.icon('users'));
    const cancel=ui.text('button','Cancelar','btn');cancel.type='button';cancel.onclick=()=>dialog.close();const actions=ui.text('div','','ux-modal-footer ux-actions ux-full');actions.append(save,cancel);
    form.append(title,structureLabel,controls,label,levels,ui.text('p','Participaciones electorales','ux-full'),participations,status,actions);const close=ui.text('button','Cerrar','btn');close.type='button';close.dataset.close='assignment';close.onclick=()=>dialog.close();dialog.append(form,close);document.body.append(dialog);SicratUI.dialog(dialog);dialog.showModal();
    let loading=false,closed=false;const assignment=new SicratAssignment({request:path=>ui.request(path),select:responsible,levels,controls});
    dialog.addEventListener('close',()=>{closed=true;assignment.version++;dialog.remove();});
    async function load(){
        if(loading)return;loading=true;save.disabled=true;structure.disabled=true;status.textContent='Cargando opciones…';
        try{await assignment.open(structure.value);if(closed)return;SicratUI.electionChoices(participations,assignment.data.elections);status.textContent='';save.disabled=!assignment.data.can_root&&!assignment.candidates.size;if(save.disabled)status.textContent='No hay responsables disponibles para esta asignación.';}
        catch(error){status.textContent=error.message;SicratFeedback.error(error);}finally{loading=false;structure.disabled=false;}
    }
    structure.onchange=load;
    form.onsubmit=event=>{event.preventDefault();if(save.disabled)return;
        ui.run(async()=>{const data={person:person.id,...assignment.values(),participations:ui.selected(participations)};await ui.request(`structures/${structure.value}/members`,'POST',data);dialog.close();
            if(selectedPerson===person.id){const fresh=await identityRequest(`persons/${person.id}`,'get');showPersonStructure(fresh.data);renderPersonParticipations(fresh.data);}
            ui.message('Persona asignada a la estructura.');
        },form);
    };
    try{const rows=await ui.pages('structures?assignment=1');if(closed)return;rows.forEach(row=>ui.option(structure,row.id,row.name));structure.value=person.structure?.own_structure || rows.find(row=>row.own)?.id || structure.value;if(!structure.value){status.textContent='No hay estructuras disponibles en esta elección.';return;}await load();}
    catch(error){status.textContent=error.message;SicratFeedback.error(error);}
};
