// Isolated localhost-only station metadata E2E; never use a remote environment.
const {chromium}=require('playwright'),assert=require('node:assert/strict'),crypto=require('node:crypto');
if(!process.env.EV_TEST_URL)throw Error('EV_TEST_URL required.');
const base=new URL(process.env.EV_TEST_URL);
if(!['127.0.0.1','localhost','::1','[::1]'].includes(base.hostname))
  throw Error('No remote/staging/production backend allowed.');
const email=process.env.EV_TEST_VENDOR_EMAIL||'',password=process.env.EV_TEST_VENDOR_PASSWORD||'';
if(!email.endsWith('@example.invalid')||password.length<16)throw Error('Disposable vendor credentials required.');
(async()=>{
 const browser=await chromium.launch({headless:true}),page=await browser.newPage({viewport:{width:1280,height:900}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  await page.goto(base.origin,{waitUntil:'networkidle'});
  await page.locator('#account input[name=email]').fill(email);
  await page.locator('#account input[name=password]').fill(password);
  await page.locator('#account button[value=login]').click();
  await page.locator('#vendor').waitFor({state:'visible',timeout:25000});
  const name='QA Station '+crypto.randomBytes(5).toString('hex');
  const creation=page.locator('#station-form');
  await creation.locator('[name=name]').fill(name);
  await creation.locator('[name=latitude]').fill('25.19');
  await creation.locator('[name=longitude]').fill('55.31');
  await creation.locator('[name=power_kw]').fill('50');
  await creation.locator('[name=source]').selectOption('simulator');
  const newStation=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/stations'&&r.request().method()==='POST',{timeout:20000});
  await creation.locator('button').click();
  const created=await newStation;assert.equal(created.status(),201);
  const station=await created.json();assert.ok(Number(station.id)>0);
  const root=page.locator('#station-information-vendor'),form=root.locator('form.station-information-form');
  await form.waitFor({timeout:25000});
  await form.locator('[name=description_en]').fill('Entrance at Gate A');
  await form.locator('[name=access_en]').fill('Visitors register at front desk');
  const expiresOn=new Date(Date.now()+30*86400000).toISOString().slice(0,10);
  await form.locator('[name=notice_en]').fill('Temporary: rear gate for deliveries');
  await form.locator('[name=notice_until]').fill(expiresOn);
  await form.locator('[name=closed_dates]').fill('2026-12-02');
  const monday=form.locator('.station-hours-row').first();
  await monday.locator('input[type=checkbox]').uncheck();
  await monday.locator('input[type=time]').nth(0).fill('08:00');
  await monday.locator('input[type=time]').nth(1).fill('22:00');
  await form.locator('label').filter({hasText:'Wi-Fi'}).locator('input[type=checkbox]').check();
  const save=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/stations/'+station.id+'/information'&&r.request().method()==='PUT',{timeout:20000});
  await form.locator('button[type=submit]').click();
  const response=await save;assert.equal(response.status(),200);
  const saved=await response.json();
  assert.equal(saved.revision,1);
  assert.equal(saved.source_label,'VENDOR_REPORTED_UNVERIFIED');
  assert.equal(saved.notice_active,true);
  assert.equal(saved.notice_en,'Temporary: rear gate for deliveries');
  assert.equal(saved.notice_until,expiresOn);
  assert.equal(saved.weekly_hours.mon.open,'08:00');
  assert.ok(saved.amenities.includes('wifi'));
  await root.getByText('Saved',{exact:true}).waitFor({timeout:20000});
  await page.reload({waitUntil:'networkidle'});
  await form.waitFor({timeout:25000});
  assert.equal(await form.locator('[name=description_en]').inputValue(),'Entrance at Gate A');
  assert.equal(await form.locator('[name=notice_en]').inputValue(),'Temporary: rear gate for deliveries');
  assert.equal(await form.locator('[name=notice_until]').inputValue(),expiresOn);
  assert.match(await form.innerText(),/Asia\/Dubai/);
  const published=await page.evaluate(async id=>{
    const r=await fetch('/api/stations/'+id+'/information');
    return {status:r.status,data:await r.json()};
  },station.id);
  assert.equal(published.status,200);
  assert.equal(published.data.source_label,'VENDOR_REPORTED_UNVERIFIED');
  assert.equal(published.data.notice_active,true);
  assert.equal(published.data.notice_until,expiresOn);
  assert.deepEqual(published.data.closed_dates,['2026-12-02']);
  assert.equal(published.data.weekly_hours.tue.open,null);
  // Create a second station, return to the original editor, then intentionally
  // hold the second GET. The original form must disappear synchronously,
  // not remain active with its previous station revision and mutable target.
  const secondName='Other QA Station '+crypto.randomBytes(5).toString('hex');
  await creation.locator('[name=name]').fill(secondName);
  await creation.locator('[name=latitude]').fill('25.21');
  await creation.locator('[name=longitude]').fill('55.34');
  await creation.locator('[name=power_kw]').fill('75');
  await creation.locator('[name=source]').selectOption('simulator');
  const creatingSecond=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/stations'&&r.request().method()==='POST',{timeout:20000});
  await creation.locator('button').click();
  const createdSecond=await creatingSecond;
  assert.equal(createdSecond.status(),201);
  const second=await createdSecond.json();
  const picker=root.locator('select[aria-label]');
  await page.waitForFunction(() => document.querySelector('#station-information-vendor select[aria-label]')?.options.length >= 2);
  // Select both stations explicitly; the newly created station may already
  // be the selected picker option while its form is still loading.
  await picker.selectOption(String(second.id));
  await page.waitForFunction(n=>document.querySelector('#station-information-vendor form h3')?.textContent?.includes(n),secondName,{timeout:20000});
  await picker.selectOption(String(station.id));
  await page.waitForFunction(n=>document.querySelector('#station-information-vendor form h3')?.textContent?.includes(n),name,{timeout:20000});
  // Retain the detached old form handle to simulate an already queued submit.
  // Even an explicitly dispatched stale DOM event must not write to station B.
  const staleForm=await form.elementHandle();
  let unsafeWrites=0;
  page.on('request',req=>{
    if(req.method()==='PUT' && new URL(req.url()).pathname==='/api/stations/'+second.id+'/information')
      unsafeWrites++;
  });
  let release, seen;
  const paused=new Promise(resolve=>{release=resolve;});
  const requestStarted=new Promise(resolve=>{seen=resolve;});
  let intercepted=0;
  await page.route('**/api/stations/*/information',async route=>{
    if(route.request().method()==='GET'&&new URL(route.request().url()).pathname==='/api/stations/'+second.id+'/information'){
      intercepted++;
      seen();
      await paused;
    }
    await route.continue();
  });
  try {
    await picker.selectOption(String(second.id));
    await requestStarted;
    assert.equal(await form.count(),0,'Old station editor must be removed while next station GET is pending');
    assert.equal(intercepted,1,'Second station load should be held by the test');
    await staleForm.evaluate(el=>el.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
    assert.equal(unsafeWrites,0,'Detached old form must never PUT to newly selected station');
  } finally {
    // Let the intercepted request finish before unregistering the route;
    // unregistering a still-paused handler can complete it twice.
    release();
  }
  await form.waitFor({timeout:20000});
  await page.unroute('**/api/stations/*/information');
  assert.equal(await form.locator('[name=description_en]').inputValue(),'');
  // The vendor list may omit a station's timezone. Never display literal
  // "undefined" or invent the station's geographical time offset.
  await page.route('**/api/vendor/stations*',async route=>{
    const response=await route.fetch();
    const body=await response.json();
    body.data=body.data.map(row=>({...row,timezone:null}));
    await route.fulfill({response,json:body});
  });
  try{
    await root.getByRole('button',{name:'Refresh',exact:true}).click();
    await form.waitFor({timeout:20000});
    const legend=await form.locator('fieldset').first().locator('legend').innerText();
    assert.match(legend,/timezone not provided/);
    assert.doesNotMatch(legend,/undefined|null/i);
  }finally{await page.unroute('**/api/vendor/stations*');}
  assert.deepEqual(errors,[]);
  await page.locator('#logout').click();
  await page.locator('#vendor').waitFor({state:'hidden',timeout:25000});
  assert.equal(await root.locator('form').count(),0);
  console.log('PASS: vendor-published expiring access notice, persistence/provenance and safe station switching with delayed GET; logout');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});