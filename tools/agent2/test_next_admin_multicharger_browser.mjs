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
const fakeToken='abcdef123456'.repeat(4);
let stage=0,requestAuthorizationCount=0;
let issuedTo=null;
const qaToken='agent2-ui-test-token-not-production';
function result(data,status=200){
 return {status,headers:{'content-type':'application/json','cache-control':'no-store'},
  body:JSON.stringify({status:'Success',data})};
}
function operations(){
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
  canIssueToken:stage===0,
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
       response=result({sessions:[
         {id:sourceA,chargerId:chargerA,protocol:'ocpp1.6',
          transactionId:'tx-first',status:'ended',energyKwh:5,paymentStatus:'not_collected'},
         {id:sourceB,chargerId:chargerB,protocol:'ocpp2.0.1',
          transactionId:'tx-second',status:'ended',energyKwh:8,paymentStatus:'not_collected'}
       ]});
     }else if(path==='/api/v1/admin/bookings/'+id+'/ocpp/issue-token'
         && request.method()==='POST'){
       const body=JSON.parse(request.postData()||'{}');
       assert.equal(body.chargerId,chargerB,'Booking must explicitly select second charger');
       issuedTo=body.chargerId;
       stage=1;
       response=result({oneTimeToken:fakeToken,chargerId:chargerB,
         expiresAt:'2026-11-07T10:20:00.000Z'},201);
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
   await page.waitForFunction(()=>document.body.innerText.includes('Booking finalized'),{timeout:15000});
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
 await page.close();
 console.log('STAGED_NEXT_ADMIN_BROWSER_TESTS_PASSED '+passed);
}finally{
 await browser.close();
}
