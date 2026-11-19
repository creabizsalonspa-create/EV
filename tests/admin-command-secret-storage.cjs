'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element {
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.attributes={};this._text='';this.disabled=false;this.hidden=false;}
 set textContent(value){this._text=String(value);this.children=[];} get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}
 append(...children){this.children.push(...children);} replaceChildren(...children){this._text='';this.children=children;}
 setAttribute(key,value){this.attributes[key]=value;} removeAttribute(key){delete this.attributes[key];}
}
const nodes=(root,tag)=>[...(root.tag===tag?[root]:[]),...root.children.flatMap(child=>nodes(child,tag))];
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(ar=false,hidden=false){
 const root=new Element('div'),events={},windowEvents={};
 const document={hidden,documentElement:{lang:ar?'ar':'en'},getElementById:()=>root,createElement:tag=>new Element(tag),addEventListener:(key,fn)=>events[key]=fn};
 const window={addEventListener:(key,fn)=>windowEvents[key]=fn};
 vm.runInNewContext(fs.readFileSync('services/business-api/public/admin-command-secret-storage.js','utf8'),{window,document,AbortController});
 return{root,document,events,windowEvents,setActor:window.EvAdminCommandSecretStorage.setActor};
}
function report(after=0,size=2,next=0){return{scope:'bounded_command_secret_storage',as_of:'2026-11-19T06:00:00.123456Z',after_id:after,limit:25,max_field_bytes:65536,rows_examined:size,encrypted_rows:size,legacy_rows:0,unreadable_rows:0,unknown_shape_rows:0,no_known_secret_rows:0,encrypted_fields:size,legacy_fields:0,has_more:next>0,next_after_id:next,whole_table_verified:false,mutation_performed:false,production_acceptance:false,commands_dispatched:0,network_requests:0};}
const buttons=f=>nodes(f.root,'button'),click=(f,index=0)=>buttons(f)[index].onclick();
test('EN/AR explicit Admin reads project only known aggregate fields',async()=>{
 for(const ar of [false,true]){
  const f=fixture(ar),calls=[];f.setActor({role:'customer'},()=>{throw Error('no customer read');});assert.equal(f.root.children.length,0);
  f.setActor({id:1,role:'admin'},async(...args)=>{calls.push(args);return{...report(),payload:'PRIVATE-UNKNOWN-EXTRA',id_token:'PRIVATE-TOKEN'};});
  assert.equal(calls.length,0);click(f);await flush();assert.equal(calls.length,1);assert.equal(calls[0][1],'GET');assert.equal(calls[0][0],'admin/command-secret-storage?limit=25&after=0');
  assert.equal(nodes(f.root,'dd').length,5);assert.equal(nodes(f.root,'dd')[0].textContent,'2');assert(!f.root.textContent.includes('PRIVATE-'));
  assert(f.root.textContent.includes(ar?'صفوف ذات حقول معروفة مشفرة':'Rows with encrypted known fields'));assert(f.root.textContent.includes(ar?'ليست قبولاً':'not security or production acceptance'));
 }
});
test('pagination clears previous values and retries only the explicitly failed read cursor',async()=>{
 const f=fixture(),calls=[];let failure=true;
 f.setActor({role:'admin'},async path=>{calls.push(path);if(calls.length===1)return report(0,25,101);if(failure)throw Error('PRIVATE-TRANSPORT');return report(101,3);});
 click(f);await flush();const staleNext=buttons(f)[1].onclick;assert.equal(buttons(f)[1].disabled,false);
 click(f,1);await flush();assert.equal(nodes(f.root,'dd').length,0);assert.equal(buttons(f)[2].hidden,false);assert(!f.root.textContent.includes('PRIVATE-TRANSPORT'));
 const before=calls.length;staleNext();await flush();assert.equal(calls.length,before);
 failure=false;click(f,2);await flush();assert.equal(calls[2],'admin/command-secret-storage?limit=25&after=101');assert.equal(nodes(f.root,'dd')[0].textContent,'3');assert.equal(buttons(f)[1].disabled,true);
});
test('initial hidden mount, background and detached callbacks cannot retain or request observations',async()=>{
 const f=fixture(false,true);let reads=0;f.setActor({role:'admin'},async()=>{reads++;return report();});const old=buttons(f)[0].onclick;
 old();await flush();assert.equal(reads,0);f.document.hidden=false;f.events.visibilitychange();old();await flush();assert.equal(reads,0);
 click(f);await flush();assert.equal(reads,1);const captured=buttons(f)[0].onclick;f.document.hidden=true;f.events.visibilitychange();assert.equal(nodes(f.root,'dd').length,0);
 f.document.hidden=false;f.events.visibilitychange();captured();await flush();assert.equal(reads,1);click(f);await flush();assert.equal(reads,2);
 f.windowEvents.pagehide();assert.equal(f.root.children.length,0);captured();await flush();assert.equal(reads,2);
});
for(const lateFailure of [false,true])test('old actor '+(lateFailure?'failure':'success')+' cannot erase or overwrite a new Admin snapshot',async()=>{
 const f=fixture();let resolve,reject,oldReads=0;
 f.setActor({id:1,role:'admin'},()=>{oldReads++;return new Promise((yes,no)=>{resolve=yes;reject=no;});});const captured=buttons(f)[0].onclick;captured();
 f.setActor({id:2,role:'admin'},async()=>report());click(f);await flush();captured();assert.equal(oldReads,1);
 lateFailure?reject(Error('PRIVATE-OLD-401')):resolve(report(0,19));await flush();assert.equal(nodes(f.root,'dd')[0].textContent,'2');assert(!f.root.textContent.includes('PRIVATE-'));
});
test('single-flight guards reject captured callbacks while a read is pending',async()=>{
 const f=fixture();let reads=0,finish;f.setActor({role:'admin'},()=>{reads++;return new Promise(resolve=>finish=resolve);});const captured=buttons(f)[0].onclick;
 captured();captured();click(f);assert.equal(reads,1);finish(report());await flush();assert.equal(buttons(f)[0].disabled,false);
});
test('incoherent or acceptance-promoting replies never render fabricated counts or raw errors',async()=>{
 const changes=[d=>d.production_acceptance=true,d=>d.mutation_performed=true,d=>d.network_requests=1,d=>d.rows_examined=3,d=>d.encrypted_rows=-1,d=>d.encrypted_fields=Number.MAX_SAFE_INTEGER,d=>d.as_of='2026-02-30T00:00:00Z',d=>{d.has_more=true;d.next_after_id=0;},d=>d.after_id=99,d=>d.limit=100];
 for(const change of changes){const f=fixture(),d=report();change(d);f.setActor({role:'admin'},async()=>d);click(f);await flush();assert.equal(nodes(f.root,'dd').length,0);assert.equal(buttons(f)[2].hidden,false);assert(f.root.textContent.includes('unavailable'));}
});
