'use strict';
// Compartido por Agregar y Personas: el campo de formulario guarda sólo la selección.
window.SicratAssignment = class {
    constructor({request,select,levels,controls,onReady=()=>{}}) {
        Object.assign(this,{request,select,levels,controls,onReady});this.candidates=new Map();this.version=0;
        const ui=window.SicratPhase3;
        select.closest('label').hidden=true;
        this.summary=ui.text('div','','assignment-selected');
        this.change=ui.text('button','Cambiar responsable','btn');this.change.type='button';this.change.prepend(SicratUI.icon('users'));
        this.panel=ui.text('div','','assignment-browser');this.panel.hidden=true;
        this.change.onclick=()=>{this.panel.hidden=!this.panel.hidden;this.change.setAttribute('aria-expanded',String(!this.panel.hidden));};
        this.search=document.createElement('input');this.search.type='search';this.search.className='form-input';this.search.placeholder='Nombre o código';this.search.setAttribute('aria-label','Buscar responsable por nombre o código');this.search.maxLength=200;
        this.find=ui.text('button','Buscar','btn');this.find.type='button';this.find.prepend(SicratUI.icon('search'));
        this.mine=ui.text('button','Mi equipo','btn');this.mine.type='button';this.mine.onclick=()=>this.browse('mine');
        this.roots=ui.text('button','Nivel superior','btn');this.roots.type='button';this.roots.onclick=()=>this.browse('roots');
        this.crumbs=ui.text('nav','','structure-breadcrumb');this.rows=ui.text('div','','assignment-candidates');
        this.more=ui.text('button','Siguiente','btn');this.more.type='button';
        this.back=ui.text('button','Anterior','btn');this.back.type='button';
        this.status=ui.text('p','','ux-help');this.status.setAttribute('role','status');
        const line=ui.text('div','','assignment-actions');line.append(this.search,this.find);
        const navigation=ui.text('div','','assignment-actions');navigation.append(this.mine,this.roots);
        const pages=ui.text('div','','assignment-actions');pages.append(this.back,this.more);
        this.panel.append(line,navigation,this.crumbs,this.status,this.rows,pages);
        controls.classList.add('assignment-control');controls.replaceChildren(ui.text('span','Responsable directo'),this.summary,this.change,this.panel);
        this.find.onclick=()=>ui.run(()=>{this.offsets=[];return this.load(0);});
        this.more.onclick=()=>ui.run(()=>{this.offsets.push(this.offset);return this.load(this.nextOffset);});
        this.back.onclick=()=>ui.run(()=>this.load(this.offsets.pop() || 0));
        this.search.onkeydown=event=>{if(event.key==='Enter'){event.preventDefault();event.stopPropagation();if(!event.repeat&&!event.isComposing)this.find.click();}};
    }
    async open(id) {this.id=id;this.parent='mine';this.offsets=[];this.search.value='';this.candidates.clear();this.select.replaceChildren();this.panel.hidden=true;this.change.setAttribute('aria-expanded','false');await this.load(0,true);}
    browse(parent){if(this.busy)return;this.parent=parent;this.search.value='';this.offsets=[];window.SicratPhase3.run(()=>this.load(0));}
    async load(offset=0,initial=false) {
        if(this.busy&&!initial)return;
        const version=++this.version;this.busy=true;this.panel.inert=true;this.change.disabled=true;this.status.textContent='Cargando responsables…';
        try {
            const response=await this.request(`structures/${this.id}/assignment-options?`+new URLSearchParams({limit:25,offset,parent:this.parent,search:this.search.value.trim()}));
            if(version!==this.version)return;
            const data=response.data;this.data=data;this.offset=offset;
            const selected=initial?data.own:this.candidates.get(this.select.value);this.candidates.clear();
            [selected,data.own,data.focus,...data.ancestors,...data.candidates].filter(Boolean).forEach(item=>this.candidates.set(item.id,item));
            if(initial)this.choose(data.own || null,false);
            this.nextOffset=data.pagination.next_offset;this.more.hidden=!data.pagination.has_more;this.back.hidden=!this.offsets.length;
            this.mine.hidden=!data.own;this.roots.hidden=false;
            this.status.textContent=data.candidates.length?'':(this.search.value.trim()?'No hay responsables permitidos que coincidan en esta página.':'No hay responsables permitidos en este nivel.');
            this.renderCandidates();this.onReady(data);
        } finally {if(version===this.version){this.busy=false;this.panel.inert=false;this.change.disabled=false;}}
    }
    choose(person,close=true){
        const ui=window.SicratPhase3;this.select.replaceChildren();ui.option(this.select,person?.id || '',person?.name || (this.data.can_root?'Sin responsable directo (raíz)':'Selecciona un responsable'));
        this.select.required=false;
        this.summary.replaceChildren(ui.text('strong',person?.name || this.select.options[0].text),ui.text('small',person?[person.code,person.level_name].filter(Boolean).join(' · '):'','ux-help'));
        this.renderLevels();if(close){this.panel.hidden=true;this.change.setAttribute('aria-expanded','false');}
    }
    renderCandidates(){
        const ui=window.SicratPhase3,data=this.data;this.rows.replaceChildren();
        window.SicratStructureBreadcrumb(this.crumbs,data.focus?data.ancestors:[],data.focus || {name:'Responsables disponibles'},id=>this.browse(id));
        const add=(person,current=false)=>{
            const row=ui.text('div','','assignment-candidate');row.dataset.member=person.id;
            const info=ui.text('div','','assignment-candidate-copy');info.append(ui.text('strong',person.name),ui.text('small',[person.code,person.level_name].filter(Boolean).join(' · '),'ux-help'));
            const actions=ui.text('div','','assignment-actions');const use=ui.text('button',current?'Elegir a esta persona':'Elegir','btn');use.type='button';use.onclick=()=>this.choose(person);actions.append(use);
            if(!current){const team=ui.text('button','Ver equipo','btn');team.type='button';team.prepend(SicratUI.icon('network'));team.onclick=()=>this.browse(person.id);actions.append(team);}
            row.append(info,actions);this.rows.append(row);
        };
        if(data.focus&&!this.search.value.trim())add(data.focus,true);
        if(data.own&&data.own.id!==data.focus?.id&&!data.candidates.some(person=>person.id===data.own.id)&&!this.search.value.trim())add(data.own);
        data.candidates.forEach(person=>add(person));
        if(data.can_root&&this.parent==='roots'&&!this.search.value.trim()){const root=ui.text('button','Sin responsable directo (raíz)','btn');root.type='button';root.onclick=()=>this.choose(null);this.rows.append(root);}
    }
    renderLevels() {
        const ui=window.SicratPhase3;
        this.levels.replaceChildren();const parent=this.candidates.get(this.select.value);
        for(const election of this.data.elections){
            const rule=parent?.levels[election.id];const allowed=election.levels.filter(level=>!rule || level.order>rule.order || (level.order===rule.order&&rule.same_level)).sort((a,b)=>a.order-b.order);
            const label=ui.text('label',`Nivel en ${election.name}`),select=document.createElement('select');select.className='form-input';select.dataset.election=election.id;select.required=election.levels.length>0;
            if(!election.levels.length)ui.option(select,'','Sin nivel');else if(!allowed.length)ui.option(select,'','No hay niveles válidos para este responsable');
            allowed.forEach(level=>ui.option(select,level.id,level.name));
            const next=allowed.find(level=>!rule||level.order>rule.order) || allowed[0];if(next)select.value=next.id;
            label.append(select);this.levels.append(label);
        }
    }
    values(){if(this.busy)throw new Error('Espera a que termine la consulta de responsables.');if(!this.select.value&&!this.data.can_root)throw new Error('Selecciona un responsable permitido.');return {responsible:this.select.value||null,levels:Object.fromEntries(Array.from(this.levels.querySelectorAll('select')).map(select=>[select.dataset.election,select.value||null]))};}
};
window.SicratStructureBreadcrumb = function(target,ancestors,current,navigate) {
    const ui=window.SicratPhase3;target.replaceChildren();target.classList.add('structure-breadcrumb');target.setAttribute('aria-label','Jerarquía de responsables');
    [...ancestors,current].forEach((person,index)=>{
        if(index){const separator=ui.text('span','›');separator.setAttribute('aria-hidden','true');target.append(separator);}
        if(index===ancestors.length){const label=ui.text('span',person.name);label.setAttribute('aria-current','location');target.append(label);}
        else {const link=ui.text('button',person.name,'structure-crumb-link');link.type='button';link.onclick=()=>ui.run(()=>navigate(person.id),link);target.append(link);}
    });
};
