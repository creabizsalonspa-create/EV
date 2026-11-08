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
let deferSource5=false,releaseSource5=null,deferSource6=false,releaseSource6=null;
const writes=[];
const api=async(path,method,body)=>{
  if(path==='vehicles/5/manual-soc'&&method==='POST'){
    writes.push(JSON.parse(JSON.stringify(body)));
    if(first){first=false;throw Error('network reply uncertain');}
    latest=body.battery_percent;
    return {battery_percent:latest,revision:1,source:'CUSTOMER_MANUAL_SELF_REPORT'};
  }
  if(path==='vehicles/5/manual-soc'){
    if(deferSource5) {
      deferSource5=false;
      await new Promise(resolve=>{releaseSource5=resolve;});
    }
    return {
      vehicle_id:5,
      battery_percent:latest===0?null:latest,revision:latest===0?0:1,
      reported_at:latest===0?null:'server timestamp',
      source:'CUSTOMER_MANUAL_SELF_REPORT',measured_by_phone:false,
      measured_by_vehicle_api:false,charging_capability_verified:false,
    };
  }
  if(path==='vehicles/6/manual-soc'){
    if(deferSource6){
      deferSource6=false;
      await new Promise(resolve=>{releaseSource6=resolve;});
    }
    return {vehicle_id:6,battery_percent:null,revision:0,reported_at:null,
      source:'CUSTOMER_MANUAL_SELF_REPORT',measured_by_phone:false,
      measured_by_vehicle_api:false,charging_capability_verified:false};
  }
  if(path==='vehicles/6/manual-soc/history?page=1')
    return {data:[],last_page:1,current_page:1};
  if(path==='vehicles/5/manual-soc/history?page=1')return {
    data:latest===0?[]:[{vehicle_id:5,battery_percent:latest,revision:1,
      reported_at:'server timestamp',source:'CUSTOMER_MANUAL_SELF_REPORT'}],
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
  assert.ok(start);
  // A real app may refresh the garage while this GET is still pending.
  const loading = start.onclick();
  view.renderGarage([{id:5,name:'Family car (refreshed)'}]);
  await loading;
  assert.ok(root.querySelector('[data-manual-soc-panel]'));
  assert.match(visible(root),/phone, vehicle API or charger/);
  const form=find(root,n=>n.tag==='form');
  find(form,n=>n.name==='battery_percent').value='77';
  await form.onsubmit({preventDefault(){}});
  assert.equal(writes.length,1);
  assert.match(visible(root),/Result uncertain/);
  // Persist the pending operation identity across a same-owner garage refresh.
  view.renderGarage([{id:5,name:'Family car (refreshed again)'}]);
  const retry=find(root,n=>n.tag==='button'&&visible(n).includes('Retry the same report'));
  assert.ok(retry);await retry.onclick();
  assert.equal(writes.length,2);
  assert.deepEqual(writes[0],writes[1]);
  assert.equal(writes[0].battery_percent,77);
  assert.equal(writes[0].expected_revision,0);
  assert.match(visible(root),/Latest self-reported battery: 77%/);
  // A source GET for vehicle 5 is delayed after a successful write. Meanwhile
  // a second vehicle is opened. The old form and old response must not modify
  // vehicle 6's state or submit another report for it.
  const oldForm=find(root,n=>n.tag==='form');
  find(oldForm,n=>n.name==='battery_percent').value='85';
  deferSource5=true;
  const pendingOld=oldForm.onsubmit({preventDefault(){}});
  for(let i=0;i<8&&!releaseSource5;i++)await Promise.resolve();
  assert.equal(typeof releaseSource5,'function');
  deferSource6=true;
  view.renderGarage([{id:5,name:'Family car'},{id:6,name:'Other car'}]);
  const switchButton=find(root,n=>n.tag==='button'&&visible(n).includes('Report battery for Other car'));
  assert.ok(switchButton);
  const opening6=switchButton.onclick();
  assert.equal(root.querySelector('[data-manual-soc-panel]'),null);
  const wroteBefore=writes.length;
  await oldForm.onsubmit({preventDefault(){}});
  assert.equal(writes.length,wroteBefore,'Detached old form cannot create another report');
  releaseSource5();
  await pendingOld;
  releaseSource6();
  await opening6;
  assert.match(visible(root),/No report yet/);
  assert.doesNotMatch(visible(root),/Latest self-reported battery: 85%/);
  assert.doesNotMatch(visible(root),/Manual report saved/);
  // POST can commit successfully while its subsequent read-back GET fails.
  // Keep the SAME operation key available until latest state AND history load.
  const retryRoot=document.createElement('section');
  const retryActor={id:51,role:'customer'};
  let accepted=false,readbackFailsOnce=true;
  const committedAttempts=[];
  const uncertainApi=async(path,method,body)=>{
    if(path==='vehicles/8/manual-soc'&&method==='POST'){
      committedAttempts.push(JSON.parse(JSON.stringify(body)));
      accepted=true;
      return {battery_percent:61,revision:1,source:'CUSTOMER_MANUAL_SELF_REPORT'};
    }
    if(path==='vehicles/8/manual-soc'){
      if(accepted&&readbackFailsOnce){
        readbackFailsOnce=false;
        throw Error('readback unavailable after successful POST');
      }
      return {vehicle_id:8,battery_percent:accepted?61:null,revision:accepted?1:0,
        reported_at:accepted?'server timestamp':null,source:'CUSTOMER_MANUAL_SELF_REPORT'};
    }
    if(path==='vehicles/8/manual-soc/history?page=1')return {
      data:accepted?[{vehicle_id:8,battery_percent:61,revision:1,
        reported_at:'server timestamp',source:'CUSTOMER_MANUAL_SELF_REPORT'}]:[],
      current_page:1,last_page:1,
    };
    throw Error('unexpected uncertain readback endpoint '+path);
  };
  const retryView=window.VehicleManualSoc.create({
    root:retryRoot,api:uncertainApi,actor:()=>retryActor,epoch:()=>1,
  });
  retryView.setActor(retryActor);
  retryView.renderGarage([{id:8,name:'Retry after readback'}]);
  await retryView.open({id:8,name:'Retry after readback'});
  const retryForm=find(retryRoot,n=>n.tag==='form');
  find(retryForm,n=>n.name==='battery_percent').value='61';
  await retryForm.onsubmit({preventDefault(){}});
  assert.equal(committedAttempts.length,1);
  assert.match(visible(retryRoot),/Result uncertain/);
  const retryAfterReadback=find(retryRoot,n=>n.tag==='button'&&visible(n).includes('Retry the same report'));
  assert.equal(retryAfterReadback.disabled,false,
    'Committed POST with failed readback must allow same-key retry');
  await retryAfterReadback.onclick();
  assert.equal(committedAttempts.length,2);
  assert.deepEqual(committedAttempts[0],committedAttempts[1],
    'Retry after uncertain readback must preserve original idempotency key');
  assert.match(visible(retryRoot),/Latest self-reported battery: 61%/);
  const doneRetry=find(retryRoot,n=>n.tag==='button'&&visible(n).includes('Retry the same report'));
  assert.equal(doneRetry.disabled,true,'Successful readback must clear the pending retry');
  retryView.clear();

  // A successful HTTP read that returns stale current or incomplete history
  // must not claim confirmation or generate a second manual observation.
  for (const defect of ['stale-current','missing-history']) {
    const probeRoot=document.createElement('section');
    const probeActor={id:90,role:'customer'};
    let accepted=false,badOnce=true;
    const attempts=[];
    const probeApi=async(path,method,body)=>{
      if(path==='vehicles/9/manual-soc'&&method==='POST'){
        attempts.push(JSON.parse(JSON.stringify(body)));
        accepted=true;
        return {vehicle_id:9,battery_percent:43,revision:1,
          source:'CUSTOMER_MANUAL_SELF_REPORT'};
      }
      if(path==='vehicles/9/manual-soc'){
        const stale=accepted&&defect==='stale-current'&&badOnce;
        if(stale)badOnce=false;
        return {vehicle_id:9,battery_percent:accepted&&!stale?43:null,
          revision:accepted&&!stale?1:0,reported_at:accepted?'server timestamp':null,
          source:'CUSTOMER_MANUAL_SELF_REPORT',
          measured_by_phone:false,measured_by_vehicle_api:false,
          charging_capability_verified:false};
      }
      if(path==='vehicles/9/manual-soc/history?page=1'){
        const missing=accepted&&defect==='missing-history'&&badOnce;
        if(missing)badOnce=false;
        return {data:accepted&&!missing?[{vehicle_id:9,battery_percent:43,
          revision:1,reported_at:'server timestamp',
          source:'CUSTOMER_MANUAL_SELF_REPORT'}]:[],
          current_page:1,last_page:1};
      }
      throw Error('Unexpected probe endpoint '+path);
    };
    const probe=window.VehicleManualSoc.create({
      root:probeRoot,api:probeApi,actor:()=>probeActor,epoch:()=>1,
    });
    probe.setActor(probeActor);
    probe.renderGarage([{id:9,name:'Confirmation probe'}]);
    await probe.open({id:9,name:'Confirmation probe'});
    const probeForm=find(probeRoot,n=>n.tag==='form');
    find(probeForm,n=>n.name==='battery_percent').value='43';
    await probeForm.onsubmit({preventDefault(){}});
    assert.equal(attempts.length,1);
    assert.match(visible(probeRoot),/Result uncertain/,
      defect+' readback must never claim a saved report');
    const probeRetry=find(probeRoot,n=>n.tag==='button'&&visible(n).includes('Retry the same report'));
    assert.equal(probeRetry.disabled,false);
    if (defect==='stale-current') {
      // Reopening a report after ambiguous write should reconcile server
      // state without submitting a duplicate request.
      const refresh=find(probeRoot,n=>n.tag==='button'&&
        visible(n).includes('Refresh source and history'));
      assert.ok(refresh);
      await refresh.onclick();
      assert.equal(attempts.length,1,
        'Refresh must preserve the original observation, not POST again');
    } else {
      await probeRetry.onclick();
      assert.equal(attempts.length,2);
      assert.deepEqual(attempts[0],attempts[1],defect+' retry changed operation identity');
    }
    assert.match(visible(probeRoot),/Manual report saved/);
    const afterReconcile=find(probeRoot,n=>n.tag==='button'&&
      visible(n).includes('Retry the same report'));
    assert.equal(afterReconcile.disabled,true,
      'Confirmed readback must clear pending report key');
    probe.clear();
  }
  // A second device can submit a newer revision before our HTTP readback.
  // Confirm the exact older observation in history without claiming that its
  // battery percentage is still the latest estimate or sending another POST.
  const advancedRoot=document.createElement('section');
  const advancedActor={id:94,role:'customer'};
  let acceptedOlder=false;
  const olderAttempts=[];
  const advancedApi=async(path,method,body)=>{
    if(path==='vehicles/10/manual-soc'&&method==='POST'){
      olderAttempts.push(JSON.parse(JSON.stringify(body)));
      acceptedOlder=true;
      return {vehicle_id:10,revision:1,battery_percent:54,
        source:'CUSTOMER_MANUAL_SELF_REPORT'};
    }
    if(path==='vehicles/10/manual-soc')return {
      vehicle_id:10,
      revision:acceptedOlder?2:0,
      battery_percent:acceptedOlder?28:null,
      reported_at:acceptedOlder?'newer server timestamp':null,
      source:'CUSTOMER_MANUAL_SELF_REPORT',
    };
    if(path==='vehicles/10/manual-soc/history?page=1')return {
      data:acceptedOlder?[
        {vehicle_id:10,revision:2,battery_percent:28,
         source:'CUSTOMER_MANUAL_SELF_REPORT',reported_at:'newer'},
        {vehicle_id:10,revision:1,battery_percent:54,
         source:'CUSTOMER_MANUAL_SELF_REPORT',reported_at:'earlier'},
      ]:[],
      current_page:1,last_page:1,
    };
    throw Error('Unexpected advanced readback '+path);
  };
  const advanced=window.VehicleManualSoc.create({
    root:advancedRoot,api:advancedApi,actor:()=>advancedActor,epoch:()=>1,
  });
  advanced.setActor(advancedActor);
  advanced.renderGarage([{id:10,name:'Shared garage vehicle'}]);
  await advanced.open({id:10,name:'Shared garage vehicle'});
  const advancedForm=find(advancedRoot,n=>n.tag==='form');
  find(advancedForm,n=>n.name==='battery_percent').value='54';
  await advancedForm.onsubmit({preventDefault(){}});
  assert.equal(olderAttempts.length,1);
  assert.equal(olderAttempts[0].expected_revision,0);
  assert.match(visible(advancedRoot),/Manual report saved; a newer battery report/,
    'Superseded self-report must be confirmed without false current estimate');
  assert.match(visible(advancedRoot),/Latest self-reported battery: 28%/);
  assert.doesNotMatch(visible(advancedRoot),/Result uncertain/);
  const advancedRetry=find(advancedRoot,n=>n.tag==='button'&&
    visible(n).includes('Retry the same report'));
  assert.equal(advancedRetry.disabled,true);
  advanced.clear();

  // Reopening the same vehicle during an unresolved POST must not allow a
  // second submission before the original response settles.
  const holdRoot=document.createElement('section');
  const holdActor={id:95,role:'customer'};
  const heldWrites=[];
  let releaseHeldPost=null,serverCommitted=false;
  const heldApi=async(path,method,body)=>{
    if(path==='vehicles/11/manual-soc'&&method==='POST'){
      heldWrites.push(JSON.parse(JSON.stringify(body)));
      await new Promise(resolve=>{releaseHeldPost=resolve;});
      serverCommitted=true;
      return {vehicle_id:11,revision:1,battery_percent:64,
        source:'CUSTOMER_MANUAL_SELF_REPORT'};
    }
    if(path==='vehicles/11/manual-soc')return {
      vehicle_id:11,revision:serverCommitted?1:0,
      battery_percent:serverCommitted?64:null,
      source:'CUSTOMER_MANUAL_SELF_REPORT',reported_at:null,
    };
    if(path==='vehicles/11/manual-soc/history?page=1')return {
      current_page:1,last_page:1,
      data:serverCommitted?[{vehicle_id:11,revision:1,battery_percent:64,
        source:'CUSTOMER_MANUAL_SELF_REPORT',reported_at:null}]:[],
    };
    throw Error('Unexpected held POST endpoint '+path);
  };
  const heldView=window.VehicleManualSoc.create({
    root:holdRoot,api:heldApi,actor:()=>holdActor,epoch:()=>1,
  });
  heldView.setActor(holdActor);
  heldView.renderGarage([{id:11,name:'Single operation EV'}]);
  await heldView.open({id:11,name:'Single operation EV'});
  const heldForm=find(holdRoot,n=>n.tag==='form');
  find(heldForm,n=>n.name==='battery_percent').value='64';
  const heldSubmission=heldForm.onsubmit({preventDefault(){}});
  assert.equal(typeof releaseHeldPost,'function');
  assert.equal(heldWrites.length,1);
  const heldRefresh=find(holdRoot,n=>n.tag==='button'&&
    visible(n).includes('Refresh source and history'));
  assert.ok(heldRefresh);
  await heldRefresh.onclick();
  const heldRetry=find(holdRoot,n=>n.tag==='button'&&
    visible(n).includes('Retry the same report'));
  assert.ok(heldRetry);
  assert.equal(heldRetry.disabled,true,
    'Same-vehicle refresh cannot enable retry before prior POST settles');
  await heldRetry.onclick(); // Programmatic call bypasses disabled HTML surface.
  assert.equal(heldWrites.length,1,
    'Unresolved original POST cannot race a second identical request');
  releaseHeldPost();
  await heldSubmission;
  const nowRetry=find(holdRoot,n=>n.tag==='button'&&
    visible(n).includes('Retry the same report'));
  assert.equal(nowRetry.disabled,false,
    'Original POST settling must unlock same-key retry after uncertain refresh');
  await heldView.open({id:11,name:'Single operation EV'});
  assert.equal(heldWrites.length,1,
    'Authoritative refresh confirms the prior POST without another submission');
  assert.match(visible(holdRoot),/Manual report saved/);
  const confirmedRetry=find(holdRoot,n=>n.tag==='button'&&
    visible(n).includes('Retry the same report'));
  assert.equal(confirmedRetry.disabled,true);
  heldView.clear();

  // A reused identity object across a new auth epoch must clear old DOM and
  // pending report forms, even before platform code allocates a new actor.
  view.renderGarage([{id:6,name:'Reused account car'}]);
  assert.ok(root.children.length>0);
  epoch++;
  view.setActor(account);
  assert.equal(root.children.length,0,
    'Same actor reference with changed session must clear private SOC UI');
  view.renderGarage([{id:6,name:'Fresh session car'}]);
  assert.doesNotMatch(visible(root),/Latest self-reported battery: 77%/);
  assert.equal(root.querySelector('[data-manual-soc-panel]'),null);
  // A garage refresh arriving before setActor() must not retain the
  // previous session's panel or status.
  epoch++;
  view.renderGarage([{id:6,name:'Stale garage callback'}]);
  assert.equal(root.children.length,0);
  view.setActor(account);
  account={id:32,role:'customer'};epoch++;
  view.setActor(account);
  assert.equal(root.children.length,0);
  assert.doesNotMatch(fs.readFileSync(path,'utf8'),/\.innerHTML\s*=/);
  console.log('PASS: manual SOC source, uncertain retry, stale form/refresh isolation across vehicles and accounts.');
})().catch(e=>{console.error(e);process.exitCode=1;});
