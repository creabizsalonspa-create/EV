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
            assert.equal(key,'account-session-security');
            return root;
        },
        createElement(tag){return new Element(tag);}
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,
        '../services/business-api/public/account-session-security.js'),'utf8'),{
        window,document
    });
    return{root,assigned,window,...window.EvAccountSessions};
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

test('only customer/vendor sees action; logout removes private fields',()=>{
 const ui=fixture();
 for(const role of ['customer','vendor']){
  ui.setActor({role},async()=>{},()=>{});
  assert.equal(ui.root.hidden,false);
  assert.equal(find(ui.root,'input')[0].type,'password');
  ui.clear();assert.equal(ui.root.hidden,true);
 }
 ui.setActor({role:'admin'},async()=>{},()=>{});
 assert.equal(find(ui.root,'form').length,0);
});
test('password is cleared before network; duplicate submit cannot repeat revoke',async()=>{
 const ui=fixture(),calls=[];let finish,revoked=0;
 ui.setActor({role:'customer'},(...args)=>{calls.push(args);return new Promise(r=>finish=r)},()=>revoked++);
 const form=find(ui.root,'form')[0],input=find(form,'input')[0];
 await form.onsubmit({preventDefault(){}});assert.equal(calls.length,0);
 input.value='confirmed-password';
 const pending=form.onsubmit({preventDefault(){}});
 assert.equal(input.value,'');
 input.value='another-password';await form.onsubmit({preventDefault(){}});
 assert.equal(calls.length,1);assert.equal(calls[0][0],'account/sessions/revoke-all');
 assert.equal(calls[0][2].password,'confirmed-password');
 finish();await pending;assert.equal(revoked,1);assert.equal(ui.root.hidden,true);
});
test('old completion cannot revoke a newly selected identity in the UI',async()=>{
 const ui=fixture();let finish,revoked=0;
 ui.setActor({role:'vendor'},()=>new Promise(r=>finish=r),()=>revoked++);
 const form=find(ui.root,'form')[0];find(form,'input')[0].value='confirmed-password';
 const pending=form.onsubmit({preventDefault(){}});
 ui.setActor({role:'customer'},async()=>{},()=>revoked++);
 finish();await pending;assert.equal(revoked,0);assert.equal(ui.root.hidden,false);
});
test('cross-tab signals carry no identity; receiver clears cached account before reload',()=>{
 const events={},stored=[],assigned=[];let cleared=0;
 const context={user:{id:99},crypto:{randomUUID:()=> 'disposable-signal'},
  localStorage:{setItem:(k,v)=>stored.push([k,v]),removeItem:k=>stored.push([k])},
  clearIdentity:()=>{cleared++;context.user=null},
  window:{addEventListener:(event,handler)=>events[event]=handler,location:{assign:v=>assigned.push(v)}}};
 const source=fs.readFileSync(path.join(__dirname,'../services/business-api/public/platform.js'),'utf8');
 vm.createContext(context);vm.runInContext(source.slice(source.indexOf('// Same-origin tabs share')),context);
 vm.runInContext('signalSessionRevocation()',context);
 assert.deepEqual(stored,[['ev.session.revoked','disposable-signal'],['ev.session.revoked']]);
 events.storage({key:'unrelated',newValue:'anything'});assert.equal(cleared,0);
 events.storage({key:'ev.session.revoked',newValue:null});assert.equal(cleared,0);
 events.storage({key:'ev.session.revoked',newValue:'signal'});assert.equal(cleared,1);
 assert.deepEqual(assigned,['/?sessions=revoked']);
 events.storage({key:'ev.session.revoked',newValue:'another'});assert.equal(cleared,1);
});
test('unavailable browser storage cannot defeat server revocation completion',()=>{
 const source=fs.readFileSync(path.join(__dirname,'../services/business-api/public/platform.js'),'utf8');
 const context={user:null,crypto:{randomUUID:()=> 'signal'},localStorage:{setItem(){throw Error('Storage blocked')}},window:{addEventListener(){}}};
 vm.createContext(context);vm.runInContext(source.slice(source.indexOf('// Same-origin tabs share')),context);
 assert.doesNotThrow(()=>vm.runInContext('signalSessionRevocation()',context));
});
