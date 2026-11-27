'use strict';
const{execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const{BusinessClient}=require('./helpers/business-client.cjs');let stage='fixture';
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const f=JSON.parse(execFileSync(process.env.EV_TEST_PHP_RUNNER||'./scripts/php',['tests/Fixtures/operational-health.php',run,password],{encoding:'utf8'}));assert.equal(f.fixture_only,true);
 async function login(email){const c=new BusinessClient({base:process.env.EV_TEST_URL||"http://127.0.0.1:8000"});c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email,password});c.csrf=(await c.request('csrf')).token;return c;}
 stage='private-api';const c=await login(f.admin_email),snapshot=await c.request('admin/operational-health');assert.equal(snapshot.scope,'default_queue_and_scheduler_observations');assert.equal(snapshot.components.length,2);assert.equal(snapshot.job_success_verified,false);assert.equal(snapshot.charger_connectivity_verified,false);
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  const context=await browser.newContext({viewport:{width:390,height:844}});await context.addCookies([...c.cookies].map(([name,value])=>({name,value,url:c.base})));
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));stage='english-web';await page.goto(c.base);
  const root=page.locator('#admin-operational-health');await root.getByRole('button',{name:'Refresh process observations',exact:true}).click();await root.getByRole('heading',{name:'Scheduler process',exact:true}).waitFor();assert.equal(await root.locator('article').count(),2);
  assert((await root.textContent()).includes('does not prove successful jobs'));
  // Reuse the existing cookie-authenticated Admin and customer fixture, with
  // no additional logins or provider traffic. The report is a server-rendered
  // private management surface, not just a mocked browser component.
  stage='coverage-english';
  const coverageLink=page.locator('#admin a[href="/admin/licensed-coverage"]');
  await coverageLink.waitFor({state:'visible'});
  assert.equal(await coverageLink.textContent(),'Licensed discovery coverage history');
  async function coverageReport(locale){
    const [response]=await Promise.all([page.waitForNavigation(),coverageLink.click()]);
    assert.equal(response.status(),200);
    const headers=response.headers();
    assert.match(headers['cache-control'],/\bprivate\b/);
    assert.match(headers['cache-control'],/\bno-store\b/);
    assert.equal(headers['referrer-policy'],'no-referrer');
    assert.equal(await page.locator('html').getAttribute('lang'),locale);
    assert.equal(await page.locator('html').getAttribute('dir'),locale==='ar'?'rtl':'ltr');
    await page.getByRole('heading',{name:locale==='ar'?'تغطية الاكتشاف المرخص':'Licensed discovery coverage',exact:true}).waitFor();
    assert.equal(await page.locator('link[href="/admin-licensed-coverage.css"]').count(),1);
  }
  await coverageReport('en');
  await page.getByRole('link',{name:'Back to platform',exact:true}).click();
  await root.getByRole('button',{name:'Refresh process observations',exact:true}).waitFor();
  stage='arabic-web';await page.locator('#language').click();await root.getByRole('button',{name:'تحديث ملاحظات العمليات',exact:true}).click();await root.getByRole('heading',{name:'عملية الجدولة',exact:true}).waitFor();assert.equal(await root.locator('article').count(),2);assert.equal(await page.locator('html').getAttribute('dir'),'rtl');assert.deepEqual(errors,[]);
  stage='coverage-arabic';
  const profile=await page.request.patch(c.base+'/api/profile',{headers:{'X-CSRF-TOKEN':c.csrf,Accept:'application/json'},data:{name:'Synthetic health Admin',locale:'ar'}});
  assert.equal(profile.status(),200);
  assert.equal((await profile.json()).locale,'ar');
  await coverageLink.waitFor({state:'visible'});
  assert.equal(await coverageLink.textContent(),'سجل تغطية الاكتشاف المرخص');
  await coverageReport('ar');
  assert.deepEqual(errors,[]);
  stage='customer-isolation';const customer=await login(f.customer_email);await assert.rejects(customer.request('admin/operational-health'),/403/);
  const foreign=await browser.newContext();
  try{
    const denied=await foreign.request.get(c.base+'/admin/licensed-coverage',{headers:{Accept:'application/json'}});
    assert.equal(denied.status(),401);
    await foreign.addCookies([...customer.cookies].map(([name,value])=>({name,value,url:customer.base})));
    const forbidden=await foreign.request.get(c.base+'/admin/licensed-coverage',{headers:{Accept:'application/json'}});
    assert.equal(forbidden.status(),403);
    assert(!((await forbidden.text()).includes('data-testid="observed-hours"')));
  }finally{await foreign.close();}
  console.log('Actual Laravel licensed coverage Admin navigation, EN/AR, private headers and anonymous/customer denial passed.');
  console.log('Actual Laravel/Admin EN/AR process snapshots and customer denial passed. Heartbeat is observation only; no payload reads, job retries, charger or payment commands.');
 }finally{await browser.close();}
})().catch(error=>{console.error(JSON.stringify({suite:'admin_operational_health',stage,error:error?.name==='TimeoutError'?'TimeoutError':'Error'}));process.exitCode=1;});
