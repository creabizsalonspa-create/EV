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
function fixture(ar=false){const root=new Element('section'),window={},document={visibilityState:'visible',addEventListener:(name,fn)=>{window[name]=fn},documentElement:{lang:ar?'ar':'en'},getElementById:()=>root,createElement:tag=>new Element(tag)};
 vm.runInNewContext(fs.readFileSync('services/business-api/public/account-second-factor.js','utf8'),{window,document,crypto:{randomUUID:()=> 'unique-request-identity'}});
 return {root,document,window,...window.EvAccountSecondFactor};}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const submit=form=>form.onsubmit({preventDefault(){}});
function input(form,name){return find(form,n=>n.name===name)[0];}
function button(ui,label){return find(ui.root,n=>n.tag==='button'&&n.textContent===label)[0];}
const status={enabled:false,pending:false,recovery_remaining:0};
test('setup is explicit and secrets are cleared on refresh',async()=>{
 const ui=fixture(),calls=[];let pending=false;
 await ui.setActor({role:'customer'},async(path,method,body)=>{calls.push({path,method,body});if(method){pending=true;return {secret:'A'.repeat(32)}}return {...status,pending}});
 assert.equal(calls.length,1);const form=find(ui.root,n=>n.tag==='form')[0];input(form,'password').value=' private password ';submit(form);assert.equal(input(form,'password').value,'');await tick();
 assert.equal(calls[1].body.password,' private password ');assert.match(ui.root.textContent,/A{32}/);
 button(ui,'Refresh authenticator status').onclick();await tick();assert.doesNotMatch(ui.root.textContent,/A{32}/);assert.equal(calls.filter(c=>c.method).length,1);
});
test('backgrounding fences pending secret responses and wipes credentials',async()=>{
 const ui=fixture();let finish;
 await ui.setActor({role:'vendor'},async(path,method)=>method?new Promise(resolve=>finish=resolve):status);
 const form=find(ui.root,n=>n.tag==='form')[0];input(form,'password').value='secret-password';submit(form);
 ui.document.visibilityState='hidden';ui.window.visibilitychange();finish({secret:'A'.repeat(32)});await tick();
 assert.doesNotMatch(ui.root.textContent,/A{32}|secret-password/);assert.ok(find(ui.root,n=>n.tag==='input').every(n=>!n.value));
});
test('recovery codes are displayed once and refresh performs no mutation',async()=>{
 const ui=fixture(),calls=[];
 await ui.setActor({role:'customer'},async(path,method,body)=>{calls.push({path,method,body});return method?{recovery_codes:['ABCDEF-123456-ABCDEF-123456']}:{...status,enabled:true,recovery_remaining:8}});
 const form=find(ui.root,n=>n.tag==='form')[0];input(form,'password').value='password';input(form,'code').value='001234';submit(form);await tick();
 assert.equal(calls[1].body.code,'001234');assert.match(ui.root.textContent,/ABCDEF-123456/);button(ui,'Refresh authenticator status').onclick();await tick();assert.doesNotMatch(ui.root.textContent,/ABCDEF-123456/);assert.equal(calls.filter(c=>c.method).length,1);
});
test('unsupported actors and stale identity responses cannot expose secrets',async()=>{
 const ui=fixture();let finish;await ui.setActor({role:'admin'},()=>{throw Error('unexpected')});assert.equal(ui.root.hidden,true);
 const old=ui.setActor({role:'customer'},()=>new Promise(resolve=>finish=resolve));await ui.setActor({role:'vendor'},async()=>status);finish({...status,enabled:true,recovery_remaining:99});await old;assert.doesNotMatch(ui.root.textContent,/99/);
});
