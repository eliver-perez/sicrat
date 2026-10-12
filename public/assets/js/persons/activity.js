'use strict';

window.InitializePersonOperations = async function (uuid,base) {
	const root=document.getElementById('person-operational-activity');if(!root)return;
	const sequence=(window.InitializePersonOperations.sequence || 0)+1;window.InitializePersonOperations.sequence=sequence;root.replaceChildren();
	const node=(tag,value)=>{const element=document.createElement(tag);element.textContent=value;return element;};
	async function api(path,method='GET',data=null){const url=new URL(base.replace(/\/$/,'')+'/api/'+path,location.href);if(url.origin!==location.origin)throw new Error('La petición debe pertenecer al mismo sitio.');const headers={Accept:'application/json'};if(method!=='GET'){headers['Content-Type']='application/json';headers['X-CSRF-Token']=document.querySelector('meta[name="csrf-token"]').content;}const response=await fetch(url,{method,headers,credentials:'same-origin',body:data===null ? undefined : JSON.stringify(data)});const result=await response.json();if(!response.ok)throw new Error(result.message);return result.data;}
	try {
		const me=await api('auth/me');if(sequence!==window.InitializePersonOperations.sequence)return;
		if(!me.process_id){root.append(node('p','Selecciona una elección para consultar la actividad operativa.'));return;}
		const section=node('section','');section.className='ux-section';section.append(node('h6','Actividad cronológica de la elección actual'));root.append(section);
		const list=node('div','');const controls=node('div','');controls.className='ux-pagination';let offset=0,hasMore=false;section.append(list,controls);
		const previous=node('button','Anterior');previous.type='button';previous.className='btn';const next=node('button','Siguiente');next.type='button';next.className='btn';controls.append(previous,next);
		async function load(){const result=await api('persons/'+uuid+'/activity?limit=25&offset='+offset);if(sequence!==window.InitializePersonOperations.sequence)return;list.replaceChildren();if(!result.rows.length)list.append(node('p','Sin actividad autorizada en esta elección.'));else{const wrapper=node('div','');wrapper.className='op-table-scroll';const table=node('table','');const header=node('tr','');['Fecha','Actividad','Detalle'].forEach(label=>header.append(node('th',label)));table.append(header);result.rows.forEach(row=>{const line=node('tr','');line.append(node('td',row.fecha),node('td',window.SicratUI.label(row.tipo)),node('td',row.titulo));table.append(line);});wrapper.append(table);list.append(wrapper);}hasMore=!!result.pagination.has_more;previous.disabled=offset===0;next.disabled=!hasMore;}
		function run(work){return event=>{event.preventDefault();work().catch(error=>section.append(node('p',error.message)));};}previous.addEventListener('click',run(async()=>{offset=Math.max(0,offset-25);await load();}));next.addEventListener('click',run(async()=>{if(hasMore)offset+=25;await load();}));await load();
		const capabilities=await api('operations/capabilities');if(sequence!==window.InitializePersonOperations.sequence)return;
		if(capabilities.modules.some(module=>module.code==='transporte' && module.enabled) && (me.is_superadmin || me.rights.includes('transporte.ver'))){
			const logistics=node('section','');logistics.className='ux-section';logistics.append(node('h6','Logística de la participación electoral actual'));root.append(logistics);
			let current;try{current=await api('operations/transporte/'+uuid);}catch(error){logistics.append(node('p',error.message));return;}
			const description=node('p',({'no_definido':'No definido','requiere':'Requiere transporte','propio':'Se trasladará por cuenta propia'})[current.necesidad]);logistics.append(description);
			if(me.is_superadmin || me.rights.includes('transporte.editar')){const form=node('form','');form.className='ux-inline-form';const label=node('label','Necesidad de transporte');const select=node('select','');select.className='form-input';Object.entries({no_definido:'No definido',requiere:'Requiere transporte',propio:'Se trasladará por cuenta propia'}).forEach(([value,text])=>{const option=node('option',text);option.value=value;select.append(option);});select.value=current.necesidad;label.append(select);const save=node('button','Guardar necesidad');save.className='btn bg-primary text-white';form.append(label,save);form.addEventListener('submit',run(async()=>{save.disabled=true;try{await api('operations/transporte/'+uuid,'PUT',{need:select.value,version:current.version});await window.InitializePersonOperations(uuid,base);}finally{save.disabled=false;}}));logistics.append(form);}
			const link=node('a','Abrir tablero de coordinación');link.className='btn';link.href=base.replace(/\/$/,'')+'/operations/?module=transporte';logistics.append(link);
		}
	}catch(error){if(sequence===window.InitializePersonOperations.sequence)root.append(node('p',error.message));}
};
