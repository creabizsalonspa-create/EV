#!/usr/bin/env node
/** Real MongoDB index/duplicate-request acceptance. Starts only its own
 * disposable loopback server. No external URI or production collection accepted.
 * Usage: node test_source_binding_indexes_mongo.mjs DEPS MONGOD REPORT.json
 * DEPS supplies mongodb6.12.0 and mongodb-memory-server-core10.2.3.
 */
import assert from 'node:assert/strict';
import {mkdtemp,readFile,writeFile,rm} from 'node:fs/promises';
import {resolve,join,dirname} from 'node:path';
import {pathToFileURL,fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {auditOcppSourceBindingIndexes,ensureStagingOcppSourceBindingIndexes} from '../../integrations/hasone-express/ocppSourceBindingIndexAudit.mjs';
const [depsArg,binaryArg,reportArg]=process.argv.slice(2);
if(!depsArg||!binaryArg||!reportArg)throw Error('Provide dependency root, mongod executable and report JSON');
const deps=resolve(depsArg),binary=resolve(binaryArg),reportPath=resolve(reportArg);
const {MongoMemoryServer}=await import(pathToFileURL(join(deps,'node_modules/mongodb-memory-server-core/lib/index.js')));
const {MongoClient}=await import(pathToFileURL(join(deps,'node_modules/mongodb/lib/index.js')));
for(const [name,version] of [['mongodb','6.12.0'],['mongodb-memory-server-core','10.2.3']])
 assert.equal(JSON.parse(await readFile(join(deps,'node_modules',name,'package.json'),'utf8')).version,version);
const data=await mkdtemp(join(dirname(reportPath),'.agent2-mongo-index-'));
let server,client;let passed=0,seq=0;const findings=[];
const approval={approval:'APPROVE_DISPOSABLE_BINDING_INDEXES'};
const sourceFilter={sourceSessionId:{$gt:''}},bookingFilter={sourceType:'ocpp',sourceSessionId:{$gt:''}};
async function check(name,fn){await fn();passed++;findings.push(name);console.log('PASS '+name);}
try{
 server=await MongoMemoryServer.create({binary:{systemBinary:binary,version:'7.0.14'},
  instance:{ip:'127.0.0.1',dbPath:data,dbName:'agent2_demo_ephemeral',args:['--nounixsocket']}});
 const uri=server.getUri();assert.match(uri,/^mongodb:\/\/127\.0\.0\.1:\d+\/$/);
 client=new MongoClient(uri);await client.connect();const db=client.db('agent2_demo_ephemeral');
 const fresh=async()=>db.createCollection('binding_case_'+(++seq));
 const row=(booking,source,user='synthetic-user')=>({bookingId:booking,userId:user,sourceType:'ocpp',sourceSessionId:source});
 const install=async c=>ensureStagingOcppSourceBindingIndexes(c,approval);
 await check('empty read-only preflight does not claim that missing indexes enforce uniqueness',async()=>{
  const c=await fresh(),before=await c.indexes();const a=await auditOcppSourceBindingIndexes(c);
  assert.equal(a.ready,true);assert.equal(a.bindingUniquenessEnforced,false);
  assert.deepEqual(await c.indexes(),before);assert.equal(await c.countDocuments(),0);
 });
 await check('actual canonical partial index definitions verify and repeated ensure creates nothing extra',async()=>{
  const c=await fresh();const a=await install(c);assert.equal(a.bindingUniquenessEnforced,true);
  const indexes=await c.indexes();assert.equal(indexes.length,3);
  assert.deepEqual(indexes.find(i=>i.name==='uniq_linked_ocpp_source_session').partialFilterExpression,sourceFilter);
  assert.deepEqual(indexes.find(i=>i.name==='uniq_source_bound_ocpp_booking').partialFilterExpression,bookingFilter);
  await install(c);assert.deepEqual(await c.indexes(),indexes);
 });
 await check('eight concurrent source claims for one booking have exactly one database winner',async()=>{
  const c=await fresh();await install(c);
  const result=await Promise.allSettled(Array.from({length:8},(_,i)=>c.insertOne(row('one-booking','source-'+i))));
  assert.equal(result.filter(r=>r.status==='fulfilled').length,1);
  for(const r of result)if(r.status==='rejected')assert.equal(r.reason.code,11000);
  assert.equal(await c.countDocuments(),1);
 });
 await check('eight concurrent booking claims for one physical source have exactly one database winner',async()=>{
  const c=await fresh();await install(c);
  const result=await Promise.allSettled(Array.from({length:8},(_,i)=>c.insertOne(row('booking-'+i,'one-source'))));
  assert.equal(result.filter(r=>r.status==='fulfilled').length,1);
  for(const r of result)if(r.status==='rejected')assert.equal(r.reason.code,11000);
  assert.equal(await c.countDocuments(),1);
 });
 await check('legacy unbound rows remain outside the canonical partial uniqueness filters',async()=>{
  const c=await fresh();await install(c);await c.insertMany(Array.from({length:4},()=>row('legacy-booking','')));
  assert.equal(await c.countDocuments(),4);const a=await auditOcppSourceBindingIndexes(c);
  assert.equal(a.sourceBoundRecordCount,0);assert.equal(a.bindingUniquenessEnforced,true);
 });
 await check('real narrow source index permits duplicate OCPP sources and audit rejects its claimed coverage',async()=>{
  const c=await fresh();await c.createIndex({sourceSessionId:1},{name:'uniq_linked_ocpp_source_session',unique:true,
   partialFilterExpression:{sourceType:'unverified',sourceSessionId:{$gt:''}}});
  const before=await c.indexes(),a=await auditOcppSourceBindingIndexes(c);
  assert.equal(a.hasUniqueSourceIndex,false);assert.equal(a.indexReviewRequired,true);
  await c.insertMany([row('a','same-source'),row('b','same-source')]);assert.equal(await c.countDocuments(),2);
  await assert.rejects(()=>install(c),/index review/);assert.deepEqual(await c.indexes(),before);
 });
 await check('real narrow booking index permits duplicate booking claims and ensure leaves indexes untouched',async()=>{
  const c=await fresh();await c.createIndex({bookingId:1,userId:1},{name:'uniq_source_bound_ocpp_booking',unique:true,
   partialFilterExpression:{sourceType:'different-source',sourceSessionId:{$gt:''}}});
  await c.insertMany(Array.from({length:8},(_,i)=>row('one-booking','source-'+i)));
  const before=await c.indexes(),a=await auditOcppSourceBindingIndexes(c);
  assert.equal(a.hasUniqueBookingIndex,false);assert.equal(a.bindingUniquenessEnforced,false);
  await assert.rejects(()=>install(c),/index review/);assert.deepEqual(await c.indexes(),before);
 });
 await check('wrong-key canonical names block every index write before either create',async()=>{
  const c=await fresh();await c.createIndex({bookingId:1},{name:'uniq_source_bound_ocpp_booking',unique:true});
  const before=await c.indexes();await assert.rejects(()=>install(c),/index review/);
  assert.deepEqual(await c.indexes(),before);
 });
 await check('unfiltered unique indexes already cover bound rows without adding duplicate indexes',async()=>{
  const c=await fresh();await c.createIndex({sourceSessionId:1},{name:'global_source',unique:true});
  await c.createIndex({bookingId:1,userId:1},{name:'global_booking',unique:true});
  const before=await c.indexes();assert.equal((await install(c)).bindingUniquenessEnforced,true);
  assert.deepEqual(await c.indexes(),before);
 });
 await check('non-staging database and absent explicit disposable approval refuse mutations',async()=>{
  await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(client.db('not_an_authorized_stage').collection('never_created'),approval),/non-staging/);
  const c=await fresh(),before=await c.indexes();await assert.rejects(()=>ensureStagingOcppSourceBindingIndexes(c),/approval/);
  assert.deepEqual(await c.indexes(),before);
 });
 const repo=resolve(dirname(fileURLToPath(import.meta.url)),'../..');const source_blobs={};
 for(const name of ['integrations/hasone-express/ocppSourceBindingIndexAudit.mjs','tools/agent2/test_source_binding_indexes_mongo.mjs']){
  const bytes=await readFile(join(repo,name));source_blobs[name]=createHash('sha1').update('blob '+bytes.length+'\0').update(bytes).digest('hex');
 }
 await writeFile(reportPath,JSON.stringify({status:'DISPOSABLE_MONGODB_INDEX_PASS',passed,findings,
  mongodb_version:(await db.admin().command({buildInfo:1})).version,mongodb_driver:'6.12.0',source_blobs,
  boundaries:['Owned disposable loopback mongod only, no external URI','No production data/indexes, charging/finance business changes, hardware or PSP acceptance','Original Express/Mongo app integration and release gates remain separate']},null,2)+'\n',{mode:0o600});
 console.log('SOURCE_BINDING_MONGO_PASS '+passed);
}finally{if(client)await client.close();if(server)await server.stop();await rm(data,{recursive:true,force:true});}
