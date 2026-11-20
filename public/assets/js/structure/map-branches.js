'use strict';

window.SicratStructureBranches = (query, changed) => {
    const el = id => document.getElementById(id);
    const dialog = el('structure-map-branches');
    const available = el('structure-branch-available');
    const selectedList = el('structure-branch-selected');
    const search = el('structure-branch-search');
    const sort = el('structure-branch-sort');
    const status = el('structure-branch-status');
    const clear = el('structure-branch-clear');
    const more = el('structure-branch-more');
    const scroll = new Map([dialog,available,selectedList].map(node=>[node,0]));
    scroll.forEach((_,node)=>node.addEventListener('scroll',()=>{
        if (dialog.open) scroll.set(node,node.scrollTop);
    }));
    const selected = new Map();
    let rows = [], matchingSelected = new Set(), total = 0, offset = 0, selectionLimit;
    let request, timer, version = 0, dirty = true;
    const selection = () => [...selected.values()];

    function row(record, remove) {
        const item = document.createElement('li');
        item.dataset.branch = record.value;
        const copy = document.createElement('div');
        const name = SicratPhase3.text('strong',record.name);
        name.id = `structure-branch-${record.value}-name`;
        copy.append(name);
        if (record.level) copy.append(SicratPhase3.text('small',record.level));
        const button = document.createElement('button');
        button.type = 'button';
        button.className = remove ? 'btn structure-branch-remove' : 'btn';
        SicratUI.action(button,remove ? 'Remover del filtro' : 'Agregar al filtro',remove ? 'x' : 'plus');
        button.setAttribute('aria-describedby',name.id);
        button.disabled = !remove && selected.size>=selectionLimit;
        button.onclick = () => {
            if (remove) {
                selected.delete(record.value);
                matchingSelected.delete(record.value);
            } else {
                selected.set(record.value,record);
                matchingSelected.add(record.value);
            }
            applySelection();
            const target = remove ? selectedList.querySelector('button') || search
                : selectedList.querySelector(`[data-branch="${CSS.escape(record.value)}"] button`);
            target?.focus({preventScroll:true});
        };
        item.append(copy,button);
        return item;
    }

    function renderList(container, records, remove) {
        const top = dialog.open ? container.scrollTop : scroll.get(container);
        const focused = container.contains(document.activeElement) && document.activeElement.closest('[data-branch]')?.dataset.branch;
        container.replaceChildren(...records.map(record=>row(record,remove)));
        if (dialog.open) container.scrollTop = top;
        if (focused) container.querySelector(`[data-branch="${CSS.escape(focused)}"] button`)?.focus({preventScroll:true});
    }

    function render() {
        el('structure-branch-selected-count').textContent = `Seleccionados (${selected.size})`;
        el('structure-branch-available-count').textContent = `Disponibles (${Math.max(0,total-matchingSelected.size)})`;
        clear.disabled = !selected.size;
        el('structure-branch-empty').hidden = Boolean(selected.size);
        renderList(selectedList,selection(),true);
        renderList(available,rows.filter(record=>!selected.has(record.value)),false);
    }

    async function load(next = 0) {
        clearTimeout(timer);
        const current = ++version;
        request?.abort();
        more.hidden = true;
        available.setAttribute('aria-busy','true');
        status.textContent = 'Consultando integrantes…';
        try {
            request = query({view:'branches',branches:[...selected.keys()].join(','),search:search.value,sort:sort.value,offset:next});
            const {data} = await request;
            if (current!==version) return;
            rows = next ? [...rows,...data.rows] : data.rows;
            matchingSelected = new Set(data.selected_matches);
            total = data.total;
            selectionLimit = data.selection_limit;
            offset = next;
            dirty = false;
            render();
            more.hidden = !data.pagination.has_more;
            status.textContent = selected.size>=selectionLimit ? `Puedes seleccionar hasta ${selectionLimit} ramas.`
                : rows.length ? '' : 'No hay integrantes disponibles para esta búsqueda.';
        } catch (error) {
            if (current!==version) return;
            dirty = true;
            status.textContent = error.responseJSON?.message || 'No fue posible consultar los integrantes.';
        } finally {
            if (current===version) available.removeAttribute('aria-busy');
        }
    }

    function applySelection() {
        render();
        changed(selection());
        load();
    }

    function filter(delay = 0) {
        clearTimeout(timer);
        ++version;
        request?.abort();
        dirty = true;
        rows = [];
        total = 0;
        matchingSelected.clear();
        more.hidden = true;
        available.replaceChildren();
        el('structure-branch-available-count').textContent = 'Disponibles (0)';
        status.textContent = 'Consultando integrantes…';
        if (delay) timer = setTimeout(()=>load(),delay);
        else load();
    }

    search.oninput = () => filter(220);
    sort.onchange = () => filter();
    el('structure-branch-search-form').onsubmit = event => { event.preventDefault(); filter(); };
    more.onclick = () => load(offset+25);
    clear.onclick = () => {
        selected.clear();
        matchingSelected.clear();
        applySelection();
        search.focus({preventScroll:true});
    };
    dialog.addEventListener('close',()=>{
        if (!dirty && !available.hasAttribute('aria-busy')) return;
        clearTimeout(timer);
        ++version;
        request?.abort();
        available.removeAttribute('aria-busy');
        dirty = true;
    });

    return {
        selection,
        open() {
            if (dialog.open) return;
            dialog.showModal();
            scroll.forEach((top,node)=>{ node.scrollTop = top; });
            if (dirty) load();
        },
        reset() {
            clearTimeout(timer);
            ++version;
            request?.abort();
            search.value = '';
            sort.value = 'name';
            selected.clear();
            matchingSelected.clear();
            rows = [];
            total = offset = 0;
            scroll.forEach((_,node)=>scroll.set(node,0));
            more.hidden = true;
            dirty = true;
            render();
        },
        personSaved(id, saved) {
            if (!saved.name_changed) return;
            [...rows,...selected.values()].forEach(record=>{
                if (record.person===id) record.name = saved.name;
            });
            dirty = true;
            render();
        }
    };
};
