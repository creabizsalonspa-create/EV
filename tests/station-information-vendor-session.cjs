// Vendor station information: stale form, picker and auth-session isolation.
// Source-backed DOM contract; no charger commands or live network required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor(tag) {
    this.tag=tag;this.children=[];this.dataset={};this.attributes={};
    this.textContent='';this.value='';this.disabled=false;this.checked=false;
    this.name='';this.className='';
    this.classList={toggle:()=>{}};
  }
  append(...nodes){for(const n of nodes){n.parent=this;this.children.push(n)}}
  prepend(...nodes){for(const n of nodes)n.parent=this;this.children.unshift(...nodes)}
  replaceChildren(...nodes){this.children=[];this.append(...nodes)}
  remove(){if(this.parent)this.parent.children=this.parent.children.filter(n=>n!==this)}
  setAttribute(name,value){this.attributes[name]=value}
  querySelector(selector){
    const predicate=n=>selector==='form' ? n.tag==='form' :
      selector==='[data-station-info-state]' && n.dataset.stationInfoState==='1';
    return find(this,predicate);
  }
  get elements(){return new Proxy({}, {get:(_,key)=>find(this,n=>n.name===key)})}
  add(option){this.append(option);if(this.children.length===1)this.value=option.value}
}
function find(node,predicate) {
  if(predicate(node))return node;
  for(const child of node.children){
    const found=find(child,predicate);if(found)return found;
  }
  return null;
}
const document={documentElement:{lang:'en'},createElement:tag=>new Element(tag)};
function Option(title,value){const node=new Element('option');node.textContent=title;node.value=value;return node}
const window={};
const source='services/business-api/public/station-information.js';
vm.runInNewContext(fs.readFileSync(source,'utf8'),{document,window,Option,Set,Object},{filename:source});
const apiCalls=[];
let holdOldWrite=false, releaseOldWrite=null;
const stationHours=Object.fromEntries(['mon','tue','wed','thu','fri','sat','sun']
  .map(d=>[d,{open:null,close:null}]));
let epoch=1;
const user={id:7,role:'vendor'};
function info(id){return {
  revision:0,station_id:id,
  source_label:'VENDOR_REPORTED_UNVERIFIED',
  description_en:'Station '+id,description_ar:'',access_en:'',
  access_ar:'',notice_en:null,notice_ar:null,notice_until:null,
  weekly_hours:stationHours,closed_dates:[],amenities:[],accessibility:[]
}}
const api=async(path,method='GET',body)=>{
  apiCalls.push({path,method,body});
  if(path.startsWith('vendor/stations?page=')){
    return {data:epoch===1?[{id:11,name:'Old station'},{id:13,name:'Old backup'}]:
      [{id:12,name:'New session station'}],current_page:1,last_page:1};
  }
  const match=/^stations\/(\d+)\/information$/.exec(path);
  if(match){
    const stationId=Number(match[1]);
    if(method==='PUT'){
      if(stationId===11 && holdOldWrite){
        holdOldWrite=false;
        await new Promise(resolve=>{releaseOldWrite=resolve;});
      }
      return {...info(stationId),...body,revision:1};
    }
    return info(stationId);
  }
  throw Error('Unexpected API call '+path);
};
const root=new Element('section');
const view=window.StationInformation.createVendorWorkspace({
  root,api,actor:()=>user,epoch:()=>epoch,
});
const count=(method,ending)=>apiCalls.filter(call=>call.method===method &&
  (!ending||call.path.includes(ending))).length;
(async()=>{
  await view.setActor(user);
  const oldForm=find(root,n=>n.tag==='form');
  const oldPicker=find(root,n=>n.tag==='select');
  const oldRefresh=find(root,n=>n.tag==='button' &&
    n.children.some(c=>c.textContent==='Refresh'));
  assert.ok(oldForm&&oldPicker&&oldRefresh);
  assert.equal(count('GET','stations/11/information'),1);
  // A station switch during an unresolved save MUST not leave the next
  // station locked, or allow the old save to replace the current UI.
  holdOldWrite=true;
  const oldSave=oldForm.onsubmit({preventDefault(){}});
  assert.equal(count('PUT','stations/11/information'),1);
  assert.equal(typeof releaseOldWrite,'function');
  oldPicker.value='13';
  await oldPicker.onchange();
  const nextForm=find(root,n=>n.tag==='form');
  assert.ok(nextForm);
  nextForm.elements.description_en.value='Updated backup station';
  await nextForm.onsubmit({preventDefault(){}});
  assert.equal(count('PUT','stations/13/information'),1,
    'Next station must save while previous station write is in flight');
  const afterSwitch=count('PUT');
  await oldForm.onsubmit({preventDefault(){}});
  assert.equal(count('PUT'),afterSwitch,
    'Detached form must not update a newer selected station');
  releaseOldWrite();
  await oldSave;
  assert.ok(find(root,n=>n.tag==='form'),
    'Late previous-station response must not remove the current editor');
  // Refreshing the station list invalidates old picker callbacks even
  // when the vendor identity and session have not changed.
  await view.reload();
  const beforeOldPicker=count('GET','/information');
  oldPicker.value='11';
  await oldPicker.onchange();
  assert.equal(count('GET','/information'),beforeOldPicker,
    'Detached picker must not load an old station after list refresh');
  // A new auth session may reuse the same user JS object.
  const formBeforeEpoch=find(root,n=>n.tag==='form');
  epoch++;
  const priorWrites=count('PUT');
  await formBeforeEpoch.onsubmit({preventDefault(){}});
  await oldRefresh.onclick();
  assert.equal(count('PUT'),priorWrites,
    'Previous-session form must not send a PUT');
  const beforeSetActor=apiCalls.length;
  await view.setActor(user);
  assert.ok(apiCalls.length>beforeSetActor,
    'Same vendor object in a new auth epoch must reload');
  assert.equal(count('GET','stations/12/information'),1);
  const currentForm=find(root,n=>n.tag==='form');
  assert.ok(currentForm);
  currentForm.elements.description_en.value='New vendor description';
  await currentForm.onsubmit({preventDefault(){}});
  assert.equal(count('PUT','stations/12/information'),1);
  assert.equal(apiCalls.filter(c=>c.method==='PUT').at(-1).body.description_en,
    'New vendor description');
  assert.equal(count('PUT','stations/11/information'),1);
  assert.equal(count('PUT','stations/13/information'),1);
  // Same identity with a stale list callback cannot disclose old stations.
  epoch++;
  const stalePicker=find(root,n=>n.tag==='select');
  const staleForm=find(root,n=>n.tag==='form');
  const beforeStale=apiCalls.length;
  await stalePicker.onchange();
  await staleForm.onsubmit({preventDefault(){}});
  assert.equal(apiCalls.length,beforeStale);
  await view.setActor(user);
  assert.ok(root.children.length>0);
  view.clear();
  assert.equal(root.children.length,0);
  assert.doesNotMatch(fs.readFileSync(source,'utf8'),/\.innerHTML\s*=/);
  console.log('PASS: vendor station metadata auth epoch, stale picker, form and tenant write isolation.');
})().catch(e=>{console.error(e);process.exitCode=1;});
