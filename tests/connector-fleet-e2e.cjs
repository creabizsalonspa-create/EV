'use strict';
const {execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
let stage='fixture';
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const fixture=JSON.parse(execFileSync(process.env.EV_TEST_PHP_RUNNER||'./scripts/php',['tests/Fixtures/unified-discovery.php',run,password],{encoding:'utf8'}));
 assert.equal(fixture.fixture_only,true);
 const client=new BusinessClient({base:process.env.EV_TEST_URL||'http://127.0.0.1:8000'});client.csrf=(await client.request('csrf')).token;
 stage='login';await client.request('login','POST',{email:`canonical-owner-${run}@example.test`,password});
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try {
  const context=await browser.newContext({viewport:{width:390,height:844}});await context.addCookies([...client.cookies].map(([name,value])=>({name,value,url:client.base})));
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));let calls=0;
  page.on('request',r=>{if(new URL(r.url()).pathname==='/api/charging-operations/connectors'){assert.equal(r.method(),'GET');calls++;}});
  stage='web-en';await page.goto(client.base);await page.locator('#commands-refresh').click();
  const root=page.locator('#charging-dashboard section');await root.getByRole('button',{name:'Read connector snapshot',exact:true}).waitFor();assert.equal(calls,0);
  await root.locator('input[name=fleet-station]').fill(String(fixture.station_id));await root.getByRole('button',{name:'Read connector snapshot',exact:true}).click();
  await root.getByText('Recent report at snapshot: Available',{exact:true}).waitFor();assert.equal(await root.locator('article').count(),1);assert.equal(calls,1);
  assert.match(await root.textContent(),/No physical availability/);assert.equal(await root.getByRole('button',{name:/start|stop|reset/i}).count(),0);
  stage='web-ar';await page.locator('#language').click();await page.locator('#commands-refresh').click();
  await root.getByRole('button',{name:'قراءة لقطة الموصلات',exact:true}).click();await root.getByText('تقرير حديث وقت اللقطة: متاح بحسب المصدر',{exact:true}).waitFor();assert.equal(calls,2);
  stage='background';await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});
  assert.equal(await root.locator('article').count(),0);
  await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});assert.equal(calls,2);assert.equal(await root.locator('article').count(),0);
  assert.deepEqual(errors,[]);console.log('PASS actual Laravel/vendor fleet source snapshot EN/AR, explicit scoped reads and background clearing; no command/payment or source query.');
 } finally { await browser.close(); }
})().catch(e=>{console.error(JSON.stringify({suite:'connector_fleet',stage,error:e?.name==='TimeoutError'?'TimeoutError':'Error'}));process.exitCode=1;});
