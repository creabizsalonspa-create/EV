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

test('source and receipt evidence must agree at exact microsecond policy cutoff',()=>{
 const project=window.EvPartnerCatalogPolicy.project;
 const row={evidence:'PARTNER_REPORTED_UNVERIFIED',catalog_checked_at:base.checked_at,
  source_updated_at:'2026-11-15T11:59:00.000001Z',reported_source_age_minutes:0,
  source_timestamp_evidence:'PARTNER_REPORTED_ONLY',source_clock_evidence:'PARTNER_REPORTED_TIME',
  received_at:'2026-11-15T11:59:30Z',received_age_minutes:0,received_timestamp_evidence:'LOCAL_INGESTION_TIME',
  catalog_age_policy:base};
 assert.equal(project(row).sourceTime,row.source_updated_at);
 assert.equal(project(row).policy.state,'within_policy');
 const cutoff={...row,source_updated_at:'2026-11-15T11:59:00Z',reported_source_age_minutes:1,catalog_age_policy:{...base,state:'stale'}};
 assert.equal(project(cutoff).policy.state,'stale');
 assert.equal(project({...cutoff,catalog_age_policy:base}).policy,null);
 for(const invalid of [
  {...row,source_updated_at:'2026-02-30T11:59:00Z'},
  {...row,source_updated_at:'2026-11-15T12:00:00.000001Z'},
  {...row,reported_source_age_minutes:1},
  {...row,source_timestamp_evidence:'LOCAL_INGESTION_TIME'},
  {...row,source_clock_evidence:'PARTNER_CLOCK_AHEAD'},
  {...row,evidence:'OTHER'},
 ]) {const p=project(invalid);assert.equal(p.sourceTime,null);assert.equal(p.sourceAge,null);assert.equal(p.policy,null);}
 assert.equal(project({...row,received_age_minutes:1}).receiptAge,null);
 assert.equal(project({...row,received_age_minutes:1}).policy,null);
 assert.equal(project({...row,received_age_minutes:1,catalog_age_policy:{...base,state:'unknown'}}).policy.state,'unknown');
 assert.equal(project({...row,catalog_age_policy:{...base,checked_at:'2026-11-15T12:00:00.000001Z'}}).policy,null);
 assert.equal(project({...row,catalog_age_policy:{...base,remote_control:true}}).policy,null);
 for(const ar of [false,true]) assert.match(window.EvPartnerCatalogPolicy.text({...base,checked_at:'2026-02-30T12:00:00Z'},ar),ar?/غير متاحة/:/policy unavailable/);
});
