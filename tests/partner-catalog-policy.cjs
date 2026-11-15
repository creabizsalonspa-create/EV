'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const window={};vm.runInNewContext(fs.readFileSync('services/business-api/public/partner-catalog-policy.js','utf8'),{window});
const base={state:'within_policy',max_age_seconds:60,checked_at:'2026-11-15T12:00:00.000000Z',evidence:'PARTNER_CATALOG_TIMESTAMPS_ONLY',physical_availability_verified:false,remote_control:false,billing_authority:false};
for(const ar of [false,true])test('public catalog policy is read-time evidence and fails closed '+ar,()=>{
 for(const [state,en,arabic] of [['within_policy','within limit','ضمن الحد'],['stale','limit exceeded','تم تجاوز الحد'],['unknown','age unknown','العمر غير معروف']]){
  const text=window.EvPartnerCatalogPolicy.text({...base,state,private:'DO-NOT-RENDER'},ar);assert(text.includes(ar?arabic:en));assert.match(text,ar?/التوفر الفعلي غير مؤكد/:/Physical availability unverified/);assert.doesNotMatch(text,/DO-NOT-RENDER/);
 }
 for(const bad of [null,{...base,state:'__proto__'},{...base,state:'policy_unavailable',max_age_seconds:null},{...base,physical_availability_verified:true},{...base,remote_control:true},{...base,billing_authority:true},{...base,max_age_seconds:59},{...base,max_age_seconds:604801},{...base,max_age_seconds:'60'},{...base,checked_at:'<img src=x>'}])assert.match(window.EvPartnerCatalogPolicy.text(bad,ar),ar?/غير متاحة/:/policy unavailable/);
});
