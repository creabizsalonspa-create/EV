'use strict';
const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});try{
for(const ar of [false,true]){
 const page=await browser.newPage();await page.setContent(`<html lang="${ar?'ar':'en'}"><body><section id="host"></section></body></html>`);await page.addScriptTag({path:'services/business-api/public/connector-freshness.js'});await page.addScriptTag({path:'services/business-api/public/connector-fleet.js'});
 await page.evaluate(()=>{
  window.opened=[];window.viewer={id:1,role:'vendor'};window.reads=[];
  window.report=p=>({scope:'recorded_connector_fleet',as_of:'2026-11-15T12:00:00Z',evidence:'SIMULATED_SOURCE',physical_availability_verified:false,remote_control:false,billing_authority:false,connectors:{current_page:p,last_page:2,data:[{mapping_id:p,station_id:7,station_name:'<img src=x> private fleet',protocol:'1.6',inventory_eligible_at_read:true,operations_target:{company_id:7,charger_id:9},status_freshness:{state:p===1?'recent':'stale',reported_status:p===1?'Faulted':null,evidence:'SIMULATED_SOURCE',physical_availability_verified:false}}]}});
  window.render=api=>{document.querySelector('#host').replaceChildren();renderConnectorFleetAction(document.querySelector('#host'),{actor:()=>viewer,api,openDiagnostics:async(company,charger)=>opened.push([company,charger])});};
  render(async(path,method)=>{if(method!=='GET')throw Error('Unexpected write');reads.push(path);return report(Number(new URL(path,'https://example.test').searchParams.get('page')));});
 });
 const read=page.getByRole('button',{name:ar?'قراءة لقطة الموصلات':'Read connector snapshot',exact:true});assert.equal(await page.evaluate(()=>reads.length),0);
 await page.locator('input').fill('7');await read.click();await page.locator('article').waitFor();assert.equal(await page.locator('img').count(),0);assert.match(await page.locator('article').textContent(),ar?/عطل/:/Faulted/);
 const diagnostics=page.getByRole('button',{name:ar?'فتح تشخيص المصدر':'Open source diagnostics',exact:true});
 const next=page.getByRole('button',{name:ar?'التالي':'Next',exact:true});
 const oldDiagnostics=await diagnostics.elementHandle(), oldNext=await next.elementHandle();
 assert.deepEqual(await page.evaluate(()=>opened),[]);await diagnostics.click();assert.deepEqual(await page.evaluate(()=>opened),[[7,9]]);
 await page.locator('input').fill('999');await next.click();await page.getByText(ar?'تقرير قديم؛ الحالة الحالية غير معروفة':'Stale report; current state unknown',{exact:true}).waitFor();
 for (const control of [oldDiagnostics, oldNext]) {
  await control.evaluate(button=>{
   if(button.isConnected)throw Error('Expected detached control');
   button.click();
  });
 }
 assert.deepEqual(await page.evaluate(()=>opened),[[7,9]]);
 assert.deepEqual(await page.evaluate(()=>reads),['charging-operations/connectors?page=1&station_id=7','charging-operations/connectors?page=2&station_id=7']);
 // One explicit page action must deduplicate two rows on one charger and independently inspect a second charger.
 await page.evaluate(()=>{
  window.liveCalls=[];
  const snapshot=report(1), first=snapshot.connectors.data[0];
  snapshot.connectors.data.push({...first,mapping_id:2}, {...first,mapping_id:3,protocol:'2.0.1',operations_target:{company_id:7,charger_id:10}});
  render(async(path,method)=>{
   if(method!=='GET')throw Error('Unexpected write');
   if(path.startsWith('charging-operations/'))return snapshot;
   liveCalls.push(path);
   const second=path.includes('/10/');
   return {company_id:7,charger_id:second?999:9,protocol:second?'2.0.1':'1.6',evidence:'SIMULATED_CSMS_SOURCE',
    source_identity_rechecked:true,source_online:true,source_topology_matches_local:true,observed_at:'2026-11-15T12:00:00Z',
    physical_availability_verified:false,remote_control_authorized:false,commands_sent:false,
    connectors:[1,2].map(mapping_id=>({mapping_id,state:'recent',reported_status:'Available',source_event_at:'2026-11-15T11:59:00Z',physical_available:false,remote_control_authorized:false}))};
  });
 });
 await read.click();await page.locator('article').first().waitFor();
 assert.deepEqual(await page.evaluate(()=>liveCalls),[]);
 await page.getByRole('button',{name:ar?'قراءة المصادر المباشرة لهذه الصفحة':'Read live sources for this page',exact:true}).click();
 await page.locator('[data-fleet-live="1"]').filter({hasText:'2026-11-15T12:00:00Z'}).waitFor();
 assert.equal(await page.evaluate(()=>liveCalls.length),2);
 assert.match(await page.locator('[data-fleet-live="2"]').textContent(),/2026-11-15T12:00:00Z/);
 assert.match(await page.locator('[data-fleet-live="3"]').textContent(),ar?/غير متاح/:/unavailable/);
 assert.doesNotMatch(await page.locator('[data-fleet-live="3"]').textContent(),/2026-11-15/);
 await page.evaluate(()=>render(async()=>({...report(1),remote_control:true})));await read.click();assert.equal(await page.locator('article').count(),0);
 await page.evaluate(()=>render(()=>new Promise(resolve=>window.finish=resolve)));await read.click();await page.evaluate(()=>{viewer={id:2,role:'vendor'};finish(report(1));});assert.equal(await page.locator('article').count(),0);
 await page.close();
}
console.log('PASS connector fleet EN/AR explicit reads, safe source text, frozen pagination, forbidden authority and late account fencing.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
