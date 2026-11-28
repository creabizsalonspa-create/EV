'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
function load(context) {
 for (const script of ['station-time','connector-freshness']) {
  vm.runInNewContext(fs.readFileSync(`services/business-api/public/${script}.js`,'utf8'),context);
 }
}
const window={};load({window});
const state=window.EvConnectorFreshness.state;
function liveElement() {
 let text = '', updates = 0;
 const attributes = new Map();
 return {
  dataset:{}, isConnected:true, attributes,
  setAttribute:(key,value)=>attributes.set(key,value),
  get textContent(){return text;},
  set textContent(value){text=value;updates++;},
  get updates(){return updates;},
 };
}
const value={state:'recent',reported_status:'Available',evidence:'SIMULATED_SOURCE',physical_availability_verified:false,max_age_seconds:300,observed_at:'2026-11-14T05:00:00Z',checked_at:'2026-11-14T05:04:00Z',valid_until:'2026-11-14T05:05:00Z'};
test('connector status expires on screen using elapsed time, independent of client clock',()=>{assert.equal(state(value,59999),'Available');assert.equal(state(value,60000),'stale');});
test('malformed, unsupported, physical and future claims fail closed',()=>{
 for(const update of [{physical_availability_verified:true},{evidence:'LIVE'},{max_age_seconds:301},{observed_at:'2026-11-14T05:05:00Z'},{valid_until:'garbage'},{reported_status:'anything'},{state:'unknown'}])assert.equal(state({...value,...update}),'unknown');
 assert.equal(state(null),'unknown');assert.equal(state(value,-1),'unknown');assert.equal(state(value,NaN),'unknown');
});

test('actual display expires in both languages and disposes its timer when removed',()=>{
 for (const lang of ['en','ar']) {
  let elapsed=0,tick,cancelled=false;const element=liveElement();
  const context={window:{},document:{documentElement:{lang},createElement:()=>element},performance:{now:()=>elapsed},setInterval:fn=>{tick=fn;return 1;},clearInterval:id=>{assert.equal(id,1);cancelled=true;}};
  load(context);
  context.window.EvConnectorFreshness.attach({append:p=>assert.equal(p,element)},value);
  assert.match(element.textContent,lang==='ar'?/متاح بحسب المصدر/:/Available/);
  assert.equal(element.attributes.get('role'),'status');
  assert.equal(element.attributes.get('aria-atomic'),'true');
  assert.match(element.textContent,/\(UTC\)$/);
  assert.doesNotMatch(element.textContent,/2026-11-14T05:00:00Z/);
  const updates=element.updates;
  elapsed=1000;tick();assert.equal(element.updates,updates);
  elapsed=60000;tick();assert.match(element.textContent,lang==='ar'?/قديم/:/stale/);
  elapsed=61000;tick();assert.equal(element.updates,updates+1);
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
  const element=liveElement();
  const window={},document={documentElement:{lang},createElement:()=>element};
  const perf={now:()=>0};
  const env={window,document,performance:perf,setInterval:fn=>{tick=fn;return 1;},
    clearInterval:()=>{}};
  load(env);
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

test('unresolved evidence stays unknown after expiry and malformed UTC never becomes recent', () => {
 for (const update of [
  {state: 'unknown'},
  {reported_status: null},
  {observed_at: '2026-11-31T05:00:00Z', checked_at: '2026-12-01T05:04:00Z', valid_until: '2026-12-01T05:05:00Z'},
  {observed_at: '2026-11-14T05:04:00.000002Z', checked_at: '2026-11-14T05:04:00.000001Z', valid_until: '2026-11-14T05:09:00.000002Z'},
 ]) assert.equal(state({...value, ...update}, 60000), 'unknown');
 assert.equal(state({...value, state: 'stale'}), 'unknown');
 assert.equal(state({...value, checked_at: value.valid_until}), 'unknown');
 assert.equal(state({...value, state: 'stale', checked_at: value.valid_until}), 'unknown');
 assert.equal(state({...value, state: 'stale', reported_status: null, checked_at: value.valid_until}), 'stale');
});

test('valid microsecond observations expire at the exact source cutoff', () => {
 const fractional = {...value, observed_at: '2026-11-14T05:00:00.000005Z',
  checked_at: '2026-11-14T05:04:00.000010Z', valid_until: '2026-11-14T05:05:00.000005Z'};
 assert.equal(state(fractional, 59999.994), 'Available');
 assert.equal(state(fractional, 59999.995), 'stale');
 assert.equal(state({...fractional, valid_until: '2026-11-14T05:05:00.000006Z'}), 'unknown');
});

test('contradictory or incomplete source evidence hides time even after expiry', () => {
 for(const lang of ['en','ar']){
  const element=liveElement();
  const env={window:{},document:{documentElement:{lang},createElement:()=>element},performance:{now:()=>480000},setInterval:()=>1,clearInterval:()=>{}};
  load(env);
  for(const update of [{state:'unknown'},{reported_status:null},{state:'stale'},{max_age_seconds:301},{checked_at:'2026-11-14T05:04:00Z\n'},{valid_until:'2026-11-14T05:05:00.000001Z'}]){
   const claim={...value,...update};
   assert.equal(state(claim,480000),'unknown');
   env.window.EvConnectorFreshness.attach({append:()=>{}},claim,480000);
   assert.match(element.textContent,lang==='ar'?/وقت الرصد: غير معروف/:/Observed: unknown/);
  }
 }
});
