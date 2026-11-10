/* Authentication failures are not network failures. No private payload is retained here. */
(() => {
    let leaving=false;
    async function expired(reason='session-expired', revoked=false) {
        if(leaving || location.pathname.startsWith('/autenticacion'))return;
        leaving=true;
        try{sessionStorage.setItem('sicrat-pwa-blocked','1');}catch{}
        document.querySelectorAll('.wrapper,[data-offline-shell] main').forEach(node=>{node.replaceChildren();node.hidden=true;});
        // A request can bypass the worker (e.g. a page not yet controlled). Its real 401
        // must still revoke a previously prepared grant before opening authentication.
        if(!revoked && navigator.serviceWorker) {
            try {
                const worker=navigator.serviceWorker.controller || (await navigator.serviceWorker.getRegistration())?.active;
                if(worker) await new Promise((resolve,reject)=>{
                    const channel=new MessageChannel();
                    channel.port1.onmessage=event=>{channel.port1.close();event.data.ok ? resolve() : reject(new Error(event.data.message));};
                    worker.postMessage({type:'LOGOUT'},[channel.port2]);
                });
            } catch { /* The protected DOM is already closed; authentication still requires the server. */ }
        }
        location.replace('/autenticacion/'+(reason==='session-expired'?'?reason=session-expired':''));
    }
    window.SicratSession={expired,get leaving(){return leaving;}};
    const originalFetch=window.fetch;
    window.fetch=async function(input,init){
        const response=await originalFetch.call(this,input,init);
        const url=new URL(typeof input==='string'||input instanceof URL?input:input.url,location.href);
        if(url.origin===location.origin && url.pathname.startsWith('/api/') && url.pathname!=='/api/auth/login' && response.status===401)expired();
        return response;
    };
})();
