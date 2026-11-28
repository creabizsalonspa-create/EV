'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element {
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.value='';this.checked=false;this._text='';}
 set textContent(value){this._text=String(value);this.children=[];}
 get textContent(){return this._text+this.children.map(n=>n.textContent).join(' ');}
 append(...nodes){this.children.push(...nodes);} prepend(...nodes){this.children.unshift(...nodes);}
 replaceChildren(...nodes){this.children=nodes;this._text='';} setAttribute(){}
 querySelectorAll(selector){const tags=selector.split(',').map(x=>x.trim());return find(this,n=>tags.includes(n.tag));}
}
function find(root,match){return [...(match(root)?[root]:[]),...root.children.flatMap(n=>find(n,match))];}
function fixture(ar=false){const root=new Element('section'),window={},document={visibilityState:'visible',addEventListener:(name,fn)=>{window[name]=fn},documentElement:{lang:ar?'ar':'en'},getElementById:()=>root,createElement:tag=>new Element(tag)};
 vm.runInNewContext(fs.readFileSync('services/business-api/public/station-time.js','utf8'),{window,document});
 vm.runInNewContext(fs.readFileSync('services/business-api/public/station-availability-blocks.js','utf8'),{window,document,crypto:{randomUUID:()=> 'unique-request-identity'}});
 return {root,document,window,...window.EvStationBlocks};}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const submit=form=>form.onsubmit({preventDefault(){}});
function input(form,name){return find(form,n=>n.name===name)[0];}
function button(ui,label){return find(ui.root,n=>n.tag==='button'&&n.textContent===label)[0];}
test('public blocks are plain text and preserve unknown live status in both languages', () => {
 const row = {
   scope: 'STATION_WIDE_OPERATOR_POLICY', physical_availability_verified: false,
   starts_at: '2026-11-17T09:00:00Z', ends_at: '2026-11-17T10:00:00Z',
   reason_en: '<script>untrusted</script>', reason_ar: 'صيانة',
 };
 for (const [arabic, text] of [[false, /live charger status unknown.*<script>untrusted/], [true, /حالة الشاحن المباشرة غير معروفة.*صيانة/]]) {
   const ui = fixture(arabic);
   ui.show(ui.root, [row, null, {...row, scope: 'OTHER'}, {...row, reason_en: null}, {...row, physical_availability_verified: true}]);
   assert.match(ui.root.textContent, text);
   assert.equal(ui.root.children.length, 1);
   assert.equal(find(ui.root, node => node.tag === 'script').length, 0);
 }
});
test('uncertain publication retries the same frozen station request; refresh only reads',async()=>{
 const ui=fixture(),calls=[];let writes=0;
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method,body)=>{calls.push({path,method,body});if(method){if(++writes===1)throw Error('uncertain');return {...body,station_id:7,id:'11111111-1111-4111-8111-111111111111',revision:1,scope:'STATION_WIDE_OPERATOR_POLICY',cancelled:false,physical_availability_verified:false}}return {station_id:7,data:[]}}});await tick();
 let form=find(ui.root,n=>n.tag==='form')[0];for(const name of ['starts_at','ends_at','reason_en','reason_ar'])input(form,name).value=({starts_at:'2026-11-24T09:00:00Z',ends_at:'2026-11-24T10:00:00Z'})[name]||name;
 submit(form);await tick();input(form,'reason_en').value='changed-after-send';const detached=form;form=find(ui.root,n=>n.tag==='form')[0];submit(form);await tick();
 const requests=calls.filter(c=>c.method);assert.equal(requests.length,2);assert.equal(requests[0].body.idempotency_key,requests[1].body.idempotency_key);assert.equal(requests[1].body.reason_en,'reason_en');assert.ok(requests.every(c=>c.path==='stations/7/availability-blocks'));
 submit(detached);await tick();assert.equal(calls.filter(c=>c.method).length,2);const before=requests.length;button(ui,'Refresh station blocks').onclick();await tick();assert.equal(calls.filter(c=>c.method).length,before);
});
test('stale selection cannot render or publish from detached closures',async()=>{
 const ui=fixture();let finish,current=true,writes=0;
 ui.mount(ui.root,{stationId:7,current:()=>current,api:()=>new Promise(resolve=>finish=resolve)});current=false;finish({station_id:7,data:[]});await tick();assert.equal(ui.root.children.length,0);
 current=true;ui.mount(ui.root,{stationId:8,current:()=>current,api:async(path,method)=>{if(method)writes++;return {station_id:8,data:[]}}});await tick();const form=find(ui.root,n=>n.tag==='form')[0];current=false;submit(form);await tick();assert.equal(writes,0);
});

