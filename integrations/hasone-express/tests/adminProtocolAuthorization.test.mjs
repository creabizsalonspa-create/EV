/** Exercise the shipped controller modules with isolated persistence adapters.
 * No Express router/Mongo concurrency/hardware acceptance is inferred here.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,copyFile,readdir,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import crypto from 'node:crypto';

const id='507f1f77bcf86cd799439011',stationId='507f1f77bcf86cd799439012';
const approved=()=>({chargerId:'CH-1',stationId,enabled:true,protocol:'ocpp1.6',
 secretHash:'private-hash',secretSalt:'private-salt'});
const booking=()=>({_id:id,userId:'owner',stationId,status:'Upcoming',isPaid:true,
 hasArrived:true,ocppTokenHash:'',ocppChargerId:'',reservedChargerId:''});
const query=value=>({select(){return this;},lean:async()=>value,then(resolve,reject){return Promise.resolve(value).then(resolve,reject);}});
function response(){return {code:200,headers:{},set(key,value){this.headers[key]=value;return this;},
 status(code){this.code=code;return this;},json(body){this.body=body;return this;}};}
async function fixture(t){
 const root=await mkdtemp(join(tmpdir(),'agent2-admin-controller-'));
 const state={booking:booking(),rows:[approved()],source:null,link:null,writes:[],indexReads:0,limits:[]};
 const key='agent2-controller-'+crypto.randomUUID();
 const cursor=rows=>({sort(){return this;},limit(n){state.limits.push(n);rows=rows.slice(0,n);return this;},
 project(){return this;},toArray:async()=>rows});
 const chargerTable={find:()=>cursor(state.rows),findOne:async(filter,options)=>{
  const row=state.rows.find(r=>r.chargerId===filter.chargerId&&r.stationId===filter.stationId);
  return !row?null:options?.projection?Object.fromEntries(Object.entries(row).filter(([key])=>options.projection[key])):row;
 },indexes:async()=>{state.indexReads++;return [];},
 countDocuments:async()=>state.rows.length,createIndex:async(...args)=>state.writes.push(['index',args]),
 insertOne:async row=>state.writes.push(['charger',row])};
 const tokenTable={findOne:async()=>null,insertOne:async row=>state.writes.push(['token',row]),
 updateMany:async(...args)=>{state.writes.push(['tokenUpdate',args]);}};
 globalThis[key]={
  mongoose:{isValidObjectId:value=>typeof value==='string'&&/^[a-f0-9]{24}$/i.test(value),
   Types:{ObjectId:class{constructor(value){this.value=value;}toString(){return this.value;}}},
   connection:{db:{collection:name=>name==='ocpp_chargers'?chargerTable:
     name==='ocpp_access_tokens'?tokenTable:{findOne:async()=>state.source}}}},
  BookingModel:{findById:()=>query(state.booking),findOneAndUpdate:async(_filter,update)=>{
   state.writes.push(['booking',update]);Object.assign(state.booking,update.$set);return state.booking;}},
  ChargingSessionModel:{findOne:()=>query(state.link),create:async fields=>{state.writes.push(['session',fields]);return {_id:'507f1f77bcf86cd799439013',...fields};}},
  NearestStationModel:{findById:()=>query({_id:stationId,name:'Synthetic station'})}
 };
 t.after(async()=>{delete globalThis[key];await rm(root,{recursive:true,force:true});});
 await mkdir(join(root,'src/controllers'),{recursive:true});
 await mkdir(join(root,'src/models'),{recursive:true});
 await mkdir(join(root,'src/utils'),{recursive:true});
 await mkdir(join(root,'node_modules/mongoose'),{recursive:true});
 await writeFile(join(root,'package.json'),JSON.stringify({type:'module'}));
 await writeFile(join(root,'node_modules/mongoose/package.json'),JSON.stringify({name:'mongoose',type:'module',exports:'./index.js'}));
 await writeFile(join(root,'node_modules/mongoose/index.js'),`export default globalThis[${JSON.stringify(key)}].mongoose;`);
 for(const model of ['BookingModel','ChargingSessionModel','NearestStationModel'])
  await writeFile(join(root,'src/models',model+'.js'),`export default globalThis[${JSON.stringify(key)}][${JSON.stringify(model)}];`);
 const source=new URL('../',import.meta.url);
 for(const file of await readdir(source))if(file.endsWith('.mjs'))
  await copyFile(new URL(file,source),join(root,'src/utils',file));
 for(const file of ['adminOcppBooking.js','adminMultiCharger.js','ocppHistoryController.js'])
  await copyFile(new URL(file,source),join(root,'src/controllers',file));
 // Copy unmodified production-intended controllers; only imports' persistence
 // dependencies are isolated. Assertions must observe real entrypoint behavior.
 const actions=await import(pathToFileURL(join(root,'src/controllers/adminOcppBooking.js')));
 const registry=await import(pathToFileURL(join(root,'src/controllers/adminMultiCharger.js')));
 const history=await import(pathToFileURL(join(root,'src/controllers/ocppHistoryController.js')));
 const call=async(fn,body={},params={id})=>{const res=response();await fn({params,body,query:{},headers:{user_id:state.booking.userId}},res);return res;};
 return {state,actions,registry,history,call};
}

test('actual token entrypoint refuses missing/unknown protocol with no credential or booking write',async t=>{
 const f=await fixture(t);
 for(const protocol of [undefined,null,'',false,'OCPP1.6','ocpp9']){
  f.state.rows=[{...approved(),protocol}];
  const res=await f.call(f.actions.AdminIssueBookingOcppToken,{chargerId:'CH-1',protocol:'ocpp1.6'});
  assert.equal(res.code,409);assert.deepEqual(f.state.writes,[]);
  assert.ok(!f.state.booking.ocppTokenHash);assert.equal(res.body.data,undefined);
 }
});
test('actual issuance refuses duplicate or invalid active registry rather than selecting another charger',async t=>{
 const f=await fixture(t);
 for(const rows of [[approved(),approved()],[approved(),{...approved(),chargerId:'invalid id'}],
  [approved(),{...approved(),chargerId:'CH-2',protocol:null}],
  Array.from({length:129},(_,i)=>({...approved(),chargerId:'CH-'+i}))]){
  f.state.rows=rows;
  const res=await f.call(f.actions.AdminIssueBookingOcppToken,{chargerId:'CH-1'});
  assert.equal(res.code,409);assert.deepEqual(f.state.writes,[]);
 }
 assert.ok(f.state.limits.every(n=>n===129));
});
test('actual issuance preserves protocol-specific connector scope and only returns one-time credential',async t=>{
 const f=await fixture(t);
 for(const protocol of ['ocpp1.6','ocpp2.0.1']){
  f.state.rows=[{...approved(),protocol}];f.state.booking=booking();f.state.writes=[];
  const scope={protocol,connectorId:2,evseId:protocol==='ocpp1.6'?null:3};
  const res=await f.call(f.actions.AdminIssueBookingOcppToken,{resourceScope:scope});
  assert.equal(res.code,201);assert.deepEqual(res.body.data.resourceScope,scope);
  assert.equal(res.body.data.chargingSessionConfirmed,false);
  const token=f.state.writes.find(([kind])=>kind==='token')[1];
  assert.deepEqual(token.resourceScope,scope);assert.equal(token.enabled,true);
  assert.equal(token.tokenHash,crypto.createHash('sha256').update(res.body.data.oneTimeToken).digest('hex'));
  assert.equal('oneTimeToken' in token,false);assert.equal('secretHash' in res.body.data,false);
 }
});
test('actual issuer blocks cross-protocol scope without changing booking authority',async t=>{
 const f=await fixture(t);
 const res=await f.call(f.actions.AdminIssueBookingOcppToken,{resourceScope:{protocol:'ocpp2.0.1',connectorId:1,evseId:1}});
 assert.equal(res.code,409);assert.deepEqual(f.state.writes,[]);
});
test('actual valid unscoped legacy issuance retains explicit protocol registration prerequisite',async t=>{
 const f=await fixture(t);
 const res=await f.call(f.actions.AdminIssueBookingOcppToken);
 assert.equal(res.code,201);assert.equal(res.body.data.resourceScope,null);
 assert.equal(res.body.data.connectorScoped,false);
});
test('actual reissue cannot guess protocol even for an expired pinned booking',async t=>{
 const f=await fixture(t);
 f.state.booking={...booking(),ocppTokenHash:'a'.repeat(64),ocppChargerId:'CH-1',
  ocppTokenIssuedAt:new Date(Date.now()-60*60000)};
 for(const protocol of [undefined,null,'ocpp9']){
  f.state.rows=[{...approved(),protocol}];
  const res=await f.call(f.actions.AdminReissueExpiredBookingOcppToken);
  assert.equal(res.code,409);assert.deepEqual(f.state.writes,[]);
  assert.equal(f.state.booking.ocppTokenHash,'a'.repeat(64));
 }
});
test('actual operations reports registry review and disables authorization without fabricating protocol',async t=>{
 const f=await fixture(t);f.state.rows=[{...approved(),protocol:null}];
 const res=await f.call(f.actions.AdminGetBookingOcppOperations);
 assert.equal(res.code,200);const data=res.body.data;
 assert.equal(data.chargerRegistryReviewRequired,true);assert.equal(data.approvedChargerId,null);
 assert.deepEqual(data.approvedChargers,[]);
 for(const flag of ['canIssueToken','canReissueToken','canBindSource','canFinalizeSource'])assert.equal(data[flag],false);
 assert.deepEqual(f.state.writes,[]);
});
test('actual operations respects reserved physical charger before authorization pin exists',async t=>{
 const f=await fixture(t);f.state.rows=[approved(),{...approved(),chargerId:'CH-2',protocol:'ocpp2.0.1'}];
 f.state.booking.reservedChargerId='CH-2';
 const res=await f.call(f.actions.AdminGetBookingOcppOperations);
 assert.equal(res.code,200);assert.equal(res.body.data.approvedChargerId,'CH-2');
 assert.equal(res.body.data.selectedChargerId,'CH-2');assert.equal(res.body.data.canIssueToken,true);
 assert.equal(res.body.data.requiresChargerSelection,false);
});
test('actual registration requires explicit protocol before index or credential mutations',async t=>{
 const f=await fixture(t);
 for(const protocol of [undefined,null,'',false,'ocpp9']){
  const res=await f.call(f.registry.AdminProvisionStationOcppCharger,{chargerId:'new-id',protocol},{id:stationId});
  assert.equal(res.code,400);assert.deepEqual(f.state.writes,[]);assert.equal(f.state.indexReads,0);
 }
});
test('actual station listing labels unknown protocols for review without leaking charger secrets',async t=>{
 const f=await fixture(t);f.state.rows=[{...approved(),protocol:undefined}];
 const res=await f.call(f.registry.AdminListStationOcppChargers,{}, {id:stationId});
 assert.equal(res.code,200);const value=res.body.data.chargers[0];
 assert.equal(value.protocol,null);assert.equal(value.protocolReviewRequired,true);
 assert.equal('secretHash' in value,false);assert.equal('secretSalt' in value,false);
 assert.deepEqual(f.state.writes,[]);
});

test('actual operations cannot offer issuance for conflicting or unavailable customer pins',async t=>{
 const f=await fixture(t);
 for(const fields of [{reservedChargerId:'CH-removed'},
  {reservedChargerId:'CH-2',ocppChargerId:'CH-1'}]) {
  f.state.booking={...booking(),...fields};
  const res=await f.call(f.actions.AdminGetBookingOcppOperations);
  assert.equal(res.code,200);assert.equal(res.body.data.canIssueToken,false);
  assert.equal(res.body.data.approvedChargerId,null);assert.deepEqual(f.state.writes,[]);
 }
});
test('actual explicit registration stores requested protocol and only a salted credential hash',async t=>{
 const f=await fixture(t);
 const res=await f.call(f.registry.AdminProvisionStationOcppCharger,{chargerId:'new-id',protocol:'ocpp2.0.1'},{id:stationId});
 assert.equal(res.code,201);assert.equal(res.body.data.protocol,'ocpp2.0.1');
 assert.equal(res.body.data.monitoringOnly,true);assert.equal(res.body.data.controlApproved,false);
 const persisted=f.state.writes.find(([kind])=>kind==='charger')[1];
 assert.equal(persisted.protocol,'ocpp2.0.1');assert.equal('password' in persisted,false);
 assert.equal(persisted.secretHash,crypto.scryptSync(res.body.data.password,Buffer.from(persisted.secretSalt,'hex'),32).toString('hex'));
 assert.equal('secretHash' in res.body.data,false);assert.equal('secretSalt' in res.body.data,false);
});

test('actual source binding rejects registry/source protocol mismatch before creating a link',async t=>{
 const f=await fixture(t);const sourceId='507f1f77bcf86cd799439099';
 f.state.booking={...booking(),ocppChargerId:'CH-1',ocppTokenHash:'a'.repeat(64),ocppTokenIssuedAt:new Date()};
 f.state.source={_id:sourceId,chargerId:'CH-1',protocol:'ocpp2.0.1',transactionId:'wrong-protocol',
  status:'active',idTokenHash:'a'.repeat(64),startedAt:new Date(),paymentStatus:'not_collected'};
 const res=await f.call(f.actions.AdminBindBookingOcppSource,{sourceSessionId:sourceId});
 assert.equal(res.code,409);assert.deepEqual(f.state.writes,[]);
});
test('actual source binding still accepts authorized source identity before its first meter',async t=>{
 const f=await fixture(t);const sourceId='507f1f77bcf86cd799439099';
 f.state.booking={...booking(),ocppChargerId:'CH-1',ocppTokenHash:'a'.repeat(64),ocppTokenIssuedAt:new Date()};
 f.state.source={_id:sourceId,chargerId:'CH-1',protocol:'ocpp1.6',transactionId:'no-meter',
  status:'active',idTagHash:'a'.repeat(64),startedAt:new Date(),paymentStatus:'not_collected'};
 const res=await f.call(f.actions.AdminBindBookingOcppSource,{sourceSessionId:sourceId});
 assert.equal(res.code,200);assert.equal(res.body.data.chargingSessionConfirmed,true);
 assert.equal(res.body.data.meterVerified,false);assert.equal(res.body.data.energyKwh,null);
 assert.deepEqual(f.state.writes.map(([kind])=>kind),['session']);
});
test('actual history query projects protocol, preserves disabled history and redacts changed registry',async t=>{
 const f=await fixture(t);const sourceId='507f1f77bcf86cd799439099',userId='507f1f77bcf86cd799439088';
 f.state.booking={...booking(),userId,ocppChargerId:'CH-1',ocppTokenHash:'a'.repeat(64)};
 f.state.link={_id:id,bookingId:id,userId,sourceType:'ocpp',sourceSessionId:sourceId,
  sourceChargerId:'CH-1',sourceTransactionId:'history-tx'};
 f.state.source={_id:sourceId,chargerId:'CH-1',protocol:'ocpp1.6',transactionId:'history-tx',
  status:'ended',idTagHash:'a'.repeat(64),startedAt:new Date('2026-11-08T00:00:00Z'),
  endedAt:new Date('2026-11-08T00:10:00Z'),paymentStatus:'not_collected',
  meterStartWh:1000,meterLastWh:2000,energyWh:1000,energyKwh:1};
 f.state.rows=[{...approved(),enabled:false}];
 let res=await f.call(f.history.CustomerOcppHistoryDetail);
 assert.equal(res.code,200);assert.equal(res.body.data.sourceVerified,true);
 assert.equal(res.body.data.energyKwh,1);assert.equal(res.body.data.chargerEnabled,false);
 f.state.rows[0].protocol='ocpp2.0.1';
 res=await f.call(f.history.CustomerOcppHistoryDetail);
 assert.equal(res.code,200);assert.equal(res.body.data.sourceVerified,false);
 assert.equal(res.body.data.energyKwh,null);assert.equal(res.body.data.transactionId,null);
 assert.deepEqual(f.state.writes,[]);
});

test('actual binding uses accepted gateway time when the charger clock is a day wrong',async t=>{
 const f=await fixture(t),sourceId='507f1f77bcf86cd799439099',now=Date.now();
 for(const offset of [-86400000,86400000]){
  f.state.writes=[];
  f.state.booking={...booking(),ocppChargerId:'CH-1',ocppTokenHash:'a'.repeat(64),ocppTokenIssuedAt:new Date(now-60000)};
  f.state.source={_id:sourceId,chargerId:'CH-1',protocol:'ocpp1.6',transactionId:'skewed-clock',
   status:'active',idTagHash:'a'.repeat(64),startedAt:new Date(now+offset),
   startedReceivedAt:new Date(now-30000),paymentStatus:'not_collected'};
  const res=await f.call(f.actions.AdminBindBookingOcppSource,{sourceSessionId:sourceId});
  assert.equal(res.code,200);assert.equal(res.body.data.meterVerified,false);
  assert.deepEqual(f.state.writes.map(([kind])=>kind),['session']);
  assert.equal(f.state.source.startedAt.valueOf(),now+offset);
 }
});
test('actual binding cannot use malformed gateway receipts or invalid issuance times to create links',async t=>{
 const f=await fixture(t),sourceId='507f1f77bcf86cd799439099',now=Date.now();
 f.state.booking={...booking(),ocppChargerId:'CH-1',ocppTokenHash:'a'.repeat(64),ocppTokenIssuedAt:new Date(now-60000)};
 f.state.source={_id:sourceId,chargerId:'CH-1',protocol:'ocpp1.6',transactionId:'bad-time',
  status:'active',idTagHash:'a'.repeat(64),startedAt:new Date(now-10000),paymentStatus:'not_collected'};
 for(const startedReceivedAt of [null,new Date(now-60001),new Date(now+86400000),new Date(now-30000).toISOString()]){
  f.state.source.startedReceivedAt=startedReceivedAt;
  assert.equal((await f.call(f.actions.AdminBindBookingOcppSource,{sourceSessionId:sourceId})).code,409);
  assert.deepEqual(f.state.writes,[]);
 }
 f.state.source.startedReceivedAt=new Date(now-10000);f.state.booking.ocppTokenIssuedAt='invalid';
 assert.equal((await f.call(f.actions.AdminBindBookingOcppSource,{sourceSessionId:sourceId})).code,409);
 assert.deepEqual(f.state.writes,[]);
});
