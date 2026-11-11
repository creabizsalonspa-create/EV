// Actual local garage -> vehicle-aware future booking. No start/payment mutation.
const fs = require('node:fs'), path = require('node:path');
const assert = require('node:assert/strict');
const {randomUUID} = require('node:crypto');
const {chromium} = require('playwright');
const {BusinessClient} = require('./helpers/business-client.cjs');
(async()=>{
 const root=path.resolve('artifacts/client-demo');
 const file=fs.realpathSync(process.env.EV_CLIENT_DEMO_MANIFEST || JSON.parse(fs.readFileSync(path.join(root,'current.json'))).manifest);
 assert(file.startsWith(root+path.sep) && (fs.statSync(file).mode&0o077)===0);
 const fixture=JSON.parse(fs.readFileSync(file)); assert(fixture.fixture_only && !fixture.real_money);
 const clients={};
 for (const role of ['customer','vendor','admin']) {
  const c=new BusinessClient();c.csrf=(await c.request('csrf')).token;
  await c.request('login','POST',fixture.accounts[role]);c.csrf=(await c.request('csrf')).token;clients[role]=c;
 }
 const target=fixture.stations[0];let station;
 for(let page=1;page<=20;page++) {
  const result=await clients.customer.request('stations?page='+page);
  station=result.data.find(s=>s.id===target.station_id);if(station||page>=result.last_page)break;
 }
 assert(station, 'Owned fixture station must be publicly discoverable');
 const options = await clients.customer.request(`stations/${station.id}/connectors`);
 const expectedMapping = options.data.find(row => row.id === target.connector_mapping_id);
 assert(expectedMapping && expectedMapping.reservable === true,
  'Owned fixture mapping must be explicitly reservable before the browser journey');

 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']});
 let booking=null;
 try {
  const ctx=await browser.newContext();await ctx.addCookies([...clients.customer.cookies].map(([name,value])=>({name,value,url:clients.customer.base})));
  const p=await ctx.newPage();p.setDefaultTimeout(20000);
  const ready=p.waitForResponse(r=>new URL(r.url()).pathname==='/api/notifications');
  await p.goto(clients.customer.base);await ready;
  const garage=p.locator('#vehicle-create');
  const name='Booking EV '+randomUUID().slice(0,8);
  await garage.locator('[name=name]').fill(name);
  const connector=station.connector_type;
  const field=garage.locator('[name=connector_type]');
  if(await field.evaluate(e=>e.tagName==='SELECT'))await field.selectOption(connector);else await field.fill(connector);
  const created=p.waitForResponse(r=>new URL(r.url()).pathname==='/api/vehicles'&&r.request().method()==='POST');
  await garage.locator('button').click();const vehicleResponse=await created;assert.equal(vehicleResponse.status(),201);
  const vehicle=await vehicleResponse.json();
  await p.evaluate(q=>stations('?q='+encodeURIComponent(q)),station.name);
  const card=p.locator(`#stations [data-station-id="${station.id}"]`);
  assert.equal(await p.evaluate(() => user?.role), 'customer');
  const connectorRead = p.waitForResponse(r =>
   new URL(r.url()).pathname === `/api/stations/${station.id}/connectors` &&
   r.request().method() === 'GET');
  await card.getByRole('button',{name:'Choose connector',exact:true}).click();
  const connectorResponse = await connectorRead;
  assert.equal(connectorResponse.status(), 200, 'Connector discovery must succeed before vehicle selection');
  const visibleOptions = await connectorResponse.json();
  const visibleMapping = visibleOptions.data.find(row => row.id === target.connector_mapping_id);
  assert(visibleMapping && visibleMapping.reservable === true,
   'The browser must receive the same available canonical mapping');
  const form=card.locator(`[data-mapping-id="${target.connector_mapping_id}"] form`);
  try {
   await form.getByRole('button',{name:'Load compatible vehicles',exact:true}).click();
  } catch (error) {
   // Retain only bounded public fixture state; no cookies, accounts, text,
   // payloads or private browser screenshots enter CI diagnostics.
   console.error(JSON.stringify({stage:'vehicle_picker',station_id:station.id,
    mapping_id:target.connector_mapping_id,reservable:visibleMapping.reservable,
    role:await p.evaluate(()=>['customer','vendor','admin'].includes(user?.role)?user.role:'none'),
    locale:await p.locator('html').getAttribute('lang'),
    matching_forms:await form.count()}));
   throw error;
  }
  await form.locator(`[name=vehicle_id] option[value="${vehicle.id}"]`).waitFor({state:'attached'});
  await form.locator('[name=vehicle_id]').selectOption(String(vehicle.id));
  const day=new Date(Date.now()+86400000).toISOString().slice(0,10);
  await form.locator('input[type=datetime-local]').fill(day+'T12:00');
  const reserved=p.waitForResponse(r=>new URL(r.url()).pathname==='/api/reservations'&&r.request().method()==='POST');
  await form.getByRole('button',{name:'Reserve simulator slot',exact:true}).click();
  const response=await reserved;assert.equal(response.status(),201);booking=await response.json();
  assert.equal(booking.vehicle_id,vehicle.id);assert.equal(booking.vehicle_connector_type,connector);
  fs.writeFileSync(path.join(path.dirname(file),'vehicle-booking-proof.json'),JSON.stringify({booking_id:booking.id,vehicle_id:vehicle.id,station_id:station.id,future_slot:booking.starts_at,browser_created_vehicle:true,browser_created_booking:true,charging_started:false,payment_processed:false},null,2)+'\n',{mode:0o600});
  for(const role of ['customer','vendor','admin']) {
   const data=await clients[role].request('reservations');const row=data.data.find(r=>r.id===booking.id);
   assert(row);assert.equal(row.vehicle_connector_type,connector);
   if(role==='customer')assert.equal(row.vehicle_label,name);else assert(!('vehicle_label' in row));
   if(role==='vendor')assert(!('vehicle_id' in row));
  }
  const after=p.waitForResponse(r=>new URL(r.url()).pathname==='/api/reservations'&&r.request().method()==='GET');
  await p.evaluate(()=>bookings());await after;
  assert.match(await p.locator('#reservation-list').innerText(),new RegExp(name));
  console.log('Actual browser garage creation -> selected owned vehicle -> future booking -> shared customer/vendor/Admin record passed. No charging/payment commands.');
 } finally {
  if(booking){const cancelled=await clients.customer.request('reservations/'+booking.id,'DELETE');assert.equal(cancelled.status,'cancelled');const proofFile=path.join(path.dirname(file),'vehicle-booking-proof.json');const proof=JSON.parse(fs.readFileSync(proofFile));fs.writeFileSync(proofFile,JSON.stringify({...proof,reservation_cancelled_after_proof:true},null,2)+'\n',{mode:0o600});}
  await browser.close();
 }
})().catch(e=>{console.error(e.message);console.error('Inspect retained fixture/booking state; never replay unknown source commands.');process.exitCode=1;});