test('invalid station policy rows never create an operator cancellation target',async()=>{
 const ui=fixture(),calls=[];
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method)=>{
  calls.push([path,method]);
  return {station_id:7,data:[{
   station_id:7,id:'wrong-source',scope:'PHYSICAL_AVAILABLE',
   cancelled:false,physical_availability_verified:true,revision:1,
   starts_at:'2026-11-24T09:00:00Z',ends_at:'2026-11-24T10:00:00Z',
   reason_en:'UNTRUSTED',reason_ar:'UNTRUSTED'
  }]};
 }});
 await tick();
 assert.equal(button(ui,'Cancel this block'),undefined);
 assert.match(ui.root.textContent,/Station blocks are unavailable/);
 assert.doesNotMatch(ui.root.textContent,/UNTRUSTED/);
 assert.equal(calls.filter(row=>row[1]==='POST').length,0);
});

test('failed authoritative refresh revokes previous cancellation actions',async()=>{
 const ui=fixture();let reads=0,writes=0;
 const block={station_id:7,id:'11111111-1111-4111-8111-111111111111',
  scope:'STATION_WIDE_OPERATOR_POLICY',physical_availability_verified:false,
  cancelled:false,revision:1,starts_at:'2026-11-24T09:00:00Z',
  ends_at:'2026-11-24T10:00:00Z',
  reason_en:'Scheduled',reason_ar:'مجدولة'};
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method)=>{
  if(method==='POST'){writes++;return {station_id:7,id:'unsafe'};}
  if(++reads===1)return {station_id:7,data:[block]};
  throw Error('PRIVATE_SOURCE_SECRET');
 }});
 await tick();
 assert.ok(button(ui,'Cancel this block'));
 button(ui,'Refresh station blocks').onclick();
 await tick();
 assert.equal(button(ui,'Cancel this block'),undefined);
 assert.doesNotMatch(ui.root.textContent,/PRIVATE_SOURCE_SECRET/);
 assert.match(ui.root.textContent,/Action not confirmed/);
 assert.equal(writes,0);
});

test('malformed publish receipt preserves frozen retry and does not read success state',async()=>{
 const ui=fixture(),calls=[];let writes=0;
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method,body)=>{
  calls.push({path,method,body});
  if(!method)return {station_id:7,data:[]};
  writes++;
  return {...body,station_id:7,id:'11111111-1111-4111-8111-111111111111',
    revision:1,scope:'STATION_WIDE_OPERATOR_POLICY',cancelled:false,
    physical_availability_verified:writes===1};
 }});
 await tick();
 let form=find(ui.root,n=>n.tag==='form')[0];
 for(const name of ['starts_at','ends_at','reason_en','reason_ar'])input(form,name).value=({starts_at:'2026-11-24T09:00:00Z',ends_at:'2026-11-24T10:00:00Z'})[name]||name;
 submit(form);await tick();
 assert.ok(button(ui,'Retry same block request'));
 assert.equal(calls.filter(c=>!c.method).length,1);
 form=find(ui.root,n=>n.tag==='form')[0];submit(form);await tick();
 const sent=calls.filter(c=>c.method);
 assert.equal(sent.length,2);assert.deepEqual(sent[0].body,sent[1].body);
 assert.equal(calls.filter(c=>!c.method).length,2);
 assert.ok(button(ui,'Publish station block'));
});
test('mismatched cancellation receipt clears stale cancel grant without accepting success',async()=>{
 const ui=fixture();let reads=0,writes=0;
 const block={station_id:7,id:'11111111-1111-4111-8111-111111111111',
  scope:'STATION_WIDE_OPERATOR_POLICY',physical_availability_verified:false,
  cancelled:false,revision:1,starts_at:'2026-11-24T09:00:00Z',
  ends_at:'2026-11-24T10:00:00Z',reason_en:'Scheduled',reason_ar:'مجدولة'};
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method)=>{
  if(method){writes++;return {...block,id:'22222222-2222-4222-8222-222222222222',cancelled:true,revision:2};}
  reads++;return {station_id:7,data:[block]};
 }});
 await tick();button(ui,'Cancel this block').onclick();await tick();
 assert.equal(writes,1);assert.equal(reads,1);
 assert.equal(button(ui,'Cancel this block'),undefined);
 assert.match(ui.root.textContent,/Action not confirmed/);
});

