'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { parseSnapshot, checkSnapshot, parseArgs, localDockerSnapshot, readSnapshotFile } = require('../scripts/staging-runtime-gate.cjs');

const SERV = ['application', 'worker', 'scheduler', 'database', 'gateway'];
function sample() {
  return SERV.map(service => ({
    Project: 'ev-staging', Service: service, State: 'running',
    Health: ['application', 'database'].includes(service) ? 'healthy' : '',
    Image: ['application', 'worker', 'scheduler'].includes(service) ? 'creabiz-ev-business:review' :
      service === 'database' ? 'postgres:17-alpine@sha256:' + 'a'.repeat(64) : 'caddy:2-alpine',
    Publishers: service === 'gateway' ? [{ URL: '127.0.0.1', TargetPort: 443, PublishedPort: 8443, Protocol: 'tcp' }] : []
  }));
}
function clone(x) { return JSON.parse(JSON.stringify(x)); }
function checkChange(change, expected) {
  const data = sample(); change(data);
  const results = checkSnapshot(data);
  assert.equal(results.ok, false);
  assert.ok(results.problems.some(x => x.includes(expected)), JSON.stringify(results.problems));
}
test('healthy five-service staging passes', () => assert.deepEqual(checkSnapshot(sample()), {ok:true,problems:[],inspected_services:5}));
test('Docker Compose JSON array parsed', () => assert.equal(parseSnapshot(JSON.stringify(sample())).length, 5));
test('Docker Compose NDJSON parsed', () => assert.equal(parseSnapshot(sample().map(x => JSON.stringify(x)).join('\n')).length, 5));
test('duplicate and unknown service reject', () => {
  checkChange(x => x.push({...clone(x[0])}), 'multiple');
  checkChange(x => x[0].Service = 'rogue', 'Unexpected service');
});
test('project mismatch rejected', () => checkChange(x => x[0].Project='production', 'unexpected Docker Compose project'));
test('application and database must be healthy', () => {
  checkChange(x => x[0].Health='starting', 'healthy');
  checkChange(x => x[3].Health='unhealthy', 'healthy');
});
test('worker/scheduler/gateway not running rejected', () => {
  checkChange(x => x[1].State='exited', 'not running');
  checkChange(x => x[2].State='restarting', 'not running');
  checkChange(x => x[4].State='exited', 'not running');
});
test('all private services must not publish host ports', () => {
  for (let i = 0; i < 4; i++) checkChange(x => x[i].Publishers=[{URL:'0.0.0.0',PublishedPort:5432,TargetPort:5432,Protocol:'tcp'}], 'private service');
});
test('gateway bind and port strictly loopback 8443 to 443', () => {
  checkChange(x => x[4].Publishers[0].URL='0.0.0.0', 'loopback-only');
  checkChange(x => x[4].Publishers[0].URL='::', 'loopback-only');
  checkChange(x => x[4].Publishers[0].PublishedPort=443, 'loopback-only');
  checkChange(x => x[4].Publishers=[], 'loopback-only');
});
test('service application images must match exactly', () => {
  checkChange(x => x[1].Image='creabiz-ev-business:old', 'image differs');
  checkChange(x => x[2].Image='creabiz-ev-business:old', 'image differs');
});
test('unknown database or gateway images reject', () => {
  checkChange(x => x[3].Image='postgres:latest', 'PostgreSQL image');
  checkChange(x => x[4].Image='nginx:latest', 'reverse-proxy image');
});
test('missing service rejected', () => checkChange(x => x.pop(), 'missing'));
test('invalid JSON and excessive snapshots reject', () => {
  assert.throws(()=>parseSnapshot('garbage'));
  assert.throws(()=>parseSnapshot('x'.repeat(1024*1024+1)));
});
test('read snapshot file rejects symlink', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(),'ev-runtime-'));
  try {
    const target=path.join(dir,'source'); fs.writeFileSync(target, JSON.stringify(sample()));
    const link=path.join(dir,'link'); fs.symlinkSync(target,link);
    assert.throws(()=>readSnapshotFile(link));
    assert.ok(readSnapshotFile(target).startsWith('['));
  } finally { fs.rmSync(dir,{recursive:true,force:true}); }
});
test('live mode refuses remote Docker endpoint environment variables', () => {
  assert.throws(()=>localDockerSnapshot({DOCKER_HOST:'tcp://server:2375'},()=>{ throw Error('MUST NOT CALL'); }));
  assert.throws(()=>localDockerSnapshot({DOCKER_CONTEXT:'production'},()=>{ throw Error('MUST NOT CALL'); }));
});
test('live mode refuses remote default context, permits only read-only command', () => {
  let calls=[];
  assert.throws(()=>localDockerSnapshot({},(_cmd,args)=>{
    calls.push(args); return 'tcp://other.host:2375\n';
  }));
  assert.equal(calls.length,1);
  calls=[];
  const text = localDockerSnapshot({},(_cmd,args)=>{
    calls.push(args);
    return calls.length===1 ? 'unix:///var/run/docker.sock\n' : sample().map(x=>JSON.stringify(x)).join('\n');
  });
  assert.equal(parseSnapshot(text).length,5);
  assert.equal(calls.length,2);
  assert.deepEqual(calls[1].slice(-4), ['ps','--all','--format','json']);
});
test('CLI accepts only explicit live or snapshot', () => {
  assert.deepEqual(parseArgs(['--live']),{mode:'live'});
  assert.deepEqual(parseArgs(['--snapshot','/private/snapshot.json']),{mode:'snapshot',file:'/private/snapshot.json'});
  for (const args of [[],['--execute'],['--live','--delete'],['--snapshot','--live']]) assert.throws(()=>parseArgs(args));
});
