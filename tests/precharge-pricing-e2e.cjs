'use strict';
const {execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
let stage='fixture';
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const f=JSON.parse(execFileSync('./scripts/php',['tests/Fixtures/precharge-pricing.php',run,password],{encoding:'utf8'}));assert(f.fixture_only && f.real_money===false);
 stage='login';
 const c=new BusinessClient();c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email:`canonical-customer-${run}@example.test`,password});c.csrf=(await c.request('csrf')).token;
 stage='unaccepted_start';
 await assert.rejects(c.request(`reservations/${f.reservation_id}/simulate-start`,'POST'),/409/);
 stage='browser';
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try {
  const ctx=await browser.newContext();await ctx.addCookies([...c.cookies].map(([name,value])=>({name,value,url:c.base})));
  const page=await ctx.newPage();page.setDefaultTimeout(20000);
  let prompt=0,confirmation=0,decline=true;
  const priceWrites=[];
  page.on('request',request=>{
   const path=new URL(request.url()).pathname;
   const prefix='/api/reservations/'+f.reservation_id+'/';
   if(request.method()==='POST'&&path.startsWith(prefix)&&
      ['price-estimate','price-consent','simulate-start'].includes(path.slice(prefix.length)))
     priceWrites.push(path.slice(prefix.length));
  });
  page.on('dialog',async d=>{
   if(d.type()==='prompt'){assert.match(d.message(),/Estimated energy in Wh/);prompt++;await d.accept('1500');}
   else {assert.equal(d.type(),'confirm');assert.match(d.message(),/AED 3.96/);assert.match(d.message(),/not a spending cap/);assert.match(d.message(),/Simulator session\/idle fees: 0/);assert.match(d.message(),/External parking\/access fees are excluded/);assert.match(d.message(),/No real payment occurs/);confirmation++;decline?await d.dismiss():await d.accept();}
  });
  stage='display';
  await page.goto(c.base);await page.locator('#reservation-list article').filter({hasText:'#'+f.reservation_id+' ·'}).waitFor();
  const card=page.locator('#reservation-list article').filter({hasText:'#'+f.reservation_id+' ·'});
  stage='decline';
  const preview=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/reservations/${f.reservation_id}/price-estimate`);
  await card.getByRole('button',{name:'Review charging price',exact:true}).click();assert.equal((await preview).status(),200);
  await page.waitForFunction(()=>!Array.from(document.querySelectorAll('#reservation-list button')).find(x=>x.textContent==='Review charging price')?.disabled);
  assert.equal(confirmation,1);assert(!(await c.request('simulations')).data.some(x=>x.reservation_id===f.reservation_id));
  // A separate review can be declined, but so must the actual Start action.
  // Exercise the customer-facing gate, not just a direct API start.
  stage='decline_start';
  const startButton=card.getByRole('button',{name:'Start simulator',exact:true});
  const declinedStartPreview=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/reservations/${f.reservation_id}/price-estimate`);
  await startButton.click();assert.equal((await declinedStartPreview).status(),200);
  await page.waitForFunction(id=>{
   const card=Array.from(document.querySelectorAll('#reservation-list article')).find(a=>a.textContent.includes('#'+id+' ·'));
   return card && Array.from(card.querySelectorAll('button')).some(b=>b.textContent==='Start simulator'&&!b.disabled);
  },f.reservation_id);
  assert.equal(confirmation,2);
  assert.deepEqual(priceWrites,['price-estimate','price-estimate'],'Declining either review must never approve a quote or issue a start');
  assert(!(await c.request('simulations')).data.some(x=>x.reservation_id===f.reservation_id));
  stage='accept';
  decline=false;
  const consent=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/reservations/${f.reservation_id}/price-consent`);
  await card.getByRole('button',{name:'Review charging price',exact:true}).click();assert.equal((await consent).status(),200);
  assert.equal(prompt,3);assert.equal(confirmation,3);
  assert.deepEqual(priceWrites,['price-estimate','price-estimate','price-estimate','price-consent']);
  assert(!(await c.request('simulations')).data.some(x=>x.reservation_id===f.reservation_id),'Standalone review must not start charging');
  stage='accepted_start';
  const startConsent=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/reservations/${f.reservation_id}/price-consent`);
  const startResult=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/reservations/${f.reservation_id}/simulate-start`);
  await startButton.click();
  assert.equal((await startConsent).status(),200);
  const started=await startResult;assert.equal(started.status(),201);
  const session=await started.json();assert.equal(session.currency,'AED');
  assert.equal(prompt,4);assert.equal(confirmation,4);
  assert.deepEqual(priceWrites,['price-estimate','price-estimate','price-estimate','price-consent','price-estimate','price-consent','simulate-start'],'Start must follow the explicit current price consent, exactly once');
  stage='replay';
  const replay=await c.request(`reservations/${f.reservation_id}/simulate-start`,'POST');assert.equal(session.id,replay.id);
  const existing=await c.request(`reservations/${f.reservation_id}/price-estimate`,'POST',{energy_wh:10000});assert.equal(existing.existing_session_id,session.id);assert.equal(existing.quote_token,undefined);
  stage='meter';
  const vendor=new BusinessClient();vendor.csrf=(await vendor.request('csrf')).token;await vendor.request('login','POST',{email:`canonical-owner-${run}@example.test`,password});vendor.csrf=(await vendor.request('csrf')).token;
  await vendor.request(`simulations/${session.id}/meter`,'PUT',{energy_wh:1500});
  const projected=(await c.request('simulations')).data.find(row=>row.id===session.id).cost_projection;
  assert.equal(projected.state,'estimated');assert.equal(projected.total_minor,'396');assert.equal(projected.tax_minor,'19');
  stage='stop';
  await c.request(`simulations/${session.id}/stop`,'POST');
  stage='invoice';
  const issued=await c.request(`finance/sessions/${session.id}/invoice`,'POST');
  const invoiced=(await c.request('simulations')).data.find(row=>row.id===session.id).cost_projection;
  assert.equal(invoiced.state,'invoiced');assert.equal(invoiced.invoice_id,issued.invoice.id);assert.equal(invoiced.total_minor,String(issued.invoice.total_minor));
  await page.reload();await page.locator('#simulation-list').getByText(/Simulated invoice total: AED 3.96/).waitFor();
  console.log('Actual Laravel/browser pre-charge review, decline, explicit acceptance, metered estimate, canonical invoice and start replay passed (synthetic; no payment or hardware).');
 } finally {await browser.close();}
})().catch(error=>{const name=['AssertionError','TimeoutError','Error'].includes(error?.name)?error.name:'Error';console.error(JSON.stringify({suite:'synthetic_precharge',stage,error:name}));process.exit(1);});