test('policy clocks normalize offsets and reject impossible, timezone-less or reversed intervals', () => {
 const row={scope:'STATION_WIDE_OPERATOR_POLICY',physical_availability_verified:false,
 starts_at:'2026-11-17T13:00:00+04:00',ends_at:'2026-11-17T14:00:00+04:00',
 reason_en:'Scheduled',reason_ar:'مجدولة'};
 for(const ar of [false,true]){
  const ui=fixture(ar);
  ui.show(ui.root,[row,{...row,starts_at:'2026-02-30T09:00:00Z'},
    {...row,starts_at:'2026-11-17T09:00:00'},
    {...row,starts_at:row.ends_at,ends_at:row.starts_at}]);
  assert.equal(ui.root.children.length,1);
  const expected=new Intl.DateTimeFormat(ar?'ar':'en-GB',{timeZone:'UTC',dateStyle:'medium',timeStyle:'short',hourCycle:'h23'})
    .format(new Date('2026-11-17T09:00:00Z'))+' (UTC)';
  assert.ok(ui.root.textContent.includes(expected));
  assert.doesNotMatch(ui.root.textContent,/2026-11-17T13:00:00/);
 }
});
test('invalid draft cannot dispatch; locale binding belongs to label leaf, not form field',async()=>{
 const ui=fixture();let writes=0;
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method)=>{
  if(method)writes++;return {station_id:7,data:[]};
 }});await tick();
 const form=find(ui.root,n=>n.tag==='form')[0];
 for(const name of ['starts_at','ends_at','reason_en','reason_ar'])input(form,name).value=({starts_at:'2026-02-30T09:00:00Z',ends_at:'2026-11-24T10:00:00Z'})[name]||name;
 submit(form);await tick();
 assert.equal(writes,0);assert.match(ui.root.textContent,/Choose a valid increasing UTC interval/);
 for(const label of find(form,n=>n.tag==='label')){
  assert.equal(label.dataset.en,undefined);
  assert.ok(label.children.some(n=>n.tag==='span'&&n.dataset.en&&n.dataset.ar));
  assert.ok(label.children.some(n=>n.tag==='input'));
 }
});
test('impossible receipt interval cannot expose cancellation',async()=>{
 const ui=fixture();
 const row={station_id:7,id:'11111111-1111-4111-8111-111111111111',scope:'STATION_WIDE_OPERATOR_POLICY',physical_availability_verified:false,
 cancelled:false,revision:1,starts_at:'2026-02-30T09:00:00Z',ends_at:'2026-11-24T10:00:00Z',reason_en:'Invalid',reason_ar:'غير صالح'};
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async()=>({station_id:7,data:[row]})});await tick();
 assert.equal(button(ui,'Cancel this block'),undefined);
 assert.match(ui.root.textContent,/Station blocks are unavailable/);
});

