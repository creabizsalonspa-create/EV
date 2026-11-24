'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
const window={};vm.runInNewContext(fs.readFileSync('services/business-api/public/connector-freshness.js','utf8'),{window});
const state=window.EvConnectorFreshness.state;
const value={state:'recent',reported_status:'Available',evidence:'SIMULATED_SOURCE',physical_availability_verified:false,max_age_seconds:300,observed_at:'2026-11-14T05:00:00Z',checked_at:'2026-11-14T05:04:00Z',valid_until:'2026-11-14T05:05:00Z'};
test('connector status expires on screen using elapsed time, independent of client clock',()=>{assert.equal(state(value,59999),'Available');assert.equal(state(value,60000),'stale');});
test('malformed, unsupported, physical and future claims fail closed',()=>{
 for(const update of [{physical_availability_verified:true},{evidence:'LIVE'},{max_age_seconds:301},{observed_at:'2026-11-14T05:05:00Z'},{valid_until:'garbage'},{reported_status:'anything'},{state:'unknown'}])assert.equal(state({...value,...update}),'unknown');
 assert.equal(state(null),'unknown');assert.equal(state(value,-1),'unknown');assert.equal(state(value,NaN),'unknown');
});

test('actual display expires in both languages and disposes its timer when removed',()=>{
 for (const lang of ['en','ar']) {
  let elapsed=0,tick,cancelled=false;const element={dataset:{},isConnected:true};
  const context={window:{},document:{documentElement:{lang},createElement:()=>element},performance:{now:()=>elapsed},setInterval:fn=>{tick=fn;return 1;},clearInterval:id=>{assert.equal(id,1);cancelled=true;}};
  vm.runInNewContext(fs.readFileSync('services/business-api/public/connector-freshness.js','utf8'),context);
  context.window.EvConnectorFreshness.attach({append:p=>assert.equal(p,element)},value);
  assert.match(element.textContent,lang==='ar'?/متاح بحسب المصدر/:/Available/);
  elapsed=60000;tick();assert.match(element.textContent,lang==='ar'?/قديم/:/stale/);
  assert.match(element.textContent,lang==='ar'?/التوفر الفعلي غير مؤكد/:/Physical availability is unverified/);
  element.isConnected=false;tick();assert.equal(cancelled,true);
  elapsed=0; element.isConnected=true;
  context.window.EvConnectorFreshness.attach({append:()=>{}},value,60000);
  assert.match(element.textContent,lang==='ar'?/قديم/:/stale/);
 }
});


test('connector clocks reject impossible calendar dates rather than silently rolling them over',()=>{
 for(const field of ['observed_at','checked_at','valid_until']){
  for(const invalid of [
   '2026-02-30T05:00:00Z',
   '2026-04-31T05:00:00Z',
   '2027-02-29T05:00:00Z',
   '2026-11-14T24:00:00Z',
   '2026-11-14T05:61:00Z',
   '2026-11-14T05:00:60Z',
  ]){
   assert.equal(state({...value,[field]:invalid}), 'unknown',field+': '+invalid);
  }
 }
 const leap={...value, observed_at:'2028-02-29T05:00:00Z',
   checked_at:'2028-02-29T05:04:00Z',valid_until:'2028-02-29T05:05:00Z'};
 assert.equal(state(leap,59999),'Available');
 assert.equal(state(leap,60000),'stale');
});
test('malformed or untrusted observed clock cannot appear in EN/AR label',()=>{
 for(const lang of ['en','ar']){
  let tick;
  const element={dataset:{},isConnected:true};
  const window={},document={documentElement:{lang},createElement:()=>element};
  const perf={now:()=>0};
  const env={window,document,performance:perf,setInterval:fn=>{tick=fn;return 1;},
    clearInterval:()=>{}};
  vm.runInNewContext(fs.readFileSync('services/business-api/public/connector-freshness.js','utf8'),env);
  window.EvConnectorFreshness.attach({append:()=>{}},{...value,observed_at:'2026-02-30T05:00:00Z'});
  assert.doesNotMatch(element.textContent,/2026-02-30/);
  assert.match(element.textContent,lang==='ar'?/غير معروف/:/unknown/);
  window.EvConnectorFreshness.attach({append:()=>{}},{...value,evidence:'LIVE',
    observed_at:'2026-11-14T05:00:00Z'});
  assert.doesNotMatch(element.textContent,/2026-11-14/);
  assert.match(element.textContent,lang==='ar'?/غير معروف/:/unknown/);
  element.isConnected=false;tick();
 }
});
