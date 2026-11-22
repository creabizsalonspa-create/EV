'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {groups,prepareOperationalAdapters,spawnOperationalSuite,
  assertOperationalSuiteExit,OPERATIONAL_SUITE_TIMEOUT_MS,OPERATIONAL_PREP_TIMEOUT_MS
}=require('./operational-connected.cjs');

test('all original operational suites remain allowlisted, isolated and deadline bounded',()=>{
  const list=groups.flat();
  assert.ok(list.length>30,'Original operational acceptance suite coverage unexpectedly shrank');
  assert.equal(new Set(list).size,list.length,'No duplicate operational suite execution');
  for(const suite of list){
    let calls=0;
    const result=spawnOperationalSuite(suite,(binary,args,options)=>{
      calls++;
      assert.equal(binary,process.execPath);
      assert.deepEqual(args,['tests/'+suite+'.cjs']);
      assert.equal(options.timeout,OPERATIONAL_SUITE_TIMEOUT_MS);
      assert.equal(options.killSignal,'SIGTERM');
      assert.equal(options.maxBuffer,4*1024*1024);
      assert.equal(options.encoding,'utf8');
      assert.equal(options.env.EV_FAKE_TEST_FLAG,'synthetic');
      assert.equal(options.env.EV_CANONICAL_TARGETS,suite==='charging-business'?'1':'caller-value');
      if(suite==='charging-business'){
        for(const key of ['EV_CSMS_CONSUMER','EV_CSMS_DAEMON','EV_CSMS_AUTO_DISPATCH'])
          assert.equal(options.env[key],'1');
      }
      return {status:0,stdout:'synthetic',stderr:''};
    },{EV_FAKE_TEST_FLAG:'synthetic',EV_CANONICAL_TARGETS:'caller-value'});
    assert.equal(result.status,0);
    assert.equal(calls,1,'No automatic retry after a suite completes');
  }
  assert.equal(OPERATIONAL_SUITE_TIMEOUT_MS,720000);
});
test('unknown or unreviewed suite names cannot be executed as a shell/process path',()=>{
  let called=false;
  const fake=()=>{called=true;return {status:0};};
  for(const name of ['../../etc/passwd','commercial;echo secret','','OPERATIONS','client-demo-lifecycle/other'])
    assert.throws(()=>spawnOperationalSuite(name,fake),/Unknown operational suite/);
  assert.equal(called,false);
});
test('operational setup is explicitly deadline bounded and preserves exact command order',()=>{
  const seen=[];
  prepareOperationalAdapters((path,args,options)=>{
    seen.push(path);
    assert.deepEqual(args,[]);
    assert.equal(options.encoding,'utf8');
    assert.equal(options.timeout,OPERATIONAL_PREP_TIMEOUT_MS);
    assert.equal(options.killSignal,'SIGTERM');
    return {status:0};
  });
  assert.deepEqual(seen,['./scripts/configuration-read-setup','./scripts/diagnostic-logs-setup','./scripts/reset-operations-setup','./scripts/availability-command-setup']);
  assert.equal(OPERATIONAL_PREP_TIMEOUT_MS,120000);
});
test('timed-out or failed preparation fails closed; no follow-on setup or test runs',()=>{
  let calls=0;
  assert.throws(()=>prepareOperationalAdapters(()=>{calls++;return {error:{code:'ETIMEDOUT'}};}),/deadline exceeded/);
  assert.equal(calls,1);
  calls=0;
  assert.throws(()=>prepareOperationalAdapters(()=>{calls++;return {status:3};}),/Local operational preparation failed/);
  assert.equal(calls,1);
});

test('operational suite exit validation rejects timeouts and nonzero status without retry',()=>{
  assert.throws(()=>assertOperationalSuiteExit({error:{code:'ETIMEDOUT'}},'charging-business'),
    /exceeded bounded synthetic CI budget/);
  assert.throws(()=>assertOperationalSuiteExit({status:1},'client-demo-lifecycle'),
    /Operational suite failed; no assertion retry/);
  assert.doesNotThrow(()=>assertOperationalSuiteExit({status:0},'privacy-e2e'));
});
