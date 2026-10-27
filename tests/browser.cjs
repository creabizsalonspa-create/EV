const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 const base=process.env.EV_TEST_URL||'http://127.0.0.1:8000';
 const run=randomUUID().slice(0,8),password=randomUUID()+'aA9!';
 const errors=[];
 async function account(name){
  const context=await browser.newContext();const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base);await page.locator('#account [name=name]').fill(name);
  const email=name.toLowerCase()+run+'@example.test';await page.locator('#account [name=email]').fill(email);await page.locator('#account [name=password]').fill(password);
  await page.locator('button[value=register]').click();await page.locator('#signed').waitFor({state:'visible'});return {page,email};
 }
 try {
  const vendor=await account('Vendor');
  execFileSync('./scripts/php',['artisan','platform:role',vendor.email,'vendor'],{stdio:'pipe'});
  await vendor.page.reload();await vendor.page.locator('#vendor').waitFor({state:'visible'});
  const f=vendor.page.locator('#station-form');
  for(const [key,value] of Object.entries({name:'Browser station '+run,latitude:'25.2',longitude:'55.3',power_kw:'50'}))await f.locator(`[name=${key}]`).fill(value);
  await f.locator('[name=source]').selectOption('simulator');await f.locator('button').click();await vendor.page.getByRole('heading',{name:'Browser station '+run,exact:true}).waitFor();
  const customer=await account('Customer');
  const article=customer.page.locator('#stations article').filter({hasText:'Browser station '+run});
  const slot=new Date(Date.now()+86400000);slot.setUTCMinutes(0,0,0);
  await article.locator('input[type=datetime-local]').fill(slot.toISOString().slice(0,16));
  await article.getByRole('button',{name:'Reserve simulator slot'}).click();await customer.page.locator('#reservation-list article').waitFor();
  await vendor.page.reload();await vendor.page.locator('#reservation-list article').waitFor();
  await customer.page.locator('#reservation-list button').filter({hasText:'Cancel'}).click();
  await customer.page.waitForFunction(()=>document.querySelector('#reservation-list').textContent.includes('cancelled'));
  await customer.page.locator('#language').click();assert.equal(await customer.page.locator('html').getAttribute('dir'),'rtl');
  await customer.page.setViewportSize({width:390,height:844});assert(await customer.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  const outsider=await account('Outsider');assert.equal(await outsider.page.locator('#reservation-list article').count(),0);
  execFileSync('./scripts/php',['artisan','platform:role',vendor.email,'admin'],{stdio:'pipe'});await vendor.page.reload();await vendor.page.locator('#admin').waitFor({state:'visible'});
  await vendor.page.waitForFunction(()=>document.querySelector('#overview').textContent.includes('audit_events'));
  assert.deepEqual(errors,[]);console.log('PASS: browser registration, vendor station creation, customer reservation/cancel, vendor visibility, tenant privacy, admin overview, Arabic RTL, mobile width; no page errors.');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
