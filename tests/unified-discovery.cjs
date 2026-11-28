'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),{test}=require('node:test');
class Element{
 constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.value='';this.text='';}
 append(...nodes){this.children.push(...nodes);}
 replaceChildren(...nodes){this.children=nodes;this.text='';}
 setAttribute(){}
 set textContent(value){this.text=String(value);this.children=[];}
 get textContent(){return this.text+this.children.map(x=>x.textContent||'').join('');}
}
const sample={data:[{key:'catalog:7',kind:'catalog',station:{id:7,name:'<b>Catalog</b>',evidence:'SIMULATED',connector_type:'CCS2',power_kw:50}},{key:'partner:7',kind:'partner',record_id:7,provider_label:'Private operator label',location:{name:'<img src=x>',address:'Synthetic',country:'ARE',availability:'unknown',remote_control:false,reservable:false},evidence:'PARTNER_REPORTED_UNVERIFIED',availability:'unknown',remote_control:false,reservable:false}],current_page:1,last_page:2,total:3};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(lang='en',api=async()=>sample){const events=[],listeners=new Map(),window={addEventListener(type,fn){listeners.set(type,[...(listeners.get(type)||[]),fn]);},dispatchEvent(event){events.push(event);for(const fn of listeners.get(event.type)||[])fn(event);}},document={visibilityState:'visible',addEventListener:(type,fn)=>window.addEventListener('document:'+type,fn),getElementById:()=>null,documentElement:{lang},createElement:tag=>new Element(tag)};vm.runInNewContext(fs.readFileSync('services/business-api/public/partner-catalog-policy.js','utf8'),{window});vm.runInNewContext(fs.readFileSync('services/business-api/public/unified-discovery.js','utf8'),{window,document,URLSearchParams,CustomEvent:class{constructor(type,data={}){this.type=type;this.detail=data.detail??null;}}});const root=new Element('section'),chosen=[],calls=[];const control=window.EvUnifiedDiscovery.install(root,async(...args)=>{calls.push(args);return api(...args);},id=>chosen.push(id));return{root,document,chosen,calls,control,events,emit:(type,detail)=>window.dispatchEvent({type,detail}),form:root.children[2],list:root.children[4],nav:root.children[5]};}
for(const lang of ['en','ar'])test('actual unified DOM query, typed identities and safe source text '+lang,async()=>{
 const f=fixture(lang);assert.equal(f.calls.length,0);f.form.children[0].children.find(n=>n.tag==='input').value='100%_hub';f.form.onsubmit({preventDefault(){}});await tick();assert.equal(new URLSearchParams(f.calls[0][0].split('?')[1]).get('q'),'100%_hub');assert.equal(f.list.children.length,2);assert.match(f.list.textContent,/<img src=x>/);assert.equal(f.list.children[1].children.some(n=>n.tag==='button'),false);f.list.children[0].children.find(n=>n.tag==='button').onclick();assert.deepEqual(f.chosen,[7]);assert.equal(f.list.children[0].children[0].innerHTML,undefined);assert.match(f.list.textContent,lang==='ar'?/التوفر غير معروف/:/availability unknown/);f.nav.children.find(n=>n.tag==='button').onclick();await tick();assert.equal(new URLSearchParams(f.calls[1][0].split('?')[1]).get('page'),'2');assert(f.calls.every(args=>args.length===1));
});
test('late results are discarded after explicit clear without another lookup',async()=>{
 let finish;const f=fixture('en',()=>new Promise(resolve=>finish=resolve));f.form.onsubmit({preventDefault(){}});f.control.clear();finish(sample);await tick();assert.equal(f.list.children.length,0);assert.equal(f.calls.length,1);
});
test('partner authority claims never render an actionable card',async()=>{
 const f=fixture('en',async()=>({...sample,data:[{...sample.data[1],reservable:true}]}));f.form.onsubmit({preventDefault(){}});await tick();assert.equal(f.list.children.length,0);assert.deepEqual(f.chosen,[]);
});


