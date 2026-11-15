'use strict';
const{execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const{BusinessClient}=require('./helpers/business-client.cjs');let stage='fixture';
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const f=JSON.parse(execFileSync(process.env.EV_TEST_PHP_RUNNER||'./scripts/php',['tests/Fixtures/fleet-report.php',run,password],{encoding:'utf8'}));assert.equal(f.fixture_only,true);
 async function login(email){const c=new BusinessClient({base:process.env.EV_TEST_URL||'http://127.0.0.1:8000'});c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email,password});c.csrf=(await c.request('csrf')).token;return c;}
 stage='private-api';const owner=await login(f.customer_email),query='?from=2026-11-01&through=2026-11-15',snapshot=await owner.request('fleets/'+f.fleet_id+'/report'+query);assert.equal(snapshot.approved_claims,0);assert.equal(snapshot.current_inventory.cost_centers,1);assert.equal(snapshot.period.timezone,'Asia/Dubai');assert.equal(snapshot.physical_charging_verified,false);
 const outsider=await login(f.outsider_email);await assert.rejects(outsider.request('fleets/'+f.fleet_id+'/report'+query),/404/);
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  for(const admin of [false,true]){
   const client=admin?await login(f.admin_email):owner;const context=await browser.newContext({viewport:{width:390,height:844}});await context.addCookies([...client.cookies].map(([name,value])=>({name,value,url:client.base})));const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));stage=admin?'admin-web':'owner-web';await page.goto(client.base);
   let root;
   if(admin){const host=page.locator('#fleet-admin-report');await host.locator('input').fill(String(f.fleet_id));await host.getByRole('button',{name:'Open fleet reporting',exact:true}).click();root=host.locator(':scope > div').first();}
   else{await page.locator('#fleet-list').getByRole('button',{name:f.fleet_name,exact:true}).click();root=page.locator('#fleet-detail section').first();}
   stage=admin?'admin-read-en':'owner-read-en';await root.getByRole('button',{name:'Read fleet report',exact:true}).click();await root.getByText('Approved claims: 0',{exact:true}).waitFor();
   stage=admin?'admin-read-ar':'owner-read-ar';await page.locator('#language').click();await root.getByRole('button',{name:'قراءة تقرير الأسطول',exact:true}).click();await root.getByText('المطالبات المعتمدة: 0',{exact:true}).waitFor();assert.equal(await page.locator('html').getAttribute('dir'),'rtl');assert.deepEqual(errors,[]);await context.close();
  }
  console.log('PASS actual Laravel fleet owner and protected Admin reports EN/AR, regional dates and outsider denial; no charging/payment mutation.');
 }finally{await browser.close();}
})().catch(error=>{console.error(JSON.stringify({suite:'fleet_report',stage,error:error?.name==='TimeoutError'?'TimeoutError':'Error'}));process.exitCode=1;});
