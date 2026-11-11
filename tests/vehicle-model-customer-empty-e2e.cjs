// Real authenticated browser journey against an isolated localhost Laravel DB.
// This verifies the empty, unseeded catalog safely without privileged users.
const {chromium} = require('playwright');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
if (!process.env.EV_TEST_URL) throw Error('EV_TEST_URL must be an isolated localhost server.');
const base = new URL(process.env.EV_TEST_URL);
if (!['127.0.0.1','localhost','[::1]','::1'].includes(base.hostname)) {
  throw Error('Customer catalog E2E forbids remote/staging/production hosts.');
}
(async () => {
  const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined});
  const context=await browser.newContext({viewport:{width:390,height:844}});
  const page=await context.newPage();
  const errors=[],calls=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('response',r=>{
    const path=new URL(r.url()).pathname;
    if (path.includes('/vehicle-models') || path.match(/\/vehicles\/\d+\/model$/)) {
      calls.push({status:r.status(),path,method:r.request().method()});
    }
  });
  try {
    await page.goto(base.origin,{waitUntil:'networkidle'});
    const random=crypto.randomBytes(7).toString('hex');
    await page.locator('#account input[name=name]').fill('Disposable EV Catalog Customer');
    await page.locator('#account input[name=email]').fill('agent5-catalog-empty-'+random+'@example.invalid');
    await page.locator('#account input[name=password]').fill('Qa!2026'+crypto.randomBytes(18).toString('hex'));
    await page.locator('#account button[value=register]').click();
    await page.locator('#signed').waitFor({state:'visible',timeout:25000});
    await page.locator('#garage').waitFor({state:'visible',timeout:20000});
    assert.equal(await page.locator('#admin').isVisible(),false);
    await page.locator('#vehicle-create input[name=name]').fill('Unverified EV');
    await page.locator('#vehicle-create select[name=connector_type]').selectOption('CCS2');
    await page.locator('#vehicle-create button').click();
    const root=page.locator('#vehicle-model-customer');
    const choose=root.getByRole('button',{name:'Select catalog model'});
    await choose.waitFor({timeout:20000});
    await choose.click();
    const picker=root.locator('[data-vehicle-model-picker]');
    await picker.waitFor({timeout:20000});
    await picker.getByText('No compatible published models on this page.').waitFor({timeout:20000});
    assert.equal(await picker.getByRole('button',{name:'Link selected model'}).isDisabled(),true);
    assert.match(await root.innerText(),/not manufacturer certification/);
    assert.ok(calls.some(r=>r.status===200&&r.path.endsWith('/model')));
    assert.ok(calls.some(r=>r.status===200&&r.path.endsWith('/vehicle-models')));
    assert.deepEqual(errors,[],'No browser exceptions');
    await page.locator('#logout').click();
    await page.locator('#signed').waitFor({state:'hidden',timeout:25000});
    assert.equal(await root.locator('[data-vehicle-model-picker]').count(),0);
    assert.equal(await root.innerText(),'','Private catalog UI must clear on logout');
    console.log('PASS: localhost customer registration, garage, honest empty catalog and logout isolation.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
