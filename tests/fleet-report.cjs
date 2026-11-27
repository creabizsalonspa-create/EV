const {chromium}=require('playwright'),assert=require('node:assert/strict');
function verifyDirectoryParser(){
 const source=require('node:fs').readFileSync('services/business-api/public/fleet-report.js','utf8');
 const start=source.indexOf('    function verifyDirectory('),end=source.indexOf('    function setActor(',start);
 assert(start>=0&&end>start);
 const verify=new Function(source.slice(start,end)+'\nreturn verifyDirectory;')();
 const row={id:7,name:'Fleet',country:'AE',owner:{id:17,name:'Owner',email:'owner@example.test'},inventory:{active_members:2,assigned_vehicles:3,cost_centers:1}};
 const good={data:[row],current_page:1,last_page:1,per_page:25,total:1};
 const run=mutate=>{const d=JSON.parse(JSON.stringify(good));mutate(d);return verify(d,1,{country:'AE',owner_id:'17'});};
 assert.equal(run(()=>{}).total,1);
 assert.equal(run(d=>{d.data=[];d.total=0;}).total,0);
 const bad=[
 d=>{d.data[0].country='US';},d=>{d.data[0].owner.id=18;},d=>{d.data[0].owner=null;},
 d=>{d.data[0].inventory.active_members=-1;},d=>{d.data[0].inventory.assigned_vehicles=1.5;},
 d=>{d.data[0].id=0;},d=>{d.data[0].name='';},d=>{d.per_page=26;},
 d=>{d.total=2;},d=>{d.last_page=2;},d=>{d.current_page=2;},
 d=>{d.data.push({...row});d.total=2;}
 ];
 for(const mutate of bad)assert.throws(()=>run(mutate),/Invalid fleet directory scope/);
 console.log('PASS fleet directory parser: 2 valid and '+bad.length+' rejected cases');
}
verifyDirectoryParser();
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH||undefined,args:['--no-sandbox']});try{for(const ar of [false,true]){
 const page=await browser.newPage();await page.setContent(`<html lang="${ar?'ar':'en'}"><body><div id="fleet-admin-report"></div><section id="report"></section></body></html>`);await page.addScriptTag({path:'services/business-api/public/fleet-report.js'});
 await page.evaluate(()=>{window.reads=[];window.result={as_of:'2026-11-15T10:00:00Z',fleet:{id:7,name:'<img src=x>'},basis:'approved_claims_submitted_in_period',physical_charging_verified:false,period:{from:'2026-11-01',through:'2026-11-15',timezone:'Asia/Dubai',cost_center_id:9},current_inventory:{active_members:2,invited_members:1,assigned_vehicles:3,cost_centers:1,private_customer_email:'unrelated@example.test'},approved_claims:1,groups:[{cost_center_id:9,code:'OPS',name:'=PRIVATE()',currency:'AED',minor_units:2,claims:1,amount_minor:'9007199254740993'}]};window.api=async(path)=>{window.reads.push(path);return window.result;};EvFleetReport.setActor({id:7,role:'customer'},window.api);EvFleetReport.mount(document.querySelector('#report'),7);});
 assert.equal(await page.evaluate(()=>reads.length),0);await page.locator('#report input[type=date]').nth(0).fill('2026-11-01');await page.locator('#report input[type=date]').nth(1).fill('2026-11-15');await page.locator('#report input[type=number]').fill('9');await page.locator('#report button[type=submit]').click();await page.getByText('OPS · =PRIVATE() · AED 90071992547409.93 · 1',{exact:true}).waitFor();assert.equal(await page.locator('img').count(),0);assert.deepEqual(await page.evaluate(()=>reads),['fleets/7/report?from=2026-11-01&through=2026-11-15&cost_center_id=9']);
 const downloaded=page.waitForEvent('download');await page.getByRole('button',{name:ar?'تنزيل لقطة CSV خاصة':'Download private CSV snapshot',exact:true}).click();const download=await downloaded;let csv='';for await(const data of await download.createReadStream())csv+=data.toString();assert(csv.includes('"\'=PRIVATE()"'));assert(csv.includes('9007199254740993'));assert(!csv.includes('private_customer_email'));assert(!csv.includes('unrelated@example.test'));
 await page.locator('#report input[type=number]').fill('10');
 assert.equal(await page.getByRole('button',{name:ar?'تنزيل لقطة CSV خاصة':'Download private CSV snapshot',exact:true}).count(),0);
 await page.locator('#report button[type=submit]').click();
 await page.getByText(ar?'التقرير غير متاح. تحقق من الصلاحية والتواريخ ومركز التكلفة ثم اقرأ مجدداً.':'Report unavailable. Check access, dates and cost center, then read again.',{exact:true}).waitFor();
 assert.equal(await page.locator('#report h4').count(),0);
 await page.evaluate(()=>{EvFleetReport.setActor({role:'admin'},()=>new Promise(resolve=>window.resolve=resolve));EvFleetReport.mount(document.querySelector('#report'),7);});await page.locator('#report button[type=submit]').click();await page.evaluate(()=>{EvFleetReport.setActor(null,window.api);window.resolve(window.result);});assert.equal(await page.locator('#report').textContent(),'');
 await page.evaluate(()=>{window.directoryReads=[];EvFleetReport.setActor({role:'admin'},async path=>{window.directoryReads.push(path);const url=new URL(path,'https://example.test/');const second=url.searchParams.get('page')==='2';const row=id=>({id,name:id===7?'<img src=x> literal fleet':'Fleet '+id,country:'AE',owner:null,inventory:{active_members:0,assigned_vehicles:0,cost_centers:0}});return{data:second?[row(32)]:Array.from({length:25},(_,i)=>row(i+7)),current_page:second?2:1,last_page:2,total:26,per_page:25};});});
 const directory=page.locator('#fleet-admin-report');await directory.locator('input[type=search]').fill('%_');await directory.locator('input[maxlength="2"]').fill('ae');await directory.getByRole('button',{name:ar?'بحث الأساطيل':'Search fleets',exact:true}).click();await directory.getByRole('button',{name:'<img src=x> literal fleet',exact:true}).waitFor();assert.equal(await page.locator('img').count(),0);
 await directory.getByRole('button',{name:ar?'التالي':'Next',exact:true}).click();
 await directory.getByRole('button',{name:'Fleet 32',exact:true}).waitFor();
 assert.deepEqual(await page.evaluate(()=>directoryReads),['admin/fleets?q=%25_&country=AE&include=owner_inventory&page=1','admin/fleets?q=%25_&country=AE&include=owner_inventory&page=2']);
 await directory.locator('input[type=search]').fill('changed');
 assert.equal(await directory.getByRole('button',{name:'Fleet 32',exact:true}).count(),0);
 assert.equal(await directory.getByRole('button',{name:ar?'السابق':'Previous',exact:true}).count(),0);
  await page.evaluate(()=>{EvFleetReport.setActor({role:'admin'},async path=>{window.ownerRead=path;return{data:[{id:7,name:'<img src=x> safe fleet',country:'AE',owner:{id:17,name:'<img src=x> safe owner',email:'private-owner@example.test'},inventory:{active_members:2,assigned_vehicles:3,cost_centers:1}}],current_page:1,last_page:1,total:1,per_page:25};});});
  await directory.locator('input[type=number]').first().fill('17');
  await directory.getByRole('button',{name:ar?'بحث الأساطيل':'Search fleets',exact:true}).click();
  await directory.getByText('private-owner@example.test',{exact:false}).waitFor();
  assert.match(await page.evaluate(()=>window.ownerRead),/owner_id=17/);
  assert.match(await directory.textContent(),/2 \/ 3 \/ 1/);
  assert.equal(await page.locator('img').count(),0);
 await page.evaluate(()=>{EvFleetReport.setActor({role:'admin'},()=>new Promise(resolve=>window.resolveDirectory=resolve));});await directory.getByRole('button',{name:ar?'بحث الأساطيل':'Search fleets',exact:true}).click();await page.evaluate(()=>{EvFleetReport.setActor(null,window.api);window.resolveDirectory({data:[{id:1,name:'PRIVATE LATE FLEET',country:'AE'}],current_page:1,last_page:1,total:1});});assert.equal(await directory.textContent(),'');assert.equal(await page.getByText('private-owner@example.test',{exact:false}).count(),0);assert.equal(await page.getByText('PRIVATE LATE FLEET',{exact:false}).count(),0);await page.close();
 }console.log('PASS fleet reporting EN/AR filters, exact amounts, formula-safe CSV, safe rendering, and account change');}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
