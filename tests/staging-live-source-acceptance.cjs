'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const gate=require('../scripts/staging-live-source-acceptance.cjs');
const {checks:routes}=require('../scripts/staging-health.cjs');

const SHA='e'.repeat(40);
const IMAGE='sha256:'+'a'.repeat(64);
const HOST='review.ev-example.org';
const url='https://'+HOST+':8443/';
const ids=()=>Object.fromEntries(gate.SERVICES.map((service,i)=>[service,String(i+1).repeat(64)]));
const proof=()=>({ok:true,source_sha:SHA,image_id:IMAGE,runtime_services:ids()});
const httpPass=()=>({ok:true,results:routes.map(x=>({path:x.path,status:x.kind==='private'?401:200,ok:true}))});
const argv=(output)=>['--source-sha',SHA,'--url',url,'--output',output];
function fixture(extra={}){
  return {getHead:()=>SHA,inspectEnv:()=>({host:HOST,checker:()=>[]}),
    inspectEffective:()=>true,inspectImages:proof,checkHttp:async()=>httpPass(),
    now:()=> '2026-11-08T03:30:00.000Z',...extra};
}
async function privatePath(callback){
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-live-source-'));
  fs.chmodSync(dir,0o700);
  try{return await callback(path.join(dir,'readiness.json'),dir);}
  finally{fs.rmSync(dir,{force:true,recursive:true});}
}
test('acceptance only permits explicit private TLS port, exact SHA and owner-only new output',()=>{
  const c=gate.parseArgs(argv('/tmp/not-written.json'));
  assert.equal(c.health.tlsLoopback,true);
  assert.equal(c.health.url.port,'8443');
  for(const unsafe of ['https://'+HOST+':443/','http://127.0.0.1:8443/',
    'https://other.example.org/','https://127.0.0.1:8443/',
    'https://'+HOST+':8443/api/me','https://user:pass@'+HOST+':8443/',
    'https://'+HOST+':8443/?token=secret']){
    assert.throws(()=>gate.parseArgs(['--source-sha',SHA,'--url',unsafe,'--output','file.json']));
  }
  assert.throws(()=>gate.parseArgs([...argv('file.json'),'--execute']));
  assert.throws(()=>gate.parseArgs(['--source-sha','bad','--url',url,'--output','file.json']));
});
test('complete private source and HTTPS evidence retains non-production truth labels',async()=>{
  const result=await gate.inspect(gate.parseArgs(argv('readiness.json')),fixture());
  assert.equal(result.status,'PRIVATE_SOURCE_LINKED_STAGE_READY');
  assert.equal(result.schema,gate.SCHEMA);
  assert.ok(result.runtime_fingerprint_sha256?.match(/^[a-f0-9]{64}$/));
  assert.ok(Object.values(result.checks).every(x=>x===true));
  assert.equal(result.http_routes.length,7);
  assert.ok(result.http_routes.every(x=>x.passed===true));
  for(const field of ['independent_build_attestation','synthetic_accounts_authenticated',
    'simulator_session_completed','live_charger_verified','live_ocpi_verified',
    'real_money_moved','production_accepted','customer_contract_accepted']){
    assert.equal(result[field],false,field);
  }
});
test('wrong source checkout stops before any Docker or HTTPS inspection',async()=>{
  let attempts=0;
  const result=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
    getHead:()=> 'f'.repeat(40),inspectEnv:()=>{attempts++;throw Error('secret');},
    inspectImages:()=>{attempts++;throw Error('secret');},
    checkHttp:async()=>{attempts++;throw Error('secret');}
  }));
  assert.equal(result.status,'INCOMPLETE');
  assert.equal(attempts,0);
  assert.equal(result.checks.clean_checkout,false);
});
test('missing canonical staging preflight and effective-config failure block all HTTP traffic',async()=>{
  for(const changed of [
    {inspectEnv:()=>{throw Error('unsafe private credential');}},
    {inspectEnv:()=>({host:'unreviewed.example.org',checker:()=>[]})},
    {inspectEffective:()=>false},
  ]){
    let requested=0;
    const result=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
      ...changed,inspectImages:()=>{requested++;return proof();},
      checkHttp:async()=>{requested++;return httpPass();}
    }));
    assert.equal(result.status,'INCOMPLETE');
    assert.equal(requested,0);
    assert.equal(JSON.stringify(result).includes('unsafe private credential'),false);
  }
});
test('unreviewed or incomplete running-source proof blocks HTTPS without secrets',async()=>{
  for(const mutate of [
    p=>p.source_sha='a'.repeat(40),
    p=>p.image_id='creabiz-ev-business:review',
    p=>delete p.runtime_services.gateway,
    p=>p.runtime_services.gateway=p.runtime_services.database,
    p=>p.runtime_services.application='not-an-id',
    p=>p.ok=false,
  ]){
    let httpCalls=0;
    const result=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
      inspectImages:()=>{const p=proof();mutate(p);return p;},
      checkHttp:async()=>{httpCalls++;return httpPass();}
    }));
    assert.equal(result.status,'INCOMPLETE');
    assert.equal(httpCalls,0);
  }
});
test('HTTP partial checks, arbitrary success or private information leakage cannot pass',async()=>{
  for(const mutate of [
    r=>r.results.pop(),
    r=>r.results[0].status=503,
    r=>r.results[4].status=200,
    r=>r.results[3].path='/api/me',
    r=>r.ok=false,
    r=>r.results[5].ok=false,
  ]){
    const failed=httpPass();mutate(failed);
    failed.results.push({body:'TOP_SECRET'});
    const result=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
      checkHttp:async()=>failed
    }));
    assert.equal(result.status,'INCOMPLETE');
    assert.equal(JSON.stringify(result).includes('TOP_SECRET'),false);
    assert.equal(result.runtime_fingerprint_sha256,null);
  }
});
test('staging app container replaced during HTTPS probe blocks final acceptance',async()=>{
  let inspections=0;
  const result=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
    inspectImages:()=>{
      const p=proof();
      if(++inspections===2)p.runtime_services.application='9'.repeat(64);
      return p;
    }
  }));
  assert.equal(inspections,2);
  assert.equal(result.status,'INCOMPLETE');
  assert.equal(result.checks.private_https_routes,true);
  assert.equal(result.checks.stable_runtime_after_http,false);
});
test('protected gateway and database changes during HTTPS probe block acceptance',async()=>{
  for(const service of ['gateway','database']){
    let count=0;
    const result=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
      inspectImages:()=>{
        const p=proof();if(++count===2)p.runtime_services[service]='9'.repeat(64);
        return p;
      }
    }));
    assert.equal(result.status,'INCOMPLETE');
    assert.equal(count,2);
  }
});
test('resolved Compose change or dirty checkout after probe blocks acceptance',async()=>{
  let checks=0;
  const changed=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
    inspectEffective:()=> ++checks===1
  }));
  assert.equal(changed.status,'INCOMPLETE');
  assert.equal(checks,2);
  let reads=0;
  const dirty=await gate.inspect(gate.parseArgs(argv('report.json')),fixture({
    getHead:()=> ++reads===1?SHA:'f'.repeat(40)
  }));
  assert.equal(dirty.status,'INCOMPLETE');
  assert.equal(reads,2);
});
test('private output is exclusive, redacted, source-bound and cannot be overwritten',async()=>privatePath(async file=>{
  const args=argv(file);
  assert.equal(await gate.main(args,fixture()),0);
  const data=JSON.parse(fs.readFileSync(file,'utf8'));
  assert.equal(data.status,'PRIVATE_SOURCE_LINKED_STAGE_READY');
  assert.equal(data.source_sha,SHA);
  assert.equal(fs.statSync(file).mode&0o077,0);
  assert.equal(await gate.main(args,fixture()),2);
  assert.equal(JSON.parse(fs.readFileSync(file,'utf8')).status,'PRIVATE_SOURCE_LINKED_STAGE_READY');
}));
test('failed inspections still produce private INCOMPLETE evidence without raw secrets',async()=>privatePath(async file=>{
  assert.equal(await gate.main(argv(file),fixture({
    inspectEffective:()=>{throw Error('SECRET_COMPOSE_PASSWORD=unsafe');}
  })),1);
  const payload=fs.readFileSync(file,'utf8');
  assert.ok(payload.includes('"INCOMPLETE"'));
  assert.ok(!payload.includes('SECRET_COMPOSE_PASSWORD'));
  assert.equal(fs.statSync(file).mode&0o077,0);
}));
test('public evidence directory, symlink directory and existing output reject before inspection',async()=>privatePath(async(file,dir)=>{
  const publicDir=path.join(dir,'public');
  fs.mkdirSync(publicDir,{mode:0o755});
  fs.chmodSync(publicDir,0o755);
  let inspections=0;
  const deps=fixture({getHead:()=>{inspections++;return SHA;}});
  assert.equal(await gate.main(argv(path.join(publicDir,'a.json')),deps),2);
  const link=path.join(dir,'alias');
  fs.symlinkSync(publicDir,link);
  assert.equal(await gate.main(argv(path.join(link,'a.json')),deps),2);
  assert.equal(inspections,0);
}));
test('the staging source-labeled runtime inventory is measured by five exact distinct IDs',()=>{
  assert.equal(gate.checkProvenance(proof(),SHA).image_id,IMAGE);
  for(const invalid of [
    {...proof(),runtime_services:{application:'1'.repeat(64)}},
    {...proof(),source_sha:'0'.repeat(40)},
    {...proof(),runtime_services:{...ids(),gateway:'1'.repeat(64)}},
  ])assert.throws(()=>gate.checkProvenance(invalid,SHA));
});