test('receipt precision and immutable revision reject altered policy intervals',async()=>{
 const ui=fixture(),calls=[];
 let publish=0;
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method,body)=>{
  calls.push({path,method,body});
  if(!method)return {station_id:7,data:[]};
  publish++;
  return {...body,station_id:7,id:'11111111-1111-4111-8111-111111111111',
    revision:1,scope:'STATION_WIDE_OPERATOR_POLICY',cancelled:false,physical_availability_verified:false,
    starts_at:publish===1?'2026-11-24T09:00:00.000001Z':'2026-11-24T13:00:00+04:00'};
 }});
 await tick();
 let form=find(ui.root,n=>n.tag==='form')[0];
 for(const name of ['starts_at','ends_at','reason_en','reason_ar'])input(form,name).value=({starts_at:'2026-11-24T09:00:00Z',ends_at:'2026-11-24T10:00:00Z'})[name]||name;
 submit(form);await tick();
 assert.ok(button(ui,'Retry same block request'));
 assert.equal(calls.filter(c=>!c.method).length,1);
 form=find(ui.root,n=>n.tag==='form')[0];submit(form);await tick();
 assert.deepEqual(calls.filter(c=>c.method)[0].body,calls.filter(c=>c.method)[1].body);
 assert.ok(button(ui,'Publish station block'));
 for(const revision of [2,2147483647]){
  const probe=fixture();
  probe.mount(probe.root,{stationId:7,current:()=>true,api:async()=>({station_id:7,data:[{
   station_id:7,id:'11111111-1111-4111-8111-111111111111',revision,scope:'STATION_WIDE_OPERATOR_POLICY',
   physical_availability_verified:false,cancelled:false,starts_at:'2026-11-24T09:00:00Z',ends_at:'2026-11-24T10:00:00Z',
   reason_en:'Scheduled',reason_ar:'مجدولة'}]})});
  await tick();
  assert.equal(button(probe,'Cancel this block'),undefined);
  assert.match(probe.root.textContent,/Station blocks are unavailable/);
 }
});
test('pending publication disables editable controls during source read without discarding request',async()=>{
 const ui=fixture();let release,reads=0,writes=0;
 ui.mount(ui.root,{stationId:7,current:()=>true,api:async(path,method)=>{
  if(method){writes++;throw Error('uncertain');}
  if(++reads===2)await new Promise(resolve=>release=resolve);
  return {station_id:7,data:[]};
 }});
 await tick();
 const form=find(ui.root,n=>n.tag==='form')[0];
 for(const name of ['starts_at','ends_at','reason_en','reason_ar'])input(form,name).value=({starts_at:'2026-11-24T09:00:00Z',ends_at:'2026-11-24T10:00:00Z'})[name]||name;
 submit(form);await tick();
 button(ui,'Refresh station blocks').onclick();
 assert.equal(typeof release,'function');
 assert.ok(find(ui.root,n=>n.tag==='input'||n.tag==='button').every(n=>n.disabled===true));
 release();await tick();
 assert.ok(button(ui,'Retry same block request'));
 assert.ok(find(ui.root,n=>n.tag==='input').every(n=>n.disabled===true));
 assert.equal(writes,1);
});

for (const action of ['publish', 'cancel']) {
 test(`confirmed ${action} is retained if the subsequent authoritative list read fails`, async () => {
  const ui = fixture();
  const block = {station_id:7,id:'11111111-1111-4111-8111-111111111111',
   scope:'STATION_WIDE_OPERATOR_POLICY',physical_availability_verified:false,
   cancelled:false,revision:1,starts_at:'2026-11-24T09:00:00Z',
   ends_at:'2026-11-24T10:00:00Z',reason_en:'Scheduled',reason_ar:'مجدولة'};
  let reads = 0, writes = 0;
  ui.mount(ui.root, {stationId:7,current:()=>true,api:async(path,method)=>{
   if(method === 'POST'){
    writes++;
    return action === 'publish' ? block : {...block,cancelled:true,revision:2};
   }
   if(++reads === 2) throw Error('PRIVATE_SOURCE_ERROR');
   return {station_id:7,data:reads === 1 && action === 'cancel' ? [block] : []};
  }});
  await tick();
  if (action === 'publish') {
   const form = find(ui.root,n=>n.tag==='form')[0];
   for (const name of ['starts_at','ends_at','reason_en','reason_ar']) input(form,name).value=block[name];
   submit(form);
  } else {
   button(ui,'Cancel this block').onclick();
  }
  await tick();
  assert.match(ui.root.textContent, action === 'publish' ? /Station block published/ : /Station block cancelled/);
  assert.match(ui.root.textContent, /block list is unavailable/);
  assert.doesNotMatch(ui.root.textContent, /Action not confirmed|PRIVATE_SOURCE_ERROR/);
  assert.equal(button(ui,'Retry same block request'),undefined);
  assert.equal(button(ui,'Cancel this block'),undefined);
  button(ui,'Refresh station blocks').onclick();
  await tick();
  assert.equal(reads,3);
  assert.equal(writes,1);
 });
}
