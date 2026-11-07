/**
 * Actual Chrome -> loopback Express -> disposable Mongo -> OCPP handler journey.
 * Called inside the isolated backend harness; no credentials are printed or
 * passed on the command line. The disposable browser uses normal Admin storage;
 * the one-time charger token is never written to browser storage.
 * The transport relays actual API responses. It supplies no mocked API bodies.
 */
import assert from 'node:assert/strict';
import {loopbackBase,relayAdminRequest} from './isolatedAdminApiRelay.mjs';
export async function runActualAdminBrowser({
 uiUrl,apiUrl,adminToken,bookingId,chargerId,createSource,verifyResult
}){
 const ui=loopbackBase(uiUrl),api=loopbackBase(apiUrl);
 assert.match(bookingId,/^[a-f0-9]{24}$/i);
 assert.equal(typeof createSource,'function');
 assert.equal(typeof verifyResult,'function');
 const imported=await import(process.env.PUPPETEER_PATH||'puppeteer');
 const browser=await (imported.default||imported).launch({
   executablePath:process.env.CHROME_PATH||undefined,headless:true,
   args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']
 });
 let passed=0,relayError=null,calls=0,mutations=0;
 const step=async(label,fn)=>{await fn();passed++;console.log('PASS real-api '+label);};
 const click=async(page,label)=>{
   await page.waitForFunction(text=>[...document.querySelectorAll('button')]
     .some(b=>b.textContent?.includes(text)&&!b.disabled),{timeout:15000},label);
   await page.evaluate(text=>{
     [...document.querySelectorAll('button')]
       .find(b=>b.textContent?.includes(text)&&!b.disabled)?.click();
   },label);
 };
 try{
   const page=await browser.newPage();
   await page.evaluateOnNewDocument(token=>{
     localStorage.setItem('admin_token',token);
     localStorage.setItem('admin_user',JSON.stringify({name:'Isolated real API test'}));
   },adminToken);
   await page.setRequestInterception(true);
   page.on('request',request=>{void (async()=>{
     const url=new URL(request.url());
     if(url.pathname.startsWith('/api/v1/')){
       const response=await relayAdminRequest({
         url:request.url(),method:request.method(),body:request.postData(),
         authorization:request.headers().authorization
       },{uiUrl:ui,apiUrl:api,adminToken});
       calls++;if(request.method()==='POST')mutations++;
       await request.respond(response);return;
     }
     if(url.origin!==ui && !['data:','blob:'].includes(url.protocol)){
       await request.abort('blockedbyclient');return;
     }
     await request.continue();
   })().catch(async()=>{
     // Do not log request contents, credentials or provider response bodies.
     relayError=Error('Actual isolated API relay failed');
     await request.abort('failed').catch(()=>{});
   });});
   await page.goto(ui+'/bookings',{waitUntil:'networkidle2'});
   await step('real Admin listing exposes the seeded booking',async()=>{
     await page.waitForSelector('button[aria-label="OCPP operations for booking '+bookingId+'"]',
       {timeout:15000});
   });
   await page.click('button[aria-label="OCPP operations for booking '+bookingId+'"]');
   await step('actual booking state allows authorization',async()=>{
     await page.waitForFunction(()=>document.body.innerText.includes('Issue one-time authorization token'),
       {timeout:15000});
     const value=await page.$eval('select[aria-label="Select booking charger"]',x=>x.value);
     assert.equal(value,chargerId);
   });
   await step('operator explicitly scopes the credential to connector one',async()=>{
     await page.click('input[aria-label="Limit authorization to connector"]');
     await page.type('input[aria-label="Booking connector ID"]','1');
     assert.equal(await page.$eval('input[aria-label="Booking connector ID"]',x=>x.value),'1');
   });
   await click(page,'Issue one-time authorization token');
   let rawToken='';
   await step('UI issues one credential through real Express authentication',async()=>{
     await page.waitForSelector('button[aria-label="Reveal token"]',{timeout:15000});
     await page.click('button[aria-label="Reveal token"]');
     rawToken=await page.$eval('code',x=>x.textContent.trim());
     assert.match(rawToken,/^[0-9A-F]{20}$/);
     const stored=await page.evaluate(()=>[...Object.values(localStorage),...Object.values(sessionStorage)]);
     assert.equal(stored.some(x=>x.includes(rawToken)),false);
   });
   const sourceId=await createSource(rawToken);
   rawToken='';
   await page.click('button[aria-label="Refresh OCPP status"]');
   await step('actual OCPP handler transaction appears in source selector',async()=>{
     await page.waitForFunction(id=>[...document.querySelectorAll(
       'select[aria-label="Select source transaction"] option')].some(x=>x.value===id),
       {timeout:15000},sourceId);
   });
   await page.select('select[aria-label="Select source transaction"]',sourceId);
   await click(page,'Bind verified source');
   await step('actual Mongo source binding unlocks finalization',async()=>{
     await page.waitForFunction(()=>[...document.querySelectorAll('button')].some(b=>
       b.textContent?.includes('Finalize verified booking')&&!b.disabled),{timeout:15000});
   });
   await click(page,'Finalize verified booking');
   await step('UI completion and persisted source agree without collected funds',async()=>{
     await page.waitForFunction(()=>document.body.innerText.includes('Booking finalized'),
       {timeout:15000});
     await verifyResult(sourceId);
     assert.ok((await page.content()).includes('not collected'));
   });
   await page.click('button[aria-label="Close OCPP operations"]');
   await step('dialog closes and actual API relay stayed within approved routes',async()=>{
     await page.waitForFunction(()=>!document.querySelector('[aria-label="Booking OCPP operations"]'));
     assert.equal(relayError,null);
     assert.ok(calls>=7);
   });
   await page.goto(ui+'/ocpp-history',{waitUntil:'networkidle2'});
   await step('actual history list and evidence inspector agree with completed booking',async()=>{
     const selector='tr[data-booking-id="'+bookingId+'"] button';
     await page.waitForSelector(selector,{timeout:15000});
     await page.click(selector);
     await page.waitForSelector('[aria-label="Source evidence details"]',{timeout:15000});
     const text=await page.$eval('[aria-label="Source evidence details"]',x=>x.innerText);
     assert.ok(text.includes('5 kWh'));
     assert.ok(text.includes('5.25 AED'));
     assert.ok(text.includes('Not collected'));
   });
   await step('history supports Arabic without changing records or issuing mutations',async()=>{
     const beforeLanguage=mutations;
     await page.$eval('button[aria-label="Change history language"]',button=>
       button.scrollIntoView({block:'center',inline:'nearest'}));
     await new Promise(done=>setTimeout(done,100));
     const clickable=await page.$eval('button[aria-label="Change history language"]',button=>{
       const r=button.getBoundingClientRect();
       const hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);
       return hit===button || button.contains(hit);
     });
     assert.equal(clickable,true,'History language control must be pointer accessible');
     await page.click('button[aria-label="Change history language"]');
     await page.waitForFunction(()=>document.querySelector('[dir="rtl"]') &&
       document.body.innerText.includes('سجل مصادر الشحن'),{timeout:15000}).catch(async error=>{
       console.error('History language diagnostic',JSON.stringify(await page.evaluate(()=>({
         dirs:[...document.querySelectorAll('[dir]')].map(x=>x.getAttribute('dir')),
         headings:[...document.querySelectorAll('h1')].map(x=>x.textContent),
         languageButtons:[...document.querySelectorAll('[aria-label="Change history language"]')]
           .map(x=>x.textContent)
       }))));
       throw error;
     });
     assert.equal(relayError,null);
     assert.equal(mutations,beforeLanguage);
   });
   console.log('ACTUAL_ISOLATED_ADMIN_BROWSER_TESTS_PASSED '+passed);
   return passed;
 }finally{await browser.close();}
}

