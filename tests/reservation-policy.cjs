'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element{constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.textContent='';}append(...children){this.children.push(...children);}}
const terms={version:1,slot_minutes:30,arrival_before_minutes:15,unused_expiry_minutes:30,no_show_fee_minor:0,cancellation_fee_minor:0,cancellation_rule:'BEFORE_ADMITTED_OR_UNCERTAIN_START',occupancy_basis:'CANONICAL_LOGICAL_SESSION_ONLY',occupancy_enforcement:'START_ADMISSION',physical_occupancy_verified:false,unresolved_session_requires_reconciliation:true,automatic_stop:false,evidence:'SIMULATED_BUSINESS_POLICY'};
function fixture(ar=false){const window={},document={documentElement:{lang:ar?'ar':'en'},createElement:tag=>new Element(tag)};vm.runInNewContext(fs.readFileSync('services/business-api/public/reservation-policy.js','utf8'),{window,document});return window.EvReservationPolicy;}
test('unknown terms, fees and physical claims cannot be silently presented as the supported policy',()=>{
 const policy=fixture();assert.equal(policy.valid(terms),true);
 for(const changed of [{version:2},{no_show_fee_minor:500},{cancellation_fee_minor:1},{physical_occupancy_verified:true},{automatic_stop:true},{occupancy_enforcement:'BOOKING'}])assert.equal(policy.valid({...terms,...changed}),false);
 assert.equal(policy.valid(null),false);
});
test('both language summaries disclose zero penalties and unknown physical availability',()=>{
 for(const ar of [false,true]){const root=new Element('section'),policy=fixture(ar);policy.attach(root,terms);assert.equal(root.children.length,1);const [summary,detail]=root.children[0].children;
  assert.match(summary.textContent,ar?/دون رسوم/:/no cancellation\/no-show fee/);assert.match(detail.textContent,ar?/لا يضمن/:/does not guarantee/);assert.match(detail.textContent,ar?/بدء جلسة أخرى/:/starting another session/);
 }
 const root=new Element('section');fixture().attach(root,{...terms,version:99});assert.equal(root.children.length,0);
});
