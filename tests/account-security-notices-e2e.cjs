'use strict';
const {chromium}=require('playwright'),{createHmac}=require('node:crypto'),assert=require('node:assert/strict');
const {BusinessClient}=require('./helpers/business-client.cjs');
function totp(secret){
 assert.match(secret,/^[A-Z2-7]{32}$/);
 let bits='';for(const char of secret)bits+='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(char).toString(2).padStart(5,'0');
 const key=Buffer.from(bits.match(/.{8}/g).map(byte=>parseInt(byte,2))),counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const digest=createHmac('sha1',key).update(counter).digest(),offset=digest[19]&15;
 return String((digest.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0');
}
let stage='register';
(async()=>{
 const client=await new BusinessClient().register('customer'),password=client.testCredentials.password;
 stage='verify';await client.verifyEmail();
 stage='prepare';const prepared=await client.request('account/second-factor/prepare','POST',{password});
 stage='enable';const enabled=await client.request('account/second-factor/enable','POST',{password,code:totp(prepared.secret)});
 assert.match(enabled.csrf_token,/^[A-Za-z0-9]{40}$/);client.csrf=enabled.csrf_token;
 stage='rotate';const rotated=await client.request('account/second-factor/recovery-codes','POST',{password,code:enabled.recovery_codes[0]});
 assert.match(rotated.csrf_token,/^[A-Za-z0-9]{40}$/);client.csrf=rotated.csrf_token;
 stage='disable';const disabled=await client.request('account/second-factor/disable','POST',{password,code:rotated.recovery_codes[0]});
 assert.match(disabled.csrf_token,/^[A-Za-z0-9]{40}$/);client.csrf=disabled.csrf_token;
 const notices=await client.request('notifications');assert.equal(notices.total,5);assert.deepEqual(notices.data.map(row=>row.kind).sort(),['account.security.enabled','account.security.disabled','account.security.recovery_rotated','account.security.recovery_used','account.security.recovery_used'].sort());
 const raw=JSON.stringify(notices);for(const secret of [password,prepared.secret,...enabled.recovery_codes,...rotated.recovery_codes])assert.equal(raw.includes(secret),false);
 stage='browser';const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  const context=await browser.newContext();await context.addCookies([...client.cookies].map(([name,value])=>({name,value,url:client.base})));
  const page=await context.newPage();await page.goto(client.base);const inbox=page.locator('#notifications');
  await inbox.locator('[data-notification-id]').first().waitFor();assert.equal(await inbox.locator('[data-notification-id]').count(),5);
  stage='browser-csrf-handoff';
  const webPrepared=await page.evaluate(password=>api('account/second-factor/prepare','POST',{password}),password);
  const webEnabled=await page.evaluate(body=>api('account/second-factor/enable','POST',body),{password,code:totp(webPrepared.secret)});
  const webRotated=await page.evaluate(body=>api('account/second-factor/recovery-codes','POST',body),{password,code:webEnabled.recovery_codes[0]});
  await page.evaluate(body=>api('account/second-factor/disable','POST',body),{password,code:webRotated.recovery_codes[0]});
  await inbox.locator('#notification-refresh').click();await page.waitForFunction(()=>document.querySelectorAll('#notifications [data-notification-id]').length===10);
  stage='browser';
  for(const fragment of ['Authenticator enabled','Authenticator disabled','recovery codes replaced','recovery code was used'])assert.match(await inbox.textContent(),new RegExp(fragment));
  assert.equal(await inbox.locator('img').count(),0);await page.locator('#language').click();await page.waitForFunction(()=>document.documentElement.lang==='ar');
  for(const fragment of ['تم تفعيل','تم تعطيل','تم استبدال','تم استخدام'])assert((await inbox.textContent()).includes(fragment));
  const row=inbox.locator('[data-notification-id]').first(),id=await row.getAttribute('data-notification-id');
  const mark=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/notifications/${id}/read`&&r.request().method()==='POST');await row.locator('button').click();assert.equal((await mark).status(),200);
  assert.equal((await client.request('notifications')).data.find(n=>String(n.id)===id).read_at!==null,true);
  console.log('PASS actual Laravel/browser private MFA change/recovery notices, bilingual text and scoped read controls (synthetic loopback account)');
 }finally{await browser.close();}
})().catch(error=>{console.error(JSON.stringify({suite:'account-security-notices',stage,error:['AssertionError','TimeoutError'].includes(error?.name)?error.name:'Error',http_status:/failed \((\d{3})\)/.exec(error?.message||'')?.[1]||null}));process.exitCode=1;});
