'use strict';

window.SicratChatMedia={
    size(bytes) {const value=Number(bytes);return Number.isFinite(value) && value>=0 ? value<1024 ? value+' B' : value<1048576 ? (value/1024).toFixed(1)+' KB' : (value/1048576).toFixed(1)+' MB' : '';},
    url(uuid,thumb=false) {return window.SicratPhase3.base+'/media/'+encodeURIComponent(uuid)+(thumb ? '?size=thumb' : '');},
    attachments(app,bubble,files=[]) {
        bubble.querySelectorAll('.ux-chat-attachment').forEach(node=>node.remove());
        files.forEach(file=>{
            let item;
            if(file.mime_type?.startsWith('image/')) {
                item=app.button('',()=>this.open(app,file.uuid));item.className='ux-chat-attachment ux-chat-image';item.dataset.mediaUuid=file.uuid;item.dataset.mediaName=file.nombre_original;item.title='Ver imagen: '+file.nombre_original;item.setAttribute('aria-label',item.title);
                const image=document.createElement('img');image.src=this.url(file.uuid,true);image.alt=file.nombre_original;image.loading='lazy';
                if(Number(file.ancho)>0 && Number(file.alto)>0){const scale=Math.min(208/Number(file.ancho),176/Number(file.alto),1);image.width=Math.round(Number(file.ancho)*scale);image.height=Math.round(Number(file.alto)*scale);}
                image.addEventListener('error',()=>{image.hidden=true;if(!item.querySelector('.ux-chat-media-error'))item.append(app.text('span','No fue posible cargar la imagen.','ux-help ux-chat-media-error'));},{once:true});item.append(image);
            }else {
                const pdf=file.mime_type==='application/pdf';item=app.text('a','','ux-chat-attachment ux-chat-document');item.href=this.url(file.uuid);item.target='_blank';item.rel='noopener';item.title='Abrir '+file.nombre_original;item.setAttribute('aria-label',item.title);
                const badge=app.text('span','','ux-chat-document-icon');badge.append(window.SicratUI.icon('file-text'),app.text('span',pdf ? 'PDF' : 'Archivo'));
                const details=app.text('span','','ux-chat-document-info');details.append(app.text('strong',file.nombre_original),app.text('small',this.size(file.tamanio)));item.append(badge,details);
            }
            bubble.insertBefore(item,bubble.querySelector('.ux-chat-message-footer'));
        });
    },
    open(app,uuid) {
        const seen=new Set(),files=Array.from(app.chatMessageTarget.querySelectorAll('.ux-chat-image')).filter(node=>!seen.has(node.dataset.mediaUuid) && seen.add(node.dataset.mediaUuid)).map(node=>({uuid:node.dataset.mediaUuid,name:node.dataset.mediaName}));
        let index=files.findIndex(file=>file.uuid===uuid);if(index<0)return;
        if(!app.chatGallery) {
            const dialog=app.text('dialog','','ux-dialog card ux-chat-gallery');dialog.setAttribute('aria-labelledby','chat-gallery-title');
            const header=app.text('div','','ux-actions'),title=app.text('h6','','card-title');title.id='chat-gallery-title';
            const close=window.SicratUI.action(app.button('Cerrar',()=>dialog.close()),'Cerrar visor','x');header.append(title,close);
            const body=app.text('div','','ux-chat-gallery-body'),image=document.createElement('img'),status=app.text('p','','ux-help');status.setAttribute('role','status');body.append(image,status);
            const controls=app.text('div','','ux-actions'),previous=window.SicratUI.action(app.button('Anterior',()=>app.chatGallery.move(-1)),'Imagen anterior','chevron-left'),next=window.SicratUI.action(app.button('Siguiente',()=>app.chatGallery.move(1)),'Imagen siguiente','chevron-right'),position=app.text('span','','ux-help');controls.append(previous,position,next);dialog.append(header,body,controls);document.body.append(dialog);
            image.addEventListener('load',()=>{status.textContent='';image.hidden=false;});image.addEventListener('error',()=>{image.hidden=true;status.textContent='No fue posible recuperar la imagen. Comprueba que siga disponible.';});
            dialog.addEventListener('keydown',event=>{if(event.key==='ArrowLeft' || event.key==='ArrowRight'){event.preventDefault();app.chatGallery.move(event.key==='ArrowLeft' ? -1 : 1);}});
            dialog.addEventListener('close',()=>{image.removeAttribute('src');app.chatGallery.files=[];});
            app.chatGallery={dialog,title,image,status,previous,next,position,files:[],index:0,move:offset=>{const viewer=app.chatGallery,nextIndex=viewer.index+offset;if(nextIndex>=0 && nextIndex<viewer.files.length){viewer.index=nextIndex;this.show(app);}}};
        }
        const viewer=app.chatGallery;viewer.files=files;viewer.index=index;this.show(app);if(!viewer.dialog.open)viewer.dialog.showModal();
    },
    show(app) {
        const viewer=app.chatGallery,file=viewer.files[viewer.index];viewer.title.textContent=file.name;viewer.image.alt=file.name;viewer.image.hidden=true;viewer.status.textContent='Cargando imagen…';viewer.image.src=this.url(file.uuid);viewer.position.textContent=(viewer.index+1)+' / '+viewer.files.length;
        window.SicratFeedback.setDisabled(viewer.previous,viewer.index===0);window.SicratFeedback.setDisabled(viewer.next,viewer.index===viewer.files.length-1);
    }
};
