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
function fixture(ar=false){const root=new Element('section'),window={addEventListener(name,fn){this[name]=fn}},document={visibilityState:'visible',addEventListener:(name,fn)=>{window[name]=fn},documentElement:{lang:ar?'ar':'en'},getElementById:()=>root,createElement:tag=>new Element(tag)};
 vm.runInNewContext(fs.readFileSync('services/business-api/public/account-second-factor.js','utf8'),{window,document,crypto:{randomUUID:()=> 'unique-request-identity'}});
 return {root,document,window,...window.EvAccountSecondFactor};}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const submit=form=>form.onsubmit({preventDefault(){}});
function input(form,name){return find(form,n=>n.name===name)[0];}
function button(ui,label){return find(ui.root,n=>n.tag==='button'&&n.textContent===label)[0];}
const status={enabled:false,pending:false,recovery_remaining:0};

for(const ar of [false,true]){
 test('first-hidden authenticator view blocks private GET until deliberate Refresh ('+(ar?'ar':'en')+')',async()=>{
  const ui=fixture(ar),calls=[];
  ui.document.visibilityState='hidden';
  await ui.setActor({role:'customer'},async path=>{calls.push(path);return status});
  assert.equal(calls.length,0);
  assert.doesNotMatch(ui.root.textContent,/Authenticator is not enabled|تطبيق التحقق غير مفعّل/);
  ui.document.visibilityState='visible';
  await tick();
  assert.equal(calls.length,0,'resume must not silently read private MFA status');
  const refresh=button(ui,ar?'تحديث حالة تطبيق التحقق':'Refresh authenticator status');
  await refresh.onclick();await tick();
  assert.deepEqual(calls,['account/second-factor']);
  assert.match(ui.root.textContent,ar?/تطبيق التحقق غير مفعّل/:/Authenticator is not enabled/);
 });
 test('hidden private authenticator recovery codes never return until explicit safe reload ('+(ar?'ar':'en')+')',async()=>{
  const ui=fixture(ar),calls=[];
  await ui.setActor({role:'vendor'},async(path,method)=>{
   calls.push({path,method});
   return method?{recovery_codes:['PRIVATE-RECOVERY-CODE-1234']}:
     {...status,enabled:true,recovery_remaining:8};
  });
  const form=find(ui.root,n=>n.tag==='form')[0];
  input(form,'password').value='PASSWORD-REAUTH';
  input(form,'code').value='123456';
  submit(form);await tick();
  assert.match(ui.root.textContent,/PRIVATE-RECOVERY-CODE-1234/);
  ui.document.visibilityState='hidden';ui.window.visibilitychange();
  assert.doesNotMatch(ui.root.textContent,/PRIVATE-RECOVERY-CODE-1234|Recovery codes remaining|رموز الاسترداد المتبقية/);
  assert.ok(find(ui.root,n=>n.tag==='input').every(n=>!n.value));
  ui.document.visibilityState='visible';await tick();
  assert.equal(calls.filter(c=>c.method).length,1);
  await button(ui,ar?'تحديث حالة تطبيق التحقق':'Refresh authenticator status').onclick();
  await tick();
  assert.doesNotMatch(ui.root.textContent,/PRIVATE-RECOVERY-CODE-1234/);
  assert.equal(calls.filter(c=>c.method).length,1,'foreground refresh may only GET');
 });
 test('visible BFCache pagehide seals private setup and blocks late results ('+(ar?'ar':'en')+')',async()=>{
  const ui=fixture(ar);let release,reads=0;
  const waiting=new Promise(resolve=>{release=resolve});
  const open=ui.setActor({role:'customer'},async()=>{reads++;return waiting});
  assert.equal(reads,1);
  ui.window.pagehide();
  release({...status,enabled:true,recovery_remaining:9});await open;
  assert.doesNotMatch(ui.root.textContent,/Recovery codes remaining|رموز الاسترداد المتبقية/);
  assert.equal(reads,1);
  assert.ok(button(ui,ar?'تحديث حالة تطبيق التحقق':'Refresh authenticator status'));
 });
}
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
