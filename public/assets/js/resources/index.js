'use strict';

function InitializeValues(url) {
	window.homeURL=(url || '').replace(/\/$/,'');
	window.SicratPhase3.base=window.homeURL;
	const app=window.SicratResources;
	app.module=app.node('resources-root').dataset.module;
	window.SicratPhase3.closeButtons();
	if(!app.node('resources-tabs'))return;
	document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('close',()=>dialog.querySelector('[data-resource-message]')?.remove()));
	const definitions={
		resumen:{title:'Resumen',read:app.module==='finanzas' ? 'finanzas.ver' : 'inventario.ver'},
		presupuestos:{title:'Presupuestos',read:'finanzas.presupuestos.ver',write:'finanzas.presupuestos.administrar',columns:[['Presupuesto',row=>row.folio+' · '+row.nombre],['Presupuestado',row=>app.moneyText(row.presupuestado)],['Comprometido',row=>app.moneyText(row.comprometido)],['Pagado',row=>app.moneyText(row.pagado)],['Disponible',row=>app.moneyText(row.disponible)]]},
		gastos:{title:'Gastos',read:'finanzas.ver',write:'finanzas.gastos.crear',columns:[['Gasto',row=>row.folio+' · '+row.concepto],['Estado',row=>window.SicratUI.badge(app.label(row.estado),['aprobado','pagado','recibido','recibida','aplicado'].includes(row.estado))],['Monto',row=>app.moneyText(row.monto)],['Pagado',row=>app.moneyText(row.pagado)],['Saldo',row=>app.moneyText(row.saldo)]]},
		anticipos:{title:'Anticipos',read:'finanzas.ver',write:'finanzas.anticipos.crear',columns:[['Anticipo',row=>row.folio+' · '+row.concepto],['Beneficiario','beneficiario_nombre'],['Estado',row=>app.label(row.estado_calculado)],['Vencimiento','vence'],['Por comprobar',row=>app.moneyText(row.saldo_comprobar)]]},
		reembolsos:{title:'Reembolsos',read:'finanzas.ver',write:'finanzas.reembolsos.solicitar',columns:[['Reembolso',row=>row.folio+' · '+row.concepto],['Estado',row=>window.SicratUI.badge(app.label(row.estado),['aprobado','pagado','recibido','recibida','aplicado'].includes(row.estado))],['Solicitado',row=>app.moneyText(row.solicitado)],['Autorizado',row=>row.autorizado===null ? 'Pendiente' : app.moneyText(row.autorizado)]]},
		ingresos:{title:'Ingresos',read:'finanzas.ingresos.ver',write:'finanzas.ingresos.crear',columns:[['Ingreso',row=>row.folio+' · '+row.concepto],['Categoría','categoria_nombre'],['Estado',row=>window.SicratUI.badge(app.label(row.estado),['aprobado','pagado','recibido','recibida','aplicado'].includes(row.estado))],['Monto',row=>app.moneyText(row.monto)]]},
		aportaciones:{title:'Aportaciones',read:'finanzas.ingresos.ver',write:'finanzas.ingresos.crear',columns:[['Aportación',row=>row.folio+' · '+row.concepto],['Tipo',row=>app.label(row.tipo)],['Estado',row=>window.SicratUI.badge(app.label(row.estado),['aprobado','pagado','recibido','recibida','aplicado'].includes(row.estado))]]},
		pagos:{title:'Pagos',read:'finanzas.ver',columns:[['Pago','folio'],['Gasto','gasto_folio'],['Fecha','fecha_operacion'],['Monto',row=>app.moneyText(row.monto)],['Operación',row=>Number(row.signo)===-1 ? 'Reversión' : 'Pago']]},
		flujo:{title:'Flujo de caja',read:'finanzas.ver'},
		categorias:{title:'Categorías financieras',read:'finanzas.administrar',write:'finanzas.administrar',columns:[['Categoría','nombre'],['Tipo',row=>app.label(row.tipo)],['Estado',row=>Number(row.activo) ? 'Activa' : 'Inactiva']]},
		cuentas:{title:'Cuentas y fondos',read:'finanzas.administrar',write:'finanzas.administrar',columns:[['Cuenta','nombre'],['Saldo de caja',row=>app.moneyText(row.saldo)],['Estado',row=>Number(row.activo) ? 'Activa' : 'Inactiva']]},
		metodos:{title:'Métodos de pago',read:'finanzas.administrar',write:'finanzas.administrar',columns:[['Método','nombre'],['Tipo',row=>app.label(row.tipo)],['Estado',row=>Number(row.activo) ? 'Activo' : 'Inactivo']]},
		politicas:{title:'Políticas de autorización',read:'finanzas.administrar',write:'finanzas.administrar',columns:[['Política','nombre'],['Ámbito',row=>row.proceso===null ? 'Organización' : 'Elección'],['Modo',row=>({sin_aprobacion:'Sin aprobación',todos:'Todos los gastos',por_monto:'Por monto'})[row.configuracion.modo]],['Versión','version']]},
		transferencias:{title:'Transferencias de fondos',read:'finanzas.administrar',write:'finanzas.administrar',columns:[['Transferencia','folio'],['Monto',row=>app.moneyText(row.monto)],['Motivo','motivo']]},
		origenes:{title:'Orígenes de movimiento',read:'inventario.administrar',write:'inventario.administrar',columns:[['Origen','nombre'],['Clase',row=>app.label(row.clase)],['Estado',row=>Number(row.activo) ? 'Activo' : 'Inactivo']]},
		articulos:{title:'Artículos',read:'inventario.ver',write:'inventario.articulos',columns:[['Artículo','nombre'],['Código','codigo'],['Categoría','categoria_nombre'],['Unidad','unidad'],['Mínimo','minimo']]},
		existencias:{title:'Existencias',read:'inventario.ver',columns:[['Artículo','articulo'],['Almacén','almacen'],['Existencia','cantidad'],['Unidad','unidad'],['Mínimo','minimo'],['Estado',row=>row.bajo_minimo ? 'Bajo mínimo' : 'Disponible']]},
		movimientos:{title:'Movimientos',read:'inventario.ver',write:'inventario.entradas',columns:[['Folio','folio'],['Artículo','articulo_nombre'],['Almacén','almacen_nombre'],['Tipo',row=>app.label(row.tipo)],['Cantidad','cantidad'],['Estado',row=>window.SicratUI.badge(app.label(row.estado),['aprobado','pagado','recibido','recibida','aplicado'].includes(row.estado))]]},
		almacenes:{title:'Almacenes',read:'inventario.ver',write:'inventario.administrar',columns:[['Almacén','nombre'],['Ubicación','ubicacion'],['Estado',row=>Number(row.activo) ? 'Activo' : 'Inactivo']]},
		categorias_inventario:{title:'Categorías de artículos',read:'inventario.administrar',write:'inventario.administrar',columns:[['Categoría','nombre'],['Estado',row=>Number(row.activo) ? 'Activa' : 'Inactiva']]}
	};
	const configuration=['categorias','cuentas','metodos','politicas','transferencias','categorias_inventario','origenes'];
	const navigate=async entity=>{
		app.entity=entity;
		app.definition=definitions[entity];
		app.filters={};
		app.selected=null;
		app.list=null;
		app.node('resources-content').replaceChildren();
		app.node('resources-title').textContent=app.definition.title;
		document.querySelectorAll('#resources-tabs button,#resources-config-tabs button').forEach(button=>{button.setAttribute('aria-selected',String(button.dataset.entity===entity));
			button.classList.toggle('active',button.dataset.entity===entity);
			});
		const configDialog=app.node('resources-config-dialog'),panel=app.node('resources-primary-panel');
		panel.hidden=false;
		if(configuration.includes(entity)){configDialog.append(panel);if(!configDialog.open)configDialog.showModal();}
		else{app.primaryEntity=entity;app.node('resources-primary-anchor').after(panel);if(configDialog.open)configDialog.close();}
		app.node('resources-new').hidden=!app.enabled || !app.definition.write || !(app.entity==='movimientos' ? ['inventario.entradas','inventario.salidas','inventario.ajustar'].some(permission=>app.can(permission)) : app.can(app.definition.write));
		app.node('resources-new').textContent=({gastos:'Nuevo gasto',presupuestos:'Nuevo presupuesto',anticipos:'Solicitar anticipo',reembolsos:'Solicitar reembolso',ingresos:'Registrar ingreso',movimientos:'Registrar movimiento'})[entity] || 'Nuevo registro';
		app.node('resources-export').hidden=!app.can('exportaciones.generar') || ['resumen','categorias','cuentas','metodos','politicas','transferencias','categorias_inventario','origenes'].includes(entity);
		app.node('resources-filters').hidden=['resumen','flujo'].includes(entity);app.node('resources-filter-panel').hidden=['resumen','flujo'].includes(entity);
		const states=app.node('resources-filters').elements.estado;
		states.replaceChildren();
		window.SicratPhase3.option(states,'','Todos');
		const statusList=['registrado','comprometido','validacion','aprobado','parcial','pagado','cancelado','correccion','pendiente','comprobado','vencido','solicitado','rechazado','prometido','recibido','recibida','validada','aplicado'];
		if(['categorias','cuentas','metodos','articulos','almacenes','categorias_inventario','origenes'].includes(entity)){window.SicratPhase3.option(states,'1','Activo');
			window.SicratPhase3.option(states,'0','Inactivo');
			}else if(!['existencias','politicas','flujo','resumen'].includes(entity))statusList.forEach(value=>window.SicratPhase3.option(states,value,app.label(value)));
		states.parentElement.hidden=['politicas','existencias'].includes(entity);
		const filterForm=app.node('resources-filters');
		const typeFilter=filterForm.elements.tipo;
		typeFilter.replaceChildren();
		window.SicratPhase3.option(typeFilter,'','Todos');
		const types=entity==='movimientos' ? ['compra','donacion','entrada','entrega','consumo','prestamo','perdida','dano','devolucion','transferencia_in','transferencia_out','ajuste_entrada','ajuste_salida','otro_entrada','otro_salida','reversa'] : entity==='gastos' ? ['ordinario','compra','apoyo','viatico','reembolso'] : [];
		types.forEach(value=>window.SicratPhase3.option(typeFilter,value,app.label(value)));
		typeFilter.parentElement.hidden=!types.length;
		typeFilter.disabled=!types.length;
		const filterDetails=app.node('resources-extra-filters');
		filterDetails.replaceChildren();
		const selectors=[];
		if(['gastos','presupuestos','ingresos','reembolsos','articulos'].includes(entity))selectors.push(['Categoría',entity==='articulos' ? 'categorias_inventario' : 'categorias','categoria']);
		if(entity==='movimientos')selectors.push(['Artículo','articulos','articulo'],['Almacén','almacenes','almacen']);
		const pickers=[];
		for(const [label,type,key] of selectors)pickers.push([key,await app.optionPicker(filterDetails,label,type,app.definition.read)]);
		filterDetails.hidden=!selectors.length;
		app.filterDto=()=>Object.fromEntries(pickers.map(([key,picker])=>[key,picker.values()]));
		filterForm.elements.bajo_minimo.parentElement.hidden=entity!=='existencias';
		filterForm.elements.bajo_minimo.disabled=entity!=='existencias';
		app.node('resources-help').textContent=entity==='gastos' ? 'Registrar, comprometer, autorizar y pagar son pasos distintos. Un gasto compartido conserva un solo monto total.' : entity==='anticipos' ? 'La entrega afecta caja. Los gastos autorizados comprueban el anticipo sin otra salida de dinero.' : entity==='movimientos' ? 'Las existencias se calculan desde movimientos. Las salidas requieren disponibilidad.' : entity==='politicas' ? 'La configuración vigente se guarda como instantánea al solicitar autorización; no cambia solicitudes anteriores.' : 'Información según tus permisos y responsabilidades.';
		if(entity==='resumen' || entity==='flujo') {
			const summary=entity==='resumen' ? await app.api('resources/summary?module='+app.module) : await app.api('resources/cash-flow');
			const cards=entity==='resumen' ? summary.cards : Object.entries(summary).filter(([key])=>key!=='moneda').map(([key,value])=>({label:app.label(key),value:app.moneyText(value)}));
			const grid=app.text('div','','ux-module-grid');
			cards.forEach(item=>{const card=app.text('div','','card p-5 ux-summary-card');const body=app.text('div');card.append(window.SicratUI.icon(app.module==='finanzas' ? 'coins' : 'package'),body);
				body.append(app.text('h6',item.label),app.text('p',item.monetary ? app.moneyText(item.value) : item.value,'text-xl font-semibold mt-3'));
				grid.append(card);
				});
			app.node('resources-content').append(grid);
		}else{app.list=app.pager(app.node('resources-content'),()=> 'resources/'+(entity==='existencias' ? 'stocks' : entity)+'?'+new URLSearchParams(app.filters),app.definition.columns,entity==='existencias' ? null : (cell,row)=>cell.append(app.button('Ver detalle',()=>app.open(row.uuid))));
			await app.list.load();
			}
		if(entity==='movimientos' && app.enabled && app.can('inventario.transferir'))app.node('resources-content').append(app.button('Transferir entre almacenes',()=>window.SicratResourceForms.transferStock(app)));
		if(entity==='existencias' && app.enabled && app.can('inventario.articulos'))app.node('resources-content').append(app.button('Configurar mínimo por almacén',()=>window.SicratResourceForms.minimum(app)));
	};
	app.navigate=navigate;
	app.node('resources-configuration').addEventListener('click',app.guard(()=>navigate(app.node('resources-config-tabs').querySelector('button')?.dataset.entity)));
	app.node('resources-config-dialog').addEventListener('close',()=>{if(configuration.includes(app.entity)){if(app.primaryEntity)app.guard(()=>navigate(app.primaryEntity))();else{const panel=app.node('resources-primary-panel');app.node('resources-primary-anchor').after(panel);panel.hidden=true;}}});
	app.node('resources-new').addEventListener('click',app.guard(()=>window.SicratResourceForms.create(app)));
	app.node('resources-export').addEventListener('click',app.guard(()=>app.exportDialog()));
	app.node('resources-filters').addEventListener('submit',app.guard(async event=>{app.filters={...app.values(event.currentTarget),...(app.filterDto ? app.filterDto() : {})};
		await app.list.reset();
		}));
	app.node('resources-refresh').addEventListener('click',app.guard(()=>app.list ? app.list.load() : navigate(app.entity)));
	app.guard(async()=>{
		app.me=await app.api('auth/me');
		const capabilities=await app.api('operations/capabilities');
		app.election=capabilities.election;
		app.modules=capabilities.modules;
		app.enabled=capabilities.modules.some(item=>item.code===app.module && item.enabled);
		app.node('resources-mode').textContent=app.enabled ? (app.module==='finanzas' ? 'Operación habilitada. Importes en pesos mexicanos (MXN).' : 'Inventario común a todas las elecciones de la organización.') : 'Modo histórico: el módulo está deshabilitado. Puedes consultar datos anteriores según tus permisos.';
		const entities=app.module==='finanzas' ? ['resumen','presupuestos','gastos','anticipos','reembolsos','ingresos','aportaciones','pagos','flujo','categorias','cuentas','metodos','politicas','transferencias'] : ['resumen','articulos','existencias','movimientos','almacenes','categorias_inventario','origenes'];
		const allowed=entities.filter(entity=>app.can(definitions[entity].read));
		app.primaryEntity=allowed.find(entity=>!configuration.includes(entity)) || null;
		if(!allowed.length){app.node('resources-mode').textContent='No tienes permiso para consultar estos apartados. Solicita al administrador el acceso necesario.';
			app.initialized=true;
			return;
			}
		allowed.forEach(entity=>{const button=app.button(definitions[entity].title,()=>navigate(entity));
			button.dataset.entity=entity;
			button.setAttribute('role','tab');
			button.setAttribute('aria-selected','false');
			app.node(configuration.includes(entity) ? 'resources-config-tabs' : 'resources-tabs').append(button);
			});
		app.node('resources-configuration').hidden=app.node('resources-config-tabs').children.length===0;
		[app.node('resources-tabs'),app.node('resources-config-tabs')].forEach(nav=>nav.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
			event.preventDefault();
			const buttons=Array.from(nav.children);
			let i=buttons.indexOf(document.activeElement);
			i=event.key==='Home' ? 0 : event.key==='End' ? buttons.length-1 : (i+(event.key==='ArrowRight' ? 1 : -1)+buttons.length)%buttons.length;
			buttons[i].focus();
			buttons[i].click();
			}));
		const requested=new URLSearchParams(location.search).get('tab');
		await navigate(allowed.includes(requested) ? requested : allowed[0]);
		const record=new URLSearchParams(location.search).get('record');
		if(record)await app.open(record);
		app.initialized=true;
	})();
}
