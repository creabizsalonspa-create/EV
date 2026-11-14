'use strict';
const {execFileSync}=require('node:child_process'), {randomBytes}=require('node:crypto'), assert=require('node:assert/strict'), {chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
let stage='fixture';
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const f=JSON.parse(execFileSync('./scripts/php',['tests/Fixtures/admin-operations-map.php',run,password],{encoding:'utf8'}));assert.equal(f.fixture_only,true);
 async function login(email){const c=new BusinessClient();c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email,password});c.csrf=(await c.request('csrf')).token;return c;}
 stage='admin-login';const c=await login(f.admin_email);
 stage='private-read';const data=await c.request('admin/operations/map?q='+encodeURIComponent(f.name));assert.equal(data.total,1);assert.equal(data.data[0].company.id,f.company_id);assert.equal(data.data[0].remote_control,false);
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  const context=await browser.newContext({viewport:{width:390,height:844}});await context.addCookies([...c.cookies].map(([name,value])=>({name,value,url:c.base})));
  const page=await context.newPage(),errors=[];let tiles=0;page.on('pageerror',e=>errors.push(e.name));
  await page.route('https://tile.openstreetmap.org/**',route=>{tiles++;return route.abort();});
  stage='web-list';await page.goto(c.base);const root=page.locator('#admin-operations-map');await root.locator('input[name=q]').fill(f.name);
  assert.equal(tiles,0);await root.getByRole('button',{name:'Search / refresh inventory',exact:true}).click();await root.locator('article').filter({hasText:f.name}).waitFor();assert.equal(await root.locator('article').count(),1);assert.equal(tiles,0);
  stage='consented-map';const tileRequest=page.waitForRequest(request=>new URL(request.url()).hostname==='tile.openstreetmap.org');await root.getByRole('button',{name:'Enable map tiles',exact:true}).click();await root.locator('.leaflet-container').waitFor();await tileRequest;
  await root.getByRole('button',{name:'Search / refresh inventory',exact:true}).click();await root.locator('.leaflet-marker-icon').waitFor();
  assert.equal(await root.locator('article').count(),1);
  stage='disable';await root.getByRole('button',{name:'Disable map tiles',exact:true}).click();assert.equal(await root.locator('.leaflet-container').count(),0);assert.equal(await root.locator('article').count(),0);
  stage='arabic';await page.locator('#language').click();await root.getByRole('button',{name:'بحث / تحديث المخزون',exact:true}).click();
  await root.locator('article').filter({hasText:f.name}).waitFor();assert.equal(await root.locator('input[name=q]').count(),1);assert.equal(await root.locator('select[name=recent_source_status]').count(),1);
  assert.equal(await page.locator('html').getAttribute('dir'),'rtl');assert.deepEqual(errors,[]);
  stage='customer-isolation';const customer=await login(f.customer_email);await assert.rejects(customer.request('admin/operations/map'),/403/);
  console.log('Actual Laravel/Admin web map search, consent-only intercepted tiles, canonical inventory ownership and customer denial passed; no external tiles, physical commands or production acceptance.');
 }finally{await browser.close();}
})().catch(error=>{console.error(JSON.stringify({suite:'admin_operations_map',stage,error:error?.name==='TimeoutError'?'TimeoutError':'Error'}));process.exitCode=1;});
