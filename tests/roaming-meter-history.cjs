'use strict';
const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});try{
 for(const ar of [false,true]){
  const page=await browser.newPage();await page.setContent(`<html lang="${ar?'ar':'en'}"><body><section id="public"></section><section id="private"></section></body></html>`);
  await page.addScriptTag({path:'services/business-api/public/locale-data.js'});await page.addScriptTag({path:'services/business-api/public/roaming.js'});
  await page.evaluate(async()=>{
   window.viewer={id:1,role:'customer'};window.reads=[];window.unsafe=false;window.delayed=false;window.notices=0;
   const text=(key,args={})=>Object.entries(args).reduce((s,[k,v])=>s.replaceAll('{'+k+'}',String(v)),window.EV_MESSAGES[key]?.[document.documentElement.lang]??key);
   window.EvLocale={text,attribute:(n,k,v)=>n.setAttribute(k,v)};
   const paged=(data,page=1,last=1)=>({data,current_page:page,last_page:last,total:data.length});
   window.report=page=>({scope:'roaming_source_meter_history',order_id:3,through_id:42,source_bound:true,coverage:'ACCEPTED_VERSIONS_SINCE_INSTRUMENTATION',evidence:'PARTNER_REPORTED_UNVERIFIED',backfilled:false,physical_energy_verified:unsafe,billing_authority:false,commands_sent:false,observations:paged([{status:'ACTIVE',reported_energy_kwh:page===1?'0.009':'0.010',meter_regressed:true,precision_requires_review:false,source_clock_ahead:false,source_updated_at:'2026-11-15T10:00:00Z',received_at:'2026-11-15T10:00:01Z',private:'PRIVATE-TOKEN <img src=x>'}],page,2)});
   window.workspace=createRoamingWorkspace({publicRoot:document.querySelector('#public'),privateRoot:document.querySelector('#private'),actor:()=>viewer,notice:()=>notices++,ui:(tag,key,args)=>{const n=document.createElement(tag);n.textContent=text(key,args);return n;},api:async(path,method='GET')=>{
    if(method!=='GET')throw Error('Unexpected source command');
    if(path.includes('/meter-history')){reads.push(path);if(delayed)return new Promise(resolve=>window.finish=resolve);return report(Number(new URL(path,'https://example.test').searchParams.get('page')));}
    if(path.startsWith('roaming/sandbox-orders'))return paged([{id:3,location_name:'Private source site',commands:[],can_stop:false,can_revoke:false,start_consent_current:true,evidence:'SANDBOX_PARTNER_UNVERIFIED',billing_eligible:false,live_commands_enabled:false,local_invoice_created:false,physical_charging_verified:false}]);
    return paged([]);
   }});await workspace.setActor();
  });
  await page.getByRole('button',{name:ar?'مراجعة سجل التجوال التجريبي':'Review sandbox history',exact:true}).click();
  const read=page.getByRole('button',{name:ar?'سجل قراءات عداد المصدر':'Recorded source meter history',exact:true});assert.equal(await page.evaluate(()=>reads.length),0);await read.click();await page.getByText('ACTIVE · 0.009 kWh',{exact:true}).waitFor();
  assert.equal(await page.locator('img').count(),0);assert(!(await page.locator('body').textContent()).includes('PRIVATE-TOKEN'));
  const region=page.locator('[data-sandbox-order] section').first();await region.getByRole('button',{name:ar?'التالي':'Next',exact:true}).click();await page.getByText('ACTIVE · 0.010 kWh',{exact:true}).waitFor();assert.deepEqual(await page.evaluate(()=>reads),['roaming/sandbox-orders/3/meter-history?page=1','roaming/sandbox-orders/3/meter-history?page=2&through=42']);
  await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});assert.equal(await page.getByText('ACTIVE · 0.010 kWh',{exact:true}).count(),0);
  await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});assert.equal(await page.evaluate(()=>reads.length),2);
  await page.evaluate(()=>{unsafe=true;});await read.click();assert.equal(await page.getByText('ACTIVE · 0.009 kWh',{exact:true}).count(),0);assert.equal(await page.evaluate(()=>notices),1);
  await page.evaluate(()=>{unsafe=false;delayed=true;});await read.click();await page.evaluate(()=>{viewer={id:2,role:'customer'};workspace.clear();finish(report(1));});assert.equal(await page.getByText('ACTIVE · 0.009 kWh',{exact:true}).count(),0);await page.close();
 }
 console.log('PASS EN/AR source meter history privacy, exact readings, frozen pagination, background clearing, forbidden authority and late account fencing.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
