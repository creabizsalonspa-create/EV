// Isolated localhost-only Admin review acceptance; never run against production.
const {chromium}=require('playwright'),crypto=require('node:crypto'),assert=require('node:assert/strict');
if(!process.env.EV_TEST_URL)throw Error('EV_TEST_URL is required.');
const host=new URL(process.env.EV_TEST_URL);
if(!['127.0.0.1','localhost','[::1]','::1'].includes(host.hostname))
  throw Error('No remote/staging/production backend allowed.');
const adminEmail=process.env.EV_TEST_ADMIN_EMAIL||'';
const adminPassword=process.env.EV_TEST_ADMIN_PASSWORD||'';
if(!adminEmail.endsWith('@example.invalid')||adminPassword.length<16)
  throw Error('Disposable Admin test identity required.');
(async()=>{
 const browser=await chromium.launch({headless:true}),page=await browser.newPage({viewport:{width:1366,height:900}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const login=async(email,password)=>{
  await page.locator('#account input[name=email]').fill(email);
  await page.locator('#account input[name=password]').fill(password);
  await page.locator('#account button[value=login]').click();
  await page.locator('#signed').waitFor({state:'visible',timeout:25000});
 };
 const logout=async()=>{
  await page.locator('#logout').click();
  await page.locator('#signed').waitFor({state:'hidden',timeout:20000});
 };
 try{
  await page.goto(host.origin,{waitUntil:'networkidle'});
  const email='agent5-privacy-review-'+crypto.randomBytes(6).toString('hex')+'@example.invalid';
  const password='Qa!2026'+crypto.randomBytes(16).toString('hex');
  await page.locator('#account input[name=name]').fill('Disposable Review Customer');
  await page.locator('#account input[name=email]').fill(email);
  await page.locator('#account input[name=password]').fill(password);
  await page.locator('#account button[value=register]').click();
  await page.locator('#signed').waitFor({state:'visible',timeout:25000});
  const self=page.locator('#privacy-self');
  await self.locator('form').waitFor({timeout:20000});
  await self.locator('select[name=request_type]').selectOption('data_access');
  await self.locator('textarea[name=reason]').fill('Please review my private data request.');
  await self.locator('input[type=checkbox]').check();
  await self.locator('form button[type=submit]').click();
  await self.getByText('Status: Submitted',{exact:false}).waitFor({timeout:20000});
  await logout();
  await login(adminEmail,adminPassword);
  await page.locator('#admin').waitFor({state:'visible',timeout:20000});
  const root=page.locator('#privacy-admin');
  const card=root.locator('.privacy-request-card').filter({hasText:email});
  await card.waitFor({timeout:20000});
  await card.getByRole('button',{name:'Start review'}).click();
  await root.locator('.privacy-request-card').filter({hasText:email})
    .getByText('Status: Under review',{exact:false}).waitFor({timeout:20000});
  const review=root.locator('.privacy-request-card').filter({hasText:email}).locator('form.privacy-review-form');
  await review.waitFor({timeout:20000});
  await review.locator('select').selectOption('referred');
  await review.locator('textarea').fill('                 ');
  const rejected=page.waitForResponse(r=>r.request().method()==='PATCH'&&
    /\/api\/admin\/privacy-requests\/\d+$/.test(new URL(r.url()).pathname),{timeout:20000});
  await review.locator('button[type=submit]').click();
  assert.equal((await rejected).status(),422,'Whitespace-only Admin note must be rejected');
  assert.equal(await review.count(),1,'Review should remain open after failed validation');
  await review.locator('textarea').fill('Referred to the local test compliance queue for documented review.');
  const closed=page.waitForResponse(r=>r.request().method()==='PATCH'&&
    /\/api\/admin\/privacy-requests\/\d+$/.test(new URL(r.url()).pathname),{timeout:20000});
  await review.locator('button[type=submit]').click();
  assert.equal((await closed).status(),200);
  await root.locator('.privacy-request-card').filter({hasText:email})
    .getByText('Status: Review closed',{exact:false}).waitFor({timeout:20000});
  await logout();
  await login(email,password);
  await self.getByText('Status: Review closed',{exact:false}).waitFor({timeout:20000});
  assert.match(await self.innerText(),/not proof of data deletion/);
  await page.reload({waitUntil:'networkidle'});
  await self.getByText('Status: Review closed',{exact:false}).waitFor({timeout:20000});
  assert.deepEqual(errors,[]);
  await logout();
  console.log('PASS: customer request -> Admin review/422 invalid note -> documented closure -> customer sees non-deletion outcome.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});