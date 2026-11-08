#!/usr/bin/env node
/**
 * Puppeteer acceptance for the staged, authenticated Next Admin OCPP flow.
 *
 * All backend HTTP responses are explicitly mocked on localhost for UI
 * acceptance. Independent isolated Mongo/Express/OCPP tests validate actual
 * backend contracts. Never point this test at the production domain.
 *
 * Usage: PUPPETEER_PATH=/path/to/puppeteer.js node test_next_admin_multicharger_browser.mjs http://127.0.0.1:3418
 */
import assert from 'node:assert/strict';
const base=process.argv[2];
if(!base || !/^http:\/\/127\.0\.0\.1:\d+$/.test(base)){
  throw new Error('Only loopback-only staging URLs are allowed');
}
const imported=await import(process.env.PUPPETEER_PATH||'puppeteer');
const puppeteer=imported.default||imported;
const browser=await puppeteer.launch({
  executablePath:process.env.CHROME_PATH||undefined,
  headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'],
});
let passed=0;
const id='507f1f77bcf86cd799439011';
const station='507f1f77bcf86cd799439012';
const sourceA='507f1f77bcf86cd799439013';
const sourceB='507f1f77bcf86cd799439014';
const chargerA='AGENT2-STATION-CHARGER-A';
const chargerB='AGENT2-STATION-CHARGER-B';
const fakeToken='A1B2C3D4E5F60718293A';
let stage=0,requestAuthorizationCount=0,registryReview=false;
let receiptPatch={},operationPatch={},holdOperations=false,operationsRelease=null,sourceListReads=0;
let issuedTo=null;
let auditPageFailure=true;
let holdIssueResponse=false,issueResponseRelease=null,failIssueResponse=false,issueRequests=0;
const auditCursor='507f1f77bcf86cd799439099';
const qaToken='agent2-ui-test-token-not-production';
function result(data,status=200){
 return {status,headers:{'content-type':'application/json','cache-control':'no-store'},
  body:JSON.stringify({status:'Success',data})};
}
function operations(){
 if(registryReview)return {bookingId:id,stationId:station,bookingStatus:'Upcoming',
  bookingPrepaymentRecorded:true,arrivalRecorded:true,approvedChargerId:null,
  selectedChargerId:null,approvedChargers:[],requiresChargerSelection:false,
  authorizationIssued:false,sourceSessionId:null,sourceTransactionId:null,
  sourceVerified:false,meterVerified:false,sourceEnded:false,energyKwh:null,
  calculatedUnpaidAed:null,paymentStatus:null,canIssueToken:false,canReissueToken:false,
  canBindSource:false,canFinalizeSource:false,needsPaymentReconciliation:false,
  chargerRegistryReviewRequired:true};
 return {
  bookingId:id,stationId:station,
  bookingStatus:stage===3?'Completed':'Upcoming',
  bookingPrepaymentRecorded:true,arrivalRecorded:true,
  approvedChargerId:stage===0?null:chargerB,
  selectedChargerId:stage===0?null:chargerB,
  approvedChargers:[
    {chargerId:chargerA,protocol:'ocpp1.6',online:true},
    {chargerId:chargerB,protocol:'ocpp2.0.1',online:true}
  ],requiresChargerSelection:stage===0,
  authorizationIssued:stage>=1,
  tokenIssuedAt:stage>=1?'2026-11-07T10:00:00.000Z':null,
  tokenExpiresAt:stage>=1?'2026-11-07T10:20:00.000Z':null,
  sourceSessionId:stage>=2?sourceB:null,
  sourceTransactionId:stage>=2?'tx-second':null,
  sourceVerified:stage>=2,meterVerified:stage>=2,
  sourceEnded:stage>=2,energyKwh:stage>=2?8:null,
  calculatedUnpaidAed:stage>=2?7.56:null,
  paymentStatus:stage>=2?'not_collected':null,
  canIssueToken:stage===0,canReissueToken:false,
  ...operationPatch,
  canBindSource:stage===1,
  canFinalizeSource:stage===2,
  needsPaymentReconciliation:stage>=2
 };
}
function booking(){
 return {_id:id,stationId:station,stationName:'Ras Al Khaimah Demo Station',
  vehicleName:'Test EV',date:'2026-11-07',time:'16:00',
  totalAmount:77,isPaid:true,hasArrived:true,
  status:stage===3?'Completed':'Upcoming',
  createdAt:'2026-11-07T08:00:00.000Z'};
}
async function step(label,fn){
 await fn();passed++;console.log('PASS '+label);
}
async function clickText(page,label){
 const wasClicked=await page.evaluate(text=>{
   const button=[...document.querySelectorAll('button')]
     .find(x=>x.textContent?.includes(text) && !x.disabled);
   if(button){button.click();return true}
   return false;
 },label);
 assert.equal(wasClicked,true,'Missing enabled button: '+label);
}
async function wait(page,pred){
 await page.waitForFunction(pred,{timeout:15000});
}
try{
 const unsigned=await browser.newPage();
 await unsigned.goto(base+'/bookings',{waitUntil:'networkidle2'});
 await step('unauthenticated browser redirected to login',async()=>{
   await unsigned.waitForFunction(()=>window.location.pathname==='/login',{timeout:15000});
   assert.ok(unsigned.url().endsWith('/login'));
 });
 await unsigned.close();
 const page=await browser.newPage();
 await page.evaluateOnNewDocument(token=>{
   window.localStorage.setItem('admin_token',token);
   window.localStorage.setItem('admin_user',JSON.stringify({name:'Agent 2 UI Test Admin'}));
 },qaToken);
 await page.setRequestInterception(true);
 page.on('request',request=>{
   const url=new URL(request.url());
   if(!url.pathname.startsWith('/api/v1/')){void request.continue();return;}
   try{
     assert.equal(url.hostname,'127.0.0.1','API cannot target production');
     assert.equal(request.headers().authorization,'Bearer '+qaToken);
     requestAuthorizationCount++;
     const path=url.pathname;
     let response;
     if(path==='/api/v1/admin/bookings' && request.method()==='GET'){
       response=result([booking()]);
     }else if(path==='/api/v1/admin/bookings/'+id+'/ocpp/operations'
         && request.method()==='GET'){
       response=result(operations());
     }else if(path==='/api/v1/admin/stations/'+station+'/ocpp/booking-sources'
         && request.method()==='GET'){
       sourceListReads++;
       response=result({sessions:[
         {id:sourceA,chargerId:chargerA,protocol:'ocpp1.6',
          transactionId:'tx-first',status:'ended',energyKwh:5,paymentStatus:'not_collected'},
         {id:sourceB,chargerId:chargerB,protocol:'ocpp2.0.1',
          transactionId:'tx-second',status:'ended',energyKwh:8,paymentStatus:'not_collected'}
       ]});
     }else if(path==='/api/v1/admin/stations/'+station+'/ocpp/start-exceptions'
         && request.method()==='GET'){
       const cursor=url.searchParams.get('cursor');
       if(cursor){
         assert.equal(cursor,auditCursor);
         if(auditPageFailure){
           auditPageFailure=false;
           response={status:503,headers:{'content-type':'application/json'},
             body:JSON.stringify({status:'fail',message:'Audit page temporarily unavailable'})};
         }else{
           response=result({scanned:1,hasMore:false,nextCursor:null,
             orphanCount:1,matchedSourceCount:0,withinGracePeriodCount:0,readOnly:true,
             exceptions:[{chargerId:chargerB,bookingId:id,
               consumedAt:'2026-11-06T09:00:00Z',
               issue:'consumed_without_source_transaction',
               action:'Older interrupted start requires review.',credentialRestored:false}]});
         }
       }else{
         response=result({scanned:4,hasMore:true,nextCursor:auditCursor,
           orphanCount:1,matchedSourceCount:2,
           withinGracePeriodCount:1,readOnly:true,
           exceptions:[{chargerId:chargerB,bookingId:id,
             consumedAt:'2026-11-07T09:00:00Z',
             issue:'consumed_without_source_transaction',
             action:'Review charger logs before issuing another credential.',
             credentialRestored:false}]});
       }
     }else if(path==='/api/v1/admin/bookings/'+id+'/ocpp/issue-token'
         && request.method()==='POST'){
       issueRequests++;
       const body=JSON.parse(request.postData()||'{}');
       assert.equal(body.chargerId,chargerB,'Booking must explicitly select second charger');
       issuedTo=body.chargerId;
       stage=1;
       response=failIssueResponse?{status:503,headers:{'content-type':'application/json'},
         body:JSON.stringify({status:'fail',message:'Authorization outcome uncertain; reload booking before recovery'})}
         :result({oneTimeToken:fakeToken,bookingId:id,chargerId:chargerB,
         expiresAt:'2026-11-07T10:20:00.000Z',connectorScoped:false,resourceScope:null,
         chargingSessionConfirmed:false,...receiptPatch},201);
     }else if(path==='/api/v1/admin/bookings/'+id+'/ocpp/bind-source'
         && request.method()==='POST'){
       const body=JSON.parse(request.postData()||'{}');
       assert.equal(body.sourceSessionId,sourceB,'Cannot bind first charger source');
       stage=2;
       response=result({sessionId:sourceB,source:'ocpp',meterVerified:true});
     }else if(path==='/api/v1/admin/bookings/'+id+'/ocpp/finalize-source'
         && request.method()==='POST'){
       assert.equal(stage,2,'Cannot finalize before verified source stop');
       stage=3;
       response=result({bookingStatus:'Completed',paymentStatus:'not_collected',
         calculatedUnpaidAed:7.56});
     }else{
       throw Error('Unexpected API call: '+request.method()+' '+path);
     }
     if(holdOperations && path.endsWith('/ocpp/operations')){
       operationsRelease=()=>request.respond(response);return;
     }
     if(holdIssueResponse && path.endsWith('/ocpp/issue-token')){
       issueResponseRelease=()=>request.respond(response);
       return;
     }
     void request.respond(response);
   }catch(error){console.error('Unexpected staging browser API request',error);
     void request.abort('failed');}
 });
 await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
 await step('authenticated booking table and OCPP action render',async()=>{
   await page.waitForSelector('button[aria-label^="OCPP operations"]',{timeout:15000});
   assert.ok((await page.content()).includes('Ras Al Khaimah Demo Station'));
 });
 await page.click('button[aria-label^="OCPP operations"]');
 await step('multi-charger choice is explicit',async()=>{
   await page.waitForSelector('select[aria-label="Select booking charger"]',{timeout:15000});
   const ids=await page.$$eval('select[aria-label="Select booking charger"] option',
     xs=>xs.map(x=>x.value));
   assert.ok(ids.includes(chargerA)&&ids.includes(chargerB));
   assert.equal(await page.$eval('select[aria-label="Select booking charger"]',x=>x.value),'');
 });
 await page.select('select[aria-label="Select booking charger"]',chargerB);
 await clickText(page,'Check interrupted starts');
 await step('operator sees redacted interrupted-start report without resetting token',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('Review charger logs'),
      {timeout:15000});
   const body=await page.content();
   assert.ok(body.includes('Investigation required'));
   assert.ok(body.includes('credential')||body.includes('Review charger logs'));
   assert.ok(!body.includes('a'.repeat(64)));
 });
 await clickText(page,'Load earlier records');
 await step('failed audit page keeps existing incidents and offers retry',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('Audit page temporarily unavailable'),
     {timeout:15000});
   assert.ok((await page.content()).includes('Review charger logs'));
   await page.waitForFunction(()=>[...document.querySelectorAll('button')].some(x=>
     x.textContent?.includes('Load earlier records')&&!x.disabled));
 });
 await clickText(page,'Load earlier records');
 await step('audit cursor appends earlier incidents once and shows end of records',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('Older interrupted start requires review.'),
     {timeout:15000});
   const text=await page.evaluate(()=>document.body.innerText);
   assert.ok(text.includes('Review charger logs'));
   assert.match(text,/Records checked:\s*5/);
   assert.match(text,/Investigation required:\s*2/);
   assert.ok(text.includes('End of available records.'));
   assert.equal(text.includes('Load earlier records'),false);
   assert.equal(text.includes('Audit page temporarily unavailable'),false);
 });
 await clickText(page,'Issue one-time authorization token');
 await step('token issued only for chosen second charger',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('Token already issued.'),{timeout:9000}).catch(async error=>{
     const detail=await page.evaluate(()=>({errors:[...document.querySelectorAll('[role="alert"]')].map(x=>x.textContent?.slice(0,200)),labels:[...document.querySelectorAll('button')].filter(x=>x.textContent?.includes('token')).map(x=>({label:x.textContent?.trim(),disabled:x.disabled})),state:document.body.innerText.slice(-750)}));
     console.error('Staged token UI diagnostic',JSON.stringify({stage,issuedTo,detail}));
     throw error;
   });
   assert.equal(stage,1);
   assert.equal(issuedTo,chargerB);
   const exposed=await page.evaluate(()=>({
     token:window.localStorage.getItem('admin_token'),
     values:Object.values(window.localStorage)
   }));
   assert.equal(exposed.token,qaToken);
   assert.equal(exposed.values.some(x=>x.includes(fakeToken)),false);
 });
 await step('only the pinned charger source is selectable',async()=>{
   await page.waitForSelector('select[aria-label="Select source transaction"]',{timeout:15000});
   const options=await page.$$eval('select[aria-label="Select source transaction"] option',
     xs=>xs.map(x=>x.value));
   assert.ok(options.includes(sourceB));
   assert.ok(!options.includes(sourceA));
 });
 await page.select('select[aria-label="Select source transaction"]',sourceB);
 await clickText(page,'Bind verified source');
 await step('binding updates verified metering and unpaid status',async()=>{
   await page.waitForFunction(()=>[...document.querySelectorAll('button')]
     .some(x=>x.textContent?.includes('Finalize verified booking') && !x.disabled),
     {timeout:15000});
   assert.equal(stage,2);
   assert.ok((await page.content()).includes('7.56'));
   assert.ok((await page.content()).toLowerCase().includes('unpaid'));
 });
 await clickText(page,'Finalize verified booking');
 await step('finalization shows completed booking without collected charge',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('Booking finalized') && document.body.innerText.includes('Completed'),{timeout:15000});
   assert.equal(stage,3);
   assert.ok((await page.content()).includes('Completed'));
   assert.ok((await page.content()).includes('not collected'));
 });
 await page.click('button[aria-label="Close OCPP operations"]');
 await step('one-time secret cleared from rendered UI and browser storage',async()=>{
   await page.waitForFunction(()=>!document.querySelector('[aria-label="Booking OCPP operations"]'),{timeout:15000});
   assert.ok(!(await page.content()).includes(fakeToken));
   const values=await page.evaluate(()=>Object.values(window.localStorage));
   assert.ok(!values.some(v=>v.includes(fakeToken)));
   assert.ok(requestAuthorizationCount>=6);
 });
 stage=0;issuedTo=null;holdIssueResponse=true;
 await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
 await page.waitForSelector('button[aria-label^="OCPP operations"]');
 await page.click('button[aria-label^="OCPP operations"]');
 await page.waitForSelector('select[aria-label="Select booking charger"]');
 await page.select('select[aria-label="Select booking charger"]',chargerB);
 const beforeHidden=issueRequests;
 await clickText(page,'Issue one-time authorization token');
 for(let i=0;i<50&&!issueResponseRelease;i++)await new Promise(done=>setTimeout(done,50));
 assert.equal(typeof issueResponseRelease,'function');
 await page.evaluate(()=>{
   Object.defineProperty(document,'visibilityState',{configurable:true,get:()=> 'hidden'});
   document.dispatchEvent(new Event('visibilitychange'));
 });
 holdIssueResponse=false;
 await issueResponseRelease();issueResponseRelease=null;
 await step('credential delivered after tab hide is discarded without exposing a copy action',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('Token already issued.'),
     {timeout:15000});
   await page.evaluate(()=>{
     delete document.visibilityState;
     document.dispatchEvent(new Event('visibilitychange'));
   });
   assert.equal(await page.$('button[aria-label="Reveal token"]'),null);
   assert.equal(await page.$('button[aria-label="Copy one-time token"]'),null);
   assert.equal((await page.content()).includes(fakeToken),false);
   const values=await page.evaluate(()=>[...Object.values(localStorage),...Object.values(sessionStorage)]);
   assert.equal(values.some(x=>x.includes(fakeToken)),false);
   assert.equal(issueRequests-beforeHidden,1);
   assert.ok((await page.content()).includes('credential was discarded'));
 });
 await page.click('button[aria-label="Close OCPP operations"]');
 stage=0;issuedTo=null;failIssueResponse=true;
 await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
 await page.waitForSelector('button[aria-label^="OCPP operations"]');
 await page.click('button[aria-label^="OCPP operations"]');
 await page.waitForSelector('select[aria-label="Select booking charger"]');
 await page.select('select[aria-label="Select booking charger"]',chargerB);
 const beforeUncertain=issueRequests;
 await clickText(page,'Issue one-time authorization token');
 await step('uncertain mutation refreshes authority before allowing another issue action',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('Authorization outcome uncertain'),
     {timeout:15000});
   await page.waitForFunction(()=>document.body.innerText.includes('Token already issued.'),
     {timeout:15000});
   const enabled=await page.evaluate(()=>[...document.querySelectorAll('button')].some(x=>
     x.textContent?.includes('Issue one-time authorization token')&&!x.disabled));
   assert.equal(enabled,false);
   assert.equal(await page.$('button[aria-label="Copy one-time token"]'),null);
   assert.equal(issueRequests-beforeUncertain,1);
 });
 await page.click('button[aria-label="Close OCPP operations"]');
 registryReview=true;stage=0;failIssueResponse=false;
 const beforeRegistryReview=issueRequests;
 await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
 await page.waitForSelector('button[aria-label^="OCPP operations"]');
 await page.click('button[aria-label^="OCPP operations"]');
 await step('malformed charger registry explains review and cannot issue a credential',async()=>{
   await page.waitForFunction(()=>document.body.innerText.includes('protocol records need operator review'));
   assert.equal(await page.$('select[aria-label="Select booking charger"]'),null);
   const actionable=await page.evaluate(()=>[...document.querySelectorAll('button')].some(x=>
     (x.textContent?.includes('Issue one-time authorization token')||x.textContent?.includes('Reissue expired unused token'))&&!x.disabled));
   assert.equal(actionable,false);assert.equal(issueRequests,beforeRegistryReview);
   assert.equal((await page.content()).includes(fakeToken),false);
 });
 await page.click('button[aria-label="Close OCPP operations"]');
 registryReview=false;
 for(const patch of [{bookingId:station},{chargerId:chargerA},{connectorScoped:true,
   resourceScope:{protocol:'ocpp2.0.1',connectorId:2,evseId:3}}]){
   stage=0;receiptPatch=patch;
   await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
   await page.waitForSelector('button[aria-label^="OCPP operations"]');
   await page.click('button[aria-label^="OCPP operations"]');
   await page.waitForSelector('select[aria-label="Select booking charger"]');
   await page.select('select[aria-label="Select booking charger"]',chargerB);
   const beforeReceipt=issueRequests;
   await clickText(page,'Issue one-time authorization token');
   await step('mismatched authorization receipt is discarded and status is reread without replay '+JSON.stringify(patch),async()=>{
     await page.waitForFunction(()=>document.body.innerText.includes('Authorization receipt did not match'));
     await page.waitForFunction(()=>document.body.innerText.includes('Token already issued.'));
     assert.equal(await page.$('button[aria-label="Copy one-time token"]'),null);
     assert.equal(await page.$('button[aria-label="Reveal token"]'),null);
     assert.equal((await page.content()).includes(fakeToken),false);
     assert.equal(issueRequests-beforeReceipt,1);
   });
   await page.click('button[aria-label="Close OCPP operations"]');
 }
 receiptPatch={};
 for(const patch of [{bookingId:station},{stationId:id}]){
   stage=0;operationPatch=patch;
   await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
   await page.waitForSelector('button[aria-label^="OCPP operations"]');
   const beforeRead=sourceListReads;
   await page.click('button[aria-label^="OCPP operations"]');
   await step('wrong booking or station operations never load source records '+JSON.stringify(patch),async()=>{
     await page.waitForFunction(()=>document.body.innerText.includes('did not match the requested booking'));
     assert.equal(sourceListReads,beforeRead);
     assert.equal(await page.$('select[aria-label="Select booking charger"]'),null);
   });
   await page.click('button[aria-label="Close OCPP operations"]');
 }
 operationPatch={};stage=0;holdOperations=true;
 await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
 await page.waitForSelector('button[aria-label^="OCPP operations"]');
 await page.click('button[aria-label^="OCPP operations"]');
 for(let i=0;i<50&&!operationsRelease;i++)await new Promise(done=>setTimeout(done,50));
 assert.equal(typeof operationsRelease,'function');
 await page.click('button[aria-label="Close OCPP operations"]');
 holdOperations=false;stage=1;
 await page.click('button[aria-label^="OCPP operations"]');
 await page.waitForFunction(()=>document.body.innerText.includes('Token already issued.'));
 const oldRead=page.waitForResponse(r=>new URL(r.url()).pathname.endsWith('/ocpp/operations'));
 await operationsRelease();operationsRelease=null;await oldRead;
 await page.evaluate(()=>new Promise(done=>requestAnimationFrame(()=>requestAnimationFrame(done))));
 await step('late closed-dialog read cannot replace the reopened authoritative state',async()=>{
   const active=await page.evaluate(()=>[...document.querySelectorAll('button')].some(x=>
     x.textContent?.includes('Issue one-time authorization token')&&!x.disabled));
   assert.equal(active,false);assert.ok((await page.content()).includes('Token already issued.'));
 });
 await page.click('button[aria-label="Close OCPP operations"]');
 stage=0;holdIssueResponse=true;
 await page.goto(base+'/bookings',{waitUntil:'networkidle2'});
 await page.waitForSelector('button[aria-label^="OCPP operations"]');
 await page.click('button[aria-label^="OCPP operations"]');
 await page.waitForSelector('select[aria-label="Select booking charger"]');
 await page.select('select[aria-label="Select booking charger"]',chargerB);
 const beforeSwitch=issueRequests;
 await clickText(page,'Issue one-time authorization token');
 for(let i=0;i<50&&!issueResponseRelease;i++)await new Promise(done=>setTimeout(done,50));
 assert.equal(typeof issueResponseRelease,'function');
 await page.evaluate(()=>{localStorage.setItem('admin_token','different-synthetic-admin');
   window.dispatchEvent(new StorageEvent('storage',{key:'admin_token'}));});
 holdIssueResponse=false;await issueResponseRelease();issueResponseRelease=null;
 await step('Admin identity switch discards delayed token delivery and closes private dialog',async()=>{
   await page.waitForFunction(()=>!document.querySelector('[aria-label="Booking OCPP operations"]'));
   assert.equal(await page.$('button[aria-label="Copy one-time token"]'),null);
   assert.equal((await page.content()).includes(fakeToken),false);
   assert.equal(issueRequests-beforeSwitch,1);
 });
 await page.close();
 console.log('STAGED_NEXT_ADMIN_BROWSER_TESTS_PASSED '+passed);
}finally{
 await browser.close();
}

