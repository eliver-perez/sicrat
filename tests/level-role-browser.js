const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');
module.exports=async function({directory,call,evaluate,waitFor,navigate}) {
    const fixture=JSON.parse(fs.readFileSync(path.join(directory,'level-role.json'),'utf8'));let checks=0;
    const check=(condition,message)=>{assert.ok(condition,message);checks++;};
    const api=(url,method='GET',data=null)=>evaluate(`(async()=>{const r=await fetch('/api/'+${JSON.stringify(url)},{method:${JSON.stringify(method)},headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:${data===null?'undefined':JSON.stringify(JSON.stringify(data))}});return {...await r.json(),status:r.status};})()`);
    await navigate('/autenticacion/');check((await api('auth/login','POST',{username:'administrador',password:' nueva contraseña '})).status===200,'Login administrador');
    await navigate('/');check((await api('auth/election','POST',{process:fixture.election})).status===200,'Seleccionar elección');
    await navigate('/administration/');await waitFor('document.querySelectorAll("#level-list button").length===4');
    await evaluate('document.getElementById("administration-tab-levels").click();Array.from(document.querySelectorAll("#level-list button")).find(b=>b.textContent.includes("Coordinador General")).click()');
    check(await evaluate(`document.querySelector('#level-form [name=associated_role]').value===${JSON.stringify(String(fixture.roles.general))}`),'Configuración muestra rol asociado a General');
    await evaluate('Array.from(document.querySelectorAll("#level-list button")).find(b=>b.textContent.includes("Coordinador de Zona")).click()');
    check(await evaluate(`document.querySelector('#level-form [name=associated_role]').value===${JSON.stringify(String(fixture.roles.zona))}`),'Configuración muestra rol diferente para Zona');
    check(await evaluate('!Array.from(document.querySelector("#level-form [name=associated_role]").options).some(o=>o.textContent.includes("Super Administrador")||o.textContent.includes("organización ajena"))'),'Selector excluye roles no válidos');
    await evaluate('document.getElementById("level-form").requestSubmit()');await waitFor('document.querySelector(".swal2-title")?.textContent.includes("Nivel")');checks++;
    for(const mobile of [false,true]){
        await call('Emulation.setDeviceMetricsOverride',{width:mobile?390:1440,height:mobile?844:1080,deviceScaleFactor:1,mobile});
        await navigate('/structure/');await waitFor('document.querySelector("#structure-members button")');
        await evaluate('document.getElementById("structure-all").click()');
        await waitFor('document.getElementById("structure-members").textContent.includes("Persona cuenta desde modal")');
        await evaluate('Array.from(document.querySelectorAll("#structure-members tr")).find(b=>b.textContent.includes("Persona cuenta desde modal")).querySelector("button").click()');
        await waitFor('document.getElementById("structure-member-dialog").open');
        await evaluate('document.getElementById("structure-administration").open=true;document.getElementById("structure-create-account").click()');
        await waitFor('document.getElementById("structure-account-dialog").open');
        check(await evaluate('!document.querySelector("#structure-account-form select") && !document.querySelector("#structure-account-form [name=type]")'),'Sin selector de rol libre');
        check(await evaluate('document.getElementById("structure-account-role").textContent==="Rol Zona prueba"'),'Rol derivado visible');
        check(await evaluate('(()=>{const r=document.getElementById("structure-account-dialog").getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1;})()'),'Modal dentro de pantalla');
        const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(directory,'level-role-'+(mobile?'mobile':'desktop')+'.png'),Buffer.from(shot.data,'base64'));
        if(mobile){
            await evaluate('document.getElementById("structure-account-form").elements.username.value="nivel-rol-modal";document.getElementById("structure-account-form").elements.password.value="Nivel rol modal seguro";document.getElementById("structure-account-form").requestSubmit()');
            await waitFor('!document.getElementById("structure-account-dialog").open');
            const person=(await api(`structures/${fixture.structure}/members/${fixture.members.browser}`)).data;check(person.has_account,'Cuenta creada sin seleccionar rol');
            const access=(await api(`users/${person.account}/accesses`)).data.accesses;check(access.find(a=>a.process===fixture.election).type===fixture.roles.zona,'Rol derivado persistido por backend');
        }
    }
    await evaluate('Array.from(document.querySelectorAll("#structure-members tr")).find(b=>b.textContent.includes("Persona sin rol configurado")).querySelector("button").click()');await waitFor('document.getElementById("structure-member-dialog").open');
    await evaluate('document.getElementById("structure-administration").open=true;document.getElementById("structure-create-account").click()');await waitFor('document.getElementById("structure-account-dialog").open');
    check(await evaluate('document.getElementById("structure-account-role").textContent.includes("Este nivel no tiene un rol asociado")&&document.querySelector("#structure-account-form button:not([type=button])").disabled'),'Sin asociación: mensaje inline y alta bloqueada');
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1080,deviceScaleFactor:1,mobile:false});
    console.log('Nivel → Rol: '+checks+' comprobaciones Chrome escritorio/móvil.');return checks;
};
