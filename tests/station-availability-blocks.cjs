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
 vm.runInNewContext(fs.readFileSync('services/business-api/public/station-availability-blocks.js','utf8'),{window,document,crypto:{randomUUID:()=> 'unique-request-identity'}});
 return {root,document,window,...window.EvStationBlocks};}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const submit=form=>form.onsubmit({preventDefault(){}});
function input(form,name){return find(form,n=>n.name===name)[0];}
function button(ui,label){return find(ui.root,n=>n.tag==='button'&&n.textContent===label)[0];}
test('public blocks are text only and carry explicit operator scope',()=>{
 const ui=fixture();ui.show(ui.root,[{scope:'STATION_WIDE_OPERATOR_POLICY',starts_at:'2026-11-17T09:00:00Z',ends_at:'2026-11-17T10:00:00Z',reason_en:'<script>untrusted</script>',reason_ar:'صيانة'}]);
 assert.match(ui.root.textContent,/<script>untrusted/);assert.equal(find(ui.root,n=>n.tag==='script').length,0);
});
test('uncertain publication retries the same frozen station request; refresh only reads',async()=>{
 const ui=fixture(),calls=[];let writes=0;
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method,body)=>{calls.push({path,method,body});if(method){if(++writes===1)throw Error('uncertain');return {station_id:7,id:'receipt'}}return {station_id:7,data:[]}}});await tick();
 let form=find(ui.root,n=>n.tag==='form')[0];for(const name of ['starts_at','ends_at','reason_en','reason_ar'])input(form,name).value=name;
 submit(form);await tick();input(form,'reason_en').value='changed-after-send';const detached=form;form=find(ui.root,n=>n.tag==='form')[0];submit(form);await tick();
 const requests=calls.filter(c=>c.method);assert.equal(requests.length,2);assert.equal(requests[0].body.idempotency_key,requests[1].body.idempotency_key);assert.equal(requests[1].body.reason_en,'reason_en');assert.ok(requests.every(c=>c.path==='stations/7/availability-blocks'));
 submit(detached);await tick();assert.equal(calls.filter(c=>c.method).length,2);const before=requests.length;button(ui,'Refresh station blocks').onclick();await tick();assert.equal(calls.filter(c=>c.method).length,before);
});
test('stale selection cannot render or publish from detached closures',async()=>{
 const ui=fixture();let finish,current=true,writes=0;
 ui.mount(ui.root,{stationId:7,current:()=>current,api:()=>new Promise(resolve=>finish=resolve)});current=false;finish({station_id:7,data:[]});await tick();assert.equal(ui.root.children.length,0);
 current=true;ui.mount(ui.root,{stationId:8,current:()=>current,api:async(path,method)=>{if(method)writes++;return {station_id:8,data:[]}}});await tick();const form=find(ui.root,n=>n.tag==='form')[0];current=false;submit(form);await tick();assert.equal(writes,0);
});
