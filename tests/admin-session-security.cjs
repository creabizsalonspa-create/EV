'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const {test}=require('node:test');
class Element{
    constructor(tag){
        this.tag=tag;this.children=[];this._text='';this.attributes={};
        this.value='';this.disabled=false;
    }
    get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
    set textContent(value){this._text=String(value);this.children=[];}
    append(...nodes){this.children.push(...nodes);}
    replaceChildren(...nodes){this._text='';this.children=nodes;}
    setAttribute(key,value){this.attributes[key]=String(value);}
}
function fixture(){
    const root=new Element('div'),assigned=[],window={
        confirming:false,
        confirm(){return this.confirming;},
        location:{assign(value){assigned.push(value);}}
    };
    const document={
        getElementById(key){
            assert.equal(key,'admin-session-security');
            return root;
        },
        createElement(tag){return new Element(tag);}
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,
        '../services/business-api/public/admin-session-security.js'),'utf8'),{
        window,document
    });
    return{root,assigned,window,...window.EvAdminSessions};
}
function find(root,tag){
    let result=[];
    function walk(node){
        if(node.tag===tag)result.push(node);
        node.children.forEach(walk);
    }
    walk(root);return result;
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));

test('only Admin sees sign-out-everywhere action, logout scrubs private form',()=>{
    const ui=fixture(),calls=[];
    ui.setActor(null,async x=>{calls.push(x);});
    ui.setActor({role:'customer'},async x=>{calls.push(x);});
    assert.equal(ui.root.children.length,0);
    ui.setActor({role:'admin'},async x=>{calls.push(x);});
    assert.ok(find(ui.root,'form').length);
    assert.equal(find(ui.root,'input')[0].type,'password');
    ui.clear();
    assert.equal(ui.root.children.length,0);
    assert.equal(calls.length,0);
});

test('no password or cancelled confirmation never sends revocation',async()=>{
    const ui=fixture(),calls=[];
    ui.setActor({role:'admin'},async (...args)=>{calls.push(args);});
    const form=find(ui.root,'form')[0],input=find(form,'input')[0];
    await form.onsubmit({preventDefault(){}});
    assert.equal(calls.length,0);
    input.value='correct-admin-password';
    await form.onsubmit({preventDefault(){}});
    assert.equal(calls.length,0);
    assert.equal(input.value,'correct-admin-password');
});

test('valid confirmation revokes through a single POST, scrubs and navigates anonymously',async()=>{
    const ui=fixture(),calls=[];
    ui.window.confirming=true;
    ui.setActor({role:'admin'},async (...args)=>{calls.push(args);return null;});
    const form=find(ui.root,'form')[0],input=find(form,'input')[0];
    input.value='newly confirmed password';
    await form.onsubmit({preventDefault(){}});
    assert.equal(calls.length,1);
    assert.equal(calls[0][0],'admin/sessions/revoke-all');
    assert.equal(calls[0][1],'POST');
    assert.equal(calls[0][2].password,'newly confirmed password');
    assert.equal(input.value,'');
    assert.equal(ui.root.children.length,0);
    assert.deepEqual(ui.assigned,['/?sessions=revoked']);
});

test('failure does not expose server exception or pretend devices were revoked',async()=>{
    const ui=fixture();
    ui.window.confirming=true;
    ui.setActor({role:'admin'},async()=>{throw Error('private session key');});
    const form=find(ui.root,'form')[0],input=find(form,'input')[0];
    input.value='wrong-password';
    await form.onsubmit({preventDefault(){}});
    assert.equal(input.value,'');
    assert.match(ui.root.textContent,/Unable to revoke sessions/);
    assert.equal(ui.root.textContent.includes('private session key'),false);
    assert.deepEqual(ui.assigned,[]);
});

test('stale revocation response cannot navigate after the Admin signs out',async()=>{
    const ui=fixture();
    ui.window.confirming=true;
    let release;
    ui.setActor({role:'admin'},()=>new Promise(resolve=>{release=resolve;}));
    const form=find(ui.root,'form')[0],input=find(form,'input')[0];
    input.value='correct-password';
    const promise=form.onsubmit({preventDefault(){}});
    assert.equal(typeof release,'function');
    ui.setActor({role:'vendor'},async()=>{throw Error('no request');});
    release(null);
    await promise;await tick();
    assert.deepEqual(ui.assigned,[]);
    assert.equal(ui.root.children.length,0);
});
