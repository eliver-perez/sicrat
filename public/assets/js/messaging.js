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
        tools.append(this.action(app.node('operation-new'),'Nueva conversación','plus'));
        const menu=app.text('details','','ux-chat-menu'),toggle=app.text('summary','','btn ux-chat-icon');toggle.title='Opciones de mensajería';toggle.setAttribute('aria-label',toggle.title);toggle.append(window.SicratUI.icon('ellipsis'));
        const entries=app.text('div','','card ux-chat-menu-content');entries.append(app.node('operation-settings'),app.node('operation-refresh'),app.node('operation-export'));menu.append(toggle,entries);tools.append(menu);head.append(title,tools);
        const filter=app.node('operation-filter');filter.className='ux-chat-search';const input=filter.elements.search;input.placeholder='Buscar conversación…';input.setAttribute('aria-label','Buscar conversación');input.closest('label').replaceChildren(input);filter.prepend(window.SicratUI.icon('search'));
        const search=filter.querySelector('button:not([type])');this.action(search,'Buscar conversación','search');
        sidebar.append(head,filter,app.text('p','Conversaciones','ux-chat-list-label'),app.node('operation-list'));
        const room=app.text('section','','ux-chat-room'),placeholder=app.text('div','','ux-chat-empty');placeholder.id='chat-placeholder';placeholder.append(window.SicratUI.icon('message-circle'),app.text('h6','Tus conversaciones'),app.text('p','Selecciona un chat para ver los mensajes o inicia una conversación.'));room.append(placeholder);
        const dialog=app.node('operation-detail-dialog');dialog.classList.add('ux-chat-conversation');dialog.querySelectorAll(':scope > .ux-actions').forEach(element=>{if(!element.children.length)element.remove();});dialog.querySelectorAll('[data-close]').forEach(button=>button.hidden=true);room.append(dialog);layout.append(sidebar,room);card.remove();app.chatSidebar=sidebar;app.chatRoom=room;
        const back=this.action(app.button('Conversaciones',()=>{dialog.close();placeholder.hidden=false;this.select(app,null);responsive();}),'Volver a conversaciones','chevron-left');back.classList.add('ux-chat-back');app.chatBack=back;app.node('operation-help')?.remove();
        const options=app.text('details','','ux-chat-options'),summary=app.text('summary','','btn ux-chat-icon');summary.title='Opciones de conversación';summary.setAttribute('aria-label',summary.title);summary.append(window.SicratUI.icon('ellipsis'));options.append(summary,app.node('operation-panel-actions'));dialog.append(options);app.chatOptions=options;
        const responsive=()=>{const mobile=innerWidth<768,active=dialog.open;sidebar.hidden=mobile && active;room.hidden=mobile && !active;};app.chatResponsive=responsive;window.addEventListener('resize',responsive);dialog.addEventListener('close',responsive);responsive();
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
    messages(app,target,rows,actions) {
        target.replaceChildren();const list=app.text('div','','ux-chat-message-list');target.append(list);if(!rows.length)list.append(app.text('p','Todavía no hay mensajes en esta conversación.','ux-chat-message-empty'));
        rows.slice().reverse().forEach(row=>{
            const outgoing=Number(row.usuario)===Number(app.me.id),line=app.text('div','','ux-chat-message-row'+(outgoing ? ' ux-chat-outgoing-row' : '')),avatar=app.text('span',(row.remitente || 'U').slice(0,2).toUpperCase(),'ux-chat-avatar');
            const bubble=app.text('article','','ux-chat-message'+(outgoing ? ' ux-chat-outgoing' : ''));bubble.append(app.text('strong',row.remitente),app.text('p',row.mensaje,'ux-chat-text'));
            row.attachments.forEach(file=>{const link=app.text('a',file.nombre_original,'ux-chat-attachment');link.href=window.SicratPhase3.base+'/media/'+file.uuid;link.target='_blank';link.rel='noopener';link.prepend(window.SicratUI.icon('paperclip'));if(file.mime_type?.startsWith('image/')){const image=document.createElement('img');image.src=link.href+'?size=thumb';image.alt=file.nombre_original;image.loading='lazy';link.append(image);}bubble.append(link);});
            const footer=app.text('div','','ux-chat-message-footer');footer.append(app.text('time',this.date(row.f_envio),'ux-help'));if(actions){const menu=app.text('details','','ux-chat-message-actions');menu.append(app.text('summary','Opciones'));const buttons=app.text('div','','ux-actions');actions(buttons,row);menu.append(buttons);if(buttons.children.length)footer.append(menu);}bubble.append(footer);line.append(avatar,bubble);list.append(line);
        });list.scrollTop=list.scrollHeight;
    },
    activate(app) {
        const dialog=app.node('operation-detail-dialog'),panel=app.node('operation-panel-summary');app.node('operation-detail-tabs').hidden=true;app.node('operation-detail-title').parentElement.hidden=true;panel.hidden=false;app.node('operation-panel-actions').hidden=false;
        const header=panel.querySelector('.ux-chat-title');header.prepend(app.chatBack,app.text('span',(header.querySelector('h6').textContent || 'C').slice(0,2).toUpperCase(),'ux-chat-avatar'));header.append(app.chatOptions);
        const search=panel.querySelector('form.ux-inline-form');if(search){const wrapper=app.text('details','','ux-chat-message-search'),toggle=app.text('summary','','btn ux-chat-icon');toggle.title='Buscar mensajes';toggle.setAttribute('aria-label',toggle.title);toggle.append(window.SicratUI.icon('search'));wrapper.append(toggle,search);header.insertBefore(wrapper,app.chatOptions);}
        const members=panel.querySelector('details.ux-section');if(members)app.node('operation-panel-actions').append(members);
        const thread=panel.querySelector('div.ux-section');if(thread)thread.className='ux-chat-thread';
        const composer=app.node('operation-panel-actions').querySelector('form:has(textarea[name=text])');if(composer){
            composer.id='chat-composer';composer.className='ux-chat-composer';panel.append(composer);const text=composer.elements.text;text.placeholder='Escribe un mensaje…';text.rows=1;text.setAttribute('aria-label','Mensaje');text.closest('label').replaceChildren(text);
            const file=composer.elements.file;if(file){const label=file.closest('label');label.className='ux-chat-attach';label.title='Adjuntar archivo';label.replaceChildren(window.SicratUI.icon('paperclip'),app.text('span','Adjuntar archivo','ux-sr-only'),file);file.className='ux-sr-only';file.addEventListener('change',()=>{let selected=composer.querySelector('.ux-chat-file-name');if(!selected){selected=app.text('span','','ux-chat-file-name');composer.append(selected);}selected.textContent=file.files[0]?.name || '';});composer.addEventListener('reset',()=>composer.querySelector('.ux-chat-file-name')?.remove());}
            const footer=composer.querySelector('.ux-modal-footer');footer?.querySelectorAll('button[type=button]').forEach(button=>button.remove());const send=composer.querySelector('button[type=submit]') || composer.querySelector('button:not([type])');send.replaceChildren(window.SicratUI.icon('send'),app.text('span','Enviar'));send.classList.add('ux-chat-send');if(footer){composer.append(send);footer.remove();}
        }
        app.chatOptions.open=false;app.node('chat-placeholder').hidden=true;dialog.open=true;this.select(app,app.selected);app.chatResponsive();if(innerWidth<768)app.chatRoom.scrollIntoView({block:'start'});
    }
};
