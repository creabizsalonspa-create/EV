'use strict';
const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
function verifySnapshotClock() {
 const window={};
 for(const name of ['station-time','connector-freshness'])
  new Function('window',fs.readFileSync('services/business-api/public/'+name+'.js','utf8'))(window);
 const source=fs.readFileSync('services/business-api/public/connector-fleet.js','utf8');
 const start=source.indexOf('    function sourceClock('),end=source.indexOf('    const clockLine',start);
 assert(start>=0&&end>start);
 const {sourceClock,stateAtSnapshot}=new Function('window',source.slice(start,end)+'\nreturn {sourceClock,stateAtSnapshot};')(window);
 const f={state:'recent',reported_status:'Faulted',observed_at:'2026-11-15T11:55:00.000005Z',
  checked_at:'2026-11-15T11:59:59.999999Z',valid_until:'2026-11-15T12:00:00.000005Z',
  max_age_seconds:300,evidence:'SIMULATED_SOURCE',physical_availability_verified:false};
 assert.equal(stateAtSnapshot(f,'2026-11-15T12:00:00.000004Z'),'Faulted');
 assert.equal(stateAtSnapshot(f,'2026-11-15T12:00:00.000005Z'),'stale');
 assert.equal(stateAtSnapshot(f,'2026-11-15T11:59:59.999998Z'),'unknown');
 assert.equal(stateAtSnapshot(f,'2026-11-15T11:59:59.999999Z'),'Faulted');
 for(const raw of ['2026-02-30T12:00:00Z','2026-11-15 12:00:00','2026-11-15T12:00:00+00:00','2026-11-15T12:00:00Z\n',null]){
  assert.equal(sourceClock(raw),null);
  assert.equal(stateAtSnapshot(f,raw),'unknown');
 }
 assert.equal(stateAtSnapshot({...f,checked_at:'2026-11-15 11:59:59'},'2026-11-15T12:00:00Z'),'unknown');
 assert.equal(stateAtSnapshot({...f,physical_availability_verified:true},'2026-11-15T12:00:00Z'),'unknown');
}
verifySnapshotClock();
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});try{
for(const ar of [false,true]){
 const page=await browser.newPage();await page.setContent(`<html lang="${ar?'ar':'en'}"><body><section id="host"></section></body></html>`);await page.addScriptTag({path:'services/business-api/public/station-time.js'});await page.addScriptTag({path:'services/business-api/public/connector-freshness.js'});await page.addScriptTag({path:'services/business-api/public/connector-fleet.js'});
 await page.evaluate(()=>{
  window.opened=[];window.viewer={id:1,role:'vendor'};window.reads=[];
  window.report=p=>({scope:'recorded_connector_fleet',as_of:'2026-11-15T12:00:00Z',evidence:'SIMULATED_SOURCE',physical_availability_verified:false,remote_control:false,billing_authority:false,connectors:{current_page:p,last_page:2,data:[{mapping_id:p,station_id:7,station_name:'<img src=x> private fleet',protocol:'1.6',inventory_eligible_at_read:true,operations_target:{company_id:7,charger_id:9},status_freshness:{state:p===1?'recent':'stale',reported_status:p===1?'Faulted':null,observed_at:p===1?'2026-11-15T11:59:00Z':'2026-11-15T11:50:00Z',checked_at:'2026-11-15T12:00:00Z',valid_until:p===1?'2026-11-15T12:04:00Z':'2026-11-15T11:55:00Z',max_age_seconds:300,evidence:'SIMULATED_SOURCE',physical_availability_verified:false}}]}});
  window.render=api=>{document.querySelector('#host').replaceChildren();renderConnectorFleetAction(document.querySelector('#host'),{actor:()=>viewer,api,openDiagnostics:async(company,charger)=>opened.push([company,charger])});};
  render(async(path,method)=>{if(method!=='GET')throw Error('Unexpected write');reads.push(path);return report(Number(new URL(path,'https://example.test').searchParams.get('page')));});
 });
 const read=page.getByRole('button',{name:ar?'قراءة لقطة الموصلات':'Read connector snapshot',exact:true});assert.equal(await page.evaluate(()=>reads.length),0);
 await page.locator('input').fill('7');await read.click();await page.locator('article').waitFor();assert.equal(await page.locator('img').count(),0);assert.match(await page.locator('article').textContent(),ar?/عطل/:/Faulted/);
 const expected=await page.evaluate(ar=>new Intl.DateTimeFormat(ar?'ar':'en-GB',{timeZone:'UTC',dateStyle:'medium',timeStyle:'short',hourCycle:'h23'}).format(new Date('2026-11-15T12:00:00Z'))+' (UTC)',ar);
 assert.ok((await page.locator('#host').textContent()).includes(expected));
 assert.ok(!(await page.locator('#host').textContent()).includes('2026-11-15T12:00:00Z'));
 await page.evaluate(()=>{window.oldDiagnostics=[...document.querySelectorAll('article button')][0].onclick;window.oldNext=[...document.querySelectorAll('#host button')].at(-1).onclick;});
 assert.deepEqual(await page.evaluate(()=>opened),[]);await page.getByRole('button',{name:ar?'فتح تشخيص المصدر':'Open source diagnostics',exact:true}).click();assert.deepEqual(await page.evaluate(()=>opened),[[7,9]]);
 await page.locator('input').fill('999');await page.getByRole('button',{name:ar?'التالي':'Next',exact:true}).click();await page.getByText(ar?'تقرير قديم؛ الحالة الحالية غير معروفة':'Stale report; current state unknown',{exact:true}).waitFor();assert.deepEqual(await page.evaluate(()=>reads),['charging-operations/connectors?page=1&station_id=7','charging-operations/connectors?page=2&station_id=7']);
 await page.evaluate(async()=>{await oldDiagnostics();oldNext();});
 assert.deepEqual(await page.evaluate(()=>opened),[[7,9]]);
 assert.equal(await page.evaluate(()=>reads.length),2);
 await page.evaluate(()=>render(async()=>{const r=report(1);r.connectors.data[0].status_freshness.observed_at='2026-02-30T11:59:00Z';return r;}));
 await read.click();await page.locator('article').waitFor();
 assert.ok((await page.locator('article').textContent()).includes(ar?'حالة المصدر غير معروفة':'Source state unknown'));
 assert.ok((await page.locator('article').textContent()).includes(ar?'وقت الحدث غير متاح':'Event time unavailable'));
 await page.evaluate(()=>render(async()=>{const r=report(1);r.as_of='2026-11-15T12:04:00Z';return r;}));
 await read.click();await page.getByText(ar?'تقرير قديم؛ الحالة الحالية غير معروفة':'Stale report; current state unknown',{exact:true}).waitFor();
 await page.evaluate(()=>render(async()=>{const r=report(1);r.as_of='2026-11-15T12:00:00.000001Z';r.connectors.data[0].status_freshness.checked_at='2026-11-15T12:00:00.000002Z';return r;}));
 await read.click();await page.getByText(ar?'حالة المصدر غير معروفة':'Source state unknown',{exact:true}).waitFor();
 await page.evaluate(()=>render(async()=>{const r=report(1);r.as_of='2026-02-30T12:00:00Z';return r;}));
 await read.click();assert.equal(await page.locator('article').count(),0);
 await page.evaluate(()=>render(async()=>({...report(1),remote_control:true})));await read.click();assert.equal(await page.locator('article').count(),0);
 await page.evaluate(()=>render(()=>new Promise(resolve=>window.finish=resolve)));await read.click();await page.evaluate(()=>{viewer={id:2,role:'vendor'};finish(report(1));});assert.equal(await page.locator('article').count(),0);
 await page.close();
}
console.log('PASS connector fleet EN/AR explicit reads, safe source text, frozen pagination, forbidden authority and late account fencing.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
