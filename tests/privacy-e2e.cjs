// Authenticated browser acceptance against an isolated localhost Laravel DB.
// Creates disposable customer data; remote/staging/production URLs are forbidden.
const {chromium} = require('playwright');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');

if (!process.env.EV_TEST_URL) throw Error('Set EV_TEST_URL to an isolated localhost Laravel server.');
const target = new URL(process.env.EV_TEST_URL);
if (!['127.0.0.1','localhost','::1','[::1]'].includes(target.hostname))
  throw Error('Privacy E2E requires localhost. Never use live or staging customers.');

(async () => {
  const browser = await chromium.launch({headless:true});
  const context = await browser.newContext({viewport:{width:1280,height:850}});
  const page = await context.newPage();
  const errors = [], calls = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', r => {
    const pathname = new URL(r.url()).pathname;
    if (pathname.startsWith('/api/privacy-requests')) {
      calls.push({path:pathname,method:r.request().method(),status:r.status()});
    }
  });
  try {
    await page.goto(target.origin,{waitUntil:'networkidle'});
    const email = 'agent5-privacy-' + crypto.randomBytes(6).toString('hex') + '@example.invalid';
    const password = 'Qa!2026' + crypto.randomBytes(16).toString('hex');
    await page.locator('#account input[name=name]').fill('Sandbox Privacy Customer');
    await page.locator('#account input[name=email]').fill(email);
    await page.locator('#account input[name=password]').fill(password);
    await page.locator('#account button[value=register]').click();
    await page.locator('#signed').waitFor({state:'visible',timeout:20000});
    await page.locator('#privacy-self form').waitFor({state:'visible',timeout:20000});

    await page.locator('#privacy-self select[name=request_type]').selectOption('account_deletion');
    await page.locator('#privacy-self textarea[name=reason]')
      .fill('Please review my account deletion request.');
    await page.locator('#privacy-self input[type=checkbox]').check();
    await page.locator('#privacy-self form button[type=submit]').click();
    await page.locator('#privacy-self').getByText('Status: Submitted',{exact:false})
      .waitFor({timeout:20000});
    await page.locator('#privacy-self').getByText('Withdraw request',{exact:false}).click();
    await page.locator('#privacy-self').getByText('Status: Withdrawn',{exact:false})
      .waitFor({timeout:20000});

    await page.reload({waitUntil:'networkidle'});
    await page.locator('#privacy-self').getByText('Status: Withdrawn',{exact:false})
      .waitFor({timeout:20000});
    assert.ok(calls.some(c=>c.method==='POST'&&c.status===201));
    assert.ok(calls.some(c=>c.path.endsWith('/withdraw')&&c.status===200));
    assert.deepEqual(errors,[],'Uncaught page errors');
    await page.locator('#logout').click();
    await page.locator('#signed').waitFor({state:'hidden',timeout:20000});
    console.log('PASS: localhost registration -> private request -> withdrawal -> persistence -> logout; no browser errors.');
  } finally {
    await browser.close();
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
