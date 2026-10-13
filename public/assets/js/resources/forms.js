'use strict';

window.SicratResourceForms={
	async related(app,target,permission,row = null) {
		const group=app.section(target,'Relación operativa opcional','Selecciona una actividad existente. Cada módulo mantiene sus propios permisos.');
		const choices={'':row?.relacion_id ? 'Conservar relación vigente' : 'Sin relación'};
		Object.entries({eventos:['Evento','eventos'],tareas:['Tarea','tareas_metas'],peticiones:['Petición','peticiones_incidencias'],incidencias:['Incidencia','peticiones_incidencias'],transporte:['Logística de transporte','transporte']}).forEach(([key,[label,module]])=>{if(app.can(key+'.ver') && app.moduleEnabled(module))choices[key]=label;
			});
		const type=app.field(group,'relacion_entidad','Tipo de referencia','select','',false,choices);
		if(row?.relacion_id)app.check(group,'quitar_relacion','Quitar la relación vigente');
		const box=app.text('div','','ux-full');
		group.append(box);
		let picker=null;
		type.addEventListener('change',app.guard(async()=>{picker=null;
			box.replaceChildren();
			if(type.value)picker=await app.optionPicker(box,'Registro relacionado',type.value,permission,false,[],true);
			}));
		return ()=>type.value && picker ? {relacion_entidad:type.value,relacion:picker.values()} : {};
	},
	async allocation(app,target,row = null) {
		const section=app.section(target,'Distribución entre elecciones','Un gasto compartido conserva un solo registro. Los porcentajes deben sumar 100 o los montos deben sumar el total.');
		const shared=app.check(section,'compartir','Compartir entre elecciones',!!row?.elecciones?.length && row.elecciones.length>1);
		const body=app.text('div','','ux-full');
		section.append(body);
		body.hidden=!shared.checked;
		const mode=app.field(body,'distribucion','Distribuir por','select',row?.distribucion || 'porcentajes',true,{porcentajes:'Porcentajes',montos:'Montos exactos'});
		const list=app.text('div','','ux-full');
		body.append(list);
		const entries=[];
		const add=async initial=>{const box=app.text('div','','resource-allocation op-form-fields ux-section');
			list.append(box);
			const picker=await app.optionPicker(box,'Elección','elecciones',app.entity==='gastos' ? (row ? 'finanzas.gastos.editar' : 'finanzas.gastos.crear') : 'finanzas.presupuestos.administrar',false,initial ? [{uuid:initial.uuid,label:initial.nombre}] : [],true);
			const amount=app.money(box,'distribucion_valor_'+entries.length,'Monto o porcentaje',initial ? (mode.value==='porcentajes' ? initial.porcentaje || '100.00' : initial.monto) : '',true);
			const entry={box,picker,amount};
			entries.push(entry);
			box.append(app.button('Quitar elección',()=>{box.remove();
				entries.splice(entries.indexOf(entry),1);
				}));
			};
		body.append(app.button('Agregar elección',()=>add(null)));
		shared.addEventListener('change',()=>{body.hidden=!shared.checked;
			body.querySelectorAll('input,select').forEach(field=>field.disabled=!shared.checked);
			});
		if(row?.elecciones?.length>1)for(const election of row.elecciones)await add(election);
		else if(app.election)await add({uuid:app.election.uuid,nombre:app.election.nombre,porcentaje:'100.00',monto:row?.monto || ''});
		if(!shared.checked)body.querySelectorAll('input,select').forEach(field=>field.disabled=true);
		return ()=>{if(!shared.checked)return {};
			if(entries.length<1)throw new Error('Agrega elecciones para distribuir el gasto.');
			return {distribucion:mode.value,elecciones:entries.map(entry=>({proceso:entry.picker.values(),[mode.value==='porcentajes' ? 'porcentaje' : 'monto']:entry.amount.value}))};
			};
	},
	async policy(app,form,row = null) {
		app.field(form,'nombre','Nombre de la política','text',row?.nombre || 'Autorización de gastos',true);
		const scope=app.field(form,'ambito','Ámbito','select',row?.proceso===null ? 'organizacion' : 'eleccion',true,{eleccion:'Elección de trabajo',organizacion:'Toda la organización'});
		if(row)scope.disabled=true;
		const config=row?.configuracion || {modo:'sin_aprobacion',rangos:[]};
		const mode=app.field(form,'modo','Revisión requerida','select',config.modo,true,{sin_aprobacion:'Sin aprobación',todos:'Todos los gastos',por_monto:'Por monto'});
		const container=app.text('div','','ux-full');
		form.append(container);
		const ranges=[];
		const addRange=async initial=>{
			const box=app.section(container,'Rango '+(ranges.length+1),'Importes consecutivos desde cero; deja vacío el límite del último rango.');
			const from=app.money(box,'rango_desde_'+ranges.length,'Desde',initial?.desde || '0.00',true);
			const to=app.money(box,'rango_hasta_'+ranges.length,'Hasta',initial?.hasta || '',false);
			const stepsBox=app.text('div','','ux-full');
			box.append(stepsBox);
			const steps=[];
			const range={box,from,to,steps};
			ranges.push(range);
			const addStep=async definition=>{
				const step=app.text('div','','op-form-fields ux-section');
				stepsBox.append(step);
				step.append(app.text('h6','Paso '+(steps.length+1),'ux-full'));
				const type=app.field(step,'tipo_aprobador_'+steps.length,'Seleccionar por','select',definition?.tipo || 'usuario',true,{usuario:'Cuenta de usuario',rol:'Rol electoral',permiso:'Permiso específico'});
				const selector=app.text('div','','ux-full');
				step.append(selector);
				let chosen=null;
				const load=async()=>{selector.replaceChildren();
					chosen=null;
					if(type.value==='permiso')chosen=app.field(selector,'permiso_aprobador','Capacidad requerida','select',definition?.referencia || 'finanzas.gastos.aprobar',true,{'finanzas.gastos.aprobar':'Autorizar gastos','finanzas.anticipos.aprobar':'Autorizar anticipos','finanzas.reembolsos.aprobar':'Revisar reembolsos','inventario.ajustar':'Autorizar ajustes de inventario'});
					else chosen=await app.optionPicker(selector,type.value==='usuario' ? 'Aprobador' : 'Rol del aprobador',type.value==='usuario' ? 'usuarios' : 'roles','finanzas.administrar',false,definition ? [{uuid:definition.referencia,label:'Selección vigente'}] : [],true);
					};
				type.addEventListener('change',app.guard(load));
				await load();
				const record={step,values:()=>({tipo:type.value,referencia:type.value==='permiso' ? chosen.value : chosen.values()})};
				steps.push(record);
				step.append(app.button('Quitar paso',()=>{step.remove();
					steps.splice(steps.indexOf(record),1);
					}));
			};
			box.append(app.button('Agregar paso de autorización',()=>addStep(null)));
			for(const step of initial?.pasos || [{tipo:'usuario'}])await addStep(step.referencia ? step : null);
			box.append(app.button('Quitar rango',()=>{box.parentElement.remove();
				ranges.splice(ranges.indexOf(range),1);
				}));
		};
		const add=app.button('Agregar rango',()=>addRange(null));
		form.append(add);
		const toggle=()=>{container.hidden=mode.value==='sin_aprobacion';
			add.hidden=mode.value!=='por_monto';
			container.querySelectorAll('input,select').forEach(field=>field.disabled=container.hidden);
			};
		mode.addEventListener('change',app.guard(async()=>{if(mode.value!=='sin_aprobacion' && !ranges.length)await addRange(null);
			toggle();
			}));
		for(const range of config.rangos)await addRange(range);
		toggle();
		if(row)app.field(form,'motivo','Motivo de la revisión','textarea','',true);
		return ()=>({proceso:scope.value==='organizacion' ? 'organizacion' : app.election.uuid,modo:mode.value,rangos:mode.value==='sin_aprobacion' ? [] : ranges.map(range=>({desde:range.from.value,hasta:range.to.value || null,pasos:range.steps.map(step=>step.values())}))});
	},
	async expense(app,form,row = null) {
		const permission=row ? 'finanzas.gastos.editar' : 'finanzas.gastos.crear';
		app.field(form,'concepto','Concepto','text',row?.concepto || '',true);
		app.field(form,'tipo','Tipo de gasto','select',row?.tipo || 'ordinario',true,{ordinario:'Gasto ordinario',compra:'Compra inventariable',apoyo:'Apoyo fijo',viatico:'Viático fijo'});
		app.money(form,'monto','Importe total',row?.monto || '');
		app.field(form,'fecha_operacion','Fecha del gasto','date',row?.fecha_operacion || new Date().toISOString().slice(0,10),true);
		app.field(form,'proveedor','Proveedor externo','text',row?.proveedor || '');
		const category=await app.optionPicker(form,'Categoría','categorias',permission,false,row?.categoria_uuid ? [{uuid:row.categoria_uuid,label:row.categoria_nombre}] : [],true);
		const person=await app.optionPicker(form,'Beneficiario registrado','personas',permission,false,row?.beneficiario_uuid ? [{uuid:row.beneficiario_uuid,label:row.beneficiario_nombre}] : []);
		const budget=await app.optionPicker(form,'Presupuesto opcional','presupuestos',permission,false,row?.presupuesto_uuid ? [{uuid:row.presupuesto_uuid,label:row.presupuesto_nombre}] : []);
		app.field(form,'descripcion','Descripción','textarea',row?.descripcion || '');
		app.field(form,'referencia','Referencia administrativa','text',row?.referencia || '');
		const allocations=await this.allocation(app,form,row);
		const relation=await this.related(app,form,permission,row);
		if(row)app.field(form,'motivo','Motivo del cambio','textarea','',true);
		else{app.check(form,'comprometer','Comprometer presupuesto al registrar');
			app.check(form,'autorizar','Solicitar autorización al registrar');
			}
		return ()=>({categoria:category.values(),beneficiario:person.values(),presupuesto:budget.values(),...allocations(),...relation(),...(row ? {version:row.version,quitar_relacion:form.elements.quitar_relacion?.checked ? 1 : 0} : {comprometer:form.elements.comprometer.checked ? 1 : 0,autorizar:form.elements.autorizar.checked ? 1 : 0})});
	},
	async create(app,row = null) {
		const entity=app.entity;
		await app.modal((row ? 'Editar ' : 'Registrar ')+app.definition.title.toLowerCase(),async form=>{
			if(entity==='politicas')return this.policy(app,form,row);
			if(entity==='gastos')return this.expense(app,form,row);
			if(['categorias','categorias_inventario','metodos','cuentas','origenes','articulos','almacenes'].includes(entity)) {
				app.field(form,'nombre','Nombre','text',row?.nombre || '',true);
				if(entity!=='metodos')app.field(form,'descripcion','Descripción','textarea',row?.descripcion || '');
				app.field(form,'activo','Estado','select',String(row?.activo ?? 1),true,{'1':'Activo','0':'Inactivo'});
				if(entity==='origenes')app.field(form,'clase','Clase de movimiento','select',row?.clase || 'entrada',true,{entrada:'Entrada',salida:'Salida'});
				if(entity==='categorias')app.field(form,'tipo','Tipo','select',row?.tipo || 'gasto',true,{gasto:'Gasto',ingreso:'Ingreso'});
				if(entity==='metodos')app.field(form,'tipo','Método','select',row?.tipo || 'efectivo',true,{efectivo:'Efectivo',transferencia:'Transferencia',tarjeta:'Tarjeta',otro:'Otro'});
				let category=null,users=null;
				if(entity==='articulos'){app.field(form,'codigo','Código administrativo','text',row?.codigo || '',true);
					app.field(form,'unidad','Unidad de medida','text',row?.unidad || 'Pieza',true);
					app.money(form,'minimo','Existencia mínima general',row?.minimo || '0.000',true,3);
					app.money(form,'costo_referencia','Costo de referencia',row?.costo_referencia || '',false);
					category=await app.optionPicker(form,'Categoría de artículo','categorias_inventario','inventario.articulos',false,row?.categoria_uuid ? [{uuid:row.categoria_uuid,label:'Categoría vigente'}] : [],true);
					}
				if(entity==='almacenes'){app.field(form,'ubicacion','Ubicación','text',row?.ubicacion || '');
					app.check(form,'validar_mermas','Pérdidas y daños requieren validación',!!Number(row?.validar_mermas));
					users=await app.optionPicker(form,'Responsables autorizados','usuarios','inventario.administrar',true,row?.responsables || []);
					}
				if(row)app.field(form,'motivo','Motivo del cambio','textarea','',true);
				return ()=>({...(category ? {categoria:category.values()} : {}),...(users ? {responsables:users.values(),validar_mermas:form.elements.validar_mermas.checked ? 1 : 0} : {}),...(row ? {version:row.version} : {})});
			}
			if(entity==='presupuestos'){const scope=app.field(form,'registro_ambito','Ámbito del presupuesto','select','eleccion',true,{eleccion:'Elección de trabajo',organizacion:'Organización'});
				app.field(form,'nombre','Nombre del presupuesto','text','',true);
				app.money(form,'monto','Presupuestado');
				app.field(form,'inicio','Inicio del periodo','date',new Date().getFullYear()+'-01-01',true);
				app.field(form,'fin','Fin del periodo','date',new Date().getFullYear()+'-12-31',true);
				const category=await app.optionPicker(form,'Categoría opcional','categorias','finanzas.presupuestos.administrar');
				const event=app.can('eventos.ver') && app.moduleEnabled('eventos') ? await app.optionPicker(form,'Evento opcional','eventos','finanzas.presupuestos.administrar') : null;
				const elections=await app.optionPicker(form,'Elecciones (vacío para presupuesto general)','elecciones','finanzas.presupuestos.administrar',true,app.election ? [{uuid:app.election.uuid,label:app.election.nombre}] : [],false);
				app.field(form,'motivo','Motivo','textarea','Presupuesto inicial',true);
				return ()=>({proceso:scope.value==='organizacion' ? 'organizacion' : app.election.uuid,categoria:category.values(),elecciones:elections.values(),...(event ? {evento:event.values()} : {})});
				}
			if(entity==='anticipos' || entity==='reembolsos'){const permission=entity==='anticipos' ? 'finanzas.anticipos.crear' : 'finanzas.reembolsos.solicitar';
				app.field(form,'concepto','Finalidad','text','',true);
				app.money(form,entity==='anticipos' ? 'monto' : 'solicitado',entity==='anticipos' ? 'Anticipo solicitado' : 'Importe solicitado');
				if(entity==='anticipos')app.field(form,'vence','Fecha límite de comprobación','date','',true);
				const person=await app.optionPicker(form,'Beneficiario','personas',permission,false,[],true);
				let responsible=null,category=null,budget=null;
				if(entity==='anticipos')responsible=await app.optionPicker(form,'Responsable','personas',permission);
				else{category=await app.optionPicker(form,'Categoría','categorias',permission,false,[],true);
					budget=await app.optionPicker(form,'Presupuesto opcional','presupuestos',permission);
					}const relation=await this.related(app,form,permission);
				return ()=>({beneficiario:person.values(),...(responsible ? {responsable:responsible.values() || person.values()} : {}),...(category ? {categoria:category.values(),presupuesto:budget.values()} : {}),...relation()});
				}
			if(entity==='ingresos'){app.field(form,'concepto','Concepto','text','',true);
				app.field(form,'fuente','Fuente del ingreso','text','',true);
				app.money(form,'monto','Importe');
				app.field(form,'estado','Estado','select','prometido',true,{prometido:'Prometido: no aumenta caja',recibido:'Recibido'});
				app.field(form,'fecha_operacion','Fecha','date',new Date().toISOString().slice(0,10),true);
				const category=await app.optionPicker(form,'Categoría de ingreso','categorias','finanzas.ingresos.crear',false,[],true);
				const account=await app.optionPicker(form,'Cuenta receptora','cuentas','finanzas.ingresos.crear');
				const method=await app.optionPicker(form,'Método','metodos','finanzas.ingresos.crear');
				app.field(form,'notas','Notas','textarea');
				return ()=>({categoria:category.values(),cuenta:account.values(),metodo:method.values()});
				}
			if(entity==='transferencias'){app.money(form,'monto','Importe a transferir');
				const origin=await app.optionPicker(form,'Cuenta de origen','cuentas','finanzas.administrar',false,[],true);
				const dest=await app.optionPicker(form,'Cuenta de destino','cuentas','finanzas.administrar',false,[],true);
				app.field(form,'motivo','Motivo','textarea','',true);
				return ()=>({origen:origin.values(),destino:dest.values()});
				}
			if(entity==='movimientos')return this.movement(app,form);
			if(entity==='aportaciones')return this.contribution(app,form);
			throw new Error('Formulario no disponible.');
		},values=>app.api('resources/'+entity+(row ? '/'+row.uuid : ''),row ? 'PUT' : 'POST',values));
	},
	async movement(app,form) {
		const choices={};
		if(app.can('inventario.entradas'))Object.assign(choices,{entrada:'Entrada administrativa',compra:'Recepción de compra',donacion:'Donación recibida',otro_entrada:'Otra entrada configurada'});
		if(app.can('inventario.salidas'))Object.assign(choices,{entrega:'Entrega',consumo:'Consumo',prestamo:'Préstamo',perdida:'Pérdida',dano:'Daño',otro_salida:'Otra salida configurada'});
		if(app.can('inventario.ajustar'))Object.assign(choices,{ajuste_entrada:'Ajuste positivo',ajuste_salida:'Ajuste negativo'});
		const type=app.field(form,'tipo','Tipo de movimiento','select',Object.keys(choices)[0],true,choices);
		const references=app.text('div','','op-form-fields ux-full');
		form.append(references);
		let item=null,warehouse=null;
		const loadReferences=async()=>{references.replaceChildren();
			const permission=type.value.startsWith('ajuste') ? 'inventario.ajustar' : ['entrada','compra','donacion','otro_entrada'].includes(type.value) ? 'inventario.entradas' : 'inventario.salidas';
			item=await app.optionPicker(references,'Artículo','articulos',permission,false,[],true);
			warehouse=await app.optionPicker(references,'Almacén','almacenes',permission,false,[],true);
			};
		type.addEventListener('change',app.guard(loadReferences));
		await loadReferences();
		app.money(form,'cantidad','Cantidad','',true,3);
		app.money(form,'costo_unitario','Costo unitario de entrada','0.00',false);
		app.field(form,'fecha_operacion','Fecha','date',new Date().toISOString().slice(0,10),true);
		app.field(form,'motivo','Motivo','textarea','',true);
		const origin=await app.optionPicker(form,'Origen configurable','origenes','inventario.ver');
		const extra=app.section(form,'Responsabilidades y referencias opcionales');
		const person=await app.optionPicker(extra,'Responsable de la entrega','personas','inventario.ver');
		let expense=null,approver=null;
		if(app.can('finanzas.ver'))expense=await app.optionPicker(extra,'Compra relacionada','gastos','finanzas.ver');
		if(app.can('inventario.salidas'))approver=await app.optionPicker(extra,'Aprobador de pérdida o daño','usuarios','inventario.salidas');
		const related=await this.related(app,form,'inventario.ver');
		return ()=>({origen_catalogo:origin.values(),articulo:item.values(),almacen:warehouse.values(),responsable:person.values(),...(expense && type.value==='compra' ? {gasto:expense.values()} : {}),...(approver ? {aprobador:approver.values()} : {}),...related()});
	},
	async contribution(app,form) {
		app.field(form,'concepto','Concepto','text','',true);
		const type=app.field(form,'tipo','Tipo de aportación','select','monetaria',true,{monetaria:'Monetaria',especie:'En especie'});
		app.field(form,'donante_externo','Donante externo','text');
		const donor=await app.optionPicker(form,'Donante registrado','personas','finanzas.ingresos.crear');
		const section=app.text('div','','op-form-fields ux-full');
		form.append(section);
		let dto=null;
		const load=async()=>{section.replaceChildren();
			if(type.value==='monetaria'){app.money(section,'monto','Importe');
				app.field(section,'estado','Estado','select','prometido',true,{prometido:'Prometido',recibido:'Recibido'});
				const category=await app.optionPicker(section,'Categoría de ingreso','categorias','finanzas.ingresos.crear',false,[],true);
				const account=await app.optionPicker(section,'Cuenta','cuentas','finanzas.ingresos.crear');
				const method=await app.optionPicker(section,'Método','metodos','finanzas.ingresos.crear');
				dto=()=>({categoria:category.values(),cuenta:account.values(),metodo:method.values()});
				}else{const item=await app.optionPicker(section,'Artículo','articulos','inventario.entradas',false,[],true);
				const warehouse=await app.optionPicker(section,'Almacén de recepción','almacenes','inventario.entradas',false,[],true);
				app.money(section,'cantidad','Cantidad','',true,3);
				app.money(section,'costo_unitario','Valor unitario administrativo','0.00',true);
				app.field(section,'recepcion','Registro en inventario','select','posterior',true,{posterior:'Después de confirmar recepción física',directa:'Al validar administrativamente la donación'});
				dto=()=>({articulo:item.values(),almacen:warehouse.values()});
				}};
		type.addEventListener('change',app.guard(load));
		await load();
		return ()=>({donante:donor.values(),...dto()});
	},
	async transferStock(app) { await app.modal('Transferir entre almacenes',async form=>{const item=await app.optionPicker(form,'Artículo','articulos','inventario.transferir',false,[],true);
			const origin=await app.optionPicker(form,'Almacén de origen','almacenes','inventario.transferir',false,[],true);
			const dest=await app.optionPicker(form,'Almacén de destino','almacenes','inventario.transferir',false,[],true);
			app.money(form,'cantidad','Cantidad','',true,3);
			app.field(form,'motivo','Motivo','textarea','',true);
			return ()=>({articulo:item.values(),origen:origin.values(),destino:dest.values()});
			},data=>app.api('resources/stock-transfers','POST',data));
		},
	async minimum(app) { await app.modal('Configurar mínimo por almacén',async form=>{const item=await app.optionPicker(form,'Artículo','articulos','inventario.articulos',false,[],true);
			const warehouse=await app.optionPicker(form,'Almacén','almacenes','inventario.articulos',false,[],true);
			app.money(form,'cantidad','Existencia mínima','',true,3);
			app.field(form,'motivo','Motivo','textarea','',true);
			return ()=>({articulo:item.values(),almacen:warehouse.values()});
			},data=>app.api('resources/stock-minimum','PUT',data));
		}
};
