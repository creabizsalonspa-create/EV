// Local primary-platform proof; never sends hardware or payment commands.
const { BusinessClient } = require('./helpers/business-client.cjs');
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const assert = require('node:assert/strict');
(async () => {
 const root = path.resolve('artifacts/client-demo');
 const file = fs.realpathSync(JSON.parse(fs.readFileSync(path.join(root,'current.json'))).manifest);
 assert(file.startsWith(root + path.sep) && (fs.statSync(file).mode & 0o077) === 0);
 const fixture = JSON.parse(fs.readFileSync(file));
 assert(fixture.fixture_only && !fixture.real_money);
 const client = new BusinessClient();
 client.csrf = (await client.request('csrf')).token;
 await client.request('login','POST',fixture.accounts.customer);
 client.csrf = (await client.request('csrf')).token;
 let vehicle = (await client.request('vehicles')).data[0];
 if (!vehicle) vehicle = await client.request('vehicles','POST',{name:'Demo EV','connector_type':'CCS2'});
 assert(vehicle.can_simulate_battery !== false);
 const body = {operation_key:randomUUID(),battery_percent:15};
 const first = await client.request(`vehicles/${vehicle.id}/battery-simulation`,'POST',body);
 const replay = await client.request(`vehicles/${vehicle.id}/battery-simulation`,'POST',body);
 assert.equal(first.notification_id,replay.notification_id);
 const notification = (await client.request('notifications')).data.find(x=>x.id===first.notification_id);
 assert.equal(notification.kind,'vehicle.battery.simulated');
 assert.equal(notification.metadata.source,'simulator');
 const browser = await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH || '/usr/bin/chromium',args:['--no-sandbox']});
 try {
  const context = await browser.newContext();
  await context.addCookies([...client.cookies].map(([name,value])=>({name,value,url:client.base})));
  const page = await context.newPage();
  await page.goto(client.base);
  await page.waitForFunction(()=>typeof garage==='function' && user?.role==='customer');
  await page.evaluate(async()=>{await garage();await support();});
  assert.equal(await page.locator('#vehicle-list button').count()>0,true);
  assert.match(await page.locator('#notifications').innerText(),/Simulated low battery/);
  await context.close();
 } finally {await browser.close();}
 console.log('Persisted simulated battery -> replay -> actual web garage/inbox passed; no charging/payment mutation.');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
