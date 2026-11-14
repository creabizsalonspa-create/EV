'use strict';
const {execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const f=JSON.parse(execFileSync('./scripts/php',['tests/Fixtures/precharge-pricing.php',run,password],{encoding:'utf8'}));assert(f.fixture_only && f.real_money===false);
 const c=new BusinessClient();c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email:`canonical-customer-${run}@example.test`,password});c.csrf=(await c.request('csrf')).token;
 await assert.rejects(c.request(`reservations/${f.reservation_id}/simulate-start`,'POST'),/409/);
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try {
  const ctx=await browser.newContext();await ctx.addCookies([...c.cookies].map(([name,value])=>({name,value,url:c.base})));
  const page=await ctx.newPage();page.setDefaultTimeout(20000);
  let prompt=0,confirmation=0,decline=true;
  page.on('dialog',async d=>{
   if(d.type()==='prompt'){assert.match(d.message(),/Estimated energy in Wh/);prompt++;await d.accept('1500');}
   else {assert.equal(d.type(),'confirm');assert.match(d.message(),/AED 3.96/);assert.match(d.message(),/not a spending cap/);confirmation++;decline?await d.dismiss():await d.accept();}
  });
  await page.goto(c.base);await page.locator('#reservation-list article').filter({hasText:'#'+f.reservation_id+' ·'}).waitFor();
  const card=page.locator('#reservation-list article').filter({hasText:'#'+f.reservation_id+' ·'});
  const preview=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/reservations/${f.reservation_id}/price-estimate`);
  await card.getByRole('button',{name:'Review charging price',exact:true}).click();assert.equal((await preview).status(),200);
  await page.waitForFunction(()=>!Array.from(document.querySelectorAll('#reservation-list button')).find(x=>x.textContent==='Review charging price')?.disabled);
  assert.equal(confirmation,1);assert(!(await c.request('simulations')).data.some(x=>x.reservation_id===f.reservation_id));
  decline=false;
  const consent=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/reservations/${f.reservation_id}/price-consent`);
  await card.getByRole('button',{name:'Review charging price',exact:true}).click();assert.equal((await consent).status(),200);
  assert.equal(prompt,2);assert.equal(confirmation,2);
  const session=await c.request(`reservations/${f.reservation_id}/simulate-start`,'POST');assert.equal(session.currency,'AED');
  const replay=await c.request(`reservations/${f.reservation_id}/simulate-start`,'POST');assert.equal(session.id,replay.id);
  const existing=await c.request(`reservations/${f.reservation_id}/price-estimate`,'POST',{energy_wh:10000});assert.equal(existing.existing_session_id,session.id);assert.equal(existing.quote_token,undefined);
  await c.request(`simulations/${session.id}/stop`,'POST');
  console.log('Actual Laravel/browser pre-charge review, decline, explicit acceptance and canonical start replay passed (synthetic; no payment or hardware).');
 } finally {await browser.close();}
})().catch(()=>{console.error('Synthetic pre-charge browser acceptance failed.');process.exit(1);});
