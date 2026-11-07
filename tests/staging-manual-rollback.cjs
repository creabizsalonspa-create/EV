'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const release=require('../scripts/staging-release.cjs');
const recovery=require('../scripts/staging-manual-rollback.cjs');

const SHA='a'.repeat(40),OLD='sha256:'+'b'.repeat(64),NEW='sha256:'+'c'.repeat(64);
const oldRef='creabiz-ev-business:old',newRef='creabiz-ev-business:new';
function temp(body){
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-recovery-'));
  fs.chmodSync(dir,0o700);
  try{return body(dir);} finally {fs.rmSync(dir,{recursive:true,force:true});}
}
function plan(){return {project:'ev-staging',source_sha:SHA,old_ref:oldRef,old_id:OLD,
  candidate_ref:newRef,candidate_id:NEW,roles:['application','worker','scheduler'],
  protected_services:{database:'4'.repeat(64),gateway:'5'.repeat(64)},
  database_and_gateway_unchanged:true,automatic_migrations:false,external_services_or_payments_invoked:false};}
function setup(dir,events=['RELEASE_STARTED']) {
  const file=path.join(dir,'release.jsonl');
  release.createJournal(file,plan());
  for(const event of events)release.journal(file,event);
  return file;
}
function mock(dir){
  const state={tag:'new',up:0,badGateway:false,badDatabase:false,foreign:false,
    staleOld:false,failOld:false,appUnhealthy:true,seen:[]};
  const options={lockPath:path.join(dir,'operation.lock'),env:{},envFile:path.join(dir,'private.env'),preflight:()=>({ok:true,errors:[]}),effectiveConfig:()=>true,checkHttp:()=>true};
  fs.writeFileSync(options.envFile,'DB_PASSWORD=SYNTHETIC\nAPP_KEY=local_fake_not_real\n',{mode:0o600});
  const rows=()=> ['application','worker','scheduler','database','gateway'].map((service,i)=>({
    Project:'ev-staging',Service:service,State:'running',
    Health:service==='database'?(state.badDatabase?'unhealthy':'healthy'):
      service==='application'?(state.appUnhealthy?'unhealthy':'healthy'):'',
    ID:String(i+1).repeat(64),
    Image:i<3?(state.foreign?'creabiz-ev-business:foreign': 'creabiz-ev-business:'+state.tag):
      i===3?'postgres:17-alpine':'caddy:2-alpine',
    Publishers:service==='gateway'?[{URL:state.badGateway?'0.0.0.0':'127.0.0.1',
      PublishedPort:8443,TargetPort:443,Protocol:'tcp'}]:[],
  }));
  const run=(command,args,opts={})=>{
    state.seen.push([command,...args]);assert.equal(command,'docker');
    if(args[0]==='context')return args[1]==='show'?'default\n':'unix:///var/run/docker.sock\n';
    if(args[0]==='image'){
      assert.equal(args[2],oldRef);
      return JSON.stringify([{Id:state.staleOld?NEW:OLD,RepoTags:[oldRef],Config:{Labels:{}}}]);
    }
    if(args[0]==='container'){
      return JSON.stringify(args.slice(2).map(id=>({Id:id,Image:state.tag==='old'?OLD:NEW,State:{Running:true,Status:'running'}})));
    }
    assert.equal(args[0],'compose');
    if(args.includes('ps'))return JSON.stringify(rows());
    if(args.includes('up')){
      assert.ok(args.includes('--no-deps') && args.includes('--no-build'));
      assert.deepEqual(args.slice(-3),['application','worker','scheduler']);
      assert.equal(opts.env.EV_IMAGE_TAG,'old');
      state.up++;state.tag='old';state.appUnhealthy=false;
      if(state.failOld)throw Error('Simulated Compose interruption');
      return '';
    }
    throw Error('Unexpected Docker operation');
  };
  return {state,...options,run};
}
const approval='ROLLBACK_PRIVATE_STAGING_'+SHA.slice(0,12);
function cli(file,token=approval){return ['rollback','--journal',file,'--approval',token];}
test('explicit rollback command accepts only journal and correct option shape',()=>temp(dir=>{
  assert.equal(recovery.parseArgs(cli('file.jsonl')).approval,approval);
  for(const args of [[],['rollback'],['rollback','--journal','x'],['apply','--journal','file','--approval',approval]])
    assert.throws(()=>recovery.parseArgs(args));
}));
test('prepared-only journal cannot cause a rollback',()=>temp(dir=>{
  const file=setup(dir,[]);
  assert.throws(()=>recovery.loadJournal(file));
}));
test('correct private interrupted release journal loads',()=>temp(dir=>{
  const file=setup(dir);
  const parsed=recovery.loadJournal(file);
  assert.equal(parsed.plan.old_id,OLD);
  assert.deepEqual(parsed.events.map(x=>x.event),['PREPARED','RELEASE_STARTED']);
}));
test('manual rollback of interrupted release restores only three app services',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);
  assert.equal(recovery.main(cli(file),deps),0);
  assert.equal(deps.state.tag,'old');
  assert.equal(deps.state.up,1);
  assert.ok(fs.readFileSync(file,'utf8').includes('MANUAL_ROLLBACK_VERIFIED'));
  assert.ok(deps.state.seen.every(args=>!args.includes('migrate')&&!args.includes('down')&&!args.includes('rm')));
}));
test('successful earlier rollback is read-only confirmed without another up',()=>temp(dir=>{
  const file=setup(dir,['RELEASE_STARTED','ROLLBACK_VERIFIED']),deps=mock(dir);
  deps.state.tag='old';deps.state.appUnhealthy=false;
  assert.equal(recovery.main(cli(file),deps),0);
  assert.equal(deps.state.up,0);
}));
test('wrong approval rejected before Docker operation',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);
  assert.equal(recovery.main(cli(file,'WRONG'),deps),2);
  assert.equal(deps.state.seen.length,0);
}));
test('publicly bound staging gateway blocks manual rollback',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);deps.state.badGateway=true;
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));
test('database unhealthy blocks rollback instead of hiding environment failure',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);deps.state.badDatabase=true;
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));
test('unrecognized newer running tag cannot be overwritten',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);deps.state.foreign=true;
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));
test('former tag retargeted to different image ID blocks rollback',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);deps.state.staleOld=true;
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));
test('Compose rollback failure leaves operator reconciliation record',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);deps.state.failOld=true;
  assert.equal(recovery.main(cli(file),deps),3);
  assert.equal(deps.state.up,1);
  assert.ok(fs.readFileSync(file,'utf8').includes('OPERATOR_RECONCILIATION_REQUIRED'));
}));
test('concurrent staging release lock prevents manual rollback',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir),unlock=release.acquireLock(deps);
  try{assert.equal(recovery.main(cli(file),deps),2);assert.equal(deps.state.up,0);}
  finally{unlock();}
}));
test('symlinked, public-readable and unknown-event journals fail closed',()=>temp(dir=>{
  const file=setup(dir),link=path.join(dir,'alias.jsonl');fs.symlinkSync(file,link);
  assert.throws(()=>recovery.loadJournal(link));
  release.journal(file,'UNEXPECTED_OPERATOR_COMMAND');
  assert.throws(()=>recovery.loadJournal(file));
  if(process.platform!=='win32'){
    fs.chmodSync(file,0o644);
    assert.throws(()=>recovery.loadJournal(file));
  }
}));
test('already restored tag with different actual image is never accepted',()=>temp(dir=>{
  const file=setup(dir,['MANUAL_ROLLBACK_VERIFIED']),deps=mock(dir);
  deps.state.tag='new';
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));

test('unsafe preflight rejects manual recovery before any Compose command',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);
  deps.preflight=()=>({ok:false,errors:['DB_URL external production']});
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
  assert.equal(deps.state.seen.length,0);
}));

test('manual rollback refuses unsafe resolved Compose even with approved journal',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);
  deps.effectiveConfig=()=>false;
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
  assert.equal(deps.state.seen.length,0);
}));

test('manual recovery rejects changed database identity after first release',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir),original=deps.run;
  deps.run=(cmd,args,opts)=>{
    if(args[0]==='compose'&&args.includes('ps')){
      const rows=JSON.parse(original(cmd,args,opts));
      rows.find(x=>x.Service==='database').ID='9'.repeat(64);
      return JSON.stringify(rows);
    }
    return original(cmd,args,opts);
  };
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));
test('recovery provenance requires stable protected container IDs',()=>temp(dir=>{
  const file=setup(dir);
  const records=fs.readFileSync(file,'utf8').trim().split('\n').map(JSON.parse);
  delete records[0].plan.protected_services;
  fs.writeFileSync(file,records.map(x=>JSON.stringify(x)).join('\n')+'\n');
  assert.throws(()=>recovery.loadJournal(file));
}));

test('manual rollback requires healthy private HTTP routes after restoring prior app',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir);
  deps.checkHttp=()=>false;
  assert.equal(recovery.main(cli(file),deps),3);
  assert.equal(deps.state.up,1);
  const events=fs.readFileSync(file,'utf8');
  assert.ok(events.includes('OPERATOR_RECONCILIATION_REQUIRED'));
  assert.ok(!events.includes('"event":"MANUAL_ROLLBACK_VERIFIED"'));
}));
test('previous verified rollback is not reaccepted with broken HTTP readiness',()=>temp(dir=>{
  const file=setup(dir,['RELEASE_STARTED','ROLLBACK_VERIFIED']),deps=mock(dir);
  deps.state.tag='old';deps.state.appUnhealthy=false;
  deps.checkHttp=()=>false;
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));

test('old image tag retarget between manual recovery checks refuses any Compose mutation',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir),original=deps.run;
  let checks=0;
  deps.run=(cmd,args,opts)=>{
    const raw=original(cmd,args,opts);
    if(args[0]==='image'&&args[2]===oldRef&&++checks===2){
      const items=JSON.parse(raw);items[0].Id=NEW;return JSON.stringify(items);
    }
    return raw;
  };
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
  assert.ok(!fs.readFileSync(file,'utf8').includes('MANUAL_ROLLBACK_STARTED'));
}));
test('old tag retarget after manual Compose recover marks reconciliation instead of acceptance',()=>temp(dir=>{
  const file=setup(dir),deps=mock(dir),original=deps.run;
  let checks=0;
  deps.run=(cmd,args,opts)=>{
    const raw=original(cmd,args,opts);
    if(args[0]==='image'&&args[2]===oldRef&&++checks>=3){
      const items=JSON.parse(raw);items[0].Id=NEW;return JSON.stringify(items);
    }
    return raw;
  };
  assert.equal(recovery.main(cli(file),deps),3);
  assert.equal(deps.state.up,1);
  const event=fs.readFileSync(file,'utf8');
  assert.ok(event.includes('OPERATOR_RECONCILIATION_REQUIRED'));
  assert.ok(!event.includes('MANUAL_ROLLBACK_VERIFIED'));
}));
test('previously verified manual recovery still detects later retargeted old image',()=>temp(dir=>{
  const file=setup(dir,['RELEASE_STARTED','ROLLBACK_VERIFIED']),deps=mock(dir),original=deps.run;
  deps.state.tag='old';deps.state.appUnhealthy=false;
  let checks=0;
  deps.run=(cmd,args,opts)=>{
    const raw=original(cmd,args,opts);
    if(args[0]==='image'&&args[2]===oldRef&&++checks===2){
      const items=JSON.parse(raw);items[0].Id=NEW;return JSON.stringify(items);
    }
    return raw;
  };
  assert.equal(recovery.main(cli(file),deps),2);
  assert.equal(deps.state.up,0);
}));
