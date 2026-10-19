'use strict';

window.SicratMessaging={
    action(button,label,icon) {
        button.className='btn ux-chat-icon';button.title=label;button.setAttribute('aria-label',label);
        const text=document.createElement('span');text.className='ux-sr-only';text.textContent=label;
        button.replaceChildren(window.SicratUI.icon(icon),text);return button;
    },
    layout(app) {
        const layout=app.text('div','','card ux-chat-layout'),sidebar=app.text('aside','','ux-chat-sidebar');
        const card=app.node('operation-filter').closest('.card');card.before(layout);
        const head=app.text('div','','ux-chat-sidebar-head'),title=app.text('h6','Chats','card-title'),tools=app.text('div','','ux-actions');
        const newMenu=app.text('details','','ux-chat-menu'),newToggle=app.text('summary');newToggle.append(this.action(app.node('operation-new'),'Nueva conversación','plus'));const choices=app.text('div','','card ux-chat-menu-content');
        const choose=(kind,label)=>{const button=app.button(label,()=>{newMenu.open=false;app.chatCreateKind=kind;return app.create();});button.id='chat-new-'+kind;choices.append(button);};
        if(app.can('mensajes.enviar'))choose('direct','Nueva conversación');
        if(app.can('mensajes.crear_grupos') || app.can('mensajes.crear_grupos_estructura')){choose('group','Nuevo grupo');if(app.can('mensajes.administrar_grupos'))choose('announcement','Nuevo anuncio');}
        newMenu.append(newToggle,choices);newMenu.hidden=choices.children.length===0;tools.append(newMenu);app.chatNewMenu=newMenu;
        const menu=app.text('details','','ux-chat-menu'),toggle=app.text('summary','','btn ux-chat-icon');toggle.title='Opciones de mensajería';toggle.setAttribute('aria-label',toggle.title);toggle.append(window.SicratUI.icon('ellipsis'));
        const entries=app.text('div','','card ux-chat-menu-content');entries.append(app.node('operation-settings'),app.node('operation-refresh'),app.node('operation-export'));menu.append(toggle,entries);tools.append(menu);head.append(title,tools);
        const filter=app.node('operation-filter');filter.className='ux-chat-search';const input=filter.elements.search;input.placeholder='Buscar conversación…';input.setAttribute('aria-label','Buscar conversación');input.closest('label').replaceChildren(input);filter.prepend(window.SicratUI.icon('search'));
        const search=filter.querySelector('button:not([type])');this.action(search,'Buscar conversación','search');
        sidebar.append(head,filter,app.text('p','Conversaciones','ux-chat-list-label'),app.node('operation-list'));
        const room=app.text('section','','ux-chat-room'),placeholder=app.text('div','','ux-chat-empty');placeholder.id='chat-placeholder';placeholder.append(window.SicratUI.icon('message-circle'),app.text('h6','Tus conversaciones'),app.text('p','Selecciona un chat para ver los mensajes o inicia una conversación.'));room.append(placeholder);
        const dialog=app.node('operation-detail-dialog');dialog.classList.add('ux-chat-conversation');dialog.querySelectorAll(':scope > .ux-actions').forEach(element=>{if(!element.children.length)element.remove();});dialog.querySelectorAll('[data-close]').forEach(button=>button.hidden=true);room.append(dialog);layout.append(sidebar,room);card.remove();app.chatSidebar=sidebar;app.chatRoom=room;
        const back=this.action(app.button('Conversaciones',()=>{dialog.close();placeholder.hidden=false;this.select(app,null);responsive();}),'Volver a conversaciones','chevron-left');back.classList.add('ux-chat-back');app.chatBack=back;app.node('operation-help')?.remove();
        const options=app.text('details','','ux-chat-options'),summary=app.text('summary','','btn ux-chat-icon');summary.title='Opciones de conversación';summary.setAttribute('aria-label',summary.title);summary.append(window.SicratUI.icon('ellipsis'));options.append(summary,app.node('operation-panel-actions'));dialog.append(options);app.chatOptions=options;
        const responsive=()=>{const mobile=innerWidth<768,active=dialog.open;sidebar.hidden=mobile && active;room.hidden=mobile && !active;};app.chatResponsive=responsive;window.addEventListener('resize',responsive);dialog.addEventListener('close',()=>{responsive();this.stop(app);this.startSidebar(app);});window.addEventListener('pagehide',()=>this.stop(app),{once:true});responsive();this.startSidebar(app);
    },
    select(app,uuid) {
        app.chatSidebar.querySelectorAll('[data-open-chat]').forEach(button=>{const selected=button.dataset.openChat===uuid;button.classList.toggle('active',selected);button.setAttribute('aria-current',String(selected));});
    },
    conversations(app,target,rows) {
        target.replaceChildren();if(!rows.length){target.append(app.text('p','No hay conversaciones para esta búsqueda.','ux-help p-4'));return;}
        rows.forEach(row=>{
            const button=app.button('',()=>app.open(row.uuid));button.className='ux-chat-conversation-item';button.dataset.openChat=row.uuid;button.setAttribute('aria-label','Abrir conversación '+row.titulo);
            const avatar=app.text('span',(row.titulo || 'C').slice(0,2).toUpperCase(),'ux-chat-avatar'),body=app.text('span','','ux-chat-item-body');body.append(app.text('strong',row.titulo),app.text('span',row.ultimo_mensaje || (row.anuncios ? 'Anuncios' : row.estructura ? 'Grupo de estructura' : 'Sin mensajes todavía'),'ux-chat-preview'));
            const metadata=app.text('span','','ux-chat-item-meta');if(row.ultimo_envio || row.fecha)metadata.append(app.text('time',this.date(row.ultimo_envio || row.fecha)));if(Number(row.no_leidos))metadata.append(app.text('span',String(row.no_leidos),'ux-chat-unread'));if(Number(row.silenciada))metadata.append(app.text('span','Silenciado','ux-chat-muted'));
            button.append(avatar,body,metadata);target.append(button);
        });this.select(app,app.selected);
    },
    date(value) {return String(value || '').slice(0,10)===(new Date()).toLocaleDateString('en-CA') ? String(value).slice(11,16) : String(value || '').slice(0,10);},
    attachments(app,bubble,files) {
        window.SicratChatMedia.attachments(app,bubble,files);
    },
    message(app,row,actions) {
        const outgoing=Number(row.usuario)===Number(app.me.id),line=app.text('div','','ux-chat-message-row'+(outgoing ? ' ux-chat-outgoing-row' : '')),avatar=app.text('span',(row.remitente || 'U').slice(0,2).toUpperCase(),'ux-chat-avatar');line.dataset.messageId=String(row.id);line.dataset.messageUuid=row.uuid;
        const bubble=app.text('article','','ux-chat-message'+(outgoing ? ' ux-chat-outgoing' : ''));bubble.append(app.text('strong',row.remitente),app.text('p',row.mensaje,'ux-chat-text'));
        const footer=app.text('div','','ux-chat-message-footer');footer.append(app.text('time',this.date(row.f_envio),'ux-help'));if(actions){const menu=app.text('details','','ux-chat-message-actions'),toggle=app.text('summary','','ux-chat-message-toggle');toggle.title='Opciones del mensaje';toggle.setAttribute('aria-label',toggle.title);toggle.append(window.SicratUI.icon('ellipsis'));menu.append(toggle);const buttons=app.text('div','','ux-actions');actions(buttons,row);menu.append(buttons);if(buttons.children.length)footer.append(menu);}bubble.append(footer);this.attachments(app,bubble,row.attachments);line.append(avatar,bubble);return line;
    },
    messages(app,target,rows,actions,result) {
        app.chatSnapshot=result;app.chatMessageTarget=target;app.chatMessageActions=actions;
        target.replaceChildren();const list=app.text('div','','ux-chat-message-list');target.append(list);if(!rows.length)list.append(app.text('p','Todavía no hay mensajes en esta conversación.','ux-chat-message-empty'));
        rows.slice().reverse().forEach(row=>list.append(this.message(app,row,actions)));list.scrollTop=list.scrollHeight;
        if(app.chatSession){const search=app.chatSearch?.value || '';if(search!==app.chatSession.search){app.chatSession.after=Number(result.latest_id);app.chatSession.search=search;}}
        list.addEventListener('scroll',()=>{if(list.scrollTop<180)void app.messagePager?.older();const session=app.chatSession;if(session && session.unread.size && list.scrollHeight-list.scrollTop-list.clientHeight<80)void this.refresh(app);},{passive:true});
    },
    stop(app) {app.chatPoller?.destroy();app.chatPoller=null;app.chatSession=null;},
    startSidebar(app) {
        this.stop(app);app.chatPoller=new window.SicratChatPoller(async()=>{await app.list?.load();return {};},{interval:15000,alive:()=>app.chatSidebar.isConnected});app.chatPoller.schedule(15000);
    },
    status(app,message='') {
        let node=app.node('operation-panel-summary').querySelector('.ux-chat-live-status');
        if(!message){node?.remove();return;}if(!node){node=app.text('p','','ux-help ux-chat-live-status');node.setAttribute('role','status');app.node('operation-panel-summary').querySelector('.ux-chat-title').after(node);}node.textContent=message;
    },
    async pull(app,session) {
        const search=app.messagePager?.search || '',query=new URLSearchParams({after:session.after,files_after:session.files,changes_after:session.changes,limit:100,search});
        const result=await app.api('operations/conversaciones/'+session.uuid+'/messages?'+query);
        if(app.chatSession!==session || document.visibilityState!=='visible' || search!==(app.messagePager?.search || ''))return {};
        const list=app.chatMessageTarget.querySelector('.ux-chat-message-list'),bottom=list.scrollHeight-list.scrollTop-list.clientHeight<80,scroll=list.scrollTop;
        for(const row of result.rows){if(!list.querySelector('[data-message-id="'+Number(row.id)+'"]')){list.querySelector('.ux-chat-message-empty')?.remove();list.append(this.message(app,row,app.chatMessageActions));}if(!row.leido_en && Number(row.usuario)!==Number(app.me.id))session.unread.set(row.uuid,row.id);}
        for(const update of result.attachment_updates){const bubble=list.querySelector('[data-message-id="'+Number(update.id)+'"] .ux-chat-message');if(bubble)this.attachments(app,bubble,update.attachments);}
        for(const update of result.message_updates){const line=list.querySelector('[data-message-id="'+Number(update.id)+'"]');if(line){const active=app.chatGallery?.files[app.chatGallery.index]?.uuid;if(active && line.querySelector('[data-media-uuid="'+active+'"]'))app.chatGallery.dialog.close();line.querySelector('.ux-chat-text').textContent='Mensaje retirado';line.querySelectorAll('.ux-chat-attachment,[data-message-retire]').forEach(node=>node.remove());}}
        session.after=Number(result.next_after);session.files=Number(result.latest_file_id);session.changes=Number(result.latest_change_id);
        if(bottom){list.scrollTop=list.scrollHeight;for(const image of list.querySelectorAll('img:not([data-scroll-ready])')){image.dataset.scrollReady='1';image.addEventListener('load',()=>{if(list.scrollHeight-list.scrollTop-list.clientHeight<250)list.scrollTop=list.scrollHeight;},{once:true});}}else list.scrollTop=scroll;
        const composer=app.node('chat-composer');if(composer)composer.hidden=!result.can_send;
        let changed=result.rows.length>0 || result.attachment_updates.length>0 || result.message_updates.length>0;
        if(session.unread.size){const viewport=list.getBoundingClientRect(),ids=Array.from(session.unread.keys()).filter(uuid=>{const node=list.querySelector('[data-message-uuid="'+uuid+'"]');if(!node)return false;const bounds=node.getBoundingClientRect();return bounds.bottom>viewport.top && bounds.top<viewport.bottom;}).slice(0,100);if(ids.length){await app.api('operations/conversaciones/'+session.uuid+'/read','POST',{messages:ids});if(app.chatSession!==session)return {};ids.forEach(id=>session.unread.delete(id));changed=true;}}
        if(app.chatSession!==session || document.visibilityState!=='visible')return {};
        if(changed || Date.now()-session.sidebarAt>=15000){await app.list.load();session.sidebarAt=Date.now();}
        if(app.chatSession!==session || document.visibilityState!=='visible')return {};
        this.status(app,result.conversation_state!=='activa' ? 'La conversación está cerrada. Puedes consultar sus mensajes.' : !result.can_send ? 'Consulta disponible. Actualmente no tienes autorización para enviar mensajes.' : '');
        if(result.conversation_state!=='activa')app.chatPoller.destroy();
        return {changed,more:!!result.pagination.has_more || result.changes_has_more};
    },
    refresh(app) {return app.chatPoller?.refresh() || Promise.resolve();},
    start(app) {
        this.stop(app);const seed=app.chatSnapshot;const session={uuid:app.selected,after:Number(seed?.latest_id || 0),files:Number(seed?.latest_file_id || 0),changes:Number(seed?.latest_change_id || 0),unread:new Map(),sidebarAt:Date.now(),search:app.messagePager?.search || ''};app.chatSession=session;
        app.chatPoller=new window.SicratChatPoller(()=>this.pull(app,session),{alive:()=>app.chatRoom.isConnected && app.node('operation-detail-dialog').open,onError:error=>{
            const denied=[401,403,404].includes(error.status);this.status(app,denied ? error.message || 'Esta conversación ya no está disponible.' : 'No se pudo actualizar el chat. Reintentando automáticamente.');
            if(denied){const composer=app.node('chat-composer');if(composer)composer.hidden=true;app.chatPoller.destroy();}
        }});app.chatPoller.schedule();
        if(seed?.conversation_state!=='activa'){this.status(app,'La conversación está cerrada. Puedes consultar sus mensajes.');app.chatPoller.destroy();}else if(seed?.can_send===false)this.status(app,'Consulta disponible. Actualmente no tienes autorización para enviar mensajes.');
        const composer=app.node('chat-composer');if(composer){composer.hidden=seed?.can_send===false;composer.addEventListener('input',()=>app.chatPoller?.active());}
    },
    activate(app) {
        const dialog=app.node('operation-detail-dialog'),panel=app.node('operation-panel-summary');app.node('operation-detail-tabs').hidden=true;app.node('operation-detail-title').parentElement.hidden=true;panel.hidden=false;app.node('operation-panel-actions').hidden=false;
        const header=panel.querySelector('.ux-chat-title');header.prepend(app.chatBack,app.text('span',(header.querySelector('h6').textContent || 'C').slice(0,2).toUpperCase(),'ux-chat-avatar'));header.append(app.chatOptions);
        const search=panel.querySelector('form.ux-inline-form');if(search){const wrapper=app.text('details','','ux-chat-message-search'),toggle=app.text('summary','','btn ux-chat-icon');toggle.title='Buscar mensajes';toggle.setAttribute('aria-label',toggle.title);toggle.append(window.SicratUI.icon('search'));wrapper.append(toggle,search);header.insertBefore(wrapper,app.chatOptions);}
        const members=panel.querySelector('details.ux-section');if(members)app.node('operation-panel-actions').append(members);
        const thread=panel.querySelector('div.ux-section');if(thread)thread.className='ux-chat-thread';
        const composer=app.node('operation-panel-actions').querySelector('form:has(textarea[name=text])');if(composer){
            composer.id='chat-composer';composer.className='ux-chat-composer';panel.append(composer);const text=composer.elements.text;text.placeholder='Escribe un mensaje…';text.rows=1;text.setAttribute('aria-label','Mensaje');text.closest('label').replaceChildren(text);
            composer.dataset.busyText='Enviando…';text.addEventListener('keydown',event=>{if(innerWidth>=768 && event.key==='Enter' && !event.shiftKey && !event.altKey && !event.ctrlKey && !event.metaKey && !event.isComposing && event.keyCode!==229){event.preventDefault();if(!composer.hasAttribute('aria-busy'))composer.requestSubmit();}});
            const file=composer.elements.file;if(file){const label=file.closest('label');label.className='ux-chat-attach';label.title='Adjuntar archivo';label.replaceChildren(window.SicratUI.icon('paperclip'),app.text('span','Adjuntar archivo','ux-sr-only'),file);file.className='ux-sr-only';file.addEventListener('change',()=>{let selected=composer.querySelector('.ux-chat-file-name');if(!selected){selected=app.text('span','','ux-chat-file-name');composer.append(selected);}selected.textContent=file.files[0]?.name || '';});composer.addEventListener('reset',()=>composer.querySelector('.ux-chat-file-name')?.remove());}
            const footer=composer.querySelector('.ux-modal-footer');footer?.querySelectorAll('button[type=button]').forEach(button=>button.remove());const send=composer.querySelector('button[type=submit]') || composer.querySelector('button:not([type])');send.replaceChildren(window.SicratUI.icon('send'),app.text('span','Enviar'));send.classList.add('ux-chat-send');if(footer){composer.append(send);footer.remove();}
        }
        app.chatOptions.open=false;app.node('chat-placeholder').hidden=true;dialog.open=true;this.select(app,app.selected);app.chatResponsive();const list=app.chatMessageTarget.querySelector('.ux-chat-message-list');list.scrollTop=list.scrollHeight;this.start(app);if(innerWidth<768)app.chatRoom.scrollIntoView({block:'start'});
    }
};
