// Source-backed browser regression; Admin-only catalog curation and customer linking.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor(tag) {
    this.tag=tag;this.children=[];this.dataset={};this.textContent='';
    this.attributes={};this.name='';this.value='';this.checked=false;this.disabled=false;
  }
  append(...children) {
    for(const child of children){
      if(this.tag==='select'&&child.tag==='option'&&!this.value)this.value=child.value;
      child.parent=this;this.children.push(child);
    }
  }
  prepend(...children) {children.forEach(c=>c.parent=this);this.children.unshift(...children);}
  replaceChildren(...children){this.children=[];this.append(...children);}
  setAttribute(k,v){this.attributes[k]=v;}
  removeAttribute(k){delete this.attributes[k];}
  remove(){if(this.parent)this.parent.children=this.parent.children.filter(x=>x!==this);}
  querySelector(selector){
    const match=node=>
      selector==='[data-vehicle-model-message]'&&node.dataset.vehicleModelMessage==='yes'||
      selector==='[data-vehicle-model-picker]'&&node.dataset.vehicleModelPicker==='yes';
    const visit=node=>{
      if(match(node))return node;
      for(const child of node.children){const found=visit(child);if(found)return found;}
      return null;
    };
    return visit(this);
  }
}
const document={documentElement:{lang:'en'},createElement:tag=>new Element(tag)};
const window={};
const path='services/business-api/public/vehicle-model-catalog.js';
vm.runInNewContext(fs.readFileSync(path,'utf8'),{window,document,URLSearchParams,Number,Object},{filename:path});
assert.equal(typeof window.VehicleModelCatalog.create,'function');
function visit(node,predicate){
  if(predicate(node))return node;
  for(const child of node.children){const result=visit(child,predicate);if(result)return result;}
  return null;
}
function label(node){return [node.textContent,...node.children.map(label)].join(' ');}
const model={
  id:7,make:'Curation test',model:'EV-A',variant:null,model_year:2026,
  connector_type:'CCS2',source_url:'https://example.com/ev-a',
  revision:1,published:true,provenance:'ADMIN_CURATED_UNVERIFIED',
};
const secondModel={...model,id:8,model:'EV-B',source_url:'https://example.com/ev-b'};
const adminRoot=document.createElement('section'),customerRoot=document.createElement('section');
let actor={id:3,role:'customer'},epoch=1;
const sent=[];
let activeModelId=null, delayNextLink=false, releasePendingLink=null;
const api=async(path,method='GET',body)=>{
  sent.push({path,method,body});
  if(path==='vehicles/12/model'&&method==='GET')return {vehicle_id:12,vehicle_connector_type:'CCS2',model_id:activeModelId,model:activeModelId===8?secondModel:activeModelId===7?model:null};
  if(path.startsWith('vehicle-models?'))return {data:[model,secondModel],last_page:1,current_page:1};
  if(path==='vehicles/13/model'&&method==='GET')
    return {vehicle_id:13,vehicle_connector_type:'CCS2',model_id:null,model:null};
  if(path==='vehicles/12/model'&&method==='PUT'){
    if(delayNextLink){
      delayNextLink=false;
      await new Promise(resolve=>{releasePendingLink=resolve;});
    }
    activeModelId=body.model_id;
    return {vehicle_id:12,model_id:body.model_id,model:activeModelId===8?secondModel:activeModelId===7?model:null};
  }
  if(path==='vehicle-models'&&method==='POST')return {...body,id:9,revision:1};
  throw Error('unexpected API path '+path);
};
const view=window.VehicleModelCatalog.create({adminRoot,customerRoot,api,actor:()=>actor,epoch:()=>epoch});
(async()=>{
  await view.setActor(actor);
  view.renderGarage([{id:12,name:'Family EV',connector_type:'CCS2'}]);
  const selectButton=visit(customerRoot,n=>n.tag==='button'&&label(n).includes('Select catalog model'));
  assert.ok(selectButton);
  const loading = selectButton.onclick();
  view.renderGarage([{id:12,name:'Family EV refreshed',connector_type:'CCS2'}]);
  await loading;
  assert.match(label(customerRoot),/Curation test EV-A/);
  assert.ok(customerRoot.querySelector('[data-vehicle-model-picker]'));
  assert.ok(sent.some(c=>c.path.includes('connector_type=CCS2')));
  const save=visit(customerRoot,n=>n.tag==='button'&&label(n).includes('Link selected model'));
  assert.ok(save);
  await save.onclick();
  assert.deepEqual(JSON.parse(JSON.stringify(sent.find(c=>c.method==='PUT').body)),{
    model_id:7,expected_model_id:null,
  });
  assert.match(label(customerRoot),/Model linked/);
  // Link the second option, then ensure the picker stays on it after the PUT
  // and after a same-owner garage refresh (not the first catalog entry).
  const secondSelect=visit(customerRoot,n=>n.tag==='select');
  secondSelect.value='8';
  const secondSave=visit(customerRoot,n=>n.tag==='button'&&label(n).includes('Link selected model'));
  await secondSave.onclick();
  assert.equal(activeModelId,8);
  const afterSave=visit(customerRoot,n=>n.tag==='select');
  assert.equal(afterSave.value,'8');
  assert.match(label(customerRoot),/EV-B/);
  view.renderGarage([{id:12,name:'Family EV latest',connector_type:'CCS2'}]);
  assert.match(label(customerRoot),/Model linked/);
  assert.ok(customerRoot.querySelector('[data-vehicle-model-picker]'));
  assert.equal(visit(customerRoot,n=>n.tag==='select').value,'8');
  // A pending link response from vehicle 12 must not populate vehicle 13's
  // picker after switching, even if the GET for 13 finished first.
  delayNextLink=true;
  const pendingSave=visit(customerRoot,n=>n.tag==='button'&&label(n).includes('Link selected model')).onclick();
  view.renderGarage([
    {id:12,name:'Family EV latest',connector_type:'CCS2'},
    {id:13,name:'Other EV',connector_type:'CCS2'},
  ]);
  const other=visit(customerRoot,n=>n.tag==='li'&&label(n).includes('Other EV'));
  assert.ok(other);
  await visit(other,n=>n.tag==='button'&&label(n).includes('Select catalog model')).onclick();
  assert.match(label(customerRoot),/No catalog model associated/);
  assert.equal(typeof releasePendingLink,'function');
  releasePendingLink();
  await pendingSave;
  const searchAgain=visit(customerRoot,n=>n.tag==='button'&&label(n).includes('Search catalog'));
  assert.ok(searchAgain);
  searchAgain.onclick();
  await new Promise(resolve=>setImmediate(resolve));
  assert.match(label(customerRoot),/No catalog model associated/);
  assert.doesNotMatch(label(customerRoot),/Current: Curation test EV-B/);
  // When the vehicle disappears, discard panel and linked model immediately.
  view.renderGarage([]);
  assert.equal(customerRoot.querySelector('[data-vehicle-model-picker]'), null);
  assert.doesNotMatch(fs.readFileSync(path,'utf8'),/\.innerHTML\s*=/);
  // A reused actor object is not sufficient proof of the same logged-in
  // session. Clear all catalog UI and old vehicle selections on epoch change.
  view.renderGarage([{id:12,name:'Same object prior session',connector_type:'CCS2'}]);
  assert.ok(customerRoot.children.length>0);
  epoch++;
  await view.setActor(actor);
  assert.equal(customerRoot.children.length,0,
    'Reauthentication with identical actor must clear previous catalog DOM');
  view.renderGarage([{id:12,name:'New session',connector_type:'CCS2'}]);
  assert.match(label(customerRoot),/New session/);
  epoch++;
  view.renderGarage([{id:12,name:'Delayed stale garage',connector_type:'CCS2'}]);
  assert.equal(customerRoot.children.length,0,
    'Garage callback from old auth epoch must not preserve a stale vehicle list');
  await view.setActor(actor);
  actor={id:10,role:'admin'};epoch++;
  await view.setActor(actor);
  assert.equal(customerRoot.children.length,0);
  assert.match(label(adminRoot),/Curated vehicle model catalog/);
  const form=visit(adminRoot,n=>n.tag==='form'&&n.className==='vehicle-model-admin-form');
  assert.ok(form);
  visit(form,n=>n.name==='make').value='New brand';
  visit(form,n=>n.name==='model').value='Model Z';
  visit(form,n=>n.name==='source_url').value='https://example.com/model-z';
  await form.onsubmit({preventDefault(){}});
  assert.ok(sent.find(c=>c.path==='vehicle-models'&&c.method==='POST'));
  assert.match(label(adminRoot),/Catalog entry saved/);
  epoch++;
  await view.setActor(actor);
  assert.match(label(adminRoot),/Curated vehicle model catalog/,
    'Reused Admin object must reload after a new auth epoch');
  view.clear();
  assert.equal(adminRoot.children.length,0);
  console.log('PASS: customer model association, connector-filtered options, Admin curation and cross-account clearing.');
})().catch(e=>{console.error(e);process.exitCode=1;});
