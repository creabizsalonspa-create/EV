'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const { parseArgs, inspect, writeEvidence, main, evidenceSchema, getCurrentHead } = require('../scripts/staging-acceptance.cjs');

const sourceSHA = 'c'.repeat(40);
const services = ['application','worker','scheduler','database','gateway'];
function snapshot() {
  return services.map(service => ({Project:'ev-staging',Service:service,State:'running',
    Health: ['application','database'].includes(service) ? 'healthy' : '',
    Image: ['application','worker','scheduler'].includes(service) ? 'creabiz-ev-business:review' :
      service === 'database' ? 'postgres:17-alpine' : 'caddy:2-alpine',
    Publishers: service === 'gateway' ? [{URL:'127.0.0.1',TargetPort:443,PublishedPort:8443,Protocol:'tcp'}] : []}));
}
function args(output) {return ['--runtime-snapshot','/unused/snapshot','--url','http://127.0.0.1:8765/',
  '--demo-manifest','/unused/access.json','--source-sha',sourceSHA,'--output',output];}
function fixtureDeps(overrides={}) {
  return {getHead:()=>sourceSHA,readSnapshot:()=>JSON.stringify(snapshot()),inspectDemo:()=>[],
    runHttp:async()=>({ok:true,results:['/up','/','/api/stations','/api/v1/stations','/api/me',
      '/api/v1/me','/api/admin/overview'].map(path=>({path,ok:true}))}),
    now:()=> '2026-11-07T13:00:00.000Z',...overrides};
}
function privateDestination(cb) {
  const folder = fs.mkdtempSync(path.join(os.tmpdir(),'ev-acceptance-'));
  fs.chmodSync(folder,0o700);
  try {return cb(path.join(folder,'read-only-proof.json'));}
  finally {fs.rmSync(folder,{force:true,recursive:true});}
}
test('explicit checkpoint and loopback arguments parsed',()=>privateDestination(file=>{
  const v=parseArgs(args(file)); assert.equal(v.source_sha,sourceSHA);
  assert.equal(v.health.url.hostname,'127.0.0.1');
  assert.equal(v.allowLocalQa,false);
}));
test('remote URLs, embedded credentials and duplicate keys fail closed',()=>privateDestination(file=>{
  for (const unsafe of ['https://evil.example.org:8080/','http://user:secret@127.0.0.1:8765/',
    'http://127.0.0.1:8765/secret','http://127.0.0.1:8765/?token=abc']) {
    const argv=args(file);argv[argv.indexOf('--url')+1]=unsafe;
    assert.throws(()=>parseArgs(argv));
  }
  assert.throws(()=>parseArgs([...args(file),'--source-sha',sourceSHA]));
  assert.throws(()=>parseArgs([...args(file),'--execute']));
}));
test('healthy read-only staging prerequisites produce safe limited verdict',async()=>{
  const data=await inspect(parseArgs(args('/unused/proof.json')),fixtureDeps());
  assert.equal(data.schema,evidenceSchema);
  assert.equal(data.status,'PRIVATE_PREREQUISITES_PASSED');
  assert.equal(data.prerequisites.source_checkout,'passed');
  assert.equal(data.prerequisites.synthetic_demo,'passed');
  assert.equal(data.prerequisites.docker_snapshot,'passed');
  assert.equal(data.prerequisites.http_routes,'passed');
  assert.equal(data.http_checks.length,7);
  for(const key of ['production_accepted','release_authorized','live_charger_verified','payment_processor_verified']) assert.equal(data[key],false);
});
test('mismatched checkout SHA stops before reading snapshot or contacting server',async()=>{
  let stages=0;const deps=fixtureDeps({getHead:()=> 'a'.repeat(40),readSnapshot:()=>{stages++;throw Error('MUST NOT READ');},runHttp:async()=>{stages++;throw Error('MUST NOT NETWORK');}});
  const proof=await inspect(parseArgs(args('/unused/proof.json')),deps);
  assert.equal(proof.status,'INCOMPLETE');assert.equal(stages,0);
  assert.equal(proof.prerequisites.source_checkout,'not_checked');
});
test('unexpected publicly bound DB fails before demo/network',async()=>{
  const rows=snapshot();rows[3].Publishers=[{URL:'0.0.0.0',TargetPort:5432,PublishedPort:5432,Protocol:'tcp'}];
  let demoCalled=false;
  const proof=await inspect(parseArgs(args('/unused/proof.json')),fixtureDeps({readSnapshot:()=>JSON.stringify(rows),inspectDemo:()=>{demoCalled=true;return [];}}));
  assert.equal(proof.status,'INCOMPLETE');assert.equal(demoCalled,false);
  assert.equal(proof.prerequisites.source_checkout,'passed');
  assert.equal(proof.prerequisites.docker_snapshot,'not_checked');
});
test('rejected private fixture stops before HTTP transport',async()=>{
  let calls=0;
  const proof=await inspect(parseArgs(args('/unused/proof.json')),fixtureDeps({inspectDemo:()=>['Secret leak'],runHttp:async()=>{calls++;throw Error('Do not call');}}));
  assert.equal(calls,0);assert.equal(proof.status,'INCOMPLETE');
  assert.equal(proof.prerequisites.docker_snapshot,'passed');
  assert.equal(proof.prerequisites.synthetic_demo,'not_checked');
});
test('failed HTTP readiness evidence contains route only, not private bodies',async()=>{
  const proof=await inspect(parseArgs(args('/unused/proof.json')),fixtureDeps({runHttp:async()=>({ok:false,results:[{path:'/api/me',ok:false,secret:'TOP_SECRET',body:'TOP_SECRET'}]})}));
  assert.equal(proof.status,'INCOMPLETE');
  assert.equal(JSON.stringify(proof).includes('TOP_SECRET'),false);
  assert.equal(proof.http_checks.length,7);
  assert.ok(proof.http_checks.some(x=>x.route==='/api/me' && x.result==='failed'));
});
test('output is created privately once and original cannot be overwritten',()=>privateDestination(file=>{
  writeEvidence(file,{schema:evidenceSchema,status:'INCOMPLETE'});
  assert.equal((fs.statSync(file).mode & 0o077),0);
  assert.throws(()=>writeEvidence(file,{schema:'overwritten'}));
  assert.equal(JSON.parse(fs.readFileSync(file)).schema,evidenceSchema);
}));
test('group-readable evidence directory is rejected', {skip:process.platform==='win32'},()=>privateDestination(file=>{
  fs.chmodSync(path.dirname(file),0o755);
  assert.throws(()=>writeEvidence(file,{}));
}));
test('evidence symlink directory rejected',()=>privateDestination(file=>{
  const alias=path.join(path.dirname(file),'alias');fs.symlinkSync(path.dirname(file),alias);
  assert.throws(()=>writeEvidence(path.join(alias,'proof.json'),{}));
}));
test('CLI end to end creates safe immutable PASS evidence',async()=>{
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-acceptance-cli-'));fs.chmodSync(dir,0o700);
  try {
    const file=path.join(dir,'proof.json');
    assert.equal(await main(args(file),fixtureDeps()),0);
    const proof=JSON.parse(fs.readFileSync(file));
    assert.equal(proof.status,'PRIVATE_PREREQUISITES_PASSED');
    assert.equal(await main(args(file),fixtureDeps()),2);
  }finally{fs.rmSync(dir,{force:true,recursive:true});}
});
test('CLI writes FAILED evidence only after source and output validations',async()=>{
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-acceptance-failed-'));fs.chmodSync(dir,0o700);
  try {
    const file=path.join(dir,'proof.json');
    const result=await main(args(file),fixtureDeps({inspectDemo:()=>['Account secret not synthetic']}));
    assert.equal(result,1);
    const proof=JSON.parse(fs.readFileSync(file));
    assert.equal(proof.status,'INCOMPLETE');
    assert.equal(JSON.stringify(proof).includes('Account secret not synthetic'),false);
  }finally{fs.rmSync(dir,{force:true,recursive:true});}
});
test('actual loopback HTTP pass through existing seven-route gate',async()=>{
  const server=http.createServer((req,res)=>{
    if (['/api/me','/api/v1/me','/api/admin/overview'].includes(req.url)) {res.writeHead(401);return res.end('no');}
    if(req.url==='/up'){res.writeHead(200);return res.end('up');}
    if(req.url==='/'){res.writeHead(200,{'content-type':'text/html','x-content-type-options':'nosniff','content-security-policy':"default-src 'self'"});return res.end('<html></html>');}
    res.writeHead(200,{'content-type':'application/json'});res.end(JSON.stringify({data:[],total:0}));
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  try {
    const port=server.address().port;
    const config=parseArgs([...args('/unused/proof.json').slice(0,2),'--url','http://127.0.0.1:' + port + '/',...args('/unused/proof.json').slice(4)]);
    const result=await inspect(config,fixtureDeps({runHttp:undefined})); // use real HTTP
    assert.equal(result.status,'PRIVATE_PREREQUISITES_PASSED');
  }finally{await new Promise(resolve=>server.close(resolve));}
});
test('running image is not confused with exact build provenance',async()=>{
  const proof=await inspect(parseArgs(args('/unused/proof.json')),fixtureDeps());
  assert.equal(proof.running_image_matches_source_verified,false);
});
test('source head guard rejects dirty tracked worktree',()=>{
  const calls=[];
  assert.throws(()=>getCurrentHead((_cmd,args)=>{calls.push(args);return calls.length===1?sourceSHA:' M scripts/staging-acceptance.cjs';}));
  assert.equal(calls.length,2);
  assert.equal(getCurrentHead((_cmd,args)=>args[0]==='rev-parse'?sourceSHA:''),sourceSHA);
});
test('noncanonical repeated route results cannot produce fake HTTP acceptance',async()=>{
  const proof=await inspect(parseArgs(args('/unused/proof.json')),fixtureDeps({runHttp:async()=>({ok:true,
    results:Array.from({length:7},()=>({path:'/up',ok:true,body:'secret'}))})}));
  assert.equal(proof.status,'INCOMPLETE');
  assert.ok(proof.http_checks.some(x=>x.result==='failed'));
  assert.equal(JSON.stringify(proof).includes('"body"'),false);
});
