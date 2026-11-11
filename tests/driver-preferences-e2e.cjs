// Isolated, authenticated localhost-only regression. Never run on production.
const {chromium} = require('playwright');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
if (!process.env.EV_TEST_URL) throw Error('Set EV_TEST_URL to an isolated localhost Laravel test server.');
const target = new URL(process.env.EV_TEST_URL);
if (!['127.0.0.1','localhost'].includes(target.hostname))
  throw Error('localhost only');
(async()=>{
  const browser = await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined});
  const context = await browser.newContext({viewport:{width:1280,height:850}});
  const page = await context.newPage();
  const errors = [], requests=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('response',async r=>{
    if(new URL(r.url()).pathname.includes('driver-preferences')){
      let body={}; try{body=await r.json();}catch(_){}
      requests.push({method:r.request().method(),status:r.status(),
        scope:body.scope,applied_to_charging:body.applied_to_charging});
    }
  });
  try{
    await page.goto(target.origin,{waitUntil:'networkidle'});
    const email='agent5-driver-'+crypto.randomBytes(6).toString('hex')+'@example.invalid';
    await page.locator('#account input[name=name]').fill('Sandbox Driver');
    await page.locator('#account input[name=email]').fill(email);
    await page.locator('#account input[name=password]').fill('Qa!2026'+crypto.randomBytes(16).toString('hex'));
    await page.locator('#account button[value=register]').click();
    await page.locator('#signed').waitFor({state:'visible',timeout:20000});
    await page.locator('#garage').waitFor({state:'visible',timeout:20000});
    await page.locator('#vehicle-create input[name=name]').fill('Sandbox EV');
    await page.locator('#vehicle-create select[name=connector_type]').selectOption('CCS2');
    await page.locator('#vehicle-create button').click();
    const open=page.locator('#vehicle-list button').filter({hasText:'Driver charging preferences'});
    await open.waitFor({timeout:20000});
    await open.click();
    await page.locator('#driver-preferences-editor form').waitFor({timeout:20000});
    await page.locator('#driver-preferences-editor input[name=preferred_power_kw]').fill('150');
    await page.locator('#driver-preferences-editor select[name=charging_goal]').selectOption('fastest');
    await page.locator('#driver-preferences-editor input[name=accessible_site_required]').check();
    await page.locator('#driver-preferences-editor form button[type=submit]').click();
    await page.locator('#driver-preferences-editor').getByText('Preferences saved.',{exact:false}).waitFor({timeout:20000});
    await page.reload({waitUntil:'networkidle'});
    await page.locator('#vehicle-list button').filter({hasText:'Driver charging preferences'}).waitFor({timeout:20000});
    await page.locator('#vehicle-list button').filter({hasText:'Driver charging preferences'}).click();
    await page.locator('#driver-preferences-editor form').waitFor({timeout:20000});
    assert.equal(await page.locator('#driver-preferences-editor input[name=preferred_power_kw]').inputValue(),'150');
    assert.equal(await page.locator('#driver-preferences-editor select[name=charging_goal]').inputValue(),'fastest');
    assert.equal(await page.locator('#driver-preferences-editor input[name=accessible_site_required]').isChecked(),true);
    assert.ok(requests.some(r=>r.method==='PUT'&&r.status===200&&r.scope==='DRIVER_PREFERENCE_ONLY'));
    assert.ok(requests.every(r=>r.applied_to_charging===false || r.applied_to_charging===undefined));
    assert.deepEqual(errors,[]);
    await page.locator('#logout').click();
    await page.locator('#signed').waitFor({state:'hidden',timeout:20000});
    console.log('PASS: localhost register -> garage -> driver preferences -> persisted reload -> logout');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});