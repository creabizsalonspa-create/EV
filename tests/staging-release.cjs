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
  const state={tag:'old',stopped:false,failCandidate:false,failRollback:false,mutations:0,commands:[],poisonOldImage:false,exposeDatabase:false,remoteContext:false,replaceDatabase:false,replaceGateway:false};
  const getId=()=>state.tag==='old'?OLD_ID:NEW_ID;
  const snapshot=()=>{
    let roles=['application','worker','scheduler','database','gateway'];
    return roles.map((service,i)=>({
      Project:'ev-staging',Service:service,State:'running',
      Health:['application','database'].includes(service)?(state.stopped && service==='application'?'unhealthy':'healthy'):'',
      Image:i<=2?'creabiz-ev-business:'+state.tag:i===3?'postgres:17-alpine@sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b':'caddy:2-alpine@sha256:78bd35025c7ba85961dd149ae670315129954e77abe2383ac47c89106b35ade9',
      ID:i===3&&state.replaceDatabase&&state.mutations>0?'9'.repeat(64):
         i===4&&state.replaceGateway&&state.mutations>0?'8'.repeat(64):String(i+1).repeat(64),
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
      if(args[1]==='show') return state.remoteContext?'production-remote\n':'default\n';
      assert.equal(args[1],'inspect');
      return 'unix:///var/run/docker.sock\n';
    }
    if(args[0]==='image'){
      assert.deepEqual(args.slice(0,2),['image','inspect']);
      if(![newRef,oldRef].includes(args[2]))throw Error('Unexpected image');
      return JSON.stringify(image(args[2]));
    }
    if(args[0]==='container') {
      const ids=args.slice(2);
      assert.equal(ids.length,3);
      return JSON.stringify(ids.map((id,i)=>({Id:id,Image:getId(),State:{Running:true,Status:'running'},Config:{Labels:{'com.docker.compose.project':'ev-staging','com.docker.compose.service':['application','worker','scheduler'][i]}}})));
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
  return {state,run,env:{},envFile,lockPath:path.join(path.dirname(envFile),'.release.lock'),preflight:()=>({ok:true,errors:[]}),effectiveConfig:()=>true,checkHttp:()=>true};
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
  assert.equal(result.old_source_sha,'0'.repeat(40));
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
test('stale old image tag fails when actual running container uses a different immutable image',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  deps.run=(cmd,args,opts)=>{
    const raw=original(cmd,args,opts);
    if(args[0]==='container'){
      const ctrs=JSON.parse(raw);
      ctrs[1].Image=NEW_ID;
      return JSON.stringify(ctrs);
    }
    return raw;
  };
  assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps),/immutable image|identity/);
  assert.equal(deps.state.mutations,0);
}));
test('release plan rejects forged role mapping and mismatched inspected container identity',()=>sandbox((dir,envFile)=>{
  for(const poison of [
    ctrs=>ctrs[1].Config.Labels['com.docker.compose.service']='application',
    ctrs=>ctrs[2].Config.Labels['com.docker.compose.project']='production',
    ctrs=>ctrs[0].Id='f'.repeat(64),
    ctrs=>ctrs[1].State.Status='exited'
  ]){
    const deps=mock(envFile),original=deps.run;
    deps.run=(cmd,args,opts)=>{
      const raw=original(cmd,args,opts);
      if(args[0]==='container'){
        const ctrs=JSON.parse(raw);poison(ctrs);return JSON.stringify(ctrs);
      }
      return raw;
    };
    assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps));
    assert.equal(deps.state.mutations,0);
  }
}));
test('different running container appears during plan image inspection: no release is planned',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let psCalls=0;
  deps.run=(cmd,args,opts)=>{
    const raw=original(cmd,args,opts);
    if(args[0]==='compose'&&args.includes('ps')&&++psCalls===3){
      const rows=JSON.parse(raw);
      rows.find(row=>row.Service==='scheduler').ID='f'.repeat(64);
      return JSON.stringify(rows);
    }
    return raw;
  };
  assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps),/changed during/);
  assert.equal(deps.state.mutations,0);
}));
test('running application changes after plan but before release start: preserve PREPARED only',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let psCalls=0;
  deps.run=(cmd,args,opts)=>{
    if(args[0]==='compose'&&args.includes('ps')&&++psCalls===4){
      // Simulate another operator advancing the app between snapshots.
      deps.state.tag='new';
    }
    return original(cmd,args,opts);
  };
  const file=path.join(dir,'concurrent-before-start.jsonl');
  assert.equal(release.main(argv('apply',file),deps),2);
  assert.equal(deps.state.mutations,0);
  assert.deepEqual(fs.readFileSync(file,'utf8').trim().split('\\n').map(JSON.parse).map(x=>x.event),['PREPARED']);
}));
test('old release without reviewed source labels cannot be planned as a rollback target',()=>sandbox((dir,envFile)=>{
  for(const change of [
    labels=>delete labels['org.opencontainers.image.revision'],
    labels=>labels['org.opencontainers.image.revision']='bad',
    labels=>labels['org.opencontainers.image.source']='https://other.example.org/EV',
    labels=>labels['com.creabiz.ev.staging.review']='false'
  ]) {
    const deps=mock(envFile),run=deps.run;
    deps.run=(cmd,args,options)=>{
      const result=run(cmd,args,options);
      if(args[0]==='image'&&args[2]===oldRef) {
        const items=JSON.parse(result);change(items[0].Config.Labels);
        return JSON.stringify(items);
      }
      return result;
    };
    assert.throws(()=>release.inspectPlan(release.parseArgs(argv('plan')),deps));
    assert.equal(deps.state.mutations,0);
  }
}));
test('old image source label change blocks apply before any Compose up',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),run=deps.run;
  let oldReads=0;
  deps.run=(cmd,args,options)=>{
    const result=run(cmd,args,options);
    if(args[0]==='image'&&args[2]===oldRef && ++oldReads>1){
      const items=JSON.parse(result);
      items[0].Config.Labels['org.opencontainers.image.revision']='d'.repeat(40);
      return JSON.stringify(items);
    }
    return result;
  };
  const journal=path.join(dir,'before-up.jsonl');
  assert.equal(release.main(argv('apply',journal),deps),2);
  assert.equal(deps.state.mutations,0);
  assert.deepEqual(fs.readFileSync(journal,'utf8').trim().split('\n').map(JSON.parse).map(x=>x.event),['PREPARED']);
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
  const publicDir=path.join(dir,'nonprivate');
  fs.mkdirSync(publicDir,{mode:0o755});
  fs.chmodSync(publicDir,0o755);
  assert.throws(()=>release.outputJournal(path.join(publicDir,'public-evidence.jsonl')));
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

test('concurrent or crashed release lock fails closed, without Docker mutation',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  const lock=release.acquireLock(deps);
  try {
    assert.equal(release.main(argv('apply',path.join(dir,'journal.jsonl')),deps),2);
    assert.equal(deps.state.mutations,0);
  } finally {lock();}
  assert.equal(fs.existsSync(deps.lockPath),false);
}));
test('staging lock removed after verified release or automatic rollback',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);deps.state.failCandidate=true;
  const file=path.join(dir,'journal.jsonl');
  assert.equal(release.main(argv('apply',file),deps),1);
  assert.equal(fs.existsSync(deps.lockPath),false);
}));

test('isolated payment/charger configuration rejection blocks Docker before staging changes',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  deps.preflight=()=>({ok:false,errors:['PAYMENT_PROVIDER: not mock']});
  assert.equal(release.main(argv('apply',path.join(dir,'blocked.jsonl')),deps),2);
  assert.equal(deps.state.commands.length,0);
  assert.equal(deps.state.mutations,0);
}));

test('resolved Compose isolation rejection stops staging before any Docker operation',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  deps.effectiveConfig=()=>false;
  assert.equal(release.main(argv('apply',path.join(dir,'effective-blocked.jsonl')),deps),2);
  assert.equal(deps.state.mutations,0);
  assert.equal(deps.state.commands.length,0);
}));

