const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try {for(const ar of [false,true]){
  const page=await browser.newPage();await page.setContent(`<html lang="${ar?'ar':'en'}"><body><main id="host"></main></body></html>`);await page.addScriptTag({path:'services/business-api/public/ocpi-sync-plans.js'});
  await page.evaluate(async()=>{
   window.writes=[];window.enabled=false;window.active=true;
   const api=async(path,method='GET',body)=>{
    if(method==='PUT'){window.writes.push({path,body});window.enabled=body.enabled;throw Error('PRIVATE PARTNER RESPONSE');}
    return {catalog_health:{state:'attention',checked_at:'2026-11-15T12:00:00.000000Z',max_age_seconds:86400,published_count:6,within_policy_count:1,stale_count:3,unknown_age_count:2,future_clock_count:2,evidence:'PARTNER_CATALOG_TIMESTAMPS_ONLY',physical_availability_verified:false,remote_control:false,billing_authority:false},configured:true,read_only_polling:true,billing_authority:false,remote_control:false,intervals:[15,60],plans:[{module:'locations',enabled:window.enabled,consent_current:window.enabled,revision:'9007199254740993',interval_minutes:60,negotiated:true,running:false}],history:{data:[{module:'locations',state:'failed',started_at:'<img src=x onerror=alert(1)>',result_code:'poll_requires_review'}],current_page:1,last_page:1}};
   };
   await window.EvOcpiSyncPlans.open({host:document.querySelector('#host'),providerId:7,api,current:()=>window.active});
  });
  assert.match(await page.locator('[data-catalog-health]').textContent(),ar?/ساعات مستقبلية 2/:/future clocks 2/);
  const save=page.getByRole('button',{name:ar?'تفعيل القراءة المراجعة':'Enable reviewed polling',exact:true});assert.equal(await save.isDisabled(),true);assert.equal(await page.locator('img').count(),0);
  await page.getByRole('checkbox').check();await page.getByRole('combobox').selectOption('15');assert.equal(await save.isDisabled(),true);
  await page.getByRole('checkbox').check();await save.click();
  const refresh=page.getByRole('button',{name:ar?'تحديث':'Refresh',exact:true});await refresh.waitFor();
  assert.deepEqual(await page.evaluate(()=>window.writes),[{path:'ocpi/providers/7/sync-plans/locations',body:{enabled:true,confirmed:true,interval_minutes:15,expected_revision:'9007199254740993'}}]);
  assert.equal(await page.getByText('PRIVATE PARTNER RESPONSE').count(),0);await refresh.click();
  const disable=page.getByRole('button',{name:ar?'تعطيل قراءة الوحدة':'Disable module polling',exact:true});await disable.waitFor();assert.equal(await page.evaluate(()=>window.writes.length),1);
  await page.evaluate(()=>window.active=false);await disable.click();assert.equal(await page.evaluate(()=>window.writes.length),1);await page.close();
 }
 console.log('PASS scheduled OCPI EN/AR consent, interval reset, exact revision, safe rendering, unknown response and account fence');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
