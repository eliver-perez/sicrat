'use strict';

window.SicratOperationCreate = function (app, saved) {
    const dialog = app.node('operation-create-dialog');
    const title = app.node('operation-create-title');
    const form = app.node('operation-create-form');
    const fields = app.node('operation-create-fields');
    let revision = 0;

    app.create = async function (parent = null) {
        const request = ++revision;
        const definition = app.definition;
        const flow = Object.assign(Object.create(app),{
            entity:app.entity, me:app.me, modules:app.modules, initialPerson:app.initialPerson
        });
        const staging = app.text('div');
        const submit = form.querySelector('[type=submit]');
        fields.replaceChildren();
        form.reset();
        app.createDTO = null;
        app.editingSurvey = null;
        title.textContent = parent ? 'Distribuir meta' : definition.newLabel;
        if (app.entity === 'conversaciones') {
            const kind = app.chatCreateKind || 'direct';
            title.textContent = {direct:'Nueva conversación',group:'Nuevo grupo',announcement:'Nuevo anuncio'}[kind];
            const label = {direct:'Abrir conversación',group:'Crear grupo',announcement:'Crear anuncio'}[kind];
            window.SicratUI.labeledAction(submit,label,kind==='direct' ? 'message-circle' : 'plus');
        }
        submit.disabled = true;
        if (!dialog.open) dialog.showModal();
        let data;
        try {
            data = await window.SicratOperationForms[definition.create](flow,staging,parent);
        } catch (error) {
            if (request === revision && dialog.open) throw error;
            return;
        }
        if (request !== revision || !dialog.open) return;
        fields.replaceChildren(...staging.children);
        app.createDTO = data;
        submit.disabled = false;
    };

    dialog.addEventListener('close', () => {
        if (dialog.open) return;
        ++revision;
        app.createDTO = null;
    });

    form.addEventListener('submit', app.guard(async event => {
        if (!app.createDTO) return;
        const entity = app.entity;
        const editing = app.editingSurvey;
        const data = app.createDTO(event.currentTarget);
        const result = editing
            ? await app.api('operations/encuestas/'+editing.uuid,'PUT',{...data,version:editing.version})
            : await app.api('operations/'+entity,'POST',data);
        dialog.close();
        await saved(editing ? {uuid:editing.uuid} : result, entity, editing);
    }));
};
