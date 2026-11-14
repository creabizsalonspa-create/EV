'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
const estimate={version:1,evidence:'SIMULATED',currency:'KWD',minor_units:3,energy_wh:1500,rate_minor_per_kwh:'251',tax_bps:500,subtotal_minor:'377',tax_minor:'19',total_minor:'396',session_fee_minor:'0',idle_fee_minor:'0',external_fees_included:false,automatic_stop:false,final_total_depends_on_meter:true,expires_at:'2026-11-18T10:35:00.000000Z'};
function fixture({allow=true,visible=true,energy='1500'}={}) {
 let current=true,confirmation;
 const calls=[],window={prompt:()=> energy,confirm:text=>{confirmation=text;return allow;}};
 vm.runInNewContext(fs.readFileSync('services/business-api/public/money-input.js','utf8'),{window});
 vm.runInNewContext(fs.readFileSync('services/business-api/public/precharge-pricing.js','utf8'),{window,document:{visibilityState:visible?'visible':'hidden'}});
 const api=async(path,method,body)=>{calls.push({path,method,body});return path.endsWith('price-estimate')?{estimate,quote_token:'private-synthetic-token'}:{id:1};};
 return {p:window.EvPrechargePricing,calls,api,current:()=>current,revoke:()=>{current=false;},confirmation:()=>confirmation};
}
test('price estimates reconcile integer half-up rounding and exact zero/three precision',()=>{
 const {p}=fixture();assert.equal(p.valid(estimate),true);assert.equal(p.amount('396',3),'0.396');assert.equal(p.amount('396',0),'396');
 for(const change of [{total_minor:'395'},{subtotal_minor:'376'},{tax_minor:'20'},{minor_units:4},{external_fees_included:true},{automatic_stop:true},{evidence:'LIVE'},{rate_minor_per_kwh:'1000001'},{session_fee_minor:'5'},{energy_wh:0},{tax_bps:-1}])assert.equal(p.valid({...estimate,...change}),false);
 assert.match(p.text(estimate,false),/KWD 0.396/);assert.match(p.text(estimate,true),/KWD 0.396/);assert.match(p.text(estimate,true),/لا يحدث دفع حقيقي/);
});
test('acceptance posts the exact reviewed opaque token only after explicit confirmation',async()=>{
 const f=fixture();assert.equal(await f.p.review({api:f.api,reservationId:7,current:f.current}),true);
 assert.equal(f.calls.length,2);assert.equal(f.calls[0].body.energy_wh,1500);assert.equal(f.calls[1].path,'reservations/7/price-consent');assert.equal(f.calls[1].body.quote_token,'private-synthetic-token');assert.equal(f.calls[1].body.accepted,true);
 assert.match(f.confirmation(),/not a spending cap/);assert.match(f.confirmation(),/External parking\/access fees are excluded/);
});
test('decline, background and changed account never approve a price or issue a start',async()=>{
 for(const options of [{allow:false},{visible:false}]){const f=fixture(options);assert.equal(await f.p.review({api:f.api,reservationId:7,current:f.current}),false);assert.equal(f.calls.length,1);}
 const f=fixture();const api=async(...args)=>{const reply=await f.api(...args);f.revoke();return reply;};assert.equal(await f.p.review({api,reservationId:7,current:f.current}),false);assert.equal(f.calls.length,1);
});
test('already recorded starts and inconsistent prices cannot produce new acceptance',async()=>{
 const f=fixture();let writes=0;assert.equal(await f.p.review({api:async()=>{writes++;return{start_already_recorded:true,existing_session_id:9};},reservationId:7,current:f.current}),false);assert.equal(writes,1);
 await assert.rejects(f.p.review({api:async()=>({estimate:{...estimate,total_minor:'1'},quote_token:'token'}),reservationId:7,current:f.current}),/Unsupported pricing estimate/);
});

test('Arabic energy input is normalized without rounding or exponent parsing',async()=>{const f=fixture({energy:'١٥٠٠'});assert.equal(await f.p.review({api:f.api,reservationId:7,current:f.current,ar:true}),true);assert.equal(f.calls[0].body.energy_wh,1500);const bad=fixture({energy:'1e3'});await assert.rejects(bad.p.review({api:bad.api,reservationId:7,current:bad.current}),/Enter valid energy/);assert.equal(bad.calls.length,0);});
