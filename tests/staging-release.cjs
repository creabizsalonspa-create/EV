'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const release = require('../scripts/staging-release.cjs');

const SHA='c'.repeat(40), OLD_ID='sha256:'+'a'.repeat(64), NEW_ID='sha256:'+'b'.repeat(64);
const oldRef='creabiz-ev-business:old',newRef='creabiz-ev-business:new';
function sandbox(body) {
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-release-'));
  fs.chmodSync(dir,0o700);
  const envFile=path.join(dir,'private.env');
  fs.writeFileSync(envFile,'APP_KEY=base64:synthetic_only\nDB_PASSWORD=local_only_not_real\n',{mode:0o600});
  try{return body(dir,envFile);}finally{fs.rmSync(dir,{force:true,recursive:true});}
}
function mock(envFile) {
  const state={tag:'old',stopped:false,failCandidate:false,failRollback:false,mutations:0,commands:[],poisonOldImage:false,exposeDatabase:false,remoteContext:false};
  const getId=()=>state.tag==='old'?OLD_ID:NEW_ID;
  const snapshot=()=>{
    let roles=['application','worker','scheduler','database','gateway'];
    return roles.map((service,i)=>({
      Project:'ev-staging',Service:service,State:'running',
      Health:['application','database'].includes(service)?(state.stopped && service==='application'?'unhealthy':'healthy'):'',
      Image:i<=2?'creabiz-ev-business:'+state.tag:i===3?'postgres:17-alpine':'caddy:2-alpine',
      ID:String(i+1).repeat(64),
      Publishers:i===4?[{URL:'127.0.0.1',TargetPort:443,PublishedPort:8443,Protocol:'tcp'}]:
        state.exposeDatabase&&i===3?[{URL:'0.0.0.0',PublishedPort:5432,TargetPort:5432,Protocol:'tcp'}]:[],
    }));
  };
  const image=(ref)=>{
    const isOld=ref===oldRef;
    const id=isOld?(state.poisonOldImage?'sha256:'+'d'.repeat(64):OLD_ID):NEW_ID;
    return [{Id:id,RepoTags:[ref],Config:{Labels:{
      'org.opencontainers.image.revision':isOld?'0'.repeat(40):SHA,
      'org.opencontainers.image.source':'https://github.com/creabizsalonspa-create/EV',
      'com.creabiz.ev.staging.review':'true',
    }}}];
  };
  function run(command,args,options={}) {
    state.commands.push([command,...args]);
    assert.equal(command,'docker');
    if(args[0]==='context'){
      assert.equal(args[1],'inspect');
      return state.remoteContext?'tcp://unknown:2375\n':'unix:///var/run/docker.sock\n';
    }
    if(args[0]==='image'){
      assert.deepEqual(args.slice(0,2),['image','inspect']);
      if(![newRef,oldRef].includes(args[2]))throw Error('Unexpected image');
      return JSON.stringify(image(args[2]));
    }
    if(args[0]==='container') {
      const ids=args.slice(2);
      assert.equal(ids.length,3);
      return JSON.stringify(ids.map(id=>({Id:id,Image:getId(),State:{Running:true,Status:'running'}})));
    }
    assert.equal(args[0],'compose');
    if(args.includes('ps'))return JSON.stringify(snapshot());
    if(args.includes('up')) {
      assert.ok(args.includes('--no-deps')&&args.includes('--no-build')&&args.includes('--wait'));
      assert.deepEqual(args.slice(-3),['application','worker','scheduler']);
      const tag=options.env?.EV_IMAGE_TAG;
      assert.ok(['old','new'].includes(tag));
      state.mutations++;
      state.tag=tag;
      state.stopped=state.failCandidate&&tag==='new';
      if(state.failRollback&&tag==='old')throw Error('Simulated failed rollback');
      return '';
    }
    throw Error('Unexpected Docker command');
  }
  return {state,run,env:{},envFile};
}
const argv=(mode,journal)=>[mode,'--candidate-tag','new','--source-sha',SHA,
  ...(mode==='apply'?['--journal',journal,'--approval','APPLY_PRIVATE_STAGING_'+SHA.slice(0,12)]:[])];

