'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element {
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.value='';this.checked=false;this._text='';}
 set textContent(value){this._text=String(value);this.children=[];}
 get textContent(){return this._text+this.children.map(n=>n.textContent).join(' ');}
 append(...nodes){this.children.push(...nodes);} prepend(...nodes){this.children.unshift(...nodes);}
 replaceChildren(...nodes){this.children=nodes;this._text='';} setAttribute(){}
 querySelectorAll(tag){return find(this,n=>n.tag===tag);}
}
function find(root,match){return [...(match(root)?[root]:[]),...root.children.flatMap(n=>find(n,match))];}
function fixture(ar=false){const root=new Element('section'),window={},document={documentElement:{lang:ar?'ar':'en'},getElementById:()=>root,createElement:tag=>new Element(tag)};
 vm.runInNewContext(fs.readFileSync('services/business-api/public/phone-verification.js','utf8'),{window,document,crypto:{randomUUID:()=> 'unique-request-identity'}});
 return {root,...window.EvPhoneVerification};}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const submit=form=>form.onsubmit({preventDefault(){}});
function input(form,name){return find(form,n=>n.name===name)[0];}
function button(ui,label){return find(ui.root,n=>n.tag==='button'&&n.textContent===label)[0];}
const status={available:true,verified:false,phone_mask:null,challenge:null};

test('disabled delivery and unsupported actors never expose a fake verification form',async()=>{
 const ui=fixture(true),calls=[];
 await ui.setActor({role:'admin'},async path=>calls.push(path));assert.equal(calls.length,0);
 await ui.setActor({role:'customer'},async path=>{calls.push(path);return {...status,available:false}});
 assert.match(ui.root.textContent,/إرسال رسائل التحقق غير متاح/);assert.equal(find(ui.root,n=>n.tag==='form').length,0);
 assert.deepEqual(calls,['account/phone']);ui.clear();assert.equal(ui.root.hidden,true);
});
test('explicit consent is required and uncertain transport retries retain original request identity',async()=>{
 const ui=fixture(),calls=[];
 await ui.setActor({role:'customer'},async (path,method,body)=>{calls.push({path,method,body});if(!method)return status;throw Error('private provider diagnostic')});
 const form=find(ui.root,n=>n.tag==='form')[0],password=input(form,'password');input(form,'phone').value='+971501234567';password.value='current-password';
 submit(form);await tick();assert.equal(calls.length,1);
 find(form,n=>n.type==='checkbox')[0].checked=true;submit(form);assert.equal(password.value,'');await tick();
 password.value='current-password';submit(form);await tick();
 assert.equal(calls.length,3);assert.equal(calls[1].body.idempotency_key,calls[2].body.idempotency_key);
 assert.equal(calls[1].body.consent,true);assert.doesNotMatch(ui.root.textContent,/private provider diagnostic|current-password/);
});
test('refresh never checks codes or resends SMS; explicit confirm clears code before network',async()=>{
 const ui=fixture(),calls=[],pending={id:'owned-challenge',status:'pending'};
 await ui.setActor({role:'vendor'},async (path,method,body)=>{calls.push({path,method,body});return method?{verified:false,status:'pending'}:{...status,challenge:pending}});
 button(ui,'Refresh verification status').onclick();await tick();assert.equal(calls.length,2);assert.ok(calls.every(c=>!c.method));
 const form=find(ui.root,n=>n.tag==='form').find(f=>input(f,'code'));
 input(form,'code').value='123456';submit(form);assert.equal(input(form,'code').value,'');await tick();
 assert.equal(calls.at(-1).path,'account/phone/challenges/owned-challenge/confirm');assert.equal(calls.at(-1).body.code,'123456');
 assert.match(ui.root.textContent,/Phone has not been verified/);
});
test('late old-account status cannot overwrite a new identity',async()=>{
 const ui=fixture();let finish,gets=0;
 await ui.setActor({role:'customer'},async (path,method)=>{if(method)return {verified:true};if(++gets===1)return {...status,challenge:{id:'old',status:'pending'}};return new Promise(resolve=>finish=resolve)});
 const form=find(ui.root,n=>n.tag==='form').find(f=>input(f,'code'));input(form,'code').value='123456';submit(form);await tick();
 await ui.setActor({role:'vendor'},async()=>({...status,available:false}));
 finish({...status,verified:true,phone_mask:'OLD-PRIVATE-PHONE'});await tick();
 assert.doesNotMatch(ui.root.textContent,/OLD-PRIVATE-PHONE/);assert.match(ui.root.textContent,/unavailable/);
});

test('unavailable or malformed optional status cannot break account initialization',async()=>{
 const ui=fixture();
 await assert.doesNotReject(()=>ui.setActor({role:'customer'},async()=>({data:[]})));
 assert.match(ui.root.textContent,/status is unavailable/);assert.equal(find(ui.root,n=>n.tag==='form').length,0);
});
