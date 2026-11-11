// Actual anonymous session response, deliberately delayed across an attempted login.
// No forced cookies, mock successful login, rate-limit bypass or private fixture output.
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const {BusinessClient} = require('./helpers/business-client.cjs');
(async () => {
  const account = await new BusinessClient().register('customer');
  const browser = await chromium.launch({headless:true, executablePath:process.env.EV_CHROMIUM_PATH || undefined});
  try {
    for (const bootstrapFails of [false, true]) {
      const context = await browser.newContext();
      const page = await context.newPage();
      let release, ready, first = true, posts = 0;
      const held = new Promise(resolve => release = resolve);
      const received = new Promise(resolve => ready = resolve);
      page.on('request', req => {if (new URL(req.url()).pathname === '/api/login' && req.method() === 'POST') posts++;});
      await page.route('**/api/me', async route => {
        if (!first) return route.continue();
        first = false;
        const response = await route.fetch();
        assert.equal(response.status(), 401, 'Initial identity must be a real anonymous session response');
        ready();
        await held;
        if (bootstrapFails) await route.fulfill({response,status:503,json:{message:'Synthetic initial identity outage'}});
        else await route.fulfill({response});
      });
      try {
        await page.goto(account.base);
        await received;
        assert.equal(await page.locator('#account [name=email]').isDisabled(), true);
        assert.equal(await page.locator('button[value=login]').isDisabled(), true);
        // A queued or programmatically submitted event must also remain fenced.
        await page.locator('#account').evaluate(form => form.dispatchEvent(new SubmitEvent('submit', {bubbles:true,cancelable:true,submitter:form.querySelector('button[value=login]')})));
        assert.equal(posts, 0, 'Startup cannot race a login mutation');
      } finally { release(); }
      await page.waitForFunction(() => !document.querySelector('#account [name=email]').disabled);
      await page.locator('#account [name=email]').fill(account.testCredentials.email);
      await page.locator('#account [name=password]').fill(account.testCredentials.password);
      const logged = page.waitForResponse(r => new URL(r.url()).pathname === '/api/login' && r.request().method() === 'POST');
      await page.locator('button[value=login]').click();
      assert.equal((await logged).status(), 200);
      await page.locator('#account').waitFor({state:'hidden'});
      const current = await page.evaluate(async () => {const r=await fetch('/api/me',{headers:{Accept:'application/json'}});return {status:r.status,user:await r.json()};});
      assert.equal(current.status, 200);
      assert.equal(current.user.id, account.user.id);
      assert.equal(posts, 1, 'Exactly one normal login; no mutation retry');
      await page.locator('#logout').click();
      await page.locator('#account [name=email]').waitFor();
      await context.close();
    }
  } finally { await browser.close(); }
  console.log('PASS actual delayed anonymous response cannot overwrite login; startup outage unlocks safely, normal login/logout and identity retained.');
})().catch(error => {console.error(error);process.exitCode=1;});