test('database replacement during staging rollout is detected and not counted as verified rollback',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  deps.state.replaceDatabase=true;
  const file=path.join(dir,'database-changed.jsonl');
  assert.equal(release.main(argv('apply',file),deps),3);
  assert.equal(deps.state.mutations,2);
  const events=fs.readFileSync(file,'utf8');
  assert.ok(events.includes('OPERATOR_RECONCILIATION_REQUIRED'));
  assert.ok(!events.includes('"event":"RELEASE_VERIFIED"'));
  assert.ok(!events.includes('"event":"ROLLBACK_VERIFIED"'));
}));
test('gateway replacement during staging rollout is detected as unverified recovery',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  deps.state.replaceGateway=true;
  const file=path.join(dir,'gateway-changed.jsonl');
  assert.equal(release.main(argv('apply',file),deps),3);
  assert.ok(fs.readFileSync(file,'utf8').includes('OPERATOR_RECONCILIATION_REQUIRED'));
}));

test('candidate staging application requires private HTTP route readiness before release is verified',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);let inspected=0;
  deps.checkHttp=()=>{inspected++;return deps.state.tag==='old';};
  const file=path.join(dir,'private-http-failed.jsonl');
  assert.equal(release.main(argv('apply',file),deps),1);
  assert.equal(deps.state.tag,'old');
  assert.equal(inspected,2);
  const events=fs.readFileSync(file,'utf8');
  assert.ok(events.includes('ROLLBACK_VERIFIED'));
  assert.ok(!events.includes('"event":"RELEASE_VERIFIED"'));
}));
test('gateway replaced during successful HTTPS smoke blocks release acceptance',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),file=path.join(dir,'gateway-during-http.jsonl');
  let probes=0;
  deps.checkHttp=()=>{
    probes++;
    if(probes===1)deps.state.replaceGateway=true;
    return true;
  };
  assert.equal(release.main(argv('apply',file),deps),3);
  // Gateway drift is detected before a second, now unsafe HTTPS probe.
  assert.equal(probes,1);
  assert.equal(deps.state.mutations,2);
  const history=fs.readFileSync(file,'utf8');
  assert.ok(history.includes('OPERATOR_RECONCILIATION_REQUIRED'));
  assert.ok(!history.includes('"event":"RELEASE_VERIFIED"'));
  assert.ok(!history.includes('"event":"ROLLBACK_VERIFIED"'));
}));
test('database changed during rollback HTTPS smoke blocks false recovery',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),file=path.join(dir,'database-during-rollback-http.jsonl');
  let probes=0;
  deps.checkHttp=()=>{
    if(++probes===1)return false;
    deps.state.replaceDatabase=true;
    return true;
  };
  assert.equal(release.main(argv('apply',file),deps),3);
  assert.equal(probes,2);
  const history=fs.readFileSync(file,'utf8');
  assert.ok(history.includes('OPERATOR_RECONCILIATION_REQUIRED'));
  assert.ok(!history.includes('"event":"ROLLBACK_VERIFIED"'));
}));
test('failed candidate and old-release HTTP smoke leave operator reconciliation',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);
  deps.checkHttp=()=>false;
  const file=path.join(dir,'both-http-failed.jsonl');
  assert.equal(release.main(argv('apply',file),deps),3);
  assert.ok(fs.readFileSync(file,'utf8').includes('OPERATOR_RECONCILIATION_REQUIRED'));
}));
test('no HTTP request is issued until candidate runtime and protected services pass',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile);deps.state.failCandidate=true;
  let called=0;
  deps.checkHttp=()=>{called++;return true;};
  assert.equal(release.main(argv('apply',path.join(dir,'unhealthy.jsonl')),deps),1);
  assert.equal(called,1);
}));

