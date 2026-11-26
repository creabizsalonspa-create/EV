'use strict';
const {execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
let stage='fixture';
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!',f=JSON.parse(execFileSync(process.env.EV_TEST_PHP_RUNNER||'./scripts/php',['tests/Fixtures/unified-discovery.php',run,password],{encoding:'utf8'}));assert.equal(f.fixture_only,true);
 stage='login';const c=new BusinessClient({base:process.env.EV_TEST_URL||'http://127.0.0.1:8000'});c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email:`canonical-customer-${run}@example.test`,password});
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  const ctx=await browser.newContext();await ctx.addCookies([...c.cookies].map(([name,value])=>({name,value,url:c.base})));
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));
  let searchReads=0;page.on('request',r=>{if(new URL(r.url()).pathname==='/api/discovery/search')searchReads++;});
  let tiles=0;await page.route('https://tile.openstreetmap.org/**',route=>{tiles++;return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64')});});
  stage='browser';await page.goto(process.env.EV_TEST_URL||'http://127.0.0.1:8000');
  await page.locator('details').filter({has:page.locator('#unified-discovery')}).locator(':scope > summary').click();
  const root=page.locator('#unified-discovery');await root.locator('input[name=unified-query]').fill(run);await root.locator('select[name=country]').selectOption('AE');
  await root.locator('select[name=max_source_age_hours]').selectOption('24');
  stage='search';const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');await root.getByRole('button',{name:'Search all sources',exact:true}).click();const result=await response;assert.equal(new URL(result.url()).searchParams.get('max_source_age_hours'),'24','Selected age filter must reach the API');assert.equal(new URL(result.url()).searchParams.get('country'),'AE','Authenticated search must apply location country filter on both inventories');assert.equal(result.status(),200,'Authenticated discovery status');assert.equal((await result.json()).total,2,'Exactly the two run-scoped synthetic source records must match');
  await root.locator('[data-discovery-key="partner:'+f.record_id+'"]').waitFor();assert.equal(await root.locator('article').count(),2);assert.equal(await root.locator('article b').count(),0);assert.equal(await root.locator('[data-discovery-key="partner:'+f.record_id+'"] button:not([data-favorite-kind])').count(),0);assert.equal(await root.locator('[data-discovery-key="partner:'+f.record_id+'"] button[data-favorite-kind]').count(),1);
  assert.match(await root.textContent(),/Reported catalog age at search:/);assert.match(await root.textContent(),/Catalog-age policy at search: within limit/);assert.match(await root.textContent(),/do not verify physical availability/);
  stage='advanced-filters';await root.locator('details > summary').click();
  await root.locator('input[name=operator]').fill('Synthetic unified '+run);await root.locator('input[name=address]').fill('Synthetic address');
  let advancedRead=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');await root.getByRole('button',{name:'Search all sources',exact:true}).click();let advancedReply=await advancedRead;assert.equal(advancedReply.status(),200);let advancedData=await advancedReply.json();assert.equal(advancedData.total,1);assert.equal(advancedData.data[0].key,'partner:'+f.record_id);
  await root.locator('input[name=access]').fill('Visitors');
  advancedRead=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');await root.getByRole('button',{name:'Search all sources',exact:true}).click();assert.equal((await (await advancedRead).json()).total,0);
  await root.locator('input[name=access]').fill('');await root.locator('input[name=max_rate_per_kwh]').fill('١٫٥٠');await root.locator('input[name=price_currency]').fill('aed');
  advancedRead=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');await root.getByRole('button',{name:'Search all sources',exact:true}).click();advancedReply=await advancedRead;assert.equal(advancedReply.status(),200);assert.equal(new URL(advancedReply.url()).searchParams.get('max_rate_per_kwh'),'1.50');assert.equal(new URL(advancedReply.url()).searchParams.get('price_currency'),'AED');assert.equal((await advancedReply.json()).total,0);
  // Requirement 2.3.5: positive intersection on actual canonical metadata
  // and current simulated tariff, not only exclusion of unknown partner prices.
  stage='advanced-positive-fixture';
  const publisher=new BusinessClient({base:c.base});
  publisher.csrf=(await publisher.request('csrf')).token;
  await publisher.request('login','POST',{email:'canonical-owner-'+run+'@example.test',password});
  publisher.csrf=(await publisher.request('csrf')).token;
  const metadata=await publisher.request('stations/'+f.station_id+'/information','PUT',{
   revision:0,operator_name:'Canonical filter '+run,address_en:'100%_hub '+run,
   address_ar:'دبي '+run,description_en:null,description_ar:null,
   access_en:'Visitors canonical',access_ar:'للزوار',
   weekly_hours:Object.fromEntries(['mon','tue','wed','thu','fri','sat','sun'].map(day=>[day,{open:null,close:null}])),
   closed_dates:[],amenities:[],accessibility:[]
  });
  assert.equal(metadata.revision,1);
  const tariff=await publisher.request('stations/'+f.station_id+'/tariffs','POST',{
   rate_minor_per_kwh:150,tax_bps:500,commission_bps:0,
   expected_currency:'AED',expected_minor_units:2,idempotency_key:'unified-filter-'+run
  });
  assert.equal(Number(tariff.rate_minor_per_kwh),150);
  assert.equal(tariff.currency,'AED');
  stage='advanced-positive-intersection';
  const filterInput=key=>root.locator('input[name='+key+']');
  const exactFilters={operator:'Canonical filter '+run,address:'100%_hub',access:'للزوار',max_rate_per_kwh:'١٫٥٠',price_currency:'aed'};
  for(const [key,value] of Object.entries(exactFilters))await filterInput(key).fill(value);
  async function searchCombined(){
   const pending=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');
   await root.getByRole('button',{name:'Search all sources',exact:true}).click();
   const reply=await pending;assert.equal(reply.status(),200);return{reply,data:await reply.json()};
  }
  let combined=await searchCombined();
  const sent=new URL(combined.reply.url()).searchParams;
  for(const [key,value] of Object.entries({...exactFilters,max_rate_per_kwh:'1.50',price_currency:'AED'}))assert.equal(sent.get(key),value);
  assert.equal(combined.data.total,1,'All four filters must intersect on the same canonical station');
  assert.equal(combined.data.last_page,1);
  assert.equal(combined.data.data[0].key,'catalog:'+f.station_id);
  await root.locator('[data-discovery-key="catalog:'+f.station_id+'"]').waitFor();
  assert.equal(await root.locator('article').count(),1);
  for(const [key,wrong] of Object.entries({operator:'Unmatched operator '+run,address:'100XXhub',access:'Unmatched access '+run,max_rate_per_kwh:'1.49'})){
   await filterInput(key).fill(wrong);
   combined=await searchCombined();
   assert.equal(combined.data.total,0,'Changing only '+key+' must exclude the otherwise matching station');
   await filterInput(key).fill(exactFilters[key]);
  }
  combined=await searchCombined();
  assert.equal(combined.data.total,1,'Restoring the exact filters must restore the canonical result');
  assert.equal(combined.data.data[0].key,'catalog:'+f.station_id);
  for(const key of ['operator','address','access','max_rate_per_kwh','price_currency'])await root.locator('input[name='+key+']').fill('');
  stage='published-power';await root.locator('input[name=min_power_kw]').fill('١');
  await root.locator('input[name=max_power_kw]').fill('١٠٠');
  advancedRead=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');
  await root.getByRole('button',{name:'Search all sources',exact:true}).click();
  advancedReply=await advancedRead;assert.equal(advancedReply.status(),200);
  assert.equal(new URL(advancedReply.url()).searchParams.get('min_power_kw'),'1');
  assert.equal(new URL(advancedReply.url()).searchParams.get('max_power_kw'),'100');
  advancedData=await advancedReply.json();assert.equal(advancedData.total,1,'Only the canonical published station has a comparable rated power');
  assert.equal(advancedData.data[0].key,'catalog:'+f.station_id);
  await root.locator('input[name=min_power_kw]').fill('');
  await root.locator('input[name=max_power_kw]').fill('');
  advancedRead=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');await root.getByRole('button',{name:'Search all sources',exact:true}).click();assert.equal((await (await advancedRead).json()).total,2);await root.locator('[data-discovery-key="partner:'+f.record_id+'"]').waitFor();
  async function verifyRecentRestore(language){
   stage='recent-restore-'+language;
   const preset=root.locator('#unified-recent-presets [data-recent-index]').first();
   await preset.waitFor();
   const input=root.locator('input[name=unified-query]'),original=await input.inputValue(),before=searchReads;
   await input.fill('Unsubmitted synthetic draft');await preset.click();
   assert.equal(await input.inputValue(),original);assert.equal(searchReads,before,'Restoring filters must not search');
   await page.evaluate(()=>{window.detachedRecentRestore=document.querySelector('#unified-recent-presets [data-recent-index]').onclick;});
   await root.locator('#unified-recent-presets > button').click();
   await input.fill('Current synthetic draft');
   await page.evaluate(()=>window.detachedRecentRestore());
   assert.equal(await input.inputValue(),'Current synthetic draft','Cleared private callback cannot refill the current draft');
   assert.equal(searchReads,before,'Clearing or discarded callbacks must not search');
   assert.equal(await root.locator('#unified-recent-presets').isVisible(),false);
   await input.fill(original);
  }
  await verifyRecentRestore('en');
  stage='favorites';for(const [kind,id,path] of [['catalog',f.station_id,'favorites'],['partner',f.record_id,'roaming/favorites']]){
   const saved=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/${path}/${id}`&&r.request().method()==='PUT');
   await root.locator(`[data-discovery-key="${kind}:${id}"] button[data-favorite-kind]`).click();assert.equal((await saved).status(),200);
   await root.locator(`[data-discovery-key="${kind}:${id}"] button[data-favorite-kind]`).filter({hasText:'Remove saved place'}).waitFor();
  }
  await root.locator('input[type=checkbox]').check();
  const savedRead=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search'&&new URL(r.url()).searchParams.get('saved_only')==='1');await root.getByRole('button',{name:'Search all sources',exact:true}).click();assert.equal((await (await savedRead).json()).total,2);
  const removed=page.waitForResponse(r=>new URL(r.url()).pathname===`/api/favorites/${f.station_id}`&&r.request().method()==='DELETE');
  await root.locator(`[data-discovery-key="catalog:${f.station_id}"] button[data-favorite-kind]`).click();assert.equal((await removed).status(),200);
  await root.locator(`[data-discovery-key="partner:${f.record_id}"] button[data-favorite-kind]`).waitFor();await page.waitForFunction(()=>document.querySelectorAll('#unified-discovery article').length===1);
  await root.locator('input[type=checkbox]').uncheck();await root.getByRole('button',{name:'Search all sources',exact:true}).click();await root.locator(`[data-discovery-key="catalog:${f.station_id}"]`).waitFor();
  await root.locator('select[name=recent_source_status]').selectOption('Available');
  const filtered=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search'&&new URL(r.url()).searchParams.get('recent_source_status')==='Available');await root.getByRole('button',{name:'Search all sources',exact:true}).click();const filteredData=await (await filtered).json();assert.equal(filteredData.total,1);assert.equal(filteredData.data[0].key,'catalog:'+f.station_id);assert.equal(filteredData.data[0].station.availability,'unknown');
  await root.locator('select[name=recent_source_status]').selectOption('');await root.getByRole('button',{name:'Search all sources',exact:true}).click();await root.locator(`[data-discovery-key="partner:${f.record_id}"]`).waitFor();
  stage='map';await page.evaluate(()=>window.addEventListener('unified-map-data',event=>window.unifiedMapKeys=event.detail.map(row=>row.key)));
  await root.getByRole('button',{name:'Map these loaded results',exact:true}).click();
  assert.deepEqual((await page.evaluate(()=>window.unifiedMapKeys)).sort(),['catalog:'+f.station_id,'partner:'+f.record_id].sort());
  assert.equal(await page.locator('#station-map').isVisible(),false,'Unified map selection must not enable tiles without consent');
  assert.equal(tiles,0);await page.getByRole('button',{name:'Enable interactive map',exact:true}).click();
  await page.locator('.station-map-pin:not(.partner-map-pin)').waitFor();await page.locator('.partner-map-pin').waitFor();
  assert.equal(await page.locator('.station-map-pin:not(.partner-map-pin)').count(),1);assert.equal(await page.locator('.partner-map-pin').count(),1);
  await page.getByRole('button',{name:'Disable interactive map',exact:true}).click();assert.equal(await page.locator('#station-map').isVisible(),false);

  await page.locator('#language').click();await page.waitForFunction(()=>document.documentElement.lang==='ar');assert.match(await root.textContent(),/التوفر غير معروف/);assert.match(await root.textContent(),/عمر الكتالوج المبلغ عنه عند البحث/);assert.match(await root.textContent(),/سياسة عمر الكتالوج عند البحث/);assert.match(await root.textContent(),/لا تؤكد التوفر الفعلي/);
  await verifyRecentRestore('ar');
  const canonical=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/stations'&&new URL(r.url()).searchParams.get('station_id')===String(f.station_id));await root.locator('[data-discovery-key="catalog:'+f.station_id+'"] button:not([data-favorite-kind])').click();assert.equal((await canonical).status(),200);
  assert.deepEqual(errors,[]);console.log('Actual unified Laravel/browser bilingual search, supplier identities, safe text and canonical catalog handoff passed (synthetic published records).');
 }finally{await browser.close();execFileSync(process.env.EV_TEST_PHP_RUNNER||'./scripts/php',['tests/Fixtures/unified-discovery-clean.php',run],{stdio:'pipe'});}
})().catch(error=>{console.error(JSON.stringify({suite:'unified-discovery',stage,error:['Error','AssertionError','TimeoutError'].includes(error?.name)?error.name:'Error',...(error?.name==='AssertionError'?{expected:error.expected,actual:error.actual,operator:error.operator}:{})}));process.exit(1);});
