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
            if (dialog.hasAttribute('aria-label') || dialog.hasAttribute('aria-labelledby')) return;
            const heading = dialog.querySelector('h6,h5,h2');
            if (heading) { if (!heading.id) heading.id = dialog.id + '-title'; dialog.setAttribute('aria-labelledby', heading.id); }
            else dialog.setAttribute('aria-label', 'Detalles y acciones');
        });
        root.querySelectorAll('table th').forEach(header => header.setAttribute('scope', 'col'));
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
        records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) prepare(node.parentElement || node); }));
    }).observe(document.querySelector('main') || document.body, {childList: true, subtree: true});
});
