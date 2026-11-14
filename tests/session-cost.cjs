'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
const window={};vm.runInNewContext(fs.readFileSync('services/business-api/public/session-cost.js','utf8'),{window});const p=window.EvSessionCost;
const value={state:'estimated',evidence:'SIMULATED',scope:'CANONICAL_SESSION_QUOTE',physical_charging_verified:false,real_payment_processed:false,external_fees_included:false,spending_cap:false,automatic_stop:false,currency:'KWD',minor_units:3,tax_bps:500,rate_minor_per_kwh:'251',energy_wh:'1500',subtotal_minor:'377',tax_minor:'19',total_minor:'396',as_of:'2026-11-14T06:00:00.000000Z',source_meter_at:null,meter_freshness_at_read:'APPLICATION_SIMULATOR',invoice_id:null};
test('recorded estimates include tax and exact configured precision without a payment or cap claim',()=>{
 assert.equal(p.valid(value),true);assert.match(p.text(value,false),/Recorded cost estimate: KWD 0.396/);assert.match(p.text(value,true),/تقدير التكلفة المسجل: KWD 0.396/);assert.match(p.text(value,false),/not a spending cap/);
 assert.match(p.text({...value,currency:'JPY',minor_units:0},false),/JPY 396/);
});
test('only consistent canonical invoices may be labeled invoiced',()=>{
 assert.equal(p.valid({...value,state:'invoiced'}),false);
 assert.match(p.text({...value,state:'invoiced',invoice_id:7},false),/Simulated invoice total/);
 for(const update of [{total_minor:'395'},{tax_minor:'20'},{currency:{toString:null}},{rate_minor_per_kwh:'1000001'},{scope:'OTHER'},{evidence:'LIVE'},{physical_charging_verified:true},{real_payment_processed:true},{spending_cap:true},{as_of:'today'},{meter_freshness_at_read:'ONLINE'}]) assert.equal(p.valid({...value,...update}),false);
});
test('stale and missing meter evidence are explicit and never become a zero cost',()=>{
 assert.match(p.text({...value,meter_freshness_at_read:'STALE',source_meter_at:'2026-11-14T05:55:00.000000Z'},false),/stale at this read/);
 assert.match(p.text({...value,meter_freshness_at_read:'UNKNOWN'},true),/حداثة المصدر غير معروفة/);
 assert.match(p.text({state:'awaiting_meter'},false),/Waiting for meter evidence/);
 assert.doesNotMatch(p.text(null,false),/0\.00/);
});
test('actual bilingual DOM presentation renders text without interpreting source HTML',()=>{
 for(const lang of ['en','ar']) {const root={children:[],append(x){this.children.push(x);}},window={},document={documentElement:{lang},createElement:()=>({dataset:{},textContent:''})};vm.runInNewContext(fs.readFileSync('services/business-api/public/session-cost.js','utf8'),{window,document});window.EvSessionCost.attach(root,value);assert.equal(root.children.length,1);assert.match(root.children[0].textContent,/KWD 0.396/);assert.equal(root.children[0].innerHTML,undefined);}
});
