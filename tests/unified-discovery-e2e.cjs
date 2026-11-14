'use strict';
const {execFileSync}=require('node:child_process'),{randomBytes}=require('node:crypto'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const {BusinessClient}=require('./helpers/business-client.cjs');
(async()=>{
 const run=randomBytes(4).toString('hex'),password=randomBytes(24).toString('hex')+'Aa9!',f=JSON.parse(execFileSync('./scripts/php',['tests/Fixtures/unified-discovery.php',run,password],{encoding:'utf8'}));assert.equal(f.fixture_only,true);
 const c=new BusinessClient();c.csrf=(await c.request('csrf')).token;await c.request('login','POST',{email:`canonical-customer-${run}@example.test`,password});
 const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});
 try{
  const ctx=await browser.newContext();await ctx.addCookies([...c.cookies].map(([name,value])=>({name,value,url:c.base})));
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.name));
  let tiles=0;await page.route('https://tile.openstreetmap.org/**',route=>{tiles++;return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64')});});
  await page.goto(process.env.EV_TEST_URL||'http://127.0.0.1:8000');
  await page.locator('details').filter({has:page.locator('#unified-discovery')}).locator('summary').click();
  const root=page.locator('#unified-discovery');await root.locator('input').fill(run);
  const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/discovery/search');await root.getByRole('button',{name:'Search all sources',exact:true}).click();const result=await response;assert.equal(result.status(),200);assert.equal((await result.json()).total,2);
  await root.locator('[data-discovery-key="partner:'+f.record_id+'"]').waitFor();assert.equal(await root.locator('article').count(),2);assert.equal(await root.locator('article b').count(),0);assert.equal(await root.locator('[data-discovery-key="partner:'+f.record_id+'"] button:not([data-favorite-kind])').count(),0);assert.equal(await root.locator('[data-discovery-key="partner:'+f.record_id+'"] button[data-favorite-kind]').count(),1);
  for(const [kind,id,path] of [['catalog',f.station_id,'favorites'],['partner',f.record_id,'roaming/favorites']]){
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
  await page.evaluate(()=>window.addEventListener('unified-map-data',event=>window.unifiedMapKeys=event.detail.map(row=>row.key)));
  await root.getByRole('button',{name:'Map these loaded results',exact:true}).click();
  assert.deepEqual((await page.evaluate(()=>window.unifiedMapKeys)).sort(),['catalog:'+f.station_id,'partner:'+f.record_id].sort());
  assert.equal(await page.locator('#station-map').isVisible(),false,'Unified map selection must not enable tiles without consent');
  assert.equal(tiles,0);await page.getByRole('button',{name:'Enable interactive map',exact:true}).click();
  await page.locator('.station-map-pin:not(.partner-map-pin)').waitFor();await page.locator('.partner-map-pin').waitFor();
  assert.equal(await page.locator('.station-map-pin:not(.partner-map-pin)').count(),1);assert.equal(await page.locator('.partner-map-pin').count(),1);
  await page.getByRole('button',{name:'Disable interactive map',exact:true}).click();assert.equal(await page.locator('#station-map').isVisible(),false);

  await page.locator('#language').click();await page.waitForFunction(()=>document.documentElement.lang==='ar');assert.match(await root.textContent(),/التوفر غير معروف/);
  const canonical=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/stations'&&new URL(r.url()).searchParams.get('station_id')===String(f.station_id));await root.locator('[data-discovery-key="catalog:'+f.station_id+'"] button:not([data-favorite-kind])').click();assert.equal((await canonical).status(),200);
  assert.deepEqual(errors,[]);console.log('Actual unified Laravel/browser bilingual search, supplier identities, safe text and canonical catalog handoff passed (synthetic published records).');
 }finally{await browser.close();}
})().catch(()=>{console.error('Synthetic unified discovery browser acceptance failed.');process.exit(1);});