for(const language of ['en','ar'])test('forged catalog evidence is never sent to map or favorite writer '+language,async()=>{
 const forged={...sample.data[0],station:{...sample.data[0].station,
   evidence:'LIVE_VERIFIED',latitude:25.2,longitude:55.3}};
 const f=fixture(language,async()=>({...sample,data:[forged,sample.data[1]]}));
 let writes=0;
 f.control.setContext({owner:'customer',customer:true,favorite:async()=>{writes++;return true;}});
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.list.children.length,2,'Untrusted station remains readable, never actionable');
 assert.equal(f.list.children[0].children.some(c=>c.tag==='button'),false);
 assert.equal(f.root.children[6].disabled,false);
 f.root.children[6].onclick();
 assert.deepEqual(Array.from(f.events[0].detail,row=>row.key),['partner:7']);
 assert.equal(writes,0);
});
for(const language of ['en','ar'])test('unverified partner map provenance is list-only with no saved action '+language,async()=>{
 const forged={...sample.data[1],evidence:'LIVE_PROVIDER_VERIFIED',
   location:{...sample.data[1].location,latitude:25.3,longitude:55.5}};
 const f=fixture(language,async()=>({...sample,data:[sample.data[0],forged]}));
 let writes=0;
 f.control.setContext({owner:'customer',customer:true,favorite:async()=>{writes++;return true;}});
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.list.children.length,2);
 assert.equal(f.list.children[1].children.some(c=>c.dataset.favoriteKind),false);
 f.root.children[6].onclick();
 assert.deepEqual(Array.from(f.events[0].detail,row=>row.key),['catalog:7']);
 assert.equal(writes,0);
});
test('bad discovery page identity, source count and pagination never leak partial cards or map pins',async()=>{
 const rows=[sample.data[0],sample.data[1]];
 for(const response of [
  {...sample,current_page:2},
  {...sample,data:[...rows,rows[0]],total:3},
  {...sample,data:[...Array(26)].map((_,i)=>({...rows[0],key:'catalog:'+i,station:{...rows[0].station,id:i}})),total:26},
  {...sample,data:[rows[0],null],total:2},
  {...sample,data:rows,total:1},
 ]) {
  const f=fixture('en',async()=>response);
  f.form.onsubmit({preventDefault(){}});await tick();
  assert.equal(f.list.children.length,0);
  assert.equal(f.nav.children.length,0);
  assert.equal(f.root.children[6].disabled,true);
  assert.match(f.root.textContent,/Search failed/);
  assert.equal(f.events.length,0);
 }
});

for(const language of ['en','ar'])test('contradictory discovery-only booking and coordinates cannot create map/favorite authority '+language,async()=>{
 const bogus={...sample.data[0],station:{...sample.data[0].station,
   evidence:'DISCOVERY_ONLY',reservable:true,latitude:25.2,longitude:55.3}};
 const f=fixture(language,async()=>({...sample,data:[bogus,sample.data[1]]}));
 let writes=0;
 f.control.setContext({owner:'customer',customer:true,favorite:async()=>{writes++;return true;}});
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.list.children.length,2);
 assert.equal(f.list.children[0].children.some(x=>x.tag==='button'),false);
 f.root.children[6].onclick();
 assert.deepEqual(Array.from(f.events[0].detail,row=>row.key),['partner:7']);
 assert.deepEqual(f.chosen,[]);
 assert.equal(writes,0);
});

for(const lang of ['en','ar'])test('mapped old account or role clears pins and old map click is invalid '+lang,async()=>{
 const f=fixture(lang);
 f.control.setContext({owner:'old',customer:true});
 f.form.onsubmit({preventDefault(){}});await tick();
 const map=f.root.children[6],oldClick=map.onclick;
 assert.equal(f.events.length,0);
 oldClick();
 assert.equal(f.events.length,1);
 assert.equal(f.events[0].type,'unified-map-data');
 assert.deepEqual(Array.from(f.events[0].detail,row=>row.key),['catalog:7','partner:7']);
 f.control.setContext({owner:'new',customer:true});
 assert.equal(f.events.length,2);
 assert.equal(f.events[1].type,'unified-map-clear');
 assert.equal(map.disabled,true);
 assert.equal(f.list.children.length,0);
 const prior=f.calls.length;oldClick();
 assert.equal(f.events.length,2,'stale callbacks cannot restore the former account map');
 assert.equal(f.calls.length,prior);
 f.control.setContext({owner:'new',customer:false});
 assert.equal(f.events.length,2,'clear without published map does not touch another source');
});

