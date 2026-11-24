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

test('unresolved evidence stays unknown after expiry and malformed UTC never becomes recent', () => {
 for (const update of [
  {state: 'unknown'},
  {reported_status: null},
  {observed_at: '2026-11-31T05:00:00Z', checked_at: '2026-12-01T05:04:00Z', valid_until: '2026-12-01T05:05:00Z'},
  {observed_at: '2026-11-14T05:04:00.000002Z', checked_at: '2026-11-14T05:04:00.000001Z', valid_until: '2026-11-14T05:09:00.000002Z'},
 ]) assert.equal(state({...value, ...update}, 60000), 'unknown');
 assert.equal(state({...value, state: 'stale'}), 'unknown');
 assert.equal(state({...value, state: 'stale', reported_status: null, checked_at: value.valid_until}), 'stale');
});

test('valid microsecond observations expire at the exact source cutoff', () => {
 const fractional = {...value, observed_at: '2026-11-14T05:00:00.000005Z',
  checked_at: '2026-11-14T05:04:00.000010Z', valid_until: '2026-11-14T05:05:00.000005Z'};
 assert.equal(state(fractional, 59999.994), 'Available');
 assert.equal(state(fractional, 59999.995), 'stale');
 assert.equal(state({...fractional, valid_until: '2026-11-14T05:05:00.000006Z'}), 'unknown');
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
  context.window.EvConnectorFreshness.attach({append:()=>{}},{...value, state:'unknown', observed_at:'unverified source time'});
  assert.match(element.textContent,lang==='ar'?/وقت الرصد: غير معروف/:/Observed: unknown/);
  assert.doesNotMatch(element.textContent,/unverified source time/);
 }
});
