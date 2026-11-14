const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try {for(const ar of [false,true]){
  const page=await browser.newPage();await page.setContent(`<html lang="${ar?'ar':'en'}"><body><main id="host"></main></body></html>`);await page.addScriptTag({path:'services/business-api/public/ocpi-sync-plans.js'});
  await page.evaluate(async()=>{
   window.writes=[];window.enabled=false;window.active=true;
   const api=async(path,method='GET',body)=>{
    if(method==='PUT'){window.writes.push({path,body});window.enabled=body.enabled;throw Error('PRIVATE PARTNER RESPONSE');}
    return {configured:true,read_only_polling:true,billing_authority:false,remote_control:false,intervals:[15,60],plans:[{module:'locations',enabled:window.enabled,consent_current:window.enabled,revision:'9007199254740993',interval_minutes:60,negotiated:true,running:false}],history:{data:[{module:'locations',state:'failed',started_at:'<img src=x onerror=alert(1)>',result_code:'poll_requires_review'}],current_page:1,last_page:1}};
   };
   await window.EvOcpiSyncPlans.open({host:document.querySelector('#host'),providerId:7,api,current:()=>window.active});
  });
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
