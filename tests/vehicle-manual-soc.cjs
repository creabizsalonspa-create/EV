// Source-backed manual SOC web regression, including frozen retry after uncertainty.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

class Element {
  constructor(tag){
    this.tag=tag;this.dataset={};this.children=[];this.textContent='';
    this.attributes={};this.value='';this.name='';this.disabled=false;
  }
  append(...nodes){for(const node of nodes){node.parent=this;this.children.push(node);}}
  prepend(...nodes){nodes.forEach(n=>n.parent=this);this.children.unshift(...nodes);}
  replaceChildren(...nodes){this.children=[];this.append(...nodes);}
  setAttribute(k,v){this.attributes[k]=v;}
  removeAttribute(k){delete this.attributes[k];}
  remove(){if(this.parent)this.parent.children=this.parent.children.filter(n=>n!==this);}
  querySelector(selector){
    const check=node=>selector==='[data-manual-soc-status]'&&node.dataset.manualSocStatus==='yes'||
      selector==='[data-manual-soc-panel]'&&node.dataset.manualSocPanel==='yes';
    const find=node=>{
      if(check(node))return node;
      for(const child of node.children){const value=find(child);if(value)return value;}
      return null;
    };
    return find(this);
  }
}
const document={documentElement:{lang:'en'},createElement:tag=>new Element(tag)};
const window={};
const crypto={randomUUID:()=> 'd9c914a4-b79f-40d6-b6ef-2a47a0d6a213'};
const path='services/business-api/public/vehicle-manual-soc.js';
vm.runInNewContext(fs.readFileSync(path,'utf8'),{window,document,crypto,Number,Object},{filename:path});
assert.equal(typeof window.VehicleManualSoc.create,'function');
const root=document.createElement('section');
let account={id:31,role:'customer'},epoch=1,first=true,latest=0;
const writes=[];
const api=async(path,method,body)=>{
  if(path==='vehicles/5/manual-soc'&&method==='POST'){
    writes.push(JSON.parse(JSON.stringify(body)));
    if(first){first=false;throw Error('network reply uncertain');}
    latest=body.battery_percent;
    return {battery_percent:latest,revision:1,source:'CUSTOMER_MANUAL_SELF_REPORT'};
  }
  if(path==='vehicles/5/manual-soc')return {
    battery_percent:latest===0?null:latest,revision:latest===0?0:1,
    reported_at:latest===0?null:'server timestamp',
    source:'CUSTOMER_MANUAL_SELF_REPORT',measured_by_phone:false,
    measured_by_vehicle_api:false,charging_capability_verified:false,
  };
  if(path==='vehicles/5/manual-soc/history?page=1')return {
    data:latest===0?[]:[{battery_percent:latest,revision:1,reported_at:'server timestamp'}],
    last_page:1,current_page:1,
  };
  throw Error('unexpected endpoint '+path);
};
const view=window.VehicleManualSoc.create({root,api,actor:()=>account,epoch:()=>epoch});
function find(node,pred){
  if(pred(node))return node;
  for(const c of node.children){const r=find(c,pred);if(r)return r;}
  return null;
}
function visible(node){return [node.textContent,...node.children.map(visible)].join(' ');}
(async()=>{
  view.setActor(account);
  view.renderGarage([{id:5,name:'Family car'}]);
  const start=find(root,n=>n.tag==='button'&&visible(n).includes('Report battery for'));
  assert.ok(start);await start.onclick();
  assert.match(visible(root),/phone, vehicle API or charger/);
  const form=find(root,n=>n.tag==='form');
  find(form,n=>n.name==='battery_percent').value='77';
  await form.onsubmit({preventDefault(){}});
  assert.equal(writes.length,1);
  assert.match(visible(root),/Result uncertain/);
  const retry=find(root,n=>n.tag==='button'&&visible(n).includes('Retry the same report'));
  assert.ok(retry);await retry.onclick();
  assert.equal(writes.length,2);
  assert.deepEqual(writes[0],writes[1]);
  assert.equal(writes[0].battery_percent,77);
  assert.equal(writes[0].expected_revision,0);
  assert.match(visible(root),/Latest self-reported battery: 77%/);
  account={id:32,role:'customer'};epoch++;
  view.setActor(account);
  assert.equal(root.children.length,0);
  assert.doesNotMatch(fs.readFileSync(path,'utf8'),/\.innerHTML\s*=/);
  console.log('PASS: manual SOC source warning, optimistic revision, same-key uncertain retry and account isolation.');
})().catch(e=>{console.error(e);process.exitCode=1;});