for(const language of ['en','ar'])test('map revoke retains its exact instance publication token '+language,async()=>{
 const f=fixture(language);
 f.control.setContext({owner:'customer',customer:true});
 f.form.onsubmit({preventDefault(){}});await tick();
 f.root.children[6].onclick();
 const published=f.events.at(-1);
 assert.equal(published.type,'unified-map-data');
 assert.ok(published.evUnifiedPublication);
 f.control.clear();
 const revoke=f.events.at(-1);
 assert.equal(revoke.type,'unified-map-clear');
 assert.equal(revoke.detail,published.evUnifiedPublication);
 assert.equal(f.list.children.length,0);
 assert.equal(f.root.children[6].disabled,true);
});
test('a fresh search revokes old map pins without implicit replot',async()=>{
 const f=fixture();
 f.form.onsubmit({preventDefault(){}});await tick();
 const map=f.root.children[6];map.onclick();const before=f.events.length;
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.events.length,before+1);
 assert.equal(f.events.at(-1).type,'unified-map-clear');
 assert.equal(map.disabled,false,'New page requires another explicit map click');
 assert.equal(f.calls.length,2);
});
test('pagehide clears mapped private query and recent history without network',async()=>{
 const f=fixture();f.control.setContext({owner:'customer',customer:true});
 const q=f.form.children[0].children.find(x=>x.tag==='input');
 q.value='Private previous customer address';
 f.form.onsubmit({preventDefault(){}});await tick();f.root.children[6].onclick();
 const before=f.calls.length;
 f.emit('pagehide');
 assert.equal(q.value,'');
 assert.equal(f.list.children.length,0);
 assert.equal(f.root.children[6].disabled,true);
 assert.equal(f.root.children[7].hidden,true);
 assert.equal(f.events.at(-1).type,'unified-map-clear');
 assert.equal(f.calls.length,before);
});

for(const lang of ['en','ar'])test('background tab purges private mapped search and refuses stale results '+lang,async()=>{
 const f=fixture(lang);
 f.control.setContext({owner:'customer',customer:true});
 const q=f.form.children[0].children.find(node=>node.tag==='input');
 q.value='Former private location';
 f.form.onsubmit({preventDefault(){}});await tick();
 f.root.children[6].onclick();
 assert.match(f.root.textContent,/Former private location/);
 const sent=f.calls.length;
 f.document.visibilityState='hidden';
 f.emit('document:visibilitychange');
 assert.equal(q.value,'');
 assert.equal(f.list.children.length,0);
 assert.equal(f.root.children[6].disabled,true);
 assert.equal(f.root.children[7].hidden,true);
 assert.equal(f.events.at(-1).type,'unified-map-clear');
 f.document.visibilityState='visible';
 f.emit('document:visibilitychange');
 await tick();
 assert.equal(f.calls.length,sent,'Foreground alone cannot perform a private discovery lookup');
 assert.equal(f.list.children.length,0);
});
test('mapping the mixed page is explicit, preserves typed sources and performs no lookup',async()=>{
 const f=fixture();assert.equal(f.events.length,0);f.form.onsubmit({preventDefault(){}});await tick();assert.equal(f.events.length,0);f.root.children[6].onclick();assert.equal(f.events.length,1);assert.equal(f.events[0].type,'unified-map-data');assert.deepEqual(Array.from(f.events[0].detail,row=>row.key),['catalog:7','partner:7']);assert.equal(f.calls.length,1);f.control.clear();assert.equal(f.root.children[6].disabled,true);
});
test('unified favorites use existing typed writers and refresh authoritative saved state',async()=>{
 const saved=new Set(),writes=[];const f=fixture('en',async()=>({...sample,data:sample.data.map(row=>({...row,is_favorite:saved.has(row.key)}))}));
 f.control.setContext({owner:1,customer:true,favorite:async(kind,id,desired)=>{const key=kind+':'+id;writes.push({key,desired});desired?saved.add(key):saved.delete(key);return desired;}});
 f.form.onsubmit({preventDefault(){}});await tick();let star=f.list.children[0].children.find(x=>x.dataset.favoriteKind);await star.onclick();assert.deepEqual(writes,[{key:'catalog:7',desired:true}]);assert.match(f.list.children[0].textContent,/Remove saved place/);
 star=f.list.children[1].children.find(x=>x.dataset.favoriteKind);await star.onclick();assert.equal(writes[1].key,'partner:7');assert.equal(saved.size,2);
 f.form.children.flatMap(n=>n.children).find(n=>n.tag==='input'&&n.type==='checkbox').checked=true;f.form.onsubmit({preventDefault(){}});await tick();assert.equal(new URLSearchParams(f.calls.at(-1)[0].split('?')[1]).get('saved_only'),'1');
 f.control.setContext({owner:2,customer:true,favorite:async()=>{throw Error('Unexpected write');}});assert.equal(f.list.children.length,0);
});
test('unknown preference outcomes require explicit refresh and late account replies do not reload',async()=>{
 let writes=0,finish;const f=fixture();f.control.setContext({owner:1,customer:true,favorite:async()=>{writes++;throw Error('lost response');}});f.form.onsubmit({preventDefault(){}});await tick();await f.list.children[0].children.find(x=>x.dataset.favoriteKind).onclick();await f.list.children[1].children.find(x=>x.dataset.favoriteKind).onclick();assert.equal(writes,1);assert.match(f.root.textContent,/outcome unknown/);
 f.control.setContext({owner:2,customer:true,favorite:()=>new Promise(resolve=>finish=resolve)});f.form.onsubmit({preventDefault(){}});await tick();const pending=f.list.children[0].children.find(x=>x.dataset.favoriteKind).onclick();f.control.setContext({owner:3,customer:true,favorite:async()=>true});const reads=f.calls.length;finish(true);await pending;assert.equal(f.calls.length,reads);assert.equal(f.list.children.length,0);
});

test('source-report selection sends an exact bounded status and clear removes it',async()=>{
 const f=fixture();f.form.children.flatMap(n=>n.children).find(n=>n.name==='recent_source_status').value='Available';f.form.onsubmit({preventDefault(){}});await tick();assert.equal(new URLSearchParams(f.calls[0][0].split('?')[1]).get('recent_source_status'),'Available');f.control.clear();assert.equal(f.form.children.flatMap(n=>n.children).find(n=>n.name==='recent_source_status').value,'');
});

test('locale application preserves nested search inputs, source dropdowns and private checkbox',async()=>{
 const f=fixture();f.form.onsubmit({preventDefault(){}});await tick();
 function apply(node){if(node.dataset.ar!==undefined&&node.textContent!==node.dataset.ar)node.textContent=node.dataset.ar;for(const child of node.children)apply(child);}
 apply(f.root);assert.match(f.root.children[3].textContent,/3 نتيجة منشورة/);assert(f.form.children[0].children.some(n=>n.tag==='input'));assert(f.form.children[1].children.some(n=>n.tag==='select'));assert(f.form.children[2].children.some(n=>n.tag==='select'));assert(f.form.children.flatMap(n=>n.children).some(n=>n.tag==='select'&&n.name==='country'));
 assert(f.form.children.flatMap(n=>n.children).some(n=>n.tag==='input'&&n.type==='checkbox'));
 assert(f.form.children.flatMap(n=>n.children).some(n=>n.tag==='select'&&n.name==='recent_source_status'));
});

for(const lang of ['en','ar'])test('partner catalog age stays explicit and unknown clocks never become fresh '+lang,async()=>{
 for(const [age,evidence,known] of [[1500,'PARTNER_REPORTED_TIME',true],[0,'PARTNER_REPORTED_TIME',true],[null,'PARTNER_CLOCK_AHEAD',false],[-1,'PARTNER_REPORTED_TIME',false],['0','PARTNER_REPORTED_TIME',false]]){
  const f=fixture(lang,async()=>({...sample,data:[{...sample.data[1],reported_source_age_minutes:age,source_clock_evidence:evidence,source_timestamp_evidence:'PARTNER_REPORTED_ONLY'}]}));f.form.onsubmit({preventDefault(){}});await tick();
  assert.match(f.list.textContent,known?(lang==='ar'?/عند البحث/:/age at search/):(lang==='ar'?/غير معروف أو/:/unknown or partner clock/));
  if(age===1500)assert.match(f.list.textContent,lang==='ar'?/أقدم من 24/:/over 24 hours old/);
  assert.match(f.list.textContent,lang==='ar'?/لا تؤكد التوفر الفعلي/:/do not verify physical availability/);assert.equal(f.calls.length,1);assert.equal(f.list.children[0].children.some(n=>n.tag==='button'),false);
 }
});

test('explicit partner age filter persists on pagination and clears with the account',async()=>{
 const f=fixture();const age=f.form.children.flatMap(n=>n.children).find(n=>n.name==='max_source_age_hours');age.value='24';f.form.onsubmit({preventDefault(){}});await tick();assert.equal(new URLSearchParams(f.calls[0][0].split('?')[1]).get('max_source_age_hours'),'24');f.nav.children.find(n=>n.tag==='button').onclick();await tick();assert.equal(new URLSearchParams(f.calls[1][0].split('?')[1]).get('max_source_age_hours'),'24');f.control.clear();assert.equal(age.value,'');f.form.onsubmit({preventDefault(){}});await tick();assert.equal(new URLSearchParams(f.calls.at(-1)[0].split('?')[1]).has('max_source_age_hours'),false);
});


for(const lang of ['en','ar'])test('partner EVSE state survives paging and clears with the account view '+lang,async()=>{
 const f=fixture(lang);
 const details=f.form.children.find(node=>node.tag==='details');
 const control=details.children.flatMap(node=>node.children).flatMap(node=>node.children).find(node=>node.name==='reported_evse_status');
 assert(control.children.some(node=>node.value==='AVAILABLE'));
 assert.match(details.textContent,lang==='ar'?/حالة وحدة الشحن حسب تقرير الشريك/:/Partner-reported EVSE state/);
 control.value='AVAILABLE';
 f.form.onsubmit({preventDefault(){}});await tick();
 const query=index=>new URLSearchParams(f.calls[index][0].split('?')[1]);
 assert.equal(query(0).get('reported_evse_status'),'AVAILABLE');
 f.nav.children.find(node=>node.tag==='button').onclick();await tick();
 assert.equal(query(1).get('reported_evse_status'),'AVAILABLE');
 f.control.clear();
 assert.equal(control.value,'');
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(query(2).has('reported_evse_status'),false);
});
for(const lang of ['en','ar'])test('advanced filters are explicit, normalize digits and persist until account clearing '+lang,async()=>{
 const f=fixture(lang),details=f.form.children.find(n=>n.tag==='details'),controls=Object.fromEntries(details.children.filter(n=>n.tag==='label').map(n=>{const input=n.children.find(c=>c.tag==='input');return[input.name,input];}));
 assert.equal(f.calls.length,0);controls.operator.value='100%_operator';controls.address.value='دبي';controls.access.value='Visitors';controls.max_rate_per_kwh.value='١٫٥٠';controls.price_currency.value='aed';
 f.form.onsubmit({preventDefault(){}});await tick();let params=new URLSearchParams(f.calls[0][0].split('?')[1]);
 assert.equal(params.get('operator'),'100%_operator');assert.equal(params.get('address'),'دبي');assert.equal(params.get('access'),'Visitors');assert.equal(params.get('max_rate_per_kwh'),'1.50');assert.equal(params.get('price_currency'),'AED');
 assert.match(details.textContent,lang==='ar'?/تستثني.*تقارير الشركاء/:/exclude partner reports/);
 controls.operator.value='unsent draft';f.nav.children.find(n=>n.tag==='button').onclick();await tick();params=new URLSearchParams(f.calls[1][0].split('?')[1]);assert.equal(params.get('operator'),'100%_operator');assert.equal(params.get('max_rate_per_kwh'),'1.50');
 f.control.clear();assert(Object.values(controls).every(n=>n.value===''));f.form.onsubmit({preventDefault(){}});await tick();params=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);assert.equal(params.has('price_currency'),false);assert.equal(params.has('operator'),false);
});

test('explicit map rectangle search preserves pagination and resets on form or account change',async()=>{
 const f=fixture();
 assert.equal(f.calls.length,0,'No automatic viewport lookup');
 const view={south:'24',north:'26',west:'54',east:'56'};
 f.emit('unified-viewport-search',view);
 await tick();
 const first=new URLSearchParams(f.calls[0][0].split('?')[1]);
 for(const [key,value] of Object.entries(view))assert.equal(first.get(key),Number(value).toFixed(6));
 assert.equal(first.get('page'),'1');
 f.nav.children.find(n=>n.tag==='button').onclick();
 await tick();
 const next=new URLSearchParams(f.calls[1][0].split('?')[1]);
 assert.equal(next.get('south'),'24.000000');
 assert.equal(next.get('page'),'2','Viewport preserved when changing result page');
 const before=f.calls.length;
 f.emit('unified-viewport-search',{south:'24',north:'26',west:'54',east:'54'});
 f.emit('unified-viewport-search',{south:'24',north:'26',west:'bad',east:'56'});
 assert.equal(f.calls.length,before,'Invalid rectangle cannot issue a lookup');
 f.form.onsubmit({preventDefault(){}});
 await tick();
 const ordinary=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(ordinary.has('south'),false,'Explicit normal search clears prior map bounds');
 f.emit('unified-viewport-search',{south:'9',north:'11',west:'179',east:'-179'});
 await tick();
 const wrapped=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(wrapped.get('west'),'179.000000');
 assert.equal(wrapped.get('east'),'-179.000000');
 f.control.clear();
 f.form.onsubmit({preventDefault(){}});
 await tick();
 assert.equal(new URLSearchParams(f.calls.at(-1)[0].split('?')[1]).has('west'),false);
});


for(const lang of ['en','ar'])test('supported market filter preserves source pagination, viewport intersection, and clears with identity '+lang,async()=>{
 const f=fixture(lang), market=f.form.children.flatMap(n=>n.children).find(n=>n.name==='country');
 assert(market, 'Accessible market filter must be present on both locales');
 assert.equal(f.calls.length,0, 'Selection alone cannot query suppliers');
 market.value='US';
 f.form.onsubmit({preventDefault(){}});
 await tick();
 let args=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(args.get('country'),'US');
 f.nav.children.find(n=>n.tag==='button').onclick();
 await tick();
 args=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(args.get('country'),'US');
 assert.equal(args.get('page'),'2');
 f.emit('unified-viewport-search',{south:24,north:26,west:54,east:56});
 await tick();
 args=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(args.get('country'),'US');
 assert.equal(args.get('south'),'24.000000');
 f.control.clear();
 assert.equal(market.value,'','Account reset must clear prior country');
 f.form.onsubmit({preventDefault(){}});
 await tick();
 assert.equal(new URLSearchParams(f.calls.at(-1)[0].split('?')[1]).has('country'),false);
});


for(const lang of ['en','ar'])test('published power range is explicit, normalized and retained across pages '+lang,async()=>{
 const f=fixture(lang),details=f.form.children.find(n=>n.tag==='details');
 const controls=Object.fromEntries(details.children.filter(n=>n.tag==='label').map(n=>{const input=n.children.find(x=>x.tag==='input');return [input.name,input];}));
 assert.equal(f.calls.length,0);
 assert(controls.min_power_kw&&controls.max_power_kw,'Both published power bounds are accessible');
 controls.min_power_kw.value='٤٠';
 controls.max_power_kw.value='٦٠';
 f.form.onsubmit({preventDefault(){}});await tick();
 let params=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(params.get('min_power_kw'),'40');
 assert.equal(params.get('max_power_kw'),'60');
 assert.match(details.textContent,lang==='ar'?/قدرة فعلية مقاسة/:/not measured live power/);
 f.nav.children.find(n=>n.tag==='button').onclick();
 await tick();params=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(params.get('page'),'2');
 assert.equal(params.get('min_power_kw'),'40');
 assert.equal(params.get('max_power_kw'),'60');
 f.control.clear();
 assert.equal(controls.min_power_kw.value,'');
 assert.equal(controls.max_power_kw.value,'');
 f.form.onsubmit({preventDefault(){}});await tick();
 params=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(params.has('min_power_kw'),false);
 assert.equal(params.has('max_power_kw'),false);
});


for(const lang of ['en','ar'])test('recent search filters are private, deduplicated, restored locally, and cleared by identity '+lang,async()=>{
 const f=fixture(lang);
 f.control.setContext({owner:'private-A',customer:true});
 const q=f.form.children[0].children.find(n=>n.tag==='input');
 const market=f.form.children.flatMap(n=>n.children).find(n=>n.name==='country');
 const details=f.form.children.find(n=>n.tag==='details');
 const operator=details.children.flatMap(n=>n.children).find(n=>n.name==='operator');
 const panel=f.root.children[7];
 assert.equal(panel.id,'unified-recent-presets');
 assert.equal(panel.hidden,true);
 q.value='Private first stop';market.value='CA';operator.value='Personal filter';
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.calls.length,1);
 assert.equal(panel.hidden,false);
 assert.equal(panel.children[1].children.length,1);
 q.value='Another stop';market.value='AE';operator.value='Different operator';
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(panel.children[1].children.length,2);
 const beforeRestore=f.calls.length;
 // Restore an older saved filter, but do not send it to the backend.
 panel.children[1].children[1].onclick();
 assert.equal(f.calls.length,beforeRestore);
 assert.equal(q.value,'Private first stop');assert.equal(market.value,'CA');
 assert.equal(operator.value,'Personal filter');
 assert.match(f.root.children[3].textContent,lang==='ar'?/استُعيدت المرشحات/:/Filters restored locally/);
 f.form.onsubmit({preventDefault(){}});await tick();
 const params=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(params.get('q'),'Private first stop');assert.equal(params.get('country'),'CA');
 assert.equal(params.get('operator'),'Personal filter');
 assert.equal(panel.children[1].children.length,2,'Same successful query must be deduplicated');
 const calls=f.calls.length;
 panel.children[2].onclick();
 assert.equal(panel.hidden,true);assert.equal(f.calls.length,calls,'Clearing history cannot search');
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(panel.children[1].children.length,1);
 f.control.setContext({owner:'private-B',customer:true});
 assert.equal(panel.hidden,true,'Account transition must destroy prior private presets');
 assert.equal(panel.children[1].children.length,0);
 assert.equal(q.value,'');
});
test('failed catalog or map-only queries cannot enter recent search history',async()=>{
 const error=fixture('en',async()=>{throw Error('Offline');});
 error.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(error.root.children[7].hidden,true);
 const f=fixture();
 f.emit('unified-viewport-search',{south:24,north:26,west:54,east:56});
 await tick();
 assert.equal(f.calls.length,1);
 assert.equal(f.root.children[7].hidden,true,'Viewport lookup is not a newly saved search');
});


for(const lang of ['en','ar'])test('untrusted catalog optionals and discovery-only reservation claims never enable booking '+lang,async()=>{
  const samples=[
    {name:'bad timezone',patch:{timezone:{unexpected:'timezone'}}},
    {name:'text distance',patch:{distance_km:'5.25'}},
    {name:'negative distance',patch:{distance_km:-9}},
    {name:'discovery-only reservation',patch:{evidence:'DISCOVERY_ONLY',reservable:true}},
  ];
  for(const item of samples){
    const f=fixture(lang,async()=>({...sample,data:[
      {...sample.data[0],station:{...sample.data[0].station,...item.patch}},
      sample.data[1],
    ]}));
    f.form.onsubmit({preventDefault(){}});await tick();
    assert.equal(f.list.children.length,2,item.name);
    assert.match(f.list.children[0].textContent,
      lang==='ar'?/بيانات المحطة المنشورة غير مكتملة/:/Published station details incomplete/,
      item.name);
    assert.equal(f.list.children[0].children.some(n=>n.tag==='button'),false,item.name);
    assert.deepEqual(f.chosen,[],item.name);
    assert.match(f.list.children[1].textContent,/Synthetic|<img src=x>/,item.name);
  }
});
for(const lang of ['en','ar'])test('incomplete catalog metadata stays readable but has no booking or favorite action '+lang,async()=>{
 const partial={...sample,data:[
  {...sample.data[0],station:{id:7,name:'<img src=x>',evidence:'SIMULATED',connector_type:'CCS2'}},
  sample.data[1],
 ]};
 const f=fixture(lang,async()=>partial);
 f.control.setContext({owner:'private',customer:true,favorite:()=>{throw Error('No write allowed');}});
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.list.children.length,2,'Valid partner must remain visible despite incomplete station');
 const station=f.list.children[0];
 assert.equal(station.children.some(child=>child.tag==='button'),false);
 assert.match(station.textContent,lang==='ar'?/غير مكتملة/:/details incomplete/);
 assert.equal(station.children[0].children.length,0,'Untrusted station name is always text');
 assert.equal(f.chosen.length,0);
 assert.equal(f.list.children[1].children.some(child=>child.tag==='button'),true,
  'Independent authorized favorite action on the partner remains');
});
test('unknown station identity fails closed and cannot navigate to a guessed ID',async()=>{
 const f=fixture('en',async()=>({...sample,data:[
  {...sample.data[0],key:'catalog:7',station:{name:'No identifier',connector_type:'CCS2',power_kw:50,evidence:'SIMULATED'}},
  sample.data[1],
 ]}));
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.list.children.length,1);
 assert.equal(f.list.children[0].dataset.partnerLocationId,'7');
 assert.equal(f.chosen.length,0);
});


for(const lang of ['en','ar'])test('missing partner name, provider, address and coordinates are explicitly unknown '+lang,async()=>{
 const row={...sample.data[1],provider_label:{unexpected:'Untrusted'},
  location:{id:null,name:null,address:{unexpected:'Untrusted'},city:null,country:null}};
 const f=fixture(lang,async()=>({...sample,data:[sample.data[0],row]}));
 f.form.onsubmit({preventDefault(){}});await tick();
 const card=f.list.children[1];
 assert.equal(f.list.children.length,2);
 assert.match(card.textContent,lang==='ar'?/سجل الشريك #7/:/Partner record #7/);
 assert.match(card.textContent,lang==='ar'?/اسم مقدم الخدمة غير متاح/:/Provider label unavailable/);
 assert.match(card.textContent,lang==='ar'?/تفاصيل العنوان غير متاحة/:/Address details unavailable/);
 assert.match(card.textContent,lang==='ar'?/إحداثيات الخريطة غير متاحة/:/Map coordinates unavailable/);
 assert.equal(card.textContent.includes('[object Object]'),false);
 assert.equal(card.textContent.includes('0,0'),false);
 assert.equal(card.children.some(child=>child.textContent.includes('no booking or remote control')),lang==='en');
});


test('recent presets are capped at five and empty filters have localized Arabic labels',async()=>{
 const f=fixture('ar'),q=f.form.children[0].children.find(node=>node.tag==='input');
 // First empty query demonstrates the localized fallback, with no automatic request.
 f.form.onsubmit({preventDefault(){}});await tick();
 const panel=f.root.children[7];
 assert.match(panel.children[1].children[0].dataset.ar,/كل المواقع/);
 assert.match(panel.children[1].children[0].dataset.ar,/كل الدول/);
 assert.match(panel.children[1].children[0].dataset.ar,/كل المصادر المنشورة/);
 for(let i=0;i<7;i++){q.value='Session place '+i;f.form.onsubmit({preventDefault(){}});await tick();}
 assert.equal(panel.children[1].children.length,5);
 assert.match(panel.children[1].children[0].textContent,/Session place 6/);
 assert.equal(panel.children[1].textContent.includes('Session place 0'),false);
 assert.equal(f.calls.length,8);
 panel.children[2].onclick();
 assert.equal(panel.hidden,true);
 assert.equal(f.calls.length,8,'Clearing in-memory entries never calls an API');
});


test('account transition resets source and connector controls in addition to country and private search history',async()=>{
 const f=fixture();
 f.control.setContext({owner:'A',customer:true});
 const selects=f.form.children.flatMap(node=>node.children).filter(node=>node.tag==='select');
 const scope=selects.find(node=>node!==null&&node.name==='scope')||f.form.children[1].children.find(node=>node.tag==='select');
 const plug=f.form.children.find(node=>node.tag==='label'&&node.textContent.includes('Connector')).children.find(node=>node.tag==='select');
 const country=selects.find(node=>node.name==='country');
 const age=selects.find(node=>node.name==='max_source_age_hours');
 scope.value='partner';plug.value='CCS2';country.value='US';age.value='24';
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.root.children[7].hidden,false);
 f.control.setContext({owner:'B',customer:true});
 assert.equal(scope.value,'all');assert.equal(plug.value,'');
 assert.equal(country.value,'');assert.equal(age.value,'');
 assert.equal(f.root.children[7].hidden,true);
 f.form.onsubmit({preventDefault(){}});await tick();
 const params=new URLSearchParams(f.calls.at(-1)[0].split('?')[1]);
 assert.equal(params.get('scope'),'all');
 assert.equal(params.get('connector'),'');
 assert.equal(params.has('country'),false);
 assert.equal(params.has('max_source_age_hours'),false);
});


test('same-account customer-to-vendor role change also clears query history and saved-only filter',async()=>{
 const f=fixture();
 f.control.setContext({owner:7,customer:true,favorite:async()=>true});
 f.form.children[0].children.find(n=>n.tag==='input').value='Customer private';
 const saved=f.form.children.flatMap(n=>n.children).find(n=>n.tag==='input'&&n.type==='checkbox');
 saved.checked=true;
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.root.children[7].hidden,false);
 assert.equal(new URLSearchParams(f.calls.at(-1)[0].split('?')[1]).get('saved_only'),'1');
 f.control.setContext({owner:7,customer:false});
 assert.equal(f.root.children[7].hidden,true);
 assert.equal(saved.checked,false);
 assert.equal(f.form.children[0].children.find(n=>n.tag==='input').value,'');
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(new URLSearchParams(f.calls.at(-1)[0].split('?')[1]).get('saved_only'),'0');
});


for(const lang of ['en','ar'])test('forged published station evidence never creates a booking or saved-place action '+lang,async()=>{
 const source={...sample.data[0],station:{
  ...sample.data[0].station,evidence:'LIVE_VERIFIED',
 }};
 const f=fixture(lang,async()=>({...sample,data:[source,sample.data[1]]}));
 f.control.setContext({owner:'customer',customer:true,
  favorite:async()=>{throw Error('Forged evidence cannot create a customer write');}});
 f.form.onsubmit({preventDefault(){}});await tick();
 assert.equal(f.list.children.length,2);
 assert.equal(f.list.children[0].children.some(x=>x.tag==='button'),false,
  'Unrecognized source cannot be a booking action');
 assert.match(f.list.children[0].textContent,
  lang==='ar'?/غير مكتملة/:/details incomplete/);
 assert.equal(f.list.children[1].children.some(x=>x.tag==='button'),true,
  'Valid partner preferences must remain independent');
 assert.equal(f.chosen.length,0);
});

for(const lang of ['en','ar'])for(const transition of ['account','role','clear'])test('detached recent-filter callback cannot restore a discarded private preset '+lang+' '+transition,async()=>{
 const f=fixture(lang);f.control.setContext({owner:'owner-A',customer:true});
 const input=f.form.children[0].children.find(n=>n.tag==='input');input.value='Private old location';
 f.form.onsubmit({preventDefault(){}});await tick();
 const panel=f.root.children[7],restore=panel.children[1].children[0].onclick;
 if(transition==='account')f.control.setContext({owner:'owner-B',customer:true});
 else if(transition==='role')f.control.setContext({owner:'owner-A',customer:false});
 else panel.children[2].onclick();
 input.value='Current draft';const before=f.calls.length;restore();
 assert.equal(input.value,'Current draft','A discarded actor/history callback must not overwrite current form fields');
 assert.equal(f.calls.length,before,'Discarded callbacks must not issue requests');
 assert.equal(panel.hidden,true);
});
