// Actual Laravel, cookie/CSRF and Chromium; isolated synthetic DB and no peer calls.
const {chromium} = require('playwright');
const {execFileSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const {mkdirSync,writeFileSync,openSync,closeSync} = require('node:fs');
const path = require('node:path'), net = require('node:net'), assert = require('node:assert/strict');
const image = 'composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc';
let stage='setup';
(async()=>{
  const run=randomBytes(4).toString('hex'), password=randomBytes(24).toString('hex')+'aA9!', secret=randomBytes(16).toString('hex');
  const folder=path.resolve('services/business-api/storage/framework/testing/ocpi-agreement-'+run);
  mkdirSync(folder,{recursive:true,mode:0o700});closeSync(openSync(path.join(folder,'db.sqlite'),'wx',0o600));
  const mounted='/app/services/business-api/storage/framework/testing/ocpi-agreement-'+run;
  const metadata={['agreement_'+run]:{origin:'https://peer.example',token_env:'OCPI_AGREEMENT_TEST_TOKEN',environment:'sandbox',technical_agreement_required:true,managed_credentials:true,party_country:'NL',party_id:'ABC',party_role:'CPO',incoming_modules:['locations','sessions']}};
  const local={url:'https://platform.example/api/roaming-protocol/versions',roles:[{role:'EMSP',country_code:'AE',party_id:'EVX',business_details:{name:'Synthetic agreement platform'}}]};
  const env=path.join(folder,'qa.env');
  writeFileSync(env,[`APP_ENV=local`,`APP_KEY=base64:${randomBytes(32).toString('base64')}`,'APP_DEBUG=false','DB_CONNECTION=sqlite',`DB_DATABASE=${mounted}/db.sqlite`,'SESSION_DRIVER=database','CACHE_STORE=database','QUEUE_CONNECTION=sync','MAIL_MAILER=array',`OCPI_CONNECTIONS_JSON=${JSON.stringify(metadata)}`,`OCPI_LOCAL_CREDENTIALS_JSON=${JSON.stringify(local)}`,`OCPI_AGREEMENT_TEST_TOKEN=${Buffer.from(secret).toString('base64')}`].join('\n')+'\n',{mode:0o600});
  const args=['--user',`${process.getuid()}:${process.getgid()}`,'--env-file',env,'-v',process.cwd()+':/app','-w','/app/services/business-api','--entrypoint','php',image];
  const php=(...commands)=>execFileSync('docker',['run','--rm',...args,...commands],{encoding:'utf8',stdio:['ignore','pipe','pipe']});
  php('artisan','migrate','--force','--no-interaction');
  const fixture=JSON.parse(php('tests/Fixtures/ocpi-technical-agreement.php','seed',run,password));
  const probe=net.createServer();await new Promise(r=>probe.listen(0,'127.0.0.1',r));const port=probe.address().port;await new Promise(r=>probe.close(r));
  const name='ev-ocpi-agreement-'+run, base='http://127.0.0.1:'+port;
  let browser;
  try{
    execFileSync('docker',['run','-d','--rm','--name',name,'--network','host',...args,'artisan','serve','--no-reload','--host=127.0.0.1','--port='+port],{stdio:'pipe'});
    for(let i=0;i<60;i++){try{if((await fetch(base+'/up')).ok)break;}catch{}await new Promise(r=>setTimeout(r,250));if(i===59)throw Error('Owned source did not start');}
    browser=await chromium.launch({executablePath:process.env.EV_CHROMIUM_PATH||undefined,headless:true,args:['--no-sandbox']});
    let versions=0;
    for(const ar of [false,true]){
      stage=ar?'arabic':'english';
      const context=await browser.newContext({viewport:{width:390,height:844}}), page=await context.newPage();const external=[];const errors=[];
      page.on('request',r=>{if(!['127.0.0.1','localhost'].includes(new URL(r.url()).hostname))external.push(r.url());});page.on('pageerror',e=>errors.push(e.message));
      await page.goto(base);await page.locator('#account [name=email]').fill(fixture.admin);await page.locator('#account [name=password]').fill(password);
      const login=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/login'&&r.request().method()==='POST');await page.locator('button[value=login]').click();assert.equal((await login).status(),200);
      if(ar){await page.locator('#language').click();await page.waitForFunction(()=>document.documentElement.lang==='ar');}
      const open=()=>page.getByRole('button',{name:ar?'اتفاقيات OCPI التقنية':'OCPI technical agreements',exact:true}).click();
      stage+=(ar?' initial-open':' initial-open');
      await open(); const root=page.locator('[data-ocpi-agreements]');
      const form=root.locator('[data-agreement-propose]');
      try { await form.waitFor(); } catch (error) {
        const response = await context.request.get(base+`/api/ocpi/providers/${fixture.provider_id}/technical-agreements`);
        const data = response.ok() ? await response.json() : null;
        const diagnostic = {status:response.status(), rootCount:await root.count(), rootSummary:(await root.allTextContents()).map(s=>s.slice(0,180)), clientValid:data?await page.evaluate(data=>window.EvOcpiAgreements.valid(data,data.provider_id),data):false,
          configured:data?.configured_identity!==null,revision:data?.revision,rows:data?.agreements?.data?.map(r=>({id:r.id,state:r.state,decisionCount:r.decisions.length,config:r.configuration_current}))};
        console.error('Synthetic agreement diagnostics:',JSON.stringify(diagnostic)); throw error;
      }
      const reference=`Synthetic <img src=x> ${run} ${ar?'AR':'EN'}`;
      const fields={reference,evidence_sha256:'a'.repeat(64),terms_en:'Synthetic technical evidence only. No live agreement or partner acceptance.',terms_ar:'دليل تقني اصطناعي لاختبار المراجعة فقط دون شريك أو اتفاقية فعلية.',valid_from:new Date(Date.now()-60000).toISOString().replace(/\.\d{3}Z$/,'Z'),valid_until:new Date(Date.now()+86400000).toISOString().replace(/\.\d{3}Z$/,'Z')};
      for(const [key,value]of Object.entries(fields))await form.locator(`[name=${key}]`).fill(value);await form.locator('[name=module][value=locations]').check();
      let lost=false, firstBody;
      const mutation=`**/api/ocpi/providers/${fixture.provider_id}/technical-agreements`;
      await page.route(mutation,async route=>{
        if(route.request().method()==='POST'&&!lost){lost=true;firstBody=route.request().postDataJSON();const response=await route.fetch();assert.equal(response.status(),201);await route.abort('failed');}
        else if(route.request().method()==='POST'){assert.deepEqual(route.request().postDataJSON(),firstBody);await route.continue();}
        else await route.continue();
      });
      stage+=' submit';
      await form.getByRole('button',{name:ar?'تسجيل الشروط المقترحة':'Record proposed terms',exact:true}).click();
      stage+=' retry-wait';
      await root.getByRole('button',{name:ar?'إعادة نفس الإجراء تماماً':'Retry exact same action',exact:true}).waitFor();
      const replay=page.waitForResponse(r=>new URL(r.url()).pathname.endsWith('/technical-agreements')&&r.request().method()==='POST');
      await root.getByRole('button',{name:ar?'إعادة نفس الإجراء تماماً':'Retry exact same action',exact:true}).click();
      const replayResponse=await replay;assert.equal(replayResponse.status(),201);const id=(await replayResponse.json()).agreement_id;
      versions++;const row=()=>root.locator(`[data-agreement-id="${id}"]`);
      await row().getByText(fields.terms_en,{exact:true}).waitFor();assert.equal(await row().locator('img,script').count(),0);
      stage+=' approve';
      await row().locator('[name=reason]').fill('Synthetic reviewed peer identity and technical scope only.');await row().locator('input[type=checkbox]').check();
      const approval=page.waitForResponse(r=>new URL(r.url()).pathname.endsWith(`/${id}/decision`)&&r.request().method()==='POST');
      await row().getByRole('button',{name:ar?'اعتماد الأدلة التقنية':'Approve technical evidence',exact:true}).click();assert.equal((await approval).status(),200);
      stage+=' approved-open';
      await open();await row().getByText(ar?'معتمدة':'Approved',{exact:true}).waitFor();
      assert.equal((await context.request.get(base+`/api/ocpi/providers/${fixture.provider_id}/technical-agreements`)).status(),200);
      stage+=' revoke';
      await row().locator('[name=reason]').fill('Synthetic revocation closes the technical testing scope.');await row().locator('input[type=checkbox]').check();
      await row().getByRole('button',{name:ar?'إلغاء الاتفاقية الحالية':'Revoke current agreement',exact:true}).click();
      stage+=' revoked-open';
      await open();await row().getByText(ar?'ملغاة':'Revoked',{exact:true}).waitFor();
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
      assert.equal(await page.locator('body').innerText().then(s=>s.includes(secret)),false);
      assert.deepEqual(external,[]);assert.deepEqual(errors,[]);
      await context.close();
    }
    stage='retained-proof';const snapshot=JSON.parse(php('tests/Fixtures/ocpi-technical-agreement.php','snapshot',run));
    assert.deepEqual(snapshot,{versions:2,decisions:4,encrypted_policy:true,provider_authorized:false,negotiated:false,credential_operations:0,source_commands:0,invoices:0,payments:0,refunds:0});
    console.log('PASS actual Laravel/Chromium OCPI technical agreements EN/AR390: exact-key lost-ack retry, immutable bilingual review/revocation, encrypted retained history, no activation/credentials/commands/finance/provider requests.');
  }finally{if(browser)await browser.close();try{execFileSync('docker',['stop',name],{stdio:'ignore'});}catch{}}
})().catch(error=>{console.error(`OCPI agreement browser failed at ${stage}; private payloads withheld. ${error.name}: ${String(error.message).split('\n').slice(0,3).join(' ').replace(/[a-f0-9]{48}aA9!/g,'[synthetic password redacted]')}`);process.exitCode=1;});
