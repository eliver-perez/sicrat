'use strict';

window.SicratChatHistory={
    conversations(app,target) {
        const content=app.text('div','','ux-chat-conversation-scroll'),status=app.text('p','','ux-help ux-chat-load-status');status.setAttribute('role','status');status.hidden=true;target.replaceChildren(content,status);
        const rows=new Map();let version=0,more=true,oldest=0,pending=null,filters={...app.filters};
        const query=extra=>new URLSearchParams({...filters,limit:30,...extra});
        const render=()=>{const previous=Array.from(content.querySelectorAll('[data-open-chat]')).find(node=>node.getBoundingClientRect().bottom>content.getBoundingClientRect().top),anchor=previous?.dataset.openChat,top=previous?.getBoundingClientRect().top;const scroll=content.scrollTop;window.SicratMessaging.conversations(app,content,Array.from(rows.values()).sort((a,b)=>Number(b.id)-Number(a.id)));const restored=anchor && Array.from(content.querySelectorAll('[data-open-chat]')).find(node=>node.dataset.openChat===anchor);content.scrollTop=scroll;if(restored)content.scrollTop+=restored.getBoundingClientRect().top-top;};
        const perform=(work)=>{if(pending)return pending;content.setAttribute('aria-busy','true');status.hidden=false;status.textContent='Cargando conversaciones…';const task=Promise.resolve().then(work).catch(error=>{status.textContent=error.message || 'No fue posible cargar las conversaciones.';throw error;}).finally(()=>{if(pending===task){pending=null;content.removeAttribute('aria-busy');if(status.textContent==='Cargando conversaciones…')status.hidden=true;}});pending=task;return task;};
        const controller={
            load:()=>perform(async()=>{const request=version,visible=Array.from(content.querySelectorAll('[data-open-chat]')).filter(node=>{const a=node.getBoundingClientRect(),b=content.getBoundingClientRect();return a.bottom>b.top && a.top<b.bottom;}).map(node=>node.dataset.openChat);if(app.selected)visible.push(app.selected);
                const result=await app.api('operations/conversaciones?'+query());if(request!==version)return;const first=!rows.size;result.rows.forEach(row=>rows.set(row.uuid,row));if(first){more=!!result.pagination.has_more;oldest=result.rows.length ? Math.min(...result.rows.map(row=>Number(row.id))) : 0;}
                const ids=Array.from(new Set(visible)).filter(uuid=>!result.rows.some(row=>row.uuid===uuid)).slice(0,40);
                if(ids.length){const params=query({limit:100});ids.forEach(uuid=>params.append('ids[]',uuid));const metadata=await app.api('operations/conversaciones?'+params);if(request!==version)return;const valid=new Set(metadata.rows.map(row=>row.uuid));ids.filter(uuid=>!valid.has(uuid)).forEach(uuid=>rows.delete(uuid));metadata.rows.forEach(row=>rows.set(row.uuid,row));}
                render();}),
            reset:async()=>{version++;if(pending){try{await pending;}catch{}}filters={...app.filters};rows.clear();content.scrollTop=0;more=true;oldest=0;return controller.load();},
            older:()=>{if(!more || !rows.size)return Promise.resolve();return perform(async()=>{const request=version;const result=await app.api('operations/conversaciones?'+query({before:oldest}));if(request!==version)return;result.rows.forEach(row=>rows.set(row.uuid,row));more=!!result.pagination.has_more;if(result.rows.length)oldest=Math.min(...result.rows.map(row=>Number(row.id)));render();});}
        };
        content.addEventListener('scroll',()=>{if(content.scrollHeight-content.scrollTop-content.clientHeight<180 && more && !pending)void controller.older().catch(()=>{});},{passive:true});return controller;
    },
    messages(app,target,uuid,actions) {
        const content=app.text('div'),status=app.text('p','','ux-help ux-chat-load-status');status.hidden=true;status.setAttribute('role','status');target.append(content,status);
        let generation=0,pending=null;const controller={search:'',oldest:0,more:false,
            load:async()=>{const request=++generation;controller.search=app.chatSearch.value;const result=await app.api('operations/conversaciones/'+uuid+'/messages?'+new URLSearchParams({search:controller.search,limit:30}));if(request!==generation || app.selected!==uuid)return;controller.oldest=result.rows.length ? Math.min(...result.rows.map(row=>Number(row.id))) : 0;controller.more=!!result.pagination.has_more;window.SicratMessaging.messages(app,content,result.rows,actions,result);status.hidden=true;},
            reset:()=>controller.load(),
            older:()=>{if(pending || !controller.more || !controller.oldest)return pending || Promise.resolve();const request=generation;status.hidden=false;status.textContent='Cargando mensajes anteriores…';const task=(async()=>{try{
                const result=await app.api('operations/conversaciones/'+uuid+'/messages?'+new URLSearchParams({before:controller.oldest,search:controller.search,limit:30}));if(request!==generation || app.selected!==uuid || app.messagePager!==controller)return;
                const list=content.querySelector('.ux-chat-message-list'),anchor=list.querySelector('.ux-chat-message-row'),top=anchor ? anchor.getBoundingClientRect().top-list.getBoundingClientRect().top : 0,fragment=document.createDocumentFragment();
                result.rows.slice().reverse().forEach(row=>{if(!list.querySelector('[data-message-id="'+Number(row.id)+'"]'))fragment.append(window.SicratMessaging.message(app,row,actions));if(app.chatSession && !row.leido_en && Number(row.usuario)!==Number(app.me.id))app.chatSession.unread.set(row.uuid,row.id);});
                status.hidden=true;list.prepend(fragment);if(anchor)list.scrollTop+=anchor.getBoundingClientRect().top-list.getBoundingClientRect().top-top;
                if(result.rows.length)controller.oldest=Math.min(...result.rows.map(row=>Number(row.id)));controller.more=!!result.pagination.has_more;
            }catch(error){if(request===generation){status.hidden=false;status.textContent=error.message || 'No fue posible cargar mensajes anteriores.';}}finally{if(pending===task)pending=null;}})();pending=task;return task;}
        };return controller;
    }
};
