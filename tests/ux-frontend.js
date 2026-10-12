const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let checks = 0;
class Element {
    constructor(tag = 'div') { this.tagName=tag; this.children=[]; this.dataset={}; this.listeners={}; this.attributes={}; this.hidden=false; this.disabled=false; this.textContent=''; this.classes=new Set(); this.classList={toggle:(name,value)=>value ? this.classes.add(name) : this.classes.delete(name)}; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children=children; }
    setAttribute(name,value) { this.attributes[name]=value; }
    addEventListener(name,listener) { this.listeners[name]=listener; }
    focus() { this.focused=true; }
    set innerHTML(value) { throw new Error('La interfaz no debe insertar HTML procedente de datos.'); }
}
const panels = new Map();
const context=vm.createContext({window:{},document:{addEventListener(){},createElement:tag=>new Element(tag),querySelector:selector=>panels.get(selector)}});
vm.runInContext(fs.readFileSync('public/assets/js/ux.js','utf8'),context);
const ui=context.window.SicratUI;
assert.equal(ui.label('aprobaciones.resolver'),'Resolver aprobaciones'); checks++;
assert.equal(ui.label('archivos.subir'),'Subir archivos'); checks++;
assert.equal(ui.label('usuarios_accesos'),'Usuarios y accesos'); checks++;
const payload='<img src=x onerror=alert(1)>';
const badge=ui.badge(payload,true);
assert.equal(badge.textContent,payload); checks++;
assert.equal(badge.children.length,0); checks++;
const target=new Element(); ui.details(target,{nombre:payload,contacto:{telefono:'123'},vacio:null});
const all=element=>[element,...element.children.flatMap(all)];
assert.ok(all(target).some(element=>element.textContent===payload)); checks++;
assert.ok(all(target).some(element=>element.textContent==='Sin información')); checks++;
const buttons=['one','hidden','disabled','two'].map(name=>{
    const button=new Element('button');button.dataset.hsTab='#'+name;
    panels.set('#'+name,new Element()); return button;
});
buttons[1].hidden=true;buttons[2].disabled=true;
const changes=[];
const activate=ui.tabs({querySelectorAll:()=>buttons},button=>changes.push(button.dataset.hsTab));
async function main() {
    await activate(buttons[0]);
    assert.equal(panels.get('#one').hidden,false); checks++;
    assert.equal(panels.get('#two').hidden,true); checks++;
    assert.equal(buttons[0].attributes['aria-selected'],'true'); checks++;
    assert.equal(buttons[3].tabIndex,-1); checks++;
    await activate(buttons[1]); assert.equal(changes.length,1); checks++;
    await activate(buttons[2]); assert.equal(changes.length,1); checks++;
    let prevented=false;
    buttons[0].listeners.keydown({key:'ArrowRight',preventDefault(){prevented=true;}});
    assert.ok(prevented && buttons[3].focused); checks++;
    assert.equal(buttons[3].attributes['aria-selected'],'true'); checks++;
    assert.equal(panels.get('#one').hidden,true); checks++;
    buttons[3].listeners.keydown({key:'Home',preventDefault(){}});
    assert.equal(buttons[0].attributes['aria-selected'],'true'); checks++;
    console.log(`OK: ${checks} comprobaciones frontend de UX, teclado y texto seguro.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
