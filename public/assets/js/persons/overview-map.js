'use strict';
window.SicratPersonsOverview = (() => {
    let overview;
    return {
        get active() { return overview?.active; },
        refresh() { overview.refresh(); },
        init() {
            const el = id => document.getElementById(id);
            const toggle = SicratUI.action(el('persons-view-toggle'),'Cambiar vista','layout-grid');
            const menu = el('persons-view-menu');
            const options = [el('persons-list-toggle'),el('persons-map-toggle')];
            overview = SicratPointsMap({
                canvas: el('persons-overview-map'),
                status: el('persons-map-status'),
                filters: () => ({search:el('field-filter-search').value,section:el('select-filter-section').value}),
                query: params => identityRequest('persons/map?'+new URLSearchParams(params),'get'),
                select: openPerson
            });
            function showMenu(open, focus = true) {
                menu.hidden = !open;
                toggle.setAttribute('aria-expanded',String(open));
                if (focus) (open ? options.find(option=>option.getAttribute('aria-checked')==='true') : toggle).focus();
            }
            toggle.onclick = () => showMenu(menu.hidden);
            toggle.onkeydown = event => {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    showMenu(true);
                }
            };
            options.forEach((option,index) => {
                option.tabIndex = -1;
                option.onclick = () => {
                    showMenu(false);
                    const showMap = index===1;
                    if (overview.active===showMap) return;
                    options.forEach((item,i)=>item.setAttribute('aria-checked',String(i===index)));
                    el('persons-list-panel').hidden = showMap;
                    el('persons-map-panel').hidden = !showMap;
                    if (showMap) overview.open();
                    else { overview.close(); GetPersons(true); }
                };
                option.onkeydown = event => {
                    if (!['ArrowUp','ArrowDown','Home','End','Escape'].includes(event.key)) return;
                    event.preventDefault();
                    if (event.key==='Escape') { showMenu(false); return; }
                    options[event.key==='Home' ? 0 : event.key==='End' ? 1 : 1-index].focus();
                };
            });
            const outside = event => { if (!menu.hidden && !toggle.parentElement.contains(event.target)) showMenu(false,false); };
            document.addEventListener('pointerdown',outside);
            document.addEventListener('focusin',outside);
            el('field-filter-search').addEventListener('input',()=>overview.refresh());
            el('person-detail').addEventListener('close',()=>overview.resume());
        }
    };
})();
