'use strict';
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm'), {test} = require('node:test');
class Element {
  constructor(tag) {this.tag=tag;this.children=[];this.dataset={};this.style={};this.value='';this.checked=false;this.disabled=false;this.hidden=false;this._text='';}
  set textContent(value) {this.children=[];this._text=String(value);}
  get textContent() {return this._text+this.children.map(x=>x.textContent).join('');}
  append(...children) {this.children.push(...children);}
  replaceChildren(...children) {this._text='';this.children=children;}
  setAttribute() {}
  scrollIntoView() {}
}
const nodes=(root,tag)=>[...(root.tag===tag?[root]:[]),...root.children.flatMap(x=>nodes(x,tag))];
const row=(id=1)=>({id,name:'<img src=x onerror=alert(1)>',country:'AE',timezone:'Asia/Dubai',latitude:25,longitude:55,source:'simulator',company:null,charger_count:2,mapped_connector_count:3,availability:'unknown',physical_availability_verified:false,remote_control:false});
const reply=(data=[row()],page=1,last=1)=>({scope:'platform_inventory_all_companies',charging_authority_granted:false,payment_authority_granted:false,data,current_page:page,last_page:last,total:data.length,as_of:'2026-11-14T00:00:00Z'});
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function fixture() {
  const root=new Element('div'), events={}, windowEvents={}, calls=[];
  const document={hidden:false,documentElement:{lang:'en'},getElementById:()=>root,createElement:tag=>new Element(tag),addEventListener:(name,fn)=>events[name]=fn};
  const map={remove:()=>calls.push('remove'),setView(){return this;},getBounds:()=>({getEast:()=>190,getWest:()=>170,getSouth:()=>0,getNorth:()=>30})};
  const layer={addTo(){return this;},clearLayers(){calls.push('clear');}};
  const window={addEventListener:(name,fn)=>windowEvents[name]=fn,L:{map:()=>map,layerGroup:()=>layer,tileLayer:()=>{calls.push('tiles');return layer;},marker:()=>({bindPopup(){return this;},addTo(){calls.push('marker');return this;},on(){}})}};
  vm.runInNewContext(fs.readFileSync('services/business-api/public/admin-operations-map.js','utf8'),{window,document,AbortController,URLSearchParams});
  return {root,events,windowEvents,document,calls,setActor:window.EvAdminOperationsMap.setActor};
}
const submit=f=>nodes(f.root,'form')[0].onsubmit({preventDefault(){}});
const click=(f,text)=>{const b=nodes(f.root,'button').find(b=>b.textContent===text);assert(b,'Missing '+text);b.onclick();};
test('Admin list reads are explicit, safe text and never imply physical availability',async()=>{
 const f=fixture(), reads=[]; const api=async(...args)=>{reads.push(args);return reply();};
 f.setActor({id:1,role:'customer'},api);assert.equal(f.root.children.length,0);
 f.setActor({id:2,role:'admin'},api);assert.equal(reads.length,0);assert(!f.calls.includes('tiles'));
 nodes(f.root,'input').find(x=>x.name==='q').value='Literal_%';submit(f);await flush();
 assert.match(reads[0][0],/q=Literal_%25/);assert.equal(reads[0][1],'GET');assert(f.root.textContent.includes('<img src=x onerror=alert(1)>'));
 assert.equal(nodes(f.root,'img').length,0);assert(f.root.textContent.includes('Physical availability unknown'));assert(!f.calls.includes('tiles'));
});
test('late private reads cannot survive account replacement',async()=>{
 const f=fixture();let resolve;f.setActor({id:1,role:'admin'},()=>new Promise(r=>resolve=r));submit(f);
 f.setActor(null,()=>{});resolve(reply());await flush();assert.equal(f.root.children.length,0);
});
test('backgrounding clears private results and map, without an automatic foreground read',async()=>{
 const f=fixture();let reads=0;f.setActor({id:1,role:'admin'},async()=>{reads++;return reply();});submit(f);await flush();assert.equal(nodes(f.root,'article').length,1);
 f.document.hidden=true;f.events.visibilitychange();assert.equal(nodes(f.root,'article').length,0);
 f.document.hidden=false;f.events.visibilitychange();await flush();assert.equal(reads,1);submit(f);await flush();assert.equal(reads,2);
});
test('tiles require consent and viewport search normalizes an antimeridian crossing',async()=>{
 const f=fixture(),reads=[];f.setActor({id:1,role:'admin'},async(path)=>{reads.push(path);return reply();});assert(!f.calls.includes('tiles'));
 click(f,'Enable map tiles');assert(f.calls.includes('tiles'));assert.equal(reads.length,0);
 const viewport=nodes(f.root,'input').find(x=>x.type==='checkbox');viewport.checked=true;submit(f);await flush();
 assert.match(reads[0],/west=170/);assert.match(reads[0],/east=-170/);assert(f.calls.includes('marker'));click(f,'Disable map tiles');assert.equal(nodes(f.root,'article').length,0);assert(f.calls.includes('remove'));
});
test('pagination retains applied filters and invalid authority is rejected',async()=>{
 const f=fixture(),reads=[];f.setActor({id:1,role:'admin'},async(path)=>{reads.push(path);return reads.length===1?reply([row()],1,2):{...reply(),charging_authority_granted:true};});
 const q=nodes(f.root,'input').find(x=>x.name==='q');q.value='original';submit(f);await flush();q.value='unsubmitted';click(f,'Next page');await flush();
 assert.match(reads[1],/q=original/);assert.match(reads[1],/page=2/);assert.equal(nodes(f.root,'article').length,0);assert(f.root.textContent.includes('could not load'));
});
