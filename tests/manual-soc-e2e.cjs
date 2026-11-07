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
    assert.deepEqual(errors,[], 'Uncaught page errors');
    await page.locator('#logout').click();
    await page.locator('#signed').waitFor({state:'hidden',timeout:20000});
    console.log('PASS: localhost registration -> garage -> manual SOC -> sourced history -> logout; no browser errors.');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error);process.exitCode=1;});