test('parse accepts explicit plan and approved staging-only apply',()=>sandbox((dir)=>{
  assert.equal(release.parseArgs(argv('plan')).mode,'plan');
  assert.equal(release.parseArgs(argv('apply',path.join(dir,'journal.jsonl'))).mode,'apply');
}));
test('unknown commands, missing approval and malformed tags fail closed',()=>sandbox((dir)=>{
  for(const args of [
    ['deploy','--candidate-tag','new','--source-sha',SHA],
    ['apply','--candidate-tag','new','--source-sha',SHA],
    ['plan','--candidate-tag','evil;rm -rf /','--source-sha',SHA],
    ['plan','--candidate-tag','new','--source-sha','bad'],
    [...argv('plan'),'--journal',path.join(dir,'j.jsonl')],
    [...argv('apply',path.join(dir,'j.jsonl')),'--approval','junk']
  ])assert.throws(()=>release.parseArgs(args));
}));
test('healthy staging candidate produces read-only image-ID plan',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  const result=release.inspectPlan(release.parseArgs(argv('plan')),deps);
  assert.equal(result.old_id,OLD_ID);
  assert.equal(result.candidate_id,NEW_ID);
  assert.equal(result.source_sha,SHA);
  assert.equal(result.automatic_migrations,false);
  assert.equal(deps.state.mutations,0);
}));
test('plan command never invokes Docker up nor writes a journal',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  assert.equal(release.main(argv('plan'),deps),0);
  assert.equal(deps.state.mutations,0);
  assert.ok(deps.state.commands.every(x=>!x.includes('up')&&!x.includes('down')&&!x.includes('rm')));
}));
test('approved release runs only app/worker/scheduler and verifies candidate image ID',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),journal=path.join(dir,'release.jsonl');
  assert.equal(release.main(argv('apply',journal),deps),0);
  assert.equal(deps.state.tag,'new');
  assert.equal(deps.state.mutations,1);
  const records=fs.readFileSync(journal,'utf8').trim().split('\n').map(JSON.parse);
  assert.deepEqual(records.map(x=>x.event),['PREPARED','RELEASE_STARTED','RELEASE_VERIFIED']);
  assert.equal(records[0].plan.database_and_gateway_unchanged,true);
  assert.equal(fs.statSync(journal).mode&0o077,0);
  assert.ok(deps.state.commands.every(x=>!x.includes('migrate')&&!x.includes('down')));
}));
test('failed staged health causes automatic rollback and recorded verified recovery',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);deps.state.failCandidate=true;
  const file=path.join(dir,'release.jsonl');
  assert.equal(release.main(argv('apply',file),deps),1);
  assert.equal(deps.state.tag,'old');
  assert.equal(deps.state.mutations,2);
  assert.ok(fs.readFileSync(file,'utf8').includes('ROLLBACK_VERIFIED'));
}));
test('uncertain failed rollback retains explicit operator reconciliation record',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);deps.state.failCandidate=true;deps.state.failRollback=true;
  const file=path.join(dir,'release.jsonl');
  assert.equal(release.main(argv('apply',file),deps),3);
  assert.ok(fs.readFileSync(file,'utf8').includes('OPERATOR_RECONCILIATION_REQUIRED'));
}));
test('old image tag retargeting prevents unsafe rollback',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  const original=deps.run;
  deps.state.failCandidate=true;
  deps.run=(cmd,args,options)=>{
    if(args[0]==='image'&&args[2]===oldRef&&deps.state.mutations>0)deps.state.poisonOldImage=true;
    return original(cmd,args,options);
  };
  const file=path.join(dir,'release.jsonl');
  assert.equal(release.main(argv('apply',file),deps),3);
  assert.equal(deps.state.mutations,1);
  assert.ok(fs.readFileSync(file,'utf8').includes('OPERATOR_RECONCILIATION_REQUIRED'));
}));
test('private .env world/group readability is rejected',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  if(process.platform!=='win32'){fs.chmodSync(envFile,0o644);assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps));}
}));
test('remote context or Docker endpoint variables are denied',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),{...deps,env:{DOCKER_HOST:'tcp://remote:2376'}}));
  deps.state.remoteContext=true;
  assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps));
}));
test('unexpected public database binding blocks release before any mutation',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);deps.state.exposeDatabase=true;
  assert.equal(release.main(argv('apply',path.join(dir,'bad.jsonl')),deps),2);
  assert.equal(deps.state.mutations,0);
}));
test('candidate has to differ from current source image ID',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),run=deps.run;
  deps.run=(cmd,args,options)=>{
    if(args[0]==='image'&&args[2]===newRef){let data=JSON.parse(run(cmd,args,options));data[0].Id=OLD_ID;return JSON.stringify(data);}
    return run(cmd,args,options);
  };
  assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps));
}));
test('candidate source label mismatch blocks staging',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),run=deps.run;
  deps.run=(cmd,args,options)=>{
    if(args[0]==='image'&&args[2]===newRef){let data=JSON.parse(run(cmd,args,options));data[0].Config.Labels['org.opencontainers.image.revision']='0'.repeat(40);return JSON.stringify(data);}
    return run(cmd,args,options);
  };
  assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps));
}));
test('journal cannot overwrite previous release evidence or escape private directory',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),file=path.join(dir,'release.jsonl');
  assert.equal(release.main(argv('apply',file),deps),0);
  assert.equal(release.main(argv('apply',file),deps),2);
  const copied=fs.readFileSync(file,'utf8');
  assert.equal(copied.split('\n').filter(Boolean).length,3);
  const outside=path.join(dir,'..','public-evidence.jsonl');
  assert.throws(()=>release.outputJournal(outside));
}));
test('running image validation checks actual immutable ID, not only tag',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),run=deps.run;
  deps.run=(cmd,args,options)=>{
    if(args[0]==='container')return JSON.stringify(args.slice(2).map(id=>({Id:id,Image:OLD_ID,State:{Running:true,Status:'running'}})));
    return run(cmd,args,options);
  };
  deps.state.tag='new';
  assert.throws(()=>release.verifyRunning(newRef,NEW_ID,deps));
}));
