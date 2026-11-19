// Actual owned loopback Admin read flows. Synthetic audits are not incidents.
'use strict';
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const {chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
const {provisionTestRole}=require('./helpers/browser-role.cjs');
let stage='fixture';
function matchesDirectoryRead(response, base, expected) {
 const url=new URL(response.url());
 return url.origin===new URL(base).origin && url.pathname==='/api/admin/accounts'
  && response.request().method()==='GET'
  && Object.entries(expected).every(([key,value])=>url.searchParams.get(key)===value);
}
module.exports={matchesDirectoryRead};
if(require.main===module) {
(async()=>{
 const base=process.env.EV_TEST_URL||'http://127.0.0.1:8000',url=new URL(base);
 assert(['127.0.0.1','localhost'].includes(url.hostname)&&url.protocol==='http:','Owned loopback API required');
 const runner=process.env.EV_TEST_PHP_RUNNER||'./scripts/php',run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const f=JSON.parse(execFileSync(runner,['tests/Fixtures/admin-current-authority.php',run,password],{encoding:'utf8'}));assert.equal(f.fixture_only,true);
 const login=async email=>{const c=new BusinessClient({base});c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email,password});c.csrf=(await c.request('csrf')).token;return c;};
 const admin=await login(f.admin_email),customer=await login(f.customer_email);
 await assert.rejects(customer.request('admin/accounts'),/403/);
 await assert.rejects(customer.request('admin/security-denials'),/403/);
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  const ctx=await browser.newContext({viewport:{width:390,height:844}});
  await ctx.addCookies([...admin.cookies].map(([name,value])=>({name,value,url:base})));
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));
  await page.addInitScript(()=>{
   const original=Response.prototype.json;
   Response.prototype.json=function(...args){
    if(window.holdAdminDirectory&&new URL(this.url).pathname==='/api/admin/accounts'){
     window.holdAdminDirectory=false;
     return new Promise((resolve,reject)=>{window.releaseAdminDirectory=async()=>{try{resolve(await original.apply(this,args));}catch(e){reject(e);}};});
    }
    return original.apply(this,args);
   };
  });
  await page.goto(base);await page.locator('#admin-accounts').waitFor({state:'attached'});await page.waitForLoadState('networkidle');
  const accounts=page.locator('#admin-accounts'),audit=page.locator('#admin-audit-trail');
  async function reveal(root){const details=root.locator('xpath=ancestor::details[1]');if(await details.count()&&!await details.evaluate(e=>e.open))await details.locator(':scope > summary').click();}
  await reveal(accounts);
  for(const language of ['en','ar']){
   stage='company-filter-'+language;
   if(language==='ar'){await page.locator('#language').click();await page.waitForFunction(()=>document.documentElement.dir==='rtl');}
   await accounts.getByLabel('Business name / اسم الشركة').fill(f.company_query);
   await accounts.getByLabel('Business country / الدولة').fill('AE');
   await accounts.getByLabel('Business review / حالة الشركة').selectOption('rejected');
   let read=page.waitForResponse(r=>matchesDirectoryRead(r,base,{company_q:f.company_query,company_country:'AE',company_status:'rejected'}));
   await accounts.locator('form').first().locator('button[type=submit]').click();
   let reply=await read;stage='rejected-response-'+language;assert.equal(reply.status(),200);stage='rejected-total-'+language;assert.equal((await reply.json()).total,0,'All filters must match the same company');
   await accounts.getByLabel('Business review / حالة الشركة').selectOption('approved');
   read=page.waitForResponse(r=>matchesDirectoryRead(r,base,{company_q:f.company_query,company_country:'AE',company_status:'approved'}));
   await accounts.locator('form').first().locator('button[type=submit]').click();reply=await read;
   stage='approved-response-'+language;assert.equal(reply.status(),200);assert.match(reply.headers()['cache-control'],/no-store/);
   const body=await reply.json();stage='approved-total-'+language;assert.equal(body.total,1);stage='approved-owner-'+language;assert.equal(body.data[0].id,f.owner_id);
   assert.equal(JSON.stringify(body).includes('trade_license_number'),false);assert.equal(JSON.stringify(body).includes('password'),false);
   await accounts.getByText(f.owner_name,{exact:true}).waitFor();assert.equal(await accounts.locator('img,script').count(),0);
   stage='mfa-denial-'+language;await reveal(audit);
   await audit.getByLabel('Audit actor ID').fill(String(f.admin_id));
   await audit.getByLabel('MFA denial evidence window').selectOption('1h');
   read=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/admin/security-denials');
   await audit.getByRole('button',{name:'Review MFA denial evidence / مراجعة رفض المصادقة',exact:true}).click();reply=await read;
   assert.equal(reply.status(),200);const evidence=await reply.json();
   assert.equal(evidence.suspicious_activity_verified,false);assert.equal(evidence.incident_confirmed,false);assert.equal(evidence.automated_action_taken,false);
   assert.equal(JSON.stringify(evidence).includes(f.private_marker),false);
   const observation=audit.getByRole('region',{name:'Observed MFA denial evidence'});
   await observation.getByText(language==='ar'?/لا يثبت اختراقاً/:/does not prove compromise/).waitFor();
   assert.equal((await observation.textContent()).includes(f.private_marker),false);
  }
  stage='late-reply-revocation';await reveal(accounts);
  await page.evaluate(()=>{window.holdAdminDirectory=true;});
  await accounts.locator('form').first().locator('button[type=submit]').click();
  await page.waitForFunction(()=>typeof window.releaseAdminDirectory==='function');
  await provisionTestRole(f.admin_email,'customer',{pages:[page],runner});
  const expired=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/me');
  await page.evaluate(()=>identity());assert.equal((await expired).status(),401);
  await page.evaluate(()=>window.releaseAdminDirectory());
  assert.equal(await accounts.locator('table').count(),0);assert.equal(await audit.locator('section[aria-label="Observed MFA denial evidence"]').count(),0);
  await page.locator('#account').waitFor({state:'visible'});
  assert.deepEqual(errors,[]);
  console.log('PASS actual Laravel Admin EN/AR390px literal same-company filters, private allowlist, bounded non-verdict MFA evidence and late reply after real role revocation; no money or charger action.');
 }finally{await browser.close();}
})().catch(error=>{console.error(JSON.stringify({suite:'admin-current-authority',stage,error:['AssertionError','TimeoutError'].includes(error.name)?error.name:'Error'}));process.exitCode=1;});

}
