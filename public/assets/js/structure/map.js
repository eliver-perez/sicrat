'use strict';

window.InitializeStructureMap = (currentStructure) => {
    const el = id => document.getElementById(id);
    const dialog = el('structure-map-dialog');
    const launch = el('structure-map-open');
    const status = el('structure-map-status');
    const title = el('structure-map-title');
    const section = el('structure-map-section');
    const extent = el('structure-map-extent');
    const search = el('structure-map-search');
    const findBranch = el('structure-map-find-branch');
    const clearBranch = el('structure-map-clear-branch');
    let structure, branch, sections;
    const query = params => identityRequest('persons/map?'+new URLSearchParams({structure,...params}),'get');
    const map = SicratPointsMap({
        canvas: el('structure-map-canvas'), status, query,
        filters: () => ({branch:branch?.value || '',extent:extent.value,section:section.value,search:search.value}),
        select: openPerson
    });

    InitializePersonIdentity(() => { if (dialog.open) map.refresh(true); });
    el('person-detail').addEventListener('close', () => map.resume());
    function setBranch(selection) {
        branch = selection;
        title.textContent = branch ? 'Estructura de '+branch.name : 'Mapa de mi estructura';
        clearBranch.hidden = !branch;
        extent.options[0].textContent = branch ? 'Toda esta rama' : 'Toda mi estructura';
    }
    launch.onclick = async () => {
        if (!currentStructure() || dialog.open) return;
        if (structure !== currentStructure()) {
            structure = currentStructure();
            setBranch(null);
            extent.value = 'all';
            section.value = search.value = '';
        }
        dialog.showModal();
        map.open();
        sections ??= personSections().then(rows => {
            rows.forEach(row=>SicratPhase3.option(section,row.id,row.section));
        }).catch(error => {
            sections = null;
            SicratFeedback.error(error);
        });
    };
    dialog.addEventListener('close', () => map.close());
    [extent,section].forEach(control=>control.onchange = () => map.refresh());
    search.oninput = () => map.refresh();
    findBranch.onclick = () => SicratPhase3.run(() => SicratUI.entitySelector({
        title: 'Seleccionar rama de la estructura',
        selected: branch?.value,
        load: async (search,offset) => (await query({view:'branches',search,offset})).data,
        choose: row => {
            setBranch(row);
            map.refresh();
        }
    }),findBranch);
    clearBranch.onclick = () => {
        setBranch(null);
        findBranch.focus();
        map.refresh();
    };
    return launch;
};
