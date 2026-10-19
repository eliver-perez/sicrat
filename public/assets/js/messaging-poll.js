'use strict';

// Un solo pedido en vuelo; la espera empieza cuando termina, nunca se acumulan intervalos.
window.SicratChatPoller=class {
    constructor(work,{interval=3000,onError=()=>{},alive=()=>true}={}) {
        this.work=work;this.interval=interval;this.onError=onError;this.alive=alive;this.empty=0;this.errors=0;this.timer=null;this.running=null;this.destroyed=false;this.again=false;
        this.visibility=()=>{clearTimeout(this.timer);this.timer=null;if(document.visibilityState==='visible')void this.refresh();};
        document.addEventListener('visibilitychange',this.visibility);
    }
    schedule(delay=this.interval) {
        clearTimeout(this.timer);this.timer=null;
        if(!this.destroyed && this.alive() && document.visibilityState==='visible')this.timer=setTimeout(()=>void this.refresh(),delay);
    }
    refresh() {
        if(this.destroyed || !this.alive() || document.visibilityState!=='visible')return Promise.resolve();
        if(this.running){this.again=true;return this.running;}
        clearTimeout(this.timer);this.timer=null;
        this.running=Promise.resolve().then(()=>this.work()).then(result=>{
            if(this.destroyed)return;this.errors=0;this.empty=result?.changed ? 0 : this.empty+1;this.again ||= !!result?.more;
        }).catch(error=>{if(!this.destroyed){this.errors++;this.onError(error);}}).finally(()=>{
            this.running=null;const delay=this.again ? 0 : this.errors ? Math.min(30000,this.interval*2**this.errors) : Math.min(15000,this.interval+Math.max(0,Math.floor((this.empty-20)/5))*3000);this.again=false;this.schedule(delay);
        });
        return this.running;
    }
    active() {const delayed=this.empty>20;this.empty=0;if(delayed && this.timer && !this.running)this.schedule();}
    destroy() {this.destroyed=true;clearTimeout(this.timer);this.timer=null;document.removeEventListener('visibilitychange',this.visibility);}
};
