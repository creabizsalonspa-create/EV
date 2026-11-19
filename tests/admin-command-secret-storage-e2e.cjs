'use strict';
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const {mkdirSync}=require('node:fs');
const {chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
const {provisionTestRole}=require('./helpers/browser-role.cjs');
let stage='fixture';
(async()=>{
 const base=process.env.EV_TEST_URL||'http://127.0.0.1:8000',target=new URL(base);
 assert(target.protocol==='http:'&&['127.0.0.1','localhost'].includes(target.hostname),'Owned loopback required');
 const runner=process.env.EV_TEST_PHP_RUNNER||'./scripts/php',run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const invoke=(mode,...args)=>JSON.parse(execFileSync(runner,['tests/Fixtures/admin-command-secret-storage.php',mode,run,...args],{encoding:'utf8'}));
 const fixture=invoke('seed',password);assert.equal(fixture.fixture_only,true);const before=invoke('snapshot');assert.equal(before.rows,28);
 async function login(email){const c=new BusinessClient({base});c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email,password});c.csrf=(await c.request('csrf')).token;return c;}
 const admin=await login(fixture.admin_email),customer=await login(fixture.customer_email);
 stage='private-api';const batch=await admin.request('admin/command-secret-storage?limit=25&after='+fixture.after_id);
 assert.equal(batch.rows_examined,25);assert.equal(batch.encrypted_rows,21);assert.equal(batch.legacy_rows,1);assert.equal(batch.unreadable_rows,1);assert.equal(batch.unknown_shape_rows,1);assert.equal(batch.no_known_secret_rows,1);
 const last=await admin.request('admin/command-secret-storage?limit=25&after='+batch.next_after_id);assert.equal(last.rows_examined,3);assert.equal(last.has_more,false);
 await assert.rejects(customer.request('admin/command-secret-storage'),/403/);
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  const context=await browser.newContext({viewport:{width:390,height:844}});await context.addCookies([...admin.cookies].map(([name,value])=>({name,value,url:base})));
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));let reads=0;page.on('request',r=>{if(new URL(r.url()).pathname==='/api/admin/command-secret-storage')reads++;});
  await page.addInitScript(()=>{const original=Response.prototype.json;Response.prototype.json=function(...args){if(window.holdStorageObservation&&new URL(this.url).pathname==='/api/admin/command-secret-storage'){window.holdStorageObservation=false;return new Promise((resolve,reject)=>{window.releaseStorageObservation=async()=>{try{resolve(await original.apply(this,args));}catch(e){reject(e);}};});}return original.apply(this,args);};});
  await page.goto(base);await page.waitForLoadState('networkidle');assert.equal(reads,0);
  const root=page.locator('#admin-command-secret-storage'),details=root.locator('xpath=ancestor::details[1]');if(await details.count()&&!await details.evaluate(e=>e.open))await details.locator(':scope > summary').click();
  for(const language of ['en','ar']){
   if(language==='ar'){await page.locator('#language').click();await page.waitForFunction(()=>document.documentElement.dir==='rtl');}
   for(const theme of ['light','dark','system']){
    stage=language+'-'+theme;await page.locator('#theme-choice').selectOption(theme);
    const replyPromise=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/admin/command-secret-storage'&&new URL(r.url()).searchParams.get('after')==='0');
    await root.getByRole('button',{name:language==='ar'?'فحص دفعة التخزين الأولى':'Inspect first storage batch',exact:true}).click();
    const reply=await replyPromise;assert.equal(reply.status(),200);assert.match(reply.headers()['cache-control'],/no-store/);const data=await reply.json();
    await root.getByRole('heading',{name:language==='ar'?'أعداد الدفعة المرصودة':'Observed batch counts',exact:true}).waitFor();
    const expected=['encrypted_rows','legacy_rows','unreadable_rows','unknown_shape_rows','no_known_secret_rows'].map(k=>String(data[k]));
    assert.deepEqual(await root.locator('dd').allTextContents(),expected);assert.equal((await root.textContent()).includes('PRIVATE-'),false);
    assert.equal(await page.locator('#theme-choice').inputValue(),theme);
    mkdirSync('artifacts/admin-command-secret-storage',{recursive:true});
    for(const width of [390,768,1440]){
     await page.setViewportSize({width,height:844});
     assert.equal(await root.locator('dl').evaluate(e=>getComputedStyle(e).display),'grid');
     assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
     await root.screenshot({path:'artifacts/admin-command-secret-storage/'+language+'-'+theme+'-'+width+'.png'});
    }
    await page.setViewportSize({width:390,height:844});
   }
  }
  stage='late-role-revocation';await page.evaluate(()=>{window.holdStorageObservation=true;});
  await root.getByRole('button',{name:'فحص دفعة التخزين الأولى',exact:true}).click();await page.waitForFunction(()=>typeof window.releaseStorageObservation==='function');
  await provisionTestRole(fixture.admin_email,'customer',{pages:[page],runner});
  const expired=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/me');await page.evaluate(()=>identity());assert.equal((await expired).status(),401);
  await page.evaluate(()=>window.releaseStorageObservation());assert.equal(await root.locator('dd').count(),0);assert.deepEqual(errors,[]);
 }finally{await browser.close();}
 assert.deepEqual(invoke('snapshot'),before);
 assert.equal(invoke('cleanup').unlinked_synthetic_rows_removed,28);
 console.log('PASS actual Laravel bounded storage counts/pagination, customer denial, EN/AR390/768/1440px Light/Dark/System, no credentials or command-storage writes and late real-role revocation. Unlinked synthetic corruption fixtures removed after unchanged-storage proof; no sealing, dispatch or production acceptance.');
})().catch(error=>{console.error(JSON.stringify({suite:'admin-command-secret-storage',stage,error:['AssertionError','TimeoutError'].includes(error.name)?error.name:'Error'}));process.exitCode=1;});
