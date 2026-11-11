// Real local Laravel + operator UI; no source command or financial mutation.
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const {BusinessClient} = require('./helpers/business-client.cjs');
const {randomUUID} = require('node:crypto');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const {execFileSync} = require('node:child_process');
(async () => {
  const local = {base:process.env.EV_CALENDAR_TEST_URL || 'http://127.0.0.1:8000'};
  const vendor = await new BusinessClient(local).register('vendor');
  const customer = await new BusinessClient(local).register('customer');
  const other = await new BusinessClient(local).register('customer');
  const run = randomUUID();
  const station = await vendor.request('stations','POST',{name:'Calendar QA '+run,country:'AE',currency:'AED',timezone:'Asia/Dubai',latitude:25.19,longitude:55.31,source:'simulator',connector_type:'CCS2',power_kw:50});
  const slot = new Date(Math.ceil((Date.now()+86400000)/1800000)*1800000);
  const start = slot.toISOString().replace('.000Z','Z');
  const holiday = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Dubai',year:'numeric',month:'2-digit',day:'2-digit'}).format(slot);
  const browser = await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH || undefined});
  let published;
  try {
    const context = await browser.newContext({viewport:{width:390,height:844}});
    const page = await context.newPage(), errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.goto(vendor.base,{waitUntil:'networkidle'});
    await page.locator('#account [name=email]').fill(vendor.testCredentials.email);
    await page.locator('#account [name=password]').fill(vendor.testCredentials.password);
    await page.locator('button[value=login]').click();
    const root = page.locator('#station-information-vendor');
    const form = root.locator('form');
    await form.waitFor();
    assert.equal(await form.locator('[name=enforce_operating_hours]').isChecked(),false);
    for (const row of await form.locator('.station-hours-row').all()) {
      await row.locator('input[type=checkbox]').uncheck();
      await row.locator('input[type=time]').nth(0).fill('00:00');
      await row.locator('input[type=time]').nth(1).fill('00:00');
    }
    await form.locator('[name=closed_dates]').fill(holiday);
    await form.locator('[name=enforce_operating_hours]').check();
    const save = page.waitForResponse(r=>new URL(r.url()).pathname===`/api/stations/${station.id}/information` && r.request().method()==='PUT');
    await form.locator('button[type=submit]').click();
    const reply = await save; assert.equal(reply.status(),200);
    published = await reply.json();
    assert.equal(published.enforce_operating_hours,true);
    assert.deepEqual(published.closed_dates,[holiday]);
    assert.equal(published.revision,1);
    await page.reload({waitUntil:'networkidle'});
    await form.waitFor();
    assert.equal(await form.locator('[name=enforce_operating_hours]').isChecked(),true);
    await page.locator('#language').click();
    assert.equal(await page.locator('html').getAttribute('dir'),'rtl');
    assert.match(await form.innerText(),/تطبيق الساعات على الحجوزات/);
    assert.match(await form.innerText(),/يمكن إكمال الشحن الجاري/);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    // Native and customer discovery consume exactly the same public policy.
    const info = await customer.request(`stations/${station.id}/information`);
    assert.equal(info.enforce_operating_hours,true);
    await page.locator('#logout').click();
    await page.locator('#account [name=email]').waitFor();
    assert.equal(await root.locator('form').count(),0);
    await page.locator('#account [name=email]').fill(customer.testCredentials.email);
    await page.locator('#account [name=password]').fill(customer.testCredentials.password);
    await page.locator('button[value=login]').click();
    await page.locator('#account').waitFor({state:'hidden'});
    await page.locator('#filters [name=q]').fill(station.name);
    await page.locator('#filters button').click();
    const card=page.locator('#stations article').filter({has:page.getByRole('heading',{name:station.name,exact:true})});
    await card.waitFor();
    await card.locator('.station-information summary').click();
    assert.match(await card.innerText(),/يُطبق جدول العمل/);
    const booking=card.locator('form');
    await booking.locator('input[type=datetime-local]').fill(start.slice(0,16));
    const denied=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/reservations' && r.request().method()==='POST');
    await booking.locator('button').last().click();
    assert.equal((await denied).status(),409);
    await page.locator('#notice').filter({hasText:'الوقت المحدد خارج جدول عمل المحطة.'}).waitFor();
    await page.locator('#language').click();
    assert.match(await page.locator('#notice').innerText(),/outside the station operating calendar/);
    const body = {station_id:station.id,starts_at:start,idempotency_key:'closed-'+run};
    await assert.rejects(customer.request('reservations','POST',body),/failed \(409\).*outside the station operating calendar/);
    assert.equal((await customer.request('reservations')).data.some(r=>r.station_id===station.id),false);
    published = await vendor.request(`stations/${station.id}/information`,'PUT',{...published,closed_dates:[]});
    const receipt = await customer.request('reservations','POST',{...body,idempotency_key:'open-'+run});
    assert.equal(receipt.operating_calendar_snapshot.revision,2);
    assert.equal(receipt.operating_calendar_snapshot.physical_availability_verified,false);
    published = await vendor.request(`stations/${station.id}/information`,'PUT',{...published,closed_dates:[holiday]});
    const replay = await customer.request('reservations','POST',{...body,idempotency_key:'open-'+run});
    assert.equal(replay.id,receipt.id);
    assert.equal(replay.operating_calendar_snapshot.revision,2);
    await customer.request(`reservations/${receipt.id}`,'DELETE');
    await page.locator('#logout').click();
    await page.locator('#account [name=email]').waitFor();
    assert.equal(await root.locator('form').count(),0);
    assert.deepEqual(errors,[]);
    await context.close();
    if (process.env.EV_OPERATING_CALENDAR_NATIVE === '1') {
      const dir=fs.mkdtempSync(path.join(os.tmpdir(),'ev-calendar-native-'));
      const fixture=path.join(dir,'fixture.json');
      try {
        fs.writeFileSync(fixture,JSON.stringify({fixture_only:true,base:vendor.base,run,station_id:station.id,start,holiday,published,accounts:{vendor:vendor.testCredentials,customer:customer.testCredentials,other:other.testCredentials}}),{mode:0o600});
        execFileSync('flutter',['test','--no-pub','test/live_operating_calendar_test.dart','--reporter','expanded'],{cwd:'apps/mobile',stdio:'inherit',env:{...process.env,EV_NATIVE_OPERATING_CALENDAR_FIXTURE:fixture}});
      } finally {fs.rmSync(dir,{recursive:true,force:true});}
    }
  } finally {await browser.close();}
  console.log('PASS actual vendor opt-in/public policy, phone EN/AR persistence, holiday booking denial, immutable replay, cancellation and logout; no charging or money.');
})().catch(error=>{console.error(error);process.exitCode=1;});
