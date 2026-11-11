#!/usr/bin/env node
/** Actual history page, mocked HTTP, owned loopback fixture only. */
import assert from 'node:assert/strict';
const base=process.argv[2];
if(!base||!/^http:\/\/127\.0\.0\.1:\d+$/.test(base))throw Error('Loopback fixture required');
const imported=await import(process.env.PUPPETEER_PATH||'puppeteer');
const browser=await (imported.default||imported).launch({executablePath:process.env.CHROME_PATH,
 headless:true,pipe:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
const sid='507f1f77bcf86cd799439011',bid='507f1f77bcf86cd799439012',source='507f1f77bcf86cd799439013';
const row=()=>({sessionId:sid,bookingId:bid,stationName:'Synthetic history',chargerId:'CH-1',
 chargerEnabled:true,protocol:'ocpp1.6',state:'ended',sourceVerified:true,meterVerified:true,
 resourceVerified:false,resourceScope:null,reviewReason:null,bookingPrepaymentRecorded:true,
 startedAt:'2026-11-08T00:00:00.000Z',endedAt:'2026-11-08T00:10:00.000Z',durationSeconds:600,
 energyWh:5000,energyKwh:5,meterStartWh:1000,meterLastWh:6000,calculatedUnpaidAed:5.25,
 subtotalFils:500,vatFils:25,totalFils:525,billingStatus:'calculated_unpaid',paymentStatus:'not_collected',
 transactionId:'tx-1',sourceSessionId:source,readOnly:true});
const pageData=entries=>({entries,scanned:entries.length,unavailableCount:0,hasMore:false,
 nextCursor:null,currency:'AED',billingMode:'calculation_only_no_collection',readOnly:true});
let list=pageData([row()]),detail=row(),holdList=false,holdDetail=false,listRelease,detailRelease,passed=0;
const page=await browser.newPage();await page.setViewport({width:1200,height:900});
await page.evaluateOnNewDocument(()=>localStorage.setItem('admin_token','synthetic-admin-A'));
await page.setRequestInterception(true);
page.on('request',async req=>{
 const url=new URL(req.url());
 if(url.origin!==base||!url.pathname.startsWith('/api/v1/admin/ocpp/charging-history'))return req.continue();
 assert.ok(['Bearer synthetic-admin-A','Bearer synthetic-admin-B'].includes(req.headers().authorization));
 const isDetail=url.pathname.endsWith('/'+sid);
 const response=structuredClone(isDetail?detail:list);
 if(isDetail&&holdDetail){holdDetail=false;await new Promise(done=>{detailRelease=done;});}
 if(!isDetail&&holdList){holdList=false;await new Promise(done=>{listRelease=done;});}
 try{await req.respond({status:200,contentType:'application/json',body:JSON.stringify({status:'Success',data:response})});}
 catch{/* A navigated-away fixture can cancel its pending request. */}
});
const waitText=text=>page.waitForFunction(value=>document.body.innerText.includes(value),{},text);
const click=async text=>{const found=await page.evaluate(value=>{
 const b=[...document.querySelectorAll('button')].find(x=>x.textContent.trim()===value);
 if(!b||b.disabled)return false;b.click();return true;
},text);assert.equal(found,true,'Enabled button '+text);};
const inspect=()=>page.click('[aria-label="Inspect history '+sid+'"]');
const noDetail=async()=>assert.equal(await page.$('[aria-label="Source evidence details"]'),null);
const load=async()=>{listRelease=null;detailRelease=null;await page.goto(base+'/ocpp-history');};
const until=async predicate=>{for(let n=0;n<100;n++){if(predicate())return;await new Promise(r=>setTimeout(r,20));}throw Error('Held HTTP request not observed');};
try{
 await load();await waitText('Synthetic history');await inspect();await page.waitForSelector('[aria-label="Source evidence details"]');
 await waitText('5.25 AED');passed++;
 await page.click('[aria-label="Change history language"]');await waitText('سجل مصادر الشحن');passed++;
 await page.click('[aria-label="Change history language"]');
 detail={...row(),bookingId:source};await click('Close');await inspect();await page.waitForSelector('[role="alert"]');await noDetail();passed++;
 detail=row();list=pageData([{...row(),energyKwh:999}]);await load();await page.waitForSelector('[role="alert"]');assert.equal(await page.$('table'),null);passed++;
 list=pageData([{...row(),sourceVerified:false}]);await load();await page.waitForSelector('[role="alert"]');assert.equal(await page.$('table'),null);passed++;
 list=pageData([row(),row()]);await load();await page.waitForSelector('[role="alert"]');assert.equal(await page.$('table'),null);passed++;
 const redacted={...row(),state:'unverified',sourceVerified:false,meterVerified:false,
  reviewReason:'charger_mapping_unavailable',billingStatus:'unverified'};
 for(const key of ['protocol','transactionId','sourceSessionId','startedAt','endedAt','durationSeconds',
  'energyWh','energyKwh','meterStartWh','meterLastWh','calculatedUnpaidAed','subtotalFils','vatFils','totalFils'])redacted[key]=null;
 list=pageData([redacted]);await load();await waitText('Needs review');assert.equal((await page.$eval('table',x=>x.innerText)).includes('5.25'),false);passed++;
 list={...pageData([]),scanned:1,unavailableCount:1};await load();await waitText('Some links are unavailable for review.');passed++;
 list=pageData([row()]);await load();await waitText('Synthetic history');holdDetail=true;await inspect();await until(()=>detailRelease);
 await click('Refresh history');await waitText('Synthetic history');detailRelease();await waitText('Synthetic history');await new Promise(r=>setTimeout(r,100));await noDetail();passed++;
 // A focus event clears already visible private data and the old delayed detail.
 holdDetail=true;await inspect();await until(()=>detailRelease);
 list=pageData([{...row(),stationName:'Current identity history'}]);
 await page.evaluate(()=>{localStorage.setItem('admin_token','synthetic-admin-B');window.dispatchEvent(new Event('focus'));});
 await waitText('Current identity history');detailRelease();await new Promise(r=>setTimeout(r,100));await noDetail();
 assert.equal((await page.$eval('body',x=>x.innerText)).includes('Synthetic history'),false);passed++;
 // A same-tab identity change with no event is fenced when the old reply arrives.
 list=pageData([{...row(),stationName:'Old delayed identity'}]);holdList=true;await click('Refresh history');await until(()=>listRelease);
 list=pageData([{...row(),stationName:'Response-fenced identity'}]);
 await page.evaluate(()=>localStorage.setItem('admin_token','synthetic-admin-A'));listRelease();
 await waitText('Response-fenced identity');assert.equal((await page.$eval('body',x=>x.innerText)).includes('Old delayed identity'),false);passed++;
 // Old first-page data cannot repopulate a freshly requested page.
 list=pageData([{...row(),stationName:'Old delayed refresh'}]);holdList=true;await click('Refresh history');await until(()=>listRelease);
 await page.evaluate(()=>{window.dispatchEvent(new Event('focus'));});
 // Trigger a real identity refresh to supersede the held page request.
 list=pageData([{...row(),stationName:'Latest refresh identity'}]);
 await page.evaluate(()=>{localStorage.setItem('admin_token','synthetic-admin-B');window.dispatchEvent(new Event('storage'));});
 await waitText('Latest refresh identity');listRelease();await new Promise(r=>setTimeout(r,100));
 assert.equal((await page.$eval('body',x=>x.innerText)).includes('Old delayed refresh'),false);passed++;
 console.log('ADMIN_HISTORY_BROWSER_PASSED '+passed);
}finally{if(listRelease)listRelease();if(detailRelease)detailRelease();await browser.close();}
