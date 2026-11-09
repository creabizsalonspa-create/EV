// Real Chromium UI contract with a SYNTHETIC Admin role and simulated API.
// This is NOT an authenticated server-side Admin authorization acceptance.
const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
  const browser=await chromium.launch({headless:true});
  const page=await browser.newPage({viewport:{width:1280,height:850}});
  const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  try{
    await page.setContent('<!doctype html><html lang="en"><body><section id="private"></section><section id="review"></section></body></html>');
    await page.addScriptTag({content:fs.readFileSync('services/business-api/public/privacy-requests.js','utf8')});
    await page.evaluate(async()=>{
      const admin={id:31,role:'admin'};
      window.fixture={admin,rowStatus:'in_review',patches:0,releases:[]};
      const api=async(path,method,body)=>{
        if(path.startsWith('admin/privacy-requests/')&&method==='PATCH'){
          window.fixture.patches++;
          return new Promise(resolve=>window.fixture.releases.push(
            ()=>resolve({id:200,status:body.status})
          ));
        }
        if(path.startsWith('admin/privacy-requests?page=')){
          return {data:[{
            id:200,status:window.fixture.rowStatus,request_type:'account_deletion',
            reason:'Synthetic privacy review only',resolution:null,decision_note:null,
            user:{email:'fake-review@example.invalid'}
          }],current_page:1,last_page:1,total:1};
        }
        if(path.startsWith('privacy-requests?page='))
          return {data:[],current_page:1,last_page:1,total:0};
        throw Error('Unexpected synthetic request '+path+' '+method);
      };
      window.fixture.workspace=window.createPrivacyWorkspace({
        selfRoot:document.querySelector('#private'),
        adminRoot:document.querySelector('#review'),
        api,actor:()=>window.fixture.admin,epoch:()=>1,
      });
      await window.fixture.workspace.setActor(admin);
    });
    const reviewForm=page.locator('#review form.privacy-review-form');
    await reviewForm.waitFor();
    await reviewForm.locator('select').selectOption('review_documented');
    await reviewForm.locator('textarea').fill('Synthetic documented review for the fake Admin UI.');
    await reviewForm.locator('button[type=submit]').click();
    await page.waitForFunction(()=>window.fixture.patches===1);
    await page.evaluate(()=>window.fixture.workspace.refreshAdmin());
    const latestReview=page.locator('#review form.privacy-review-form');
    assert.equal(await latestReview.locator('button[type=submit]').isDisabled(),true,
      'Refreshed Admin form must preserve the pending per-request lock');
    await page.evaluate(()=>document.querySelector('#review form').onsubmit({
      preventDefault(){}
    }));
    assert.equal(await page.evaluate(()=>window.fixture.patches),1,
      'A refreshed Admin close must not send a second PATCH');
    await page.evaluate(()=>window.fixture.releases.shift()());
    await page.waitForFunction(()=>
      !document.querySelector('#review form button[type=submit]').disabled);
    await page.evaluate(()=>{window.fixture.rowStatus='submitted';return window.fixture.workspace.refreshAdmin()});
    const start=page.getByRole('button',{name:'Start review'});
    await start.click();
    await page.waitForFunction(()=>window.fixture.patches===2);
    await page.evaluate(()=>window.fixture.workspace.refreshAdmin());
    assert.equal(await start.isDisabled(),true,
      'Refreshed start-review control must remain locked until the PATCH settles');
    await page.evaluate(()=>[...document.querySelectorAll('#review button')]
      .find(x=>x.textContent.includes('Start review')).onclick());
    assert.equal(await page.evaluate(()=>window.fixture.patches),2,
      'A refreshed start-review button must not send a second PATCH');
    await page.evaluate(()=>window.fixture.releases.shift()());
    await page.waitForFunction(()=>[...document.querySelectorAll('#review button')]
      .some(x=>x.textContent.includes('Start review')&&!x.disabled));
    assert.deepEqual(errors,[]);
    await page.evaluate(()=>window.fixture.workspace.clear());
    assert.equal(await page.locator('#review').innerText(),'');
    console.log('PASS: synthetic Admin Chromium DOM; close/start review locks persist across queue refresh, release on settle and clear on logout.');
  }finally{await browser.close();}
})().catch(err=>{console.error(err);process.exitCode=1;});
