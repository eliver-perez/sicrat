'use strict';
window.SicratPersonsOverview = (() => {
    let overview;
    return {
        get active() { return overview?.active; },
        refresh() { overview.refresh(); },
        init() {
            const el = id => document.getElementById(id);
            overview = SicratPointsMap({
                canvas: el('persons-overview-map'),
                status: el('persons-map-status'),
                filters: () => ({search:el('field-filter-search').value,section:el('select-filter-section').value}),
                query: params => identityRequest('persons/map?'+new URLSearchParams(params),'get'),
                select: openPerson
            });
            SicratUI.viewMenu(el('persons-view-toggle'),el('persons-view-menu'),(_,index)=>{
                const showMap=index===1;
                el('persons-list-panel').hidden=showMap;
                el('persons-map-panel').hidden=!showMap;
                if(showMap) overview.open();
                else { overview.close(); GetPersons(true); }
            });
            el('field-filter-search').addEventListener('input',()=>overview.refresh());
            el('person-detail').addEventListener('close',()=>overview.resume());
        }
    };
})();
