'use strict';

window.SicratUI = {
    tabs(root, changed = () => {}) {
        const buttons = Array.from(root.querySelectorAll('[data-hs-tab]'));
        const activate = async button => {
            if (button.hidden || button.disabled) return;
            buttons.forEach(item => {
                const selected = item === button;
                item.classList.toggle('active', selected);
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
                const panel = document.querySelector(item.dataset.hsTab);
                panel.hidden = !selected;
                panel.classList.toggle('hidden', !selected);
            });
            await changed(button);
        };
        buttons.forEach(button => {
            button.addEventListener('click', () => activate(button));
            button.addEventListener('keydown', event => {
                const visible = buttons.filter(item => !item.hidden && !item.disabled);
                let index = visible.indexOf(button);
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                if (event.key === 'Home') index = 0;
                else if (event.key === 'End') index = visible.length - 1;
                else index = (index + (event.key === 'ArrowRight' ? 1 : -1) + visible.length) % visible.length;
                visible[index].focus(); activate(visible[index]);
            });
        });
        return activate;
    },
    label(code) {
        const words = {codigo_persona:'Código de persona',f_registro:'Fecha de registro',f_nacimiento:'Fecha de nacimiento',activo:'Estado',paterno:'Apellido paterno',materno:'Apellido materno',curp_unica:'Impedir CURP repetida',clave_elector_unica:'Impedir clave de elector repetida',personas:'Personas',participaciones:'Participaciones',usuarios:'Usuarios',usuarios_accesos:'Usuarios y accesos',estructura:'Estructura',niveles:'Niveles organizacionales',permisos:'Permisos',modulos:'Módulos',aprobaciones:'Aprobaciones',archivos:'Archivos',exportaciones:'Exportaciones',notificaciones:'Notificaciones',auditoria:'Auditoría',trabajos:'Trabajos',respaldos:'Respaldos',admin:'Administración',captura:'Captura de personas',ver:'Ver',crear:'Crear',editar:'Editar',eliminar:'Eliminar',administrar:'Administrar',formar:'Incorporar integrantes',transferir:'Transferir integrantes',resolver:'Resolver',subir:'Subir',descargar:'Descargar',generar:'Generar',seleccionar:'Seleccionar',autorizar:'Autorizar',aprobar:'Aprobar',solicitar:'Solicitar',leer:'Leer',bd:'Base de datos',archivos_full:'Archivos completos',incremental:'Archivos incrementales',completo:'Base de datos y archivos',exportacion:'Exportación',respaldo:'Respaldo',restauracion:'Restauración',verificar_respaldo:'Verificar respaldo',notificacion:'Notificación',mantenimiento:'Mantenimiento',paquete:'Paquete de datos',pendiente:'Pendiente',ejecutando:'En proceso',reintento:'Esperando reintento',completado:'Completado',completada:'Completada',fallido:'Fallido',fallida:'Fallida',cancelado:'Cancelado',cancelada:'Cancelada',aprobada:'Aprobada',rechazada:'Rechazada',expirada:'Expirada',enviado:'Enviado',incierto:'Requiere revisión',personas_procesos:'Participación electoral',usuarios_procesos:'Accesos electorales',usuarios_tipos:'Roles',estructura_transferencias:'Transferencias',niveles_organizacionales:'Niveles organizacionales',procesos_estructura_config:'Reglas de estructura',organizaciones:'Organizaciones',procesos_electorales:'Elecciones',permisos_usuarios_tipo:'Permisos del rol',permisos_usuarios:'Excepciones por usuario',notificaciones_config:'Canales de notificación',crear_persona:'Registrar persona',editar_persona:'Editar persona',participacion:'Participación electoral',configuracion:'Configuración',prueba_fase4:'Comprobación de servicios'};
        if (words[code]) return words[code];
        const parts = String(code || '').split('.');
        if (parts.length === 2 && words[parts[0]] && words[parts[1]]) return words[parts[1]] + ' ' + words[parts[0]].toLowerCase();
        return String(code || '').replace(/[_.]/g, ' ').replace(/^./, character => character.toUpperCase());
    },
    badge(value, positive = false) {
        const badge = document.createElement('span');
        badge.className = 'ux-badge ' + (positive ? 'ux-badge-success' : 'ux-badge-neutral');
        badge.textContent = value;
        return badge;
    },
    details(target, values, business = false) {
        target.replaceChildren();
        const list = document.createElement('dl'); list.className = 'ux-description';
        Object.entries(values || {}).forEach(([key, value]) => {
            if (business && (/^(id|uuid|organizacion|proceso|usuario|persona|integrante|estructura|registro|registrado_por|editado_por)$|(_id|_uuid)$/.test(key) || (typeof value === 'string' && /^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value)))) return;
            const label = document.createElement('dt'); label.textContent = this.label(key);
            const content = document.createElement('dd');
            if (value && typeof value === 'object') this.details(content, value, business);
            else content.textContent = value == null ? 'Sin información' : business && key === 'activo' ? (Number(value) ? 'Activo' : 'Inactivo') : typeof value === 'boolean' ? (value ? 'Sí' : 'No') : String(value);
            list.append(label, content);
        });
        target.append(list);
    }
};

