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
const adminRoot=document.createElement('section'),customerRoot=document.createElement('section');
let actor={id:3,role:'customer'},epoch=1;
const sent=[];
const api=async(path,method='GET',body)=>{
  sent.push({path,method,body});
  if(path==='vehicles/12/model'&&method==='GET')return {vehicle_id:12,vehicle_connector_type:'CCS2',model_id:null,model:null};
  if(path.startsWith('vehicle-models?'))return {data:[model],last_page:1,current_page:1};
  if(path==='vehicles/12/model'&&method==='PUT')return {vehicle_id:12,model_id:body.model_id,model};
  if(path==='vehicle-models'&&method==='POST')return {...body,id:9,revision:1};
  throw Error('unexpected API path '+path);
};
const view=window.VehicleModelCatalog.create({adminRoot,customerRoot,api,actor:()=>actor,epoch:()=>epoch});
(async()=>{
  await view.setActor(actor);
  view.renderGarage([{id:12,name:'Family EV',connector_type:'CCS2'}]);
  const selectButton=visit(customerRoot,n=>n.tag==='button'&&label(n).includes('Select catalog model'));
  assert.ok(selectButton);
  await selectButton.onclick();
  assert.match(label(customerRoot),/Curation test EV-A/);
  assert.ok(sent.some(c=>c.path.includes('connector_type=CCS2')));
  const save=visit(customerRoot,n=>n.tag==='button'&&label(n).includes('Link selected model'));
  assert.ok(save);
  await save.onclick();
  assert.deepEqual(JSON.parse(JSON.stringify(sent.find(c=>c.method==='PUT').body)),{
    model_id:7,expected_model_id:null,
  });
  assert.match(label(customerRoot),/Model linked/);
  assert.doesNotMatch(fs.readFileSync(path,'utf8'),/\.innerHTML\s*=/);
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
  view.clear();
  assert.equal(adminRoot.children.length,0);
  console.log('PASS: customer model association, connector-filtered options, Admin curation and cross-account clearing.');
})().catch(e=>{console.error(e);process.exitCode=1;});
