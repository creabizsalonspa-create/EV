// Actual Chromium UI contracts with an explicit fake API boundary; no partner calls.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const key = '00000000-0000-4000-8000-000000000001';
const draft = () => ({id: 1, revision: 1, request_key: key, configuration_current: true, reference: 'Synthetic <script> reference',
  evidence_sha256: 'a'.repeat(64), terms_en: 'Synthetic <img src=x> technical evidence. No real partner.', terms_ar: 'دليل تقني اصطناعي لاختبار شروط الطرفين فقط.',
  allowed_modules: ['locations'], valid_from: '2026-01-01T00:00:00Z', valid_until: '2027-01-01T00:00:00Z', state: 'proposed', current: false,
  created_at: '2026-01-01T00:00:00Z', decisions: []});
const envelope = (rows = []) => ({provider_id: 7, revision: rows.length ? 1 : 0, required: true,
  configured_identity: {local_country:'AE',local_party:'EVX',local_role:'EMSP',peer_country:'NL',peer_party:'ABC',peer_role:'CPO',version:'2.2.1'},
  active_agreement_id: null, external_requests_made:false, partner_acceptance_verified:false,commands_enabled:false,billing_eligible:false,
  agreements:{data:rows,current_page:1,last_page:1,total:rows.length}});
async function mount(page, data = envelope(), ar = false) {
  await page.setContent(`<html lang="${ar?'ar':'en'}" dir="${ar?'rtl':'ltr'}"><head><style>body{margin:0;font:16px sans-serif}input,textarea{box-sizing:border-box;width:100%;max-width:100%}input[type=checkbox]{width:auto}label{display:block}button{max-width:100%}</style></head><body><div id="host"></div></body></html>`);
  await page.addScriptTag({path:path.resolve('services/business-api/public/ocpi-agreements.js')});
  await page.evaluate(async data => {
    window.calls=[]; window.readData=data; window.currentActor=true; window.mode='normal'; window.authorityRefreshes=0;
    await window.EvOcpiAgreements.open({host:document.querySelector('#host'),providerId:7,current:()=>window.currentActor,
      onAuthorityChange:async()=>{window.authorityRefreshes++;},
      api:async(path,method,body)=>{
        window.calls.push({path,method,body:body?structuredClone(body):null});
        if(method==='GET'){
          if(window.mode==='hold-read')return new Promise(resolve=>window.releaseRead=resolve);
          return structuredClone(window.readData);
        }
        if(window.mode==='lose-write')throw Error('PRIVATE-WIRE-DETAIL');
        return {agreement_id:1,external_requests_made:false,provider_activated:false,credentials_require_separate_review:body?.action==='approved'||body?.action==='revoked'};
      }});
  },data);
}
async function fill(page) {
  const form=page.locator('[data-agreement-propose]');
  for(const [name,value]of Object.entries({reference:'Synthetic review only',evidence_sha256:'a'.repeat(64),terms_en:'Synthetic approved technical scope for test only.',terms_ar:'نطاق تقني اصطناعي لأغراض اختبار المراجعة فقط.',valid_from:'2026-01-01T00:00:00Z',valid_until:'2027-01-01T00:00:00Z'}))await form.locator(`[name=${name}]`).fill(value);
  await form.locator('[name=module][value=locations]').check();
}
(async()=>{
  const browser=await chromium.launch({executablePath:process.env.EV_CHROMIUM_PATH||undefined,headless:true,args:['--no-sandbox']});
  let checks=0;
  try {
    const page=await browser.newPage();
    await page.route('https://ocpi-ui.example/**', route => route.fulfill({body:'<html><body></body></html>', contentType:'text/html'}));
    await page.goto('https://ocpi-ui.example/');
    await mount(page); await fill(page); await page.getByRole('button',{name:'Record proposed terms',exact:true}).click();
    const writes=await page.evaluate(()=>window.calls.filter(c=>c.method==='POST'));
    assert.equal(writes.length,1); assert.deepEqual(writes[0].body.allowed_modules,['locations']);assert.equal(writes[0].body.expected_revision,0);assert.match(writes[0].body.request_key,/^[0-9a-f-]{36}$/);checks++;
    await mount(page); await fill(page); await page.evaluate(()=>window.mode='lose-write');
    await page.getByRole('button',{name:'Record proposed terms',exact:true}).click();
    await page.getByText('No confirmed outcome. Review recorded history or retry exactly the same action; nothing is retried automatically.',{exact:true}).waitFor();
    assert.equal((await page.evaluate(()=>window.calls.filter(c=>c.method==='POST'))).length,1);
    await page.evaluate(()=>window.mode='normal');
    await page.getByRole('button',{name:'Retry exact same action',exact:true}).click();
    const retries=await page.evaluate(()=>window.calls.filter(c=>c.method==='POST'));
    assert.equal(retries.length,2);assert.deepEqual(retries[0].body,retries[1].body);assert.equal(await page.getByText('PRIVATE-WIRE-DETAIL').count(),0);checks++;
    await mount(page,envelope([draft()]));
    assert.equal(await page.locator('script img').count(),0);assert.equal(await page.getByText(draft().terms_en,{exact:true}).count(),1);
    await page.locator('[data-agreement-id="1"] [name=reason]').fill('Synthetic explicit technical evidence review.');
    await page.getByRole('button',{name:'Approve technical evidence',exact:true}).click();
    assert.equal((await page.evaluate(()=>window.calls.filter(c=>c.method==='POST'))).length,0);
    await page.locator('[data-agreement-id="1"] input[type=checkbox]').check();
    await page.getByRole('button',{name:'Approve technical evidence',exact:true}).click();
    assert.equal(await page.evaluate(()=>window.authorityRefreshes),1);
    const actions=await page.evaluate(()=>window.calls.filter(c=>c.method==='POST'));
    assert.equal(actions[0].body.action,'approved');assert.equal(actions.length,1);assert(!actions.some(c=>c.path.includes('/authorization')||c.path.includes('/negotiate')));checks++;
    await mount(page,envelope([draft()]));
    await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>true});document.dispatchEvent(new Event('visibilitychange'));});
    assert.equal(await page.getByText(draft().terms_en,{exact:true}).count(),0);assert.equal(await page.locator('textarea').count(),0);
    await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>false});document.dispatchEvent(new Event('visibilitychange'));});
    assert.equal((await page.evaluate(()=>window.calls)).length,1);
    await page.getByRole('button',{name:'Reload agreement history',exact:true}).click();
    assert.equal((await page.evaluate(()=>window.calls)).length,2);checks++;
    for(const difference of [{commands_enabled:true},{billing_eligible:true},{provider_id:8},{partner_acceptance_verified:true}]){
      await mount(page,{...envelope(),...difference});
      await page.getByText('Agreement history unavailable. No provider action was taken.',{exact:true}).waitFor();
      assert.equal(await page.locator('form').count(),0);checks++;
    }
    await mount(page,envelope([draft()]));
    await page.evaluate(()=>{
      window.mode='hold-read';document.querySelector('[data-ocpi-agreements] button').click();
    });
    // The first button is refresh in this snapshot. Wait for the held GET to exist.
    await page.waitForFunction(()=>typeof window.releaseRead==='function');
    await page.evaluate(data=>{window.currentActor=false;window.releaseRead(data);},envelope([draft()]));
    await page.waitForTimeout(30);
    assert.equal(await page.getByText(draft().terms_en,{exact:true}).count(),0);checks++;
    await page.setViewportSize({width:390,height:844});await mount(page,envelope([draft()]),true);
    await page.getByRole('heading',{name:'اتفاقيات OCPI التقنية',exact:true}).waitFor();
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    assert.equal(await page.locator('[data-agreement-propose] [name=terms_ar]').getAttribute('dir'),'rtl');checks++;
    console.log(`PASS ${checks} controlled Chromium OCPI agreement actions/privacy/retry/identity/capability/Arabic checks; synthetic only.`);
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