Object.assign(window.SicratUI, {
    icon(name) { if(window.SicratLucide)return window.SicratLucide.icon(name);const icon=document.createElement('i');icon.setAttribute('data-lucide',name);icon.setAttribute('aria-hidden','true');return icon; },
    action(button, title, icon='eye') {
        if(button.dataset.uxIconReady)return button;
        button.dataset.uxIconReady='1';button.classList.add('ux-icon-action');button.title=title;button.setAttribute('aria-label',title);
        const label=document.createElement('span');label.className='ux-sr-only';label.textContent=title;button.replaceChildren(this.icon(icon),label);return button;
    },
    layoutFields(container) {
        const children=Array.from(container.children);
        children.forEach(child=>{if(child.dataset.uxAutoWide){child.classList.remove('ux-full');delete child.dataset.uxAutoWide;}});
        let single=null;const finish=()=>{if(single){single.classList.add('ux-full');single.dataset.uxAutoWide='1';single=null;}};
        children.forEach(child=>{
            if(child.hidden || child.classList.contains('hidden'))return;
            if(child.tagName==='LABEL' && !child.classList.contains('ux-full') && !child.querySelector('textarea'))single=single ? null : child;
            else finish();
        });finish();
    },
    dialog(dialog) {
        if(dialog.classList.contains('ux-chat-conversation'))return;
        dialog.classList.add('ux-dialog');
        dialog.classList.toggle('ux-dialog-compact',/^Exportar/i.test(dialog.querySelector('h6,h5,h2')?.textContent || ''));
        dialog.querySelectorAll('[data-close]').forEach(button=>{
            if(button.dataset.uxCloseReady)return;
            button.dataset.uxCloseReady='1';button.type='button';button.classList.add('ux-modal-close');this.action(button,'Cerrar diálogo','x');
            let header=dialog.querySelector('.ux-modal-header');
            if(!header){header=document.createElement('div');header.className='ux-modal-header';const heading=dialog.querySelector('h6,h5,h2');dialog.insertBefore(header,dialog.firstChild);if(heading)header.append(heading);}
            header.append(button);
        });
        dialog.querySelectorAll('form').forEach(form=>{
            if(form.classList.contains('ux-inline-form'))return;
            form.classList.add('ux-modal-form');
            const submits=Array.from(form.querySelectorAll('button[type=submit],button:not([type])')).filter(button=>button.closest('form')===form);
            submits.forEach(button=>{
                if(button.closest('.ux-modal-footer'))return;
                let footer=form.querySelector(':scope > .ux-modal-footer');
                if(!footer){footer=document.createElement('div');footer.className='ux-modal-footer';const cancel=document.createElement('button');cancel.type='button';cancel.className='btn';cancel.textContent='Cancelar';cancel.addEventListener('click',()=>dialog.close());footer.append(cancel);form.append(footer);}
                footer.append(button);
            });
            form.querySelectorAll(':scope > .ux-actions:empty').forEach(actions=>actions.remove());
        });
        dialog.querySelectorAll('.ux-modal-form,.op-form-fields,.ux-form-grid').forEach(container=>this.layoutFields(container));
    },
    async entitySelector({title,selected,load,choose}) {
        const dialog=document.createElement('dialog');dialog.className='ux-entity-selector';
        const header=document.createElement('div');const heading=document.createElement('h6');heading.textContent=title;const close=document.createElement('button');close.type='button';close.className='btn';close.dataset.close='entity-selector';close.textContent='Cerrar';close.addEventListener('click',()=>dialog.close());header.append(heading,close);
        const form=document.createElement('form');form.className='ux-inline-form ux-search-row';const search=document.createElement('input');search.type='search';search.className='form-input';search.placeholder='Buscar por nombre o código';search.setAttribute('aria-label',search.placeholder);const find=document.createElement('button');find.type='submit';find.className='btn';find.textContent='Buscar';form.append(search,find);
        const list=document.createElement('div');list.className='ux-selector-results';const pager=document.createElement('div');pager.className='ux-pagination';let offset=0,nonce=0;
        const refresh=async()=>{const request=++nonce;list.textContent='Cargando…';const data=await load(search.value,offset);if(request!==nonce || !dialog.open)return;list.replaceChildren();if(!data.rows.length)list.textContent='No hay coincidencias.';
            data.rows.forEach(row=>{const button=document.createElement('button');button.type='button';button.className='btn ux-selector-row';button.textContent=row.label;button.setAttribute('aria-pressed',String(row.value===selected));button.addEventListener('click',window.SicratFeedback.guard(async()=>{await choose(row);dialog.close();}));list.append(button);});
            pager.replaceChildren();const previous=document.createElement('button');previous.type='button';previous.className='btn';previous.textContent='Anterior';previous.disabled=!offset;previous.addEventListener('click',window.SicratFeedback.guard(async()=>{offset=Math.max(0,offset-25);await refresh();}));const next=document.createElement('button');next.type='button';next.className='btn';next.textContent='Siguiente';next.disabled=!data.pagination.has_more;next.addEventListener('click',window.SicratFeedback.guard(async()=>{offset+=25;await refresh();}));const page=document.createElement('span');page.textContent='Página '+(offset/25+1);pager.append(previous,page,next);
        };
        form.addEventListener('submit',window.SicratFeedback.guard(async()=>{offset=0;await refresh();}));dialog.append(header,form,list,pager);document.querySelector('main').append(dialog);dialog.addEventListener('close',()=>{nonce++;dialog.remove();},{once:true});this.dialog(dialog);dialog.showModal();search.focus();await refresh();
    },
    electionChoices(target, choices, selected=[]) {
        target.replaceChildren();target.classList.add('ux-choice-list');
        const ids=selected.length ? selected : choices.length===1 ? [choices[0].id] : [];
        choices.forEach(choice=>{const label=document.createElement('label');label.className='ux-choice';const input=document.createElement('input');input.type='checkbox';input.value=choice.id;input.checked=ids.includes(choice.id);const text=document.createElement('span');text.textContent=choice.name || choice.process;label.append(this.icon('vote'),text,input);target.append(label);});
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const prepare = root => {
        root.querySelectorAll('input[placeholder], textarea[placeholder]').forEach(field => {
            if (field.type === 'hidden' || field.hasAttribute('aria-label') || field.closest('label') || (field.id && document.querySelector('label[for="' + field.id + '"]'))) return;
            if (!field.id) field.id = 'ux-field-' + (++prepare.sequence);
            const label = document.createElement('label'); label.htmlFor = field.id;
            label.className = 'ux-field-label'; label.textContent = field.placeholder;
            field.before(label);
        });
        root.querySelectorAll('dialog').forEach(dialog => {
            window.SicratUI.dialog(dialog);
            if (dialog.hasAttribute('aria-label') || dialog.hasAttribute('aria-labelledby')) return;
            const heading = dialog.querySelector('h6,h5,h2');
            if (heading) { if (!heading.id) heading.id = dialog.id + '-title'; dialog.setAttribute('aria-labelledby', heading.id); }
            else dialog.setAttribute('aria-label', 'Detalles y acciones');
        });
        root.querySelectorAll('table th').forEach(header => header.setAttribute('scope', 'col'));
        root.querySelectorAll('table .btn').forEach(button=>{
            const title=button.textContent.trim();const icon=/^editar$/i.test(title) ? 'pencil' : /^eliminar$/i.test(title) ? 'trash-2' : /^(ver detalle|abrir|ver \/ comparar|consultar)$/i.test(title) ? 'eye' : null;
            if(icon)window.SicratUI.action(button,title,icon);
        });
    };
    prepare.sequence = 0;
    prepare(document);
    const menu = document.getElementById('app-menu');
    const toggle = document.getElementById('button-toggle-menu');
    if (menu && toggle) {
        const state = () => {
            const floating = ['offcanvas','hidden'].includes(document.documentElement.getAttribute('data-sidenav-size'));
            menu.inert = floating && !document.documentElement.classList.contains('sidenav-enable');
            toggle.setAttribute('aria-expanded', String(!menu.inert));
            toggle.setAttribute('aria-controls', 'app-menu');
        };
        state();
        new MutationObserver(state).observe(document.documentElement, {attributes:true,attributeFilter:['class','data-sidenav-size']});
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !menu.inert && document.documentElement.getAttribute('data-sidenav-size') === 'offcanvas') {
                document.getElementById('custom-backdrop')?.click(); toggle.focus();
            }
        });
    }
    new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1 && node.namespaceURI!=='http://www.w3.org/2000/svg') {const dialog=node.closest('dialog');if(dialog)window.SicratUI.dialog(dialog);prepare(node.parentElement || node);} }));
    }).observe(document.querySelector('main') || document.body, {childList: true, subtree: true});
});
