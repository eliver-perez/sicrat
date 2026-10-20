'use strict';

// Patrones visuales exclusivos de Jornada; los formularios conservan sus handlers comunes.
window.SicratJornadaUI = {
    icon(name) {
        const icon = window.SicratUI.icon(name);
        const paths = {
            save: [
                ['path', { d: 'M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z' }],
                ['path', { d: 'M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7M7 3v4a1 1 0 0 0 1 1h7' }]
            ],
            image: [
                ['rect', { width: 18, height: 18, x: 3, y: 3, rx: 2, ry: 2 }],
                ['circle', { cx: 9, cy: 9, r: 2 }],
                ['path', { d: 'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21' }]
            ]
        };
        if (paths[name]) {
            icon.replaceChildren();
            paths[name].forEach(([tag, attributes]) => {
                const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
                Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
                icon.append(node);
            });
        }
        return icon;
    },

    decorate(button, icon, iconOnly = false) {
        if (button.dataset.jornadaIcon) return button;
        button.dataset.jornadaIcon = icon;
        const label = button.textContent;
        button.replaceChildren(this.icon(icon));
        const text = document.createElement('span');
        text.textContent = label;
        if (iconOnly) {
            text.className = 'ux-sr-only';
            button.classList.add('jornada-icon-button');
        }
        button.append(text);
        button.title = label;
        button.setAttribute('aria-label', label);
        return button;
    },

    section(app, target, key, title, description, wide = false) {
        const card = app.text('section', '', 'card jornada-settings-card' + (wide ? ' jornada-wide' : ''));
        card.id = 'jornada-settings-' + key;
        const heading = app.text('h6', title);
        heading.id = card.id + '-title';
        card.setAttribute('aria-labelledby', heading.id);
        const header = app.text('header', '', 'jornada-section-header');
        const copy = app.text('div');
        copy.append(heading, app.text('p', description, 'ux-help'));
        header.append(copy);
        const content = app.text('div', '', 'jornada-section-content');
        card.append(header, content);
        target.append(card);
        return { card, header, content };
    },

    form(app, target, title, builder, submit, label = 'Guardar', icon = 'save') {
        const form = app.form(target, title, builder, submit, label);
        form.classList.add('jornada-form');
        const heading = form.querySelector(':scope > h6');
        if (!title) heading.remove();
        const button = form.querySelector(':scope > button[type="submit"]');
        this.decorate(button, icon);
        const footer = app.text('div', '', 'ux-modal-footer jornada-form-actions');
        footer.append(button);
        form.append(footer);
        return form;
    },

    group(app, target, title) {
        const group = app.text('fieldset', '', 'jornada-field-group ux-full');
        group.append(app.text('legend', title));
        const fields = app.text('div', '', 'op-form-fields');
        group.append(fields);
        target.append(group);
        return fields;
    },

    picker(picker) {
        picker.box.querySelectorAll('button').forEach(button => {
            if (button.textContent === 'Buscar') this.decorate(button, 'search');
        });
        return picker;
    }
};
