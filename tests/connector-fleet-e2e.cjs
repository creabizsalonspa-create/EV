'use strict';
const {execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
let stage='fixture';
const proof={phase:'fixture',requests:0,englishResponse:null,arabicResponse:null,matchedStationScope:[],responseStatuses:[],transportFailures:[]};
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!';
 const fixture=JSON.parse(execFileSync(process.env.EV_TEST_PHP_RUNNER||'./scripts/php',['tests/Fixtures/unified-discovery.php',run,password],{encoding:'utf8'}));
 assert.equal(fixture.fixture_only,true);
 const client=new BusinessClient({base:process.env.EV_TEST_URL||'http://127.0.0.1:8000'});client.csrf=(await client.request('csrf')).token;
 stage='login';await client.request('login','POST',{email:`canonical-owner-${run}@example.test`,password});
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 let resumeIdentity=()=>{};
 try {
  const context=await browser.newContext({viewport:{width:390,height:844}});await context.addCookies([...client.cookies].map(([name,value])=>({name,value,url:client.base})));
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));let calls=0;
  const requestedStationIds=[];page.on('request',r=>{const url=new URL(r.url());if(url.pathname==='/api/charging-operations/connectors'){assert.equal(r.method(),'GET');calls++;proof.requests=calls;requestedStationIds.push(url.searchParams.get('station_id'));proof.matchedStationScope.push(url.searchParams.get('station_id')===String(fixture.station_id));}});
  page.on('response',r=>{if(new URL(r.url()).pathname==='/api/charging-operations/connectors')proof.responseStatuses.push(r.status());});
  page.on('requestfailed',r=>{
    if(new URL(r.url()).pathname!=='/api/charging-operations/connectors')return;
    const failure=r.failure(),raw=typeof failure==='string'?failure:failure?.errorText||'';
    proof.transportFailures.push(/^(?:net::ERR_[A-Z_]+|NS_ERROR_[A-Z_]+)$/.test(raw)?raw:'other_network_failure');
  });
  stage='web-en-navigate';await page.goto(client.base);
  stage='web-en-bootstrap';await page.locator('#charging-dashboard section').getByRole('button',{name:'Read connector snapshot',exact:true}).waitFor();
  // The history refresh renders the read button before its transaction completes.
  // Wait for the same refresh request to finish before starting a new scoped read.
  stage='web-en-history-complete';await page.waitForFunction(()=>{
    const button=document.querySelector('#commands-refresh');
    return button&&button.disabled===false;
  });
  const root=page.locator('#charging-dashboard section');stage='web-en-form';await root.getByRole('button',{name:'Read connector snapshot',exact:true}).waitFor();
  stage='web-en-no-auto-request';assert.equal(calls,0);
  stage='web-en-station';await root.locator('input[name=fleet-station]').fill(String(fixture.station_id));
  stage='web-en-read';const englishResponse=page.waitForResponse(r=>{
    const url=new URL(r.url());
    return url.pathname==='/api/charging-operations/connectors' &&
      r.request().method()==='GET';
  });
  await root.getByRole('button',{name:'Read connector snapshot',exact:true}).click();
  const englishScoped=await englishResponse;proof.englishResponse=englishScoped.status();
  stage='web-en-scope-response';assert.equal(new URL(englishScoped.url()).searchParams.get('station_id'),String(fixture.station_id));
  stage='web-en-response';assert.equal(englishScoped.status(),200);
  stage='web-en-result';await root.getByText('Recent report at snapshot: Available',{exact:true}).waitFor();
  stage='web-en-count';assert.equal(await root.locator('article').count(),1);assert.equal(calls,1);
  stage='web-en-scope';assert.deepEqual(requestedStationIds,[String(fixture.station_id)]);
  stage='web-en-safety';assert.match(await root.textContent(),/No physical availability/);assert.equal(await root.getByRole('button',{name:/start|stop|reset/i}).count(),0);
  // Hold a real identity refresh before its final automatic command-history
  // load. An explicit user snapshot/draft must survive this late bootstrap.
  const identityGate=new Promise(resolve=>{resumeIdentity=resolve;});
  const pausedIdentity=page.waitForRequest(r=>new URL(r.url()).pathname==='/api/ocpi/providers');
  await page.route('**/api/ocpi/providers?*',async route=>{await identityGate;await route.continue();},{times:1});
  stage='identity-refresh-paused';await page.evaluate(()=>{window.pendingConnectorIdentity=identity();});
  await pausedIdentity;
  stage='web-ar-language';await page.locator('#language').click();await page.locator('#commands-refresh').click();
  stage='web-ar-history-complete';await page.waitForFunction(()=>{
    const button=document.querySelector('#commands-refresh');
    return button&&button.disabled===false;
  });
  stage='web-ar-station';await root.locator('input[name=fleet-station]').fill(String(fixture.station_id));
  stage='late-identity-preserves-explicit-scope';resumeIdentity();await page.evaluate(()=>window.pendingConnectorIdentity);
  assert.equal(await root.locator('input[name=fleet-station]').inputValue(),String(fixture.station_id));
  // Explicitly re-apply the owned synthetic fixture after changing language.
  // A fresh dashboard widget is not expected to preserve an uncommitted form filter.
  stage='web-ar-read';
  const arabicResponse = page.waitForResponse(r=>{
    const url=new URL(r.url());
    return url.pathname==='/api/charging-operations/connectors' &&
      r.request().method()==='GET';
  });
  await root.getByRole('button',{name:'قراءة لقطة الموصلات',exact:true}).click();
  const scopedResponse = await arabicResponse;
  stage='web-ar-scope-response';assert.equal(new URL(scopedResponse.url()).searchParams.get('station_id'),String(fixture.station_id));
  stage='web-ar-response';proof.arabicResponse=scopedResponse.status();assert.equal(scopedResponse.status(),200);
  const snapshot=await scopedResponse.json();
  assert.equal(snapshot.scope,'recorded_connector_fleet');
  assert.equal(snapshot.evidence,'SIMULATED_SOURCE');
  assert.equal(snapshot.connectors?.data?.length,1);
  assert.equal(snapshot.connectors.data[0].station_id,fixture.station_id);
  assert.equal(snapshot.connectors.data[0].inventory_eligible_at_read,true);
  assert.equal(snapshot.connectors.data[0].status_freshness.state,'recent');
  assert.equal(snapshot.connectors.data[0].status_freshness.reported_status,'Available');
  stage='web-ar-result';
  try {
    await root.getByText('تقرير حديث وقت اللقطة: متاح بحسب المصدر',{exact:true}).waitFor({timeout:12000});
  } catch (error) {
    const view=await root.evaluate(el=>({
      lang:document.documentElement.lang,
      articles:el.querySelectorAll('article').length,
      readForms:el.querySelectorAll('input[name=fleet-station]').length,
      visibleError:el.textContent.includes('لقطة الموصلات غير متاحة'),
      visibleUnknown:el.textContent.includes('حالة المصدر غير معروفة'),
      visibleStale:el.textContent.includes('تقرير قديم'),
      visibleAvailable:el.textContent.includes('متاح بحسب المصدر'),
      reading:el.textContent.includes('جارٍ قراءة الموصلات المسجلة'),
    })).catch(()=>({rootUnavailable:true}));
    console.error(JSON.stringify({suite:'connector_fleet',stage,diagnostic:view}));
    throw error;
  }
  assert.equal(calls,2);
  assert.deepEqual(requestedStationIds,[String(fixture.station_id),String(fixture.station_id)]);
  stage='background';await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});
  assert.equal(await root.locator('article').count(),0);
  await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});assert.equal(calls,2);assert.equal(await root.locator('article').count(),0);
  assert.deepEqual(errors,[]);console.log('PASS actual Laravel/vendor fleet source snapshot EN/AR, explicit scoped reads and background clearing; no command/payment or source query.');
 } finally { resumeIdentity();try { await browser.close(); } finally { execFileSync(process.env.EV_TEST_PHP_RUNNER||'./scripts/php',['tests/Fixtures/unified-discovery-clean.php',run],{stdio:'pipe'}); } }
})().catch(e=>{console.error(JSON.stringify({suite:'connector_fleet',stage,error:e?.name==='TimeoutError'?'TimeoutError':e?.name||'Error',operator:e?.operator||null,
  // Never print account IDs, cookies, source payloads, passwords, bearer tokens or private URLs.
  proof}));process.exitCode=1;});
