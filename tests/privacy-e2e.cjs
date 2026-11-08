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
  const errors = [], calls = [], postBodies = [];
  let submittedOnce = false, failNextReadback = true;
  page.on('pageerror', e => errors.push(e.message));
  page.on('request', r => {
    if (new URL(r.url()).pathname === '/api/privacy-requests' && r.method() === 'POST') {
      postBodies.push(r.postDataJSON());
      submittedOnce = true;
    }
  });
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

    // Simulate a successful POST but a temporary GET failure immediately
    // afterwards. The following retry MUST replay the same operation key.
    await page.route('**/api/privacy-requests?page=1',async route => {
      if (submittedOnce && failNextReadback) {
        failNextReadback = false;
        return route.fulfill({
          status:503,contentType:'application/json',
          body:JSON.stringify({message:'Simulated post-commit list readback failure'}),
        });
      }
      await route.continue();
    });
    await page.locator('#privacy-self select[name=request_type]').selectOption('account_deletion');
    const privateReason = 'Private review reference ' + crypto.randomBytes(7).toString('hex');
    await page.locator('#privacy-self textarea[name=reason]')
      .fill(privateReason);
    await page.locator('#privacy-self input[type=checkbox]').check();
    await page.locator('#privacy-self form button[type=submit]').click();
    await page.locator('#privacy-self').getByText('Request may be recorded, but list verification failed.',{exact:false})
      .waitFor({timeout:20000});
    assert.equal(failNextReadback,false,'Must exercise simulated failed readback');
    assert.equal(await page.locator('#privacy-self select[name=request_type]').inputValue(),'account_deletion');
    assert.equal(await page.locator('#privacy-self textarea[name=reason]').inputValue(),privateReason);
    await page.locator('#privacy-self input[type=checkbox]').check();
    await page.locator('#privacy-self form button[type=submit]').click();
    await page.locator('#privacy-self').getByText('Status: Submitted',{exact:false})
      .waitFor({timeout:20000});
    assert.equal(postBodies.length,2,'One initial submission and one idempotent replay');
    assert.equal(postBodies[0].idempotency_key,postBodies[1].idempotency_key,
      'Lost readback must not replace the operation identity');
    assert.ok(calls.some(c=>c.method==='POST'&&c.status===201));
    assert.ok(calls.some(c=>c.method==='POST'&&c.status===200),
      'Replay must return original request rather than a duplicate');
    const beforeWithdrawal=await page.evaluate(async () => {
      const response=await fetch('/api/privacy-requests');
      return await response.json();
    });
    assert.equal(beforeWithdrawal.total,1,'Post-commit failure must not create duplicate review records');
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
    // A second authenticated account must never inherit the previous user's
    // private reason, request status, or stale in-flight DOM state.
    const secondEmail = 'agent5-privacy-other-' +
      crypto.randomBytes(6).toString('hex') + '@example.invalid';
    await page.locator('#account input[name=name]').fill('Second Sandbox Customer');
    await page.locator('#account input[name=email]').fill(secondEmail);
    await page.locator('#account input[name=password]')
      .fill('Qa!2026' + crypto.randomBytes(16).toString('hex'));
    await page.locator('#account button[value=register]').click();
    await page.locator('#signed').waitFor({state:'visible',timeout:20000});
    await page.locator('#privacy-self').getByText('No privacy requests submitted.')
      .waitFor({timeout:20000});
    assert.ok(!(await page.locator('#privacy-self').innerText()).includes(privateReason),
      'Privacy reason leaked across customer sessions.');
    const own = await page.evaluate(async () => {
      const result = await fetch('/api/privacy-requests');
      return {status:result.status,body:await result.json()};
    });
    assert.equal(own.status,200);
    assert.deepEqual(own.body.data,[]);
    assert.deepEqual(errors,[],'Uncaught page errors');
    await page.locator('#logout').click();
    await page.locator('#signed').waitFor({state:'hidden',timeout:20000});
    console.log('PASS: privacy POST/readback failure replay kept original key; one request, withdrawal, reload, second-account isolation, logout.');
  } finally {
    await browser.close();
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
