// Agent 5 browser acceptance: isolated localhost-only Admin -> customer model flow.
// Requires a disposable local DB and an Admin account pre-seeded in that DB.
// No remote host, public backend or production target is permitted.
const {chromium} = require('playwright');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');

const url = process.env.EV_TEST_URL;
if (!url) throw Error('Provide EV_TEST_URL to an isolated localhost test backend.');
const target = new URL(url);
if (!['localhost','127.0.0.1','[::1]','::1'].includes(target.hostname) ||
    !['http:','https:'].includes(target.protocol))
  throw Error('Catalog E2E refuses non-loopback backends.');
const adminEmail = process.env.EV_TEST_ADMIN_EMAIL || '';
const adminPassword = process.env.EV_TEST_ADMIN_PASSWORD || '';
if (!adminEmail.endsWith('@example.invalid') || adminPassword.length < 16)
  throw Error('Provide disposable local Admin credentials in EV_TEST_ADMIN_*.');

(async () => {
  const browser = await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined});
  const context = await browser.newContext({viewport:{width:1366,height:900}});
  const page = await context.newPage();
  const errors=[];
  page.on('pageerror', e => errors.push(e.message));
  async function signIn(email, password) {
    await page.locator('#account input[name=email]').fill(email);
    await page.locator('#account input[name=password]').fill(password);
    await page.locator('#account button[value=login]').click();
    await page.locator('#signed').waitFor({state:'visible',timeout:25000});
  }
  async function signOut() {
    await page.locator('#logout').click();
    await page.locator('#signed').waitFor({state:'hidden',timeout:25000});
  }
  try {
    await page.goto(target.origin,{waitUntil:'networkidle'});
    await signIn(adminEmail,adminPassword);
    await page.locator('#admin').waitFor({state:'visible',timeout:25000});
    const root=page.locator('#vehicle-model-admin');
    const form=root.locator('form.vehicle-model-admin-form');
    await form.waitFor({timeout:25000});

    const stamp=crypto.randomBytes(6).toString('hex');
    const brand='E2E Curated '+stamp;
    const modelName='Verified Browser Model';
    await form.locator('[name=make]').fill(brand);
    await form.locator('[name=model]').fill(modelName);
    await form.locator('[name=source_url]').fill('https://example.com/vehicle-catalog/'+stamp);
    await form.locator('[name=connector_type]').selectOption('CCS2');
    await form.locator('[name=published]').check();
    const createdResponse=page.waitForResponse(r =>
      new URL(r.url()).pathname==='/api/vehicle-models' &&
      r.request().method()==='POST',{timeout:20000});
    await form.locator('button[type=submit]').click();
    const created=await createdResponse;
    assert.equal(created.status(),201,'Admin catalog create must succeed');
    const record=await created.json();
    assert.equal(record.provenance,'ADMIN_CURATED_UNVERIFIED');
    assert.equal(record.connector_type,'CCS2');
    await root.getByText(brand,{exact:false}).first().waitFor({timeout:20000});
    await signOut();

    const email='agent5-catalog-'+crypto.randomBytes(6).toString('hex')+'@example.invalid';
    const password='Qa!2026'+crypto.randomBytes(16).toString('hex');
    await page.locator('#account input[name=name]').fill('Disposable Catalog Customer');
    await page.locator('#account input[name=email]').fill(email);
    await page.locator('#account input[name=password]').fill(password);
    await page.locator('#account button[value=register]').click();
    await page.locator('#signed').waitFor({state:'visible',timeout:25000});
    await page.locator('#garage').waitFor({state:'visible',timeout:20000});
    await page.locator('#vehicle-create input[name=name]').fill('My E2E EV');
    await page.locator('#vehicle-create select[name=connector_type]').selectOption('CCS2');
    await page.locator('#vehicle-create button').click();
    const customer=page.locator('#vehicle-model-customer');
    const choose=customer.getByRole('button',{name:'Select catalog model'}).first();
    await choose.waitFor({timeout:25000});
    await choose.click();
    const picker=customer.locator('[data-vehicle-model-picker]');
    await picker.waitFor({timeout:20000});
    await picker.locator('select').selectOption(String(record.id));
    assert.ok((await picker.locator('a').getAttribute('href')).startsWith('https://example.com/'));
    const linked=page.waitForResponse(r=>
      /\/api\/vehicles\/\d+\/model$/.test(new URL(r.url()).pathname) &&
      r.request().method()==='PUT',{timeout:20000});
    await picker.getByRole('button',{name:'Link selected model'}).click();
    const result=await linked;assert.equal(result.status(),200);
    assert.equal((await result.json()).does_not_override_connector,true);
    await customer.getByText('Model linked.',{exact:true}).waitFor({timeout:20000});
    await page.reload({waitUntil:'networkidle'});
    await customer.getByRole('button',{name:'Select catalog model'}).first().click();
    await picker.waitFor({timeout:20000});
    assert.equal(await picker.locator('select').inputValue(),String(record.id));
    assert.match(await picker.innerText(),new RegExp(brand));
    await signOut();

    await signIn(adminEmail,adminPassword);
    await page.locator('#admin').waitFor({state:'visible',timeout:20000});
    const row=root.locator('li').filter({hasText:brand}).first();
    await row.getByRole('button',{name:'Archive'}).click();
    await row.getByRole('button',{name:'Publish'}).waitFor({timeout:20000});
    await signOut();

    await signIn(email,password);
    await page.locator('#garage').waitFor({state:'visible',timeout:20000});
    await customer.getByRole('button',{name:'Select catalog model'}).first().click();
    await picker.getByText('(archived)',{exact:false}).waitFor({timeout:20000});
    const unlinked=page.waitForResponse(r =>
      /\/api\/vehicles\/\d+\/model$/.test(new URL(r.url()).pathname) &&
      r.request().method()==='PUT',{timeout:20000});
    await picker.getByRole('button',{name:'Remove model association'}).click();
    assert.equal((await unlinked).status(),200);
    await customer.getByText('Model link removed.',{exact:true}).waitFor({timeout:20000});
    assert.deepEqual(errors,[],'Uncaught browser errors');
    await signOut();
    console.log('PASS: local Admin create/publish -> customer link -> persisted selection -> Admin archive -> customer unlink.');
  } finally {
    await browser.close();
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