test('image tag re-pointed after plan creation is refused before any Compose mutation',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let seen=0;
  deps.run=(cmd,args,opt)=>{
    const response=original(cmd,args,opt);
    if(args[0]==='image'&&args[2]===newRef&&++seen===2){
      const item=JSON.parse(response);
      item[0].Id='sha256:'+'d'.repeat(64);
      return JSON.stringify(item);
    }
    return response;
  };
  const journal=path.join(dir,'candidate-retagged.jsonl');
  assert.equal(release.main(argv('apply',journal),deps),2);
  assert.equal(deps.state.mutations,0);
  const records=fs.readFileSync(journal,'utf8').trim().split('\n').map(JSON.parse);
  assert.deepEqual(records.map(x=>x.event),['PREPARED']);
}));
test('candidate source label retarget after plan prevents stage update',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let seen=0;
  deps.run=(cmd,args,opt)=>{
    const response=original(cmd,args,opt);
    if(args[0]==='image'&&args[2]===newRef&&++seen===2){
      const items=JSON.parse(response);
      items[0].Config.Labels['org.opencontainers.image.revision']='d'.repeat(40);
      return JSON.stringify(items);
    }
    return response;
  };
  assert.equal(release.main(argv('apply',path.join(dir,'source-replaced.jsonl')),deps),2);
  assert.equal(deps.state.mutations,0);
}));
test('candidate tag replaced after startup triggers verified rollback rather than release acceptance',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let seen=0;
  deps.run=(cmd,args,opt)=>{
    const response=original(cmd,args,opt);
    if(args[0]==='image'&&args[2]===newRef&&++seen>=3){
      const items=JSON.parse(response);
      items[0].Id='sha256:'+'d'.repeat(64);
      return JSON.stringify(items);
    }
    return response;
  };
  const journal=path.join(dir,'retagged-after-startup.jsonl');
  assert.equal(release.main(argv('apply',journal),deps),1);
  assert.equal(deps.state.tag,'old');
  assert.equal(deps.state.mutations,2);
  const text=fs.readFileSync(journal,'utf8');
  assert.ok(text.includes('ROLLBACK_VERIFIED'));
  assert.ok(!text.includes('"event":"RELEASE_VERIFIED"'));
}));
test('previous image tag replaced after rollback HTTP verification blocks false recovery',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let oldChecks=0;
  deps.state.failCandidate=true;
  deps.run=(cmd,args,opt)=>{
    const response=original(cmd,args,opt);
    if(args[0]==='image'&&args[2]===oldRef&&++oldChecks>=3){
      const items=JSON.parse(response);
      items[0].Id='sha256:'+'e'.repeat(64);
      return JSON.stringify(items);
    }
    return response;
  };
  const journal=path.join(dir,'retagged-old-after-rollback.jsonl');
  assert.equal(release.main(argv('apply',journal),deps),3);
  assert.ok(fs.readFileSync(journal,'utf8').includes('OPERATOR_RECONCILIATION_REQUIRED'));
}));

test('former rollback image retarget between plan and application mutation blocks deployment',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let seen=0;
  deps.run=(cmd,args,opts)=>{
    const response=original(cmd,args,opts);
    if(args[0]==='image' && args[2]===oldRef && ++seen===2){
      const images=JSON.parse(response);
      images[0].Id='sha256:'+'d'.repeat(64);
      return JSON.stringify(images);
    }
    return response;
  };
  const file=path.join(dir,'former-image-retargeted.jsonl');
  assert.equal(release.main(argv('apply',file),deps),2);
  assert.equal(deps.state.mutations,0);
  const events=fs.readFileSync(file,'utf8').trim().split('\n').map(JSON.parse);
  assert.deepEqual(events.map(x=>x.event),['PREPARED']);
}));
test('former rollback tag replaced after successful candidate startup prevents false recovery guarantee',()=>sandbox((dir,envFile)=>{
  const deps=mock(envFile),original=deps.run;
  let seen=0;
  deps.run=(cmd,args,opts)=>{
    const response=original(cmd,args,opts);
    if(args[0]==='image' && args[2]===oldRef && ++seen>=3){
      const items=JSON.parse(response);
      items[0].Id='sha256:'+'d'.repeat(64);
      return JSON.stringify(items);
    }
    return response;
  };
  const file=path.join(dir,'old-tag-retarget-after-startup.jsonl');
  assert.equal(release.main(argv('apply',file),deps),3);
  assert.ok(fs.readFileSync(file,'utf8').includes('OPERATOR_RECONCILIATION_REQUIRED'));
  assert.ok(!fs.readFileSync(file,'utf8').includes('"event":"RELEASE_VERIFIED"'));
}));
