'use strict';

window.SicratPersonActions = (() => {
    const labels = {
        interacciones:['Registrar interacción','message-square-plus'],
        peticiones:['Nueva petición','inbox'],
        incidencias:['Nueva incidencia','file-text'],
        tareas:['Nueva tarea','list-todo'],
        metas:['Nueva meta','clipboard-check'],
        encuestas:['Aplicar encuesta','clipboard-check']
    };
    let app;
    let revision = 0;
    let activation = 0;

    function initialize(section) {
        const personDialog = document.getElementById('person-detail');
        app = Object.create(window.SicratOperations);
        app.node = id => document.getElementById('person-'+id);
        const refresh = () => {
            if (personDialog.open && selectedPerson === app.initialPerson?.[0]?.uuid) {
                return window.InitializePersonOperations(selectedPerson,homeURL);
            }
        };
        window.SicratOperationCreate(app,async () => {
            app.notice('Registro guardado.');
            await refresh();
        });
        app.afterSurveySaved = refresh;

        for (const id of ['operation-create-dialog','operation-settings-dialog']) {
            const dialog = app.node(id);
            dialog.querySelector('[data-close]').addEventListener('click',() => dialog.close());
            dialog.addEventListener('close', () => {
                if (!dialog.open && personDialog.open) app.origin?.focus({preventScroll:true});
            });
        }
        personDialog.addEventListener('close', () => {
            if (personDialog.open) return;
            ++revision;
            section.hidden = true;
        });
    }

    async function show(uuid) {
        const section = document.getElementById('person-related-actions');
        if (!app) initialize(section);
        const buttons = document.getElementById('person-related-buttons');
        const request = ++revision;
        section.hidden = true;
        section.open = false;
        buttons.replaceChildren();

        let grants;
        try {
            grants = await app.api('persons/'+encodeURIComponent(uuid)+'/operation-actions');
        } catch (error) {
            if (request === revision && ![403,404].includes(error.status)) window.SicratFeedback.error(error);
            return;
        }
        if (request !== revision || !grants.actions.length) return;

        for (const entity of grants.actions) {
            const [title,icon] = labels[entity];
            const button = app.button(title,async () => {
                const action = ++activation;
                const [me,capabilities] = await Promise.all([app.api('auth/me'),app.api('operations/capabilities')]);
                if (request !== revision || action !== activation) return;
                Object.assign(app,{me,modules:capabilities.modules,entity,definition:app.handlers[entity],initialPerson:[grants.person],origin:button});
                if (entity !== 'encuestas') {
                    await app.create();
                    return;
                }
                await window.SicratUI.entitySelector({
                    title:'Seleccionar encuesta publicada',
                    compact:true,
                    load:(search,offset)=>app.api('operations/encuestas?'+new URLSearchParams({state:'publicada',search,offset,limit:25})),
                    choose:async survey => {
                        const row = await app.api('operations/encuestas/'+encodeURIComponent(survey.value));
                        if (request === revision && action === activation) await app.answerSurvey(app,row);
                    }
                });
            });
            button.dataset.personOperation = entity;
            buttons.append(window.SicratUI.labeledAction(button,title,icon));
        }
        section.hidden = false;
    }

    return {show};
})();
