'use strict';

window.InitializeStructureMap = (currentStructure) => {
    const el = id => document.getElementById(id);
    const dialog = el('structure-map-dialog');
    const launch = el('structure-map-open');
    const status = el('structure-map-status');
    const legend = el('structure-map-legend');
    const section = el('structure-map-section');
    const extent = el('structure-map-extent');
    const search = el('structure-map-search');
    let structure, sections;
    const query = params => identityRequest('persons/map?'+new URLSearchParams({structure,...params}),'get');
    const branches = SicratStructureBranches(query,()=>{
        updateLegend();
        map.refresh();
    });
    const map = SicratPointsMap({
        canvas: el('structure-map-canvas'), status, query,
        filters: () => ({
            branches:branches.selection().map(row=>row.value).join(','),
            extent:extent.value,section:section.value,search:search.value
        }),
        select: openPerson
    });

    const detail = el('person-detail');
    detail.addEventListener('close', () => { if (!detail.open) map.resume(); });
    function updateLegend() {
        const selected = branches.selection();
        extent.options[0].textContent = selected.length ? 'Ramas seleccionadas completas' : 'Toda mi estructura';
        if (selected.length === 1) legend.textContent = 'Mostrando datos de la estructura de '+selected[0].name;
        else if (selected.length > 1) legend.textContent = `Mostrando datos de ${selected.length} estructuras seleccionadas`;
        else legend.textContent = {
            all:'Mostrando toda mi estructura',direct:'Mostrando mi equipo directo',indirect:'Mostrando mis dependientes indirectos'
        }[extent.value];
    }
    launch.onclick = () => {
        if (!currentStructure() || dialog.open) return;
        if (structure !== currentStructure()) {
            structure = currentStructure();
            branches.reset();
            extent.value = 'all';
            section.value = search.value = '';
            updateLegend();
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
    extent.onchange = () => { updateLegend(); map.refresh(); };
    section.onchange = search.oninput = () => map.refresh();
    el('structure-map-find-branch').onclick = () => branches.open();
    return {
        launch,
        personSaved(id,saved) {
            branches.personSaved(id,saved);
            updateLegend();
            if (dialog.open) map.refresh(true);
        }
    };
};
