'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element{
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.text='';}
 append(...children){this.children.push(...children);}
 set textContent(value){this.text=String(value);this.children=[];}
 get textContent(){return this.text+this.children.map(n=>n.textContent||'').join('');}
}
const report={state:'attention',checked_at:'2026-11-15T12:00:00.000000Z',max_age_seconds:86400,published_count:6,within_policy_count:1,stale_count:3,unknown_age_count:2,future_clock_count:2,evidence:'PARTNER_CATALOG_TIMESTAMPS_ONLY',physical_availability_verified:false,remote_control:false,billing_authority:false};
function render(value,ar){const window={},document={documentElement:{lang:ar?'ar':'en'},createElement:tag=>new Element(tag)};vm.runInNewContext(fs.readFileSync('services/business-api/public/ocpi-sync-plans.js','utf8'),{window,document});const root=new Element('main');window.EvOcpiSyncPlans.catalogHealth(root,value);return root;}
for(const ar of [false,true]){
 test('catalog-age observations stay distinct from availability '+ar,()=>{
  const root=render({...report,private_payload:'DO-NOT-DISPLAY'},ar);assert.match(root.textContent,ar?/ضمن سياسة العمر 1/:/within age policy 1/);assert.match(root.textContent,ar?/قديم 3/:/old 3/);assert.match(root.textContent,ar?/ساعات مستقبلية 2/:/future clocks 2/);assert.match(root.textContent,ar?/غير مؤكدة/:/remain unverified/);assert.doesNotMatch(root.textContent,/DO-NOT-DISPLAY/);assert.equal(root.children[0].children.some(n=>n.tag==='button'),false);
 });
 test('missing, forged-authority and inconsistent catalog reports stay unknown '+ar,()=>{
  for(const bad of [null,{...report,state:'unavailable',published_count:null},{...report,physical_availability_verified:true},{...report,remote_control:true},{...report,billing_authority:true},{...report,published_count:0},{...report,future_clock_count:3},{...report,stale_count:'3'},{...report,max_age_seconds:0},{...report,checked_at:'<img src=x>'},{...report,state:'within_policy'}]){const root=render(bad,ar);assert.match(root.textContent,ar?/عمر الكتالوج غير متاح/:/Catalog age unavailable/);assert.doesNotMatch(root.textContent,ar?/منشور 0/:/Published 0/);}
  const empty=render({...report,state:'empty',published_count:0,within_policy_count:0,stale_count:0,unknown_age_count:0,future_clock_count:0},ar);assert.match(empty.textContent,ar?/منشور 0/:/Published 0/);
 });
}
