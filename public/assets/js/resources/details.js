'use strict';

window.SicratResourceDetails={
	async summary(app,row,target) {
		const details={};
		const keys={nombre:'Nombre',folio:'Folio',concepto:'Concepto',descripcion:'Descripción',estado_calculado:'Estado vigente',estado:'Estado',tipo:'Tipo',categoria_nombre:'Categoría',beneficiario_nombre:'Beneficiario',responsable_nombre:'Responsable',donante_nombre:'Donante',cuenta_nombre:'Cuenta',metodo_nombre:'Método de pago',proveedor:'Proveedor',fecha_operacion:'Fecha de operación',inicio:'Inicio del periodo',fin:'Fin del periodo',vence:'Vencimiento',entregado_en:'Entregado',unidad:'Unidad',codigo:'Código administrativo',ubicacion:'Ubicación',cantidad:'Cantidad',minimo:'Existencia mínima',referencia:'Referencia administrativa',motivo:'Motivo',motivo_resolucion:'Motivo de la revisión',donante_externo:'Donante externo'};
		Object.entries(keys).forEach(([key,label])=>{if(row[key]!=null && row[key]!=='')details[label]=['estado','estado_calculado','tipo'].includes(key) ? app.label(row[key]) : row[key];
			});
		Object.entries({monto:'Importe',solicitado:'Solicitado',autorizado:'Autorizado',pagado:'Pagado',saldo:'Saldo pendiente',presupuestado:'Presupuestado',comprometido:'Comprometido',disponible:'Disponible',comprobado:'Comprobado',devuelto:'Devuelto',saldo_comprobar:'Por comprobar',valor:'Valor administrativo',saldo_caja:'Saldo de caja'}).forEach(([key,label])=>{if(row[key]!=null)details[label]=app.moneyText(row[key]);
			});
		app.description(target,details);
		if(row.elecciones?.length){const list=app.text('div','','mt-4');
			target.append(app.text('h6','Elecciones relacionadas','mt-4'),list);
			app.table(list,app.entity==='gastos' ? [['Elección','nombre'],['Monto',entry=>app.moneyText(entry.monto)],['Porcentaje',entry=>entry.porcentaje ? entry.porcentaje+' %' : 'Por monto']] : [['Elección','nombre']],row.elecciones);
			}
		if(row.revisiones?.length){const list=app.text('div','','mt-4');
			target.append(app.text('h6','Revisiones del presupuesto','mt-4'),list);
			app.table(list,[['Versión','version'],['Tipo',entry=>app.label(entry.tipo)],['Antes',entry=>app.moneyText(entry.antes)],['Después',entry=>app.moneyText(entry.despues)],['Motivo','motivo'],['Responsable','actor'],['Fecha','fecha']],row.revisiones);
			}
		if(row.pagos?.length){const list=app.text('div','','mt-4');
			target.append(app.text('h6','Pagos y reversiones','mt-4'),list);
			app.table(list,[['Pago','folio'],['Importe',entry=>app.moneyText(entry.monto)],['Operación',entry=>Number(entry.signo)===-1 ? 'Reversión' : 'Pago'],['Fecha','fecha_operacion']],row.pagos,(cell,payment)=>{if(app.enabled && app.can('finanzas.gastos.pagar') && Number(payment.signo)===1)cell.append(app.button('Revertir pago',()=>this.reason(app,'Revertir pago','pagos/'+payment.uuid+'/reverse')));
				});
			}
		if(row.autorizacion){target.append(app.text('h6','Autorización conservada','mt-4'));
			app.description(target,{'Modo':({sin_aprobacion:'Sin aprobación',todos:'Todos los gastos',por_monto:'Por monto'})[row.autorizacion.configuracion?.modo],'Versión de política':row.autorizacion.version_politica || 'Sin política configurada','Importe revisado':app.moneyText(row.autorizacion.importe),'Desde':row.autorizacion.rango?.desde,'Hasta':row.autorizacion.rango?.hasta || 'Sin límite superior'});
			}
		if(row.aprobacion_uuid)target.append(app.button('Consultar autorización y pasos',()=>this.approval(app,row.aprobacion_uuid)));
		if(row.gasto_uuid)target.append(app.button('Ver gasto autorizado del reembolso',async()=>{await app.navigate('gastos');
			await app.open(row.gasto_uuid);
			}));
		if(app.entity==='aportaciones' && row.tipo==='especie')target.append(app.text('p',row.movimiento ? 'Donación recibida físicamente en inventario.' : 'El inventario se actualizará después de validar y confirmar la recepción correspondiente.','op-small-note mt-4'));
	},
	async reason(app,title,path,fields = null) {
		await app.modal(title,async form=>{if(fields)await fields(form);
			app.field(form,'motivo','Motivo','textarea','',true);
			return null;
			},values=>app.api('resources/'+path,'POST',values));
	},
	async pay(app,row,action = 'pay') {
		await app.modal(action==='deliver' ? 'Entregar anticipo autorizado' : 'Registrar pago',async form=>{if(action!=='deliver')app.money(form,'monto','Importe a pagar',row.saldo || row.autorizado || '');
			app.field(form,'fecha_operacion','Fecha','date',new Date().toISOString().slice(0,10),true);
			const permission=action==='deliver' ? 'finanzas.anticipos.crear' : 'finanzas.gastos.pagar';
			const account=await app.optionPicker(form,'Cuenta de origen','cuentas',permission,false,[],true);
			const method=await app.optionPicker(form,'Método de pago','metodos',permission,false,[],true);
			app.field(form,'referencia','Referencia del pago','text');
			app.field(form,'motivo','Motivo','textarea','Pago autorizado',true);
			return ()=>({cuenta:account.values(),metodo:method.values()});
			},values=>app.api('resources/'+app.entity+'/'+row.uuid+'/'+action,'POST',values));
	},
	async actions(app,row,target) {
		if(!app.enabled){target.append(app.text('p','Modo de consulta: habilita el módulo autorizado desde Configuración electoral para registrar operaciones.'));
			return;
			}
		const entity=app.entity;
		const action=(label,path,fields)=>target.append(app.button(label,()=>this.reason(app,label,entity+'/'+row.uuid+'/'+path,fields)));
		if(['categorias','categorias_inventario','metodos','cuentas','politicas','articulos','almacenes'].includes(entity) && app.can(app.definition.write))target.append(app.button('Editar configuración',()=>window.SicratResourceForms.create(app,row)));
		if(entity==='presupuestos' && app.can('finanzas.presupuestos.administrar')) {
			action('Revisar presupuesto','revise',form=>app.money(form,'monto','Nuevo presupuestado',row.monto));
			target.append(app.button('Transferir presupuesto',()=>app.modal('Transferir presupuesto disponible',async form=>{const dest=await app.optionPicker(form,'Presupuesto destino','presupuestos','finanzas.presupuestos.administrar',false,[],true);
				app.money(form,'monto','Importe a transferir');
				app.field(form,'motivo','Motivo','textarea','',true);
				return ()=>({destino:dest.values()});
				},values=>app.api('resources/presupuestos/'+row.uuid+'/transfer','POST',values))));
		}
		if(entity==='gastos') {
			if(app.can('finanzas.gastos.editar') && row.estado!=='cancelado' && !(row.pagos?.length))target.append(app.button('Editar gasto y revisar autorización',()=>window.SicratResourceForms.create(app,row)));
			if(app.can('finanzas.gastos.crear') && ['registrado','comprometido','correccion'].includes(row.estado)){if(!Number(row.comprometido))target.append(app.button('Comprometer presupuesto',async()=>{await app.api('resources/gastos/'+row.uuid+'/commit','POST',{});
					await app.refresh();
					}));
				target.append(app.button('Solicitar autorización',async()=>{await app.api('resources/gastos/'+row.uuid+'/submit','POST',{});
					await app.refresh();
					}));
				}
			if(app.can('finanzas.gastos.pagar') && ['aprobado','parcial'].includes(row.estado))target.append(app.button('Registrar pago',()=>this.pay(app,row),true));
			if(app.can('finanzas.gastos.cancelar') && row.estado!=='cancelado')action('Cancelar gasto','cancel');
		}
		if(entity==='pagos' && app.can('finanzas.gastos.pagar') && Number(row.signo)===1)action('Revertir pago','reverse');
		if(entity==='anticipos') {
			if(row.estado==='aprobado' && !row.entregado_en && app.can('finanzas.anticipos.crear'))target.append(app.button('Entregar anticipo',()=>this.pay(app,row,'deliver'),true));
			if(row.entregado_en && row.estado!=='cancelado' && app.can('finanzas.anticipos.comprobar')) {
				target.append(app.button('Comprobar con gasto autorizado',()=>app.modal('Comprobar anticipo',async form=>{const expense=await app.optionPicker(form,'Gasto autorizado del beneficiario','gastos','finanzas.anticipos.comprobar',false,[],true);
					app.money(form,'monto','Importe a comprobar');
					app.field(form,'motivo','Motivo','textarea','Comprobación del anticipo',true);
					return ()=>({gasto:expense.values()});
					},values=>app.api('resources/anticipos/'+row.uuid+'/justify','POST',values))));
				action('Devolver saldo del anticipo','return',form=>app.money(form,'monto','Importe devuelto'));
			}if(app.can('finanzas.anticipos.crear') && row.estado!=='cancelado')action('Cancelar anticipo','cancel');
		}
		if(entity==='reembolsos') {
			if(row.estado==='solicitado' && app.can('finanzas.reembolsos.aprobar') && Number(row.creado_por)!==Number(app.me.id))action('Revisar importe del reembolso','review',form=>app.money(form,'autorizado','Importe autorizado (cero para rechazar)',row.solicitado));
			if(['aprobado','parcial'].includes(row.estado) && app.can('finanzas.gastos.pagar'))target.append(app.button('Pagar reembolso',()=>this.pay(app,row),true));
		}
		if(entity==='ingresos' && app.can('finanzas.ingresos.crear')) {if(row.estado==='prometido')target.append(app.button('Confirmar ingreso recibido',()=>app.modal('Registrar recepción del ingreso',async form=>{const account=await app.optionPicker(form,'Cuenta receptora','cuentas','finanzas.ingresos.crear',false,[],true);
				const method=await app.optionPicker(form,'Método','metodos','finanzas.ingresos.crear',false,[],true);
				app.field(form,'fecha_operacion','Fecha de recepción','date',new Date().toISOString().slice(0,10),true);
				return ()=>({cuenta:account.values(),metodo:method.values()});
				},values=>app.api('resources/ingresos/'+row.uuid+'/receive','POST',values))));
			if(row.estado!=='cancelado')action('Cancelar ingreso','cancel');
			}
		if(entity==='aportaciones') {if(app.can('finanzas.ingresos.crear')){if(row.tipo==='especie' && row.estado==='pendiente')action('Validar donación administrativamente','validate');
				if(row.estado!=='cancelado')action('Cancelar aportación','cancel');
				}if(row.tipo==='especie' && row.estado==='validada' && app.can('inventario.entradas'))action('Confirmar recepción física de donación','receive');
			}
		if(entity==='movimientos') {
			if(app.can('inventario.entradas') && ['entrega','consumo','prestamo'].includes(row.tipo))action('Registrar devolución al almacén','return',form=>app.money(form,'cantidad','Cantidad devuelta','',true,3));
			if(app.can('inventario.ajustar') && row.estado==='aplicado' && row.tipo!=='reversa')action('Revertir movimiento','reverse');
		}
		if(!target.children.length)target.append(app.text('p','No hay acciones disponibles para este estado y tus permisos.'));
	},
	async approval(app,uuid) {
		const approval=await app.api('approvals/'+uuid);
		await app.modal('Autorización financiera',async form=>{
			app.description(form,{'Estado':app.label(approval.estado),'Fecha de solicitud':approval.fecha,'Modo':({sin_aprobacion:'Sin aprobación',todos:'Todos los gastos',por_monto:'Por monto'})[approval.regla.configuracion?.modo],'Importe conservado':approval.regla.importe ? app.moneyText(approval.regla.importe) : null});
			const list=app.text('div','','ux-full');
			form.append(list);
			app.table(list,[['Paso','orden'],['Aprobador','aprobador_nombre'],['Estado',step=>app.label(step.estado)],['Motivo','motivo'],['Fecha','fecha']],approval.steps);
			const pending=approval.steps.find(step=>step.estado==='pendiente');
			const canResolve=app.enabled && approval.estado==='pendiente' && Number(pending?.aprobador)===Number(app.me.id) && Number(approval.solicitante)!==Number(app.me.id) && app.can('aprobaciones.resolver');
			if(!canResolve){form.append(app.text('p','Sólo el aprobador asignado puede resolver el siguiente paso.','ux-full op-small-note'));
				return ()=>({consultar:true});
				}
			app.field(form,'approved','Decisión','select','1',true,{'1':'Aprobar','0':'Rechazar'});
			app.field(form,'reason','Motivo de la decisión','textarea','',true);
			return ()=>({consultar:false});
		},async values=>{if(values.consultar)return;
			await app.api('approvals/'+uuid,'PUT',{approved:Number(values.approved),reason:values.reason});
			},'Continuar');
	},
	async evidence(app,row,target) {
		if(!app.can('archivos.ver')){target.append(app.text('p','No tienes permiso para consultar evidencias.'));
			return;
			}
		const list=app.text('div');
		target.append(list);
		await app.pager(list,'resources/'+app.entity+'/'+row.uuid+'/files',[['Archivo','nombre_original'],['Tipo',file=>file.mime_type.startsWith('image/') ? 'Imagen' : 'PDF administrativo']],(cell,file)=>{const link=app.text('a','Abrir archivo privado','btn');
			link.href=window.SicratPhase3.base+'/media/'+file.uuid;
			link.target='_blank';
			link.rel='noopener';
			cell.append(link);
			}).load();
		const writeChoices=({gastos:['finanzas.gastos.crear','finanzas.gastos.editar','finanzas.gastos.pagar'],anticipos:['finanzas.anticipos.crear','finanzas.anticipos.comprobar'],reembolsos:['finanzas.reembolsos.solicitar','finanzas.reembolsos.aprobar'],pagos:['finanzas.gastos.pagar','finanzas.anticipos.comprobar']})[app.entity] || [app.definition.write];
		if(app.enabled && app.can('archivos.subir') && writeChoices.some(permission=>permission && app.can(permission)) && !['cancelado','rechazado'].includes(row.estado)) {
			app.form(target,'Agregar evidencia privada',form=>{const field=app.field(form,'file','Imagen o PDF administrativo','file','',true);
				field.accept='image/jpeg,image/png,image/webp,application/pdf';
				},async(values,form)=>{
				const data=new FormData();
				data.append('file',form.elements.file.files[0]);
				const table=['articulos','almacenes','categorias_inventario','origenes','movimientos'].includes(app.entity) ? 'inventario_'+({categorias_inventario:'categorias'})[app.entity] : 'finanzas_'+app.entity;
				data.append('entity',app.module==='inventario' ? 'inventario_'+(app.entity==='categorias_inventario' ? 'categorias' : app.entity) : table);
				data.append('record',row.uuid);
				data.append('process',String(app.me.process_id));
				const response=await fetch(window.SicratPhase3.base+'/api/files',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:data});
				const result=await response.json();
				if(!response.ok)throw new Error(result.message);
				await app.detailSelect('evidencias');
				app.notice('Evidencia privada guardada.');
			},'Subir evidencia');
		}
	}
};
