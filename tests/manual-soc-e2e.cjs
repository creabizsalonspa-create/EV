// Authenticated localhost-only browser acceptance. Never run against public,
// staging or production domains: this creates a disposable customer/vehicle.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');

const raw = process.env.EV_TEST_URL;
if (!raw) throw new Error('Set EV_TEST_URL to an isolated localhost Laravel test server.');
const target = new URL(raw);
if (!['127.0.0.1','localhost','::1','[::1]'].includes(target.hostname) ||
    !['http:','https:'].includes(target.protocol)) {
  throw new Error('Manual SOC E2E permits localhost only, never a remote environment.');
}

(async () => {
  const browser = await chromium.launch({headless:true});
  const context = await browser.newContext({viewport:{width:1366,height:900}});
  const page = await context.newPage();
  const errors = [];
  const observed = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', async response => {
    if (new URL(response.url()).pathname.includes('/manual-soc')) {
      let body = {};
      try {body = await response.json();} catch (_) {}
      observed.push({status:response.status(),source:body.source});
    }
  });
  try {
    await page.goto(target.origin, {waitUntil:'networkidle'});
    const email = 'agent5-soc-' + crypto.randomBytes(6).toString('hex') + '@example.invalid';
    const password = 'Qa!2026' + crypto.randomBytes(16).toString('hex');
    await page.locator('#account input[name=name]').fill('Sandbox QA Customer');
    await page.locator('#account input[name=email]').fill(email);
    await page.locator('#account input[name=password]').fill(password);
    await page.locator('#account button[value=register]').click();
    await page.locator('#signed').waitFor({state:'visible',timeout:25000});
    await page.locator('#garage').waitFor({state:'visible',timeout:25000});

    await page.locator('#vehicle-create input[name=name]').fill('Sandbox Electric Vehicle');
    await page.locator('#vehicle-create select[name=connector_type]').selectOption('CCS2');
    await page.locator('#vehicle-create button').click();
    const entry = page.locator('#vehicle-manual-soc button')
      .filter({hasText:'Report battery for Sandbox Electric Vehicle'});
    await entry.waitFor({timeout:20000});
    await entry.click();
    await page.locator('#vehicle-manual-soc [data-manual-soc-panel]').waitFor({timeout:20000});
    await page.locator('#vehicle-manual-soc input[name=battery_percent]').fill('47');
    await page.locator('#vehicle-manual-soc form button[type=submit]').click();
    await page.locator('#vehicle-manual-soc')
      .getByText('Latest self-reported battery: 47%',{exact:false})
      .waitFor({timeout:20000});

    const panel = await page.locator('#vehicle-manual-soc [data-manual-soc-panel]').innerText();
    assert.match(panel,/CUSTOMER_MANUAL_SELF_REPORT/);
    assert.ok(observed.some(r => r.status === 201 && r.source === 'CUSTOMER_MANUAL_SELF_REPORT'));
    assert.ok(observed.some(r => r.status === 200 && r.source === 'CUSTOMER_MANUAL_SELF_REPORT'));
    // Verify that switching to a second vehicle with a delayed GET removes
    // the previous form immediately. A detached old form cannot submit its
    // percentage against the newly selected vehicle.
    const root=page.locator('#vehicle-manual-soc');
    await page.locator('#vehicle-create input[name=name]').fill('Other Electric Vehicle');
    const adding=page.waitForResponse(r=>
      new URL(r.url()).pathname==='/api/vehicles' && r.request().method()==='POST',{timeout:20000});
    await page.locator('#vehicle-create button').click();
    assert.equal((await adding).status(),201);
    const oldForm=await root.locator('form.vehicle-manual-soc-form').elementHandle();
    assert.ok(oldForm);
    await oldForm.evaluate(el => el.querySelector('[name=battery_percent]').value='60');
    let release, seen, writesAfterSwitch=0;
    const pending=new Promise(resolve=>{release=resolve;});
    const interceptStarted=new Promise(resolve=>{seen=resolve;});
    page.on('request',req=>{
      if(req.method()==='POST'&&new URL(req.url()).pathname.endsWith('/manual-soc'))writesAfterSwitch++;
    });
    await page.route('**/api/vehicles/*/manual-soc',async route=>{
      if(route.request().method()==='GET'){
        seen();
        await pending;
      }
      await route.continue();
    });
    try{
      const switchButton=root.getByRole('button',{name:'Report battery for Other Electric Vehicle'});
      await switchButton.waitFor({timeout:20000});
      await switchButton.click();
      await interceptStarted;
      assert.equal(await root.locator('[data-manual-soc-panel]').count(),0,
        'The previous vehicle editor must close before new GET completes');
      await oldForm.evaluate(el=>el.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
      assert.equal(writesAfterSwitch,0,'Detached form must not post to the next vehicle');
    }finally{release();}
    await root.locator('[data-manual-soc-panel]').waitFor({timeout:20000});
    await page.unroute('**/api/vehicles/*/manual-soc');
    await root.getByText('No report yet.').waitFor({timeout:20000});
    assert.doesNotMatch(await root.locator('[data-manual-soc-panel]').innerText(),
      /Latest self-reported battery: 47%/);
    assert.deepEqual(errors,[], 'Uncaught page errors');
    await page.locator('#logout').click();
    await page.locator('#signed').waitFor({state:'hidden',timeout:20000});
    console.log('PASS: source-qualified SOC, two vehicles, delayed GET, stale-form isolation, and logout.');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error);process.exitCode=1;});
