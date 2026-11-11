/** Shipped reader and generated customer service with isolated DB adapters.
 * These synthetic source contracts do not replace actual Express/Mongo gates.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,writeFile,mkdir,readFile,rm} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {randomUUID} from 'node:crypto';
import {readUniqueOcppCharger} from '../ocppChargerRegistry.mjs';
const station='507f1f77bcf86cd799439011',bookingId='507f1f77bcf86cd799439012';
const sessionId='507f1f77bcf86cd799439013',sourceId='507f1f77bcf86cd799439014';
const registered=()=>({stationId:station,chargerId:'CH-1',protocol:'ocpp1.6',enabled:true});
function table(rows,reads=[]){return {find(filter,options){
 reads.push({filter,options,limit:null});const at=reads.length-1;
 return {limit(n){reads[at].limit=n;return this;},async toArray(){
  return rows.filter(row=>Object.entries(filter).every(([k,v])=>row[k]===v))
   .slice(0,reads[at].limit).map(row=>Object.fromEntries(Object.entries(row)
    .filter(([k])=>options.projection[k]===1)));
 }};
},countDocuments:async filter=>rows.filter(row=>Object.entries(filter).every(([k,v])=>row[k]===v)).length};}

test('reader uses bounded global identity lookup and never projects credential fields',async()=>{
 const reads=[],row={...registered(),secretHash:'private',secretSalt:'private',password:'private'};
 const result=await readUniqueOcppCharger(table([row],reads),{stationId:station,chargerId:'CH-1',requireEnabled:true});
 assert.deepEqual(result,registered());assert.deepEqual(reads[0].filter,{chargerId:'CH-1'});
 assert.equal(reads[0].limit,2);assert.equal(reads[0].options.projection._id,0);
 for(const key of ['secretHash','secretSalt','password'])assert.equal(key in result,false);
});
test('reader refuses same-station, disabled and cross-station duplicates in either order',async()=>{
 const row=registered();
 for(const duplicate of [row,{...row,enabled:false},{...row,stationId:'another-station'},
   {...row,protocol:'ocpp2.0.1'}])for(const rows of [[row,duplicate],[duplicate,row]])
  assert.equal(await readUniqueOcppCharger(table(rows),{stationId:station,chargerId:'CH-1'}),null);
});
test('disabled history is resolvable but disabled active status never is',async()=>{
 const row={...registered(),enabled:false};
 assert.deepEqual(await readUniqueOcppCharger(table([row]),{stationId:station,chargerId:'CH-1'}),row);
 assert.equal(await readUniqueOcppCharger(table([row]),{stationId:station,chargerId:'CH-1',requireEnabled:true}),null);
});
test('missing/mismatched station, protocol and enabled metadata never establish identity',async()=>{
 for(const rows of [[],[{...registered(),stationId:'other'}],[{...registered(),protocol:null}],
  [{...registered(),enabled:undefined}],[{...registered(),enabled:'true'}]])
  assert.equal(await readUniqueOcppCharger(table(rows),{stationId:station,chargerId:'CH-1'}),null);
 const reads=[];
 for(const params of [{stationId:station,chargerId:{$ne:null}},{stationId:null,chargerId:'CH-1'},
  {stationId:station,chargerId:'CH-1',requireEnabled:1}])
  assert.equal(await readUniqueOcppCharger(table([],reads),params),null);
 assert.deepEqual(reads,[]);
});
async function customerFixture(t){
 const root=await mkdtemp(join(tmpdir(),'agent2-customer-registry-'));
 const key='agent2-customer-'+randomUUID();
 const state={rows:[registered()],reads:[],sourceReads:0,
  booking:{_id:bookingId,userId:'owner',stationId:station,ocppTokenHash:'a'.repeat(64),
   isPaid:true,hasArrived:true,ocppChargerId:'CH-1'},
  session:{_id:sessionId,userId:'owner',bookingId,sourceType:'ocpp',sourceSessionId:sourceId,
   sourceChargerId:'CH-1',sourceTransactionId:'tx-1'},
  source:{_id:sourceId,chargerId:'CH-1',protocol:'ocpp1.6',transactionId:'tx-1',status:'active',
   idTagHash:'a'.repeat(64),startedAt:new Date('2026-11-08T00:00:00Z'),
   paymentStatus:'not_collected',meterStartWh:1000,meterLastWh:2000,energyWh:1000,energyKwh:1}};
 globalThis[key]={
  booking:{findOne:async filter=>state.booking.userId===filter.userId?state.booking:null},
  session:{findOne:async filter=>state.session.userId===filter.userId?state.session:null},
  mongoose:{Types:{ObjectId:class{constructor(value){this.value=value;}toString(){return this.value;}}},
   connection:{db:{collection:name=>name==='ocpp_chargers'?table(state.rows,state.reads):
    {findOne:async()=>{state.sourceReads++;return state.source;}}}}}
 };
 t.after(async()=>{delete globalThis[key];await rm(root,{recursive:true,force:true});});
 for(const dir of ['src/services','src/models','src/routes','src/utils','node_modules/mongoose'])
  await mkdir(join(root,dir),{recursive:true});
 await writeFile(join(root,'package.json'),'{"type":"module"}');
 await writeFile(join(root,'node_modules/mongoose/package.json'),'{"type":"module","exports":"./index.js"}');
 await writeFile(join(root,'node_modules/mongoose/index.js'),`export default globalThis[${JSON.stringify(key)}].mongoose;`);
 for(const [model,alias] of [['Booking','booking'],['ChargingSession','session']])
  await writeFile(join(root,'src/models',model+'Model.js'),
   `const ${model}Schema={add(){}};const mongoose={model:()=>globalThis[${JSON.stringify(key)}][${JSON.stringify(alias)}]};\nconst ${model}Model = mongoose.model("${model}", ${model}Schema);\nexport default ${model}Model;`);
 await writeFile(join(root,'src/models/NearestStationModel.js'),'// source-contract placeholder\n');
 await writeFile(join(root,'src/routes/routes.js'),'const router = express.Router();\n');
 await writeFile(join(root,'src/services/userService.js'),
  'import crypto from "crypto";\nimport BookingModel from "../models/BookingModel.js";\nimport ChargingSessionModel from "../models/ChargingSessionModel.js";\n'+
  '// ─── Screen 3: Read-only charging status (no synthetic metering)\n'+
  '// replaced old status body\n// ─── Screen 4: Source-backed charger stop\n');
 const patcher=fileURLToPath(new URL('../../../tools/agent2/stage_ocpp_customer_status.py',import.meta.url));
 execFileSync('python3',[patcher,root]);
 const expected=await readFile(new URL('../ocppChargerRegistry.mjs',import.meta.url));
 assert.deepEqual(await readFile(join(root,'src/utils/ocppChargerRegistry.mjs')),expected);
 const service=await import(pathToFileURL(join(root,'src/services/userService.js')));
 return {state,service,root};
}
test('generated customer status preserves qualified meter reads for one registered charger',async t=>{
 const f=await customerFixture(t),res=await f.service.GetChargingStatusService('owner',sessionId);
 assert.equal(res.status,'Success');assert.equal(res.data.sourceVerified,true);assert.equal(res.data.kwhUsed,1);
 assert.equal(res.data.canStop,false);assert.equal(res.data.canExtend,false);assert.equal(f.state.sourceReads,1);
});
test('generated customer status redacts duplicate registry before reading source telemetry',async t=>{
 const f=await customerFixture(t);
 for(const duplicate of [registered(),{...registered(),enabled:false},
  {...registered(),stationId:'other-station'},{...registered(),protocol:'ocpp2.0.1'}]){
  f.state.rows=[registered(),duplicate];
  const res=await f.service.GetChargingStatusService('owner',sessionId);
  assert.equal(res.status,'Success');assert.equal(res.data.sourceVerified,false);
  assert.equal(res.data.kwhUsed,null);assert.equal(res.data.canStop,false);
 }
 assert.equal(f.state.sourceReads,0);assert.ok(f.state.reads.every(r=>r.limit===2));
});
test('generated customer status still rejects cross-owner and disabled active reads',async t=>{
 const f=await customerFixture(t);
 assert.equal((await f.service.GetChargingStatusService('other-owner',sessionId)).status,'fail');
 f.state.rows=[{...registered(),enabled:false}];
 const res=await f.service.GetChargingStatusService('owner',sessionId);
 assert.equal(res.data.sourceVerified,false);assert.equal(f.state.sourceReads,0);
});
test('history staging refuses missing or drifted registry dependency before any route writes',async t=>{
 const f=await customerFixture(t),path=join(f.root,'src/utils/ocppChargerRegistry.mjs');
 const customer=join(f.root,'src/routes/routes.js'),before=await readFile(customer);
 const patcher=fileURLToPath(new URL('../../../tools/agent2/stage_ocpp_history.py',import.meta.url));
 for(const missing of [true,false]){
  if(missing)await rm(path);else await writeFile(path,'// stale reader\n');
  assert.throws(()=>execFileSync('python3',[patcher,f.root],{stdio:'pipe'}),/Command failed/);
  assert.deepEqual(await readFile(customer),before);
 }
});
