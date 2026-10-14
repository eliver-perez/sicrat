'use strict';

window.SicratResources=Object.create(window.SicratOperations);
Object.assign(window.SicratResources,{
	module:'finanzas',entity:'resumen',enabled:false,filters:{},
	notice(message, type = 'success') {
		if (window.SicratFeedback) { window.SicratFeedback.toast(message,/exportación|en cola|programad/i.test(message) ? 'info' : type); return; }
		this.node('resources-message').textContent=message;
		const dialog=document.activeElement?.closest('dialog[open]') || Array.from(document.querySelectorAll('dialog[open]')).at(-1);
		if(dialog) { let status=dialog.querySelector('[data-resource-message]');
			if(!status){status=this.text('p','','mb-4 text-primary');
				status.dataset.resourceMessage='1';
				status.setAttribute('role','status');
				dialog.prepend(status);
				}status.textContent=message;
			}
	},
	label(value) { return ({registrado:'Registrado',comprometido:'Comprometido',aprobado:'Autorizado',validacion:'Pendiente de autorización',parcial:'Parcial',pagado:'Pagado',cancelado:'Cancelado',correccion:'Requiere corrección',comprobado:'Comprobado',vencido:'Vencido',prometido:'Prometido',recibido:'Recibido',recibida:'Recibida',validada:'Validada',solicitado:'Solicitado',rechazado:'Rechazado',aplicado:'Aplicado',ordinario:'Gasto ordinario',apoyo:'Apoyo fijo',viatico:'Viático fijo',compra:'Compra inventariable',reembolso:'Reembolso',monetaria:'Monetaria',especie:'En especie',entrada:'Entrada',entrega:'Entrega',consumo:'Consumo',prestamo:'Préstamo',perdida:'Pérdida',dano:'Daño',devolucion:'Devolución',transferencia_in:'Transferencia recibida',transferencia_out:'Transferencia enviada',ajuste_entrada:'Ajuste de entrada',ajuste_salida:'Ajuste de salida',reversa:'Reversión'})[value] || window.SicratUI.label(value);
		},
	money(target,name,label,value = '',required = true,scale = 2) {
		const field=this.field(target,name,label+(scale===2 ? ' (MXN)' : ''),'text',value,required);
		field.inputMode='decimal';
		field.pattern='(0|[1-9][0-9]*)(\\.[0-9]{1,'+scale+'})?';
		field.placeholder=scale===2 ? '0.00' : '0.000';
		field.setAttribute('aria-description','Sin separador de miles; punto decimal.');
		return field;
	},
	moneyText(value) { const text=String(value ?? '0.00');
		const negative=text.startsWith('-');
		const [whole,fraction='00']=(negative ? text.slice(1) : text).split('.');
		return (negative ? '−' : '')+'$'+BigInt(whole || '0').toLocaleString('es-MX')+'.'+fraction.padEnd(2,'0')+' MXN';
		},
	async optionPicker(target,label,type,permission,multiple = false,initial = [],required = false) { const path='resources/options?'+new URLSearchParams({tipo:type,permiso:permission});
		for(const item of initial){if(!item.label || ['Selección vigente','Categoría vigente'].includes(item.label)){const result=await this.api(path+'&reference='+encodeURIComponent(item.uuid));
				item.label=result.rows[0]?.nombre || item.nombre || 'Selección pendiente de revisión';
				}}return this.picker(target,label,path,multiple,initial,required);
		},
	async modal(title,builder,submit,submitLabel = 'Guardar') {
		const target=this.node('resources-dialog-content');
		target.replaceChildren();
		this.node('resources-dialog-title').textContent=title;
		const dialog=this.node('resources-dialog');
		if(!dialog.open)dialog.showModal();
		const form=this.text('form','','ux-form-grid op-form-fields');
		form.addEventListener('submit',event=>event.preventDefault());
		target.append(form);
		const status=this.text('p','Cargando opciones…','ux-full op-small-note');
		form.append(status);
		let dto=null;
		const button=this.text('button',submitLabel,'btn bg-primary text-white');
		button.type='submit';
		button.disabled=true;
		try{dto=await builder(form);
			status.remove();
			const actions=this.text('div','','ux-actions ux-full');
			actions.append(button);
			form.append(actions);
			button.disabled=false;
			}catch(error){status.textContent=error.message;
			return;
			}
		const key=window.SicratSecurity.uuid();
		form.addEventListener('submit',this.guard(async()=>{const values={...this.values(form),idempotencia:key,...(dto ? dto(form) : {})};
			if ((/cancelar|revertir|rechazar|eliminar|revocar|restaurar/i.test(submitLabel) || String(values.approved)==='0') && !await window.SicratFeedback.confirm(title,{confirm:String(values.approved)==='0' ? 'Rechazar' : submitLabel})) return;
			await submit(values);
			dialog.close();
			await this.refresh();
			if (!/export/i.test(submitLabel)) this.notice('Operación registrada.');
			}));
	},
	async refresh() { if(this.list)await this.list.reset();
		if(this.selected && this.node('resources-detail').open)await this.open(this.selected);
		},
	async exportDialog() {
		const type=this.entity==='existencias' ? 'inventario_existencias' : this.entity==='flujo' ? 'finanzas_flujo' : (this.module==='finanzas' ? 'finanzas_' : 'inventario_')+this.entity;
		await this.modal('Exportar '+this.definition.title.toLowerCase(),async form=>{this.field(form,'formato','Formato','select','xlsx',true,{xlsx:'Excel',csv:'CSV',pdf:'PDF'});
			form.append(this.text('p','El resultado privado se generará en segundo plano.','ux-full op-small-note'));
			return null;
			},async values=>{await this.api('exports','POST',{process:this.me.process_id,type,format:values.formato,filters:this.filters});
			this.notice('Exportación solicitada. Descárgala desde Servicios y notificaciones.');
			},'Solicitar exportación');
	},
	async open(uuid) {
		this.selected=uuid;
		const row=await this.api('resources/'+this.entity+'/'+uuid);
		this.current=row;
		this.node('resources-detail-title').textContent=[row.folio,row.nombre || row.concepto || this.definition.title].filter(Boolean).join(' · ');
		const dialog=this.node('resources-detail');
		if(!dialog.open)dialog.showModal();
		const tabs=this.node('resources-detail-tabs');
		tabs.replaceChildren();
		['detalle','acciones','historial','evidencias'].forEach((key,index)=>{const button=this.button(({detalle:'Detalle',acciones:'Acciones',historial:'Historial',evidencias:'Evidencias'})[key],()=>select(key));
			button.setAttribute('role','tab');
			button.setAttribute('aria-selected',String(index===0));
			button.classList.toggle('active',index===0);
			button.dataset.key=key;
			tabs.append(button);
			});
		const select=async key=>{Array.from(tabs.children).forEach(button=>(button.setAttribute('aria-selected',String(button.dataset.key===key)),button.classList.toggle('active',button.dataset.key===key)));
			const target=this.node('resources-detail-content');
			target.replaceChildren();
			if(key==='detalle')await window.SicratResourceDetails.summary(this,row,target);
			if(key==='acciones')await window.SicratResourceDetails.actions(this,row,target);
			if(key==='historial'){await this.pager(target,'resources/'+this.entity+'/'+uuid+'/history',[['Fecha','fecha'],['Actividad',entry=>this.label(entry.accion)],['Responsable','actor'],['Motivo','motivo']]).load();
				}if(key==='evidencias')await window.SicratResourceDetails.evidence(this,row,target);
			};
		this.detailSelect=select;
		tabs.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
			event.preventDefault();
			const buttons=Array.from(tabs.children);
			let index=buttons.indexOf(document.activeElement);
			index=event.key==='Home' ? 0 : event.key==='End' ? buttons.length-1 : (index+(event.key==='ArrowRight' ? 1 : -1)+buttons.length)%buttons.length;
			buttons[index].focus();
			buttons[index].click();
			});
		await select('detalle');
	}
});
// El picker común utiliza este aviso al quitar una opción seleccionada.
window.SicratOperations.notice=message=>window.SicratResources.notice(message);
