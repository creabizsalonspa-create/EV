#!/usr/bin/env node
/**
 * Agent 2: run actual staged Express app against a disposable loopback-only mongod.
 * No real merchant credentials, no production DB, no actual OCPP commands.
 *
 * node tools/agent2/run_isolated_backend_demo.mjs STAGED_SOURCE EXPRESS_DEPENDENCY_ROOT
 */
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {spawn} from 'node:child_process';
import {mkdtempSync,mkdirSync,rmSync,existsSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {createServer} from 'node:net';
import {pathToFileURL} from 'node:url';
const source=resolve(process.argv[2]??'.'), dependencies=resolve(process.argv[3]??'.');
const ocppHandler=process.argv[4] ? resolve(process.argv[4]) : null;
if(!existsSync(join(source,'src/app.js')) || !existsSync(join(source,'src/utils/paymentIntegrity.mjs'))
   || !existsSync(join(dependencies,'node_modules/mongoose/index.js'))) {
 console.error('Only isolated staged source is allowed; missing patched app/dependencies.');process.exit(2);
}
if(source.startsWith('/home/hasio/ev-deployment/') || source==='/home/hasio/ev-deployment'){
 throw new Error('Refusing to run against deployed source.');
}
if(process.env.AGENT2_REAL_ADMIN_UI_URL){
 if(!ocppHandler)throw Error('Actual browser mode requires a staged OCPP handler');
 const {loopbackBase}=await import('./isolatedAdminApiRelay.mjs');
 loopbackBase(process.env.AGENT2_REAL_ADMIN_UI_URL);
}
async function freePort(){
 const s=createServer();await new Promise(resolveListen=>s.listen(0,'127.0.0.1',resolveListen));
 const port=s.address().port;await new Promise(done=>s.close(done));return port;
}
const data=mkdtempSync(join(tmpdir(),'agent2-isolated-mongo-'));
const mongoPort=await freePort();
const uri='mongodb://127.0.0.1:'+mongoPort+'/agent2_demo_ephemeral';
const mongod=spawn('mongod',['--dbpath',data,'--port',String(mongoPort),'--bind_ip','127.0.0.1',
  '--logpath',join(data,'mongod.log'),'--quiet'],{stdio:'ignore'});
process.env.MONGODB_URI=uri;
process.env.JWT_SECRET='agent2-ephemeral-local-test-secret-not-for-production';
process.env.JWT_SECRET_EXPIRES_IN='1h';
process.env.STRIPE_SECRET_KEY='';
process.env.STRIPE_WEBHOOK_SECRET='';
process.env.SSLCOMMERZ_STORE_ID='';
process.env.SSLCOMMERZ_STORE_PASSWORD='';
process.env.OCPP_REMOTE_CONTROL_ENABLED='false';
process.env.BACKEND_URL='http://127.0.0.1';
process.env.OCPP_PUBLIC_WS_BASE='';
const mongoose=(await import(pathToFileURL(join(dependencies,'node_modules/mongoose/index.js')).href)).default;
let server;
try{
 let connected=false;
 for(let i=0;i<35;i++){
  try{await mongoose.connect(uri,{serverSelectionTimeoutMS:750});connected=true;break;}
  catch{await new Promise(done=>setTimeout(done,150));}
 }
 if(!connected)throw new Error('Disposable MongoDB could not start');
 const app=(await import(pathToFileURL(join(source,'src/app.js')).href)).default;
 const {default:UserModel}=await import(pathToFileURL(join(source,'src/models/UserModel.js')).href);
 const {default:BookingModel}=await import(pathToFileURL(join(source,'src/models/BookingModel.js')).href);
 await BookingModel.init(); // Explicitly wait for the atomic station-slot index.
 const {default:AppSettingsModel}=await import(pathToFileURL(join(source,'src/models/AppSettingsModel.js')).href);
 const {default:ChargingSessionModel}=await import(pathToFileURL(join(source,'src/models/ChargingSessionModel.js')).href);
 await ChargingSessionModel.init(); // Wait for the one-source-session-per-booking constraint.
 const {auditOcppSourceBindingIndexes}=await import(
   pathToFileURL(join(source,'src/utils/ocppSourceBindingIndexAudit.mjs')).href);
 const {default:AdminModel}=await import(pathToFileURL(join(source,'src/models/AdminModel.js')).href);
 const jwt=(await import(pathToFileURL(join(dependencies,'node_modules/jsonwebtoken/index.js')).href)).default;
 const {default:PaymentCardModel}=await import(pathToFileURL(join(source,'src/models/PaymentCardModel.js')).href);
 const {EncodeToken}=await import(pathToFileURL(join(source,'src/utils/tokenHelper.js')).href);
 await AppSettingsModel.create({key:'main',savedCardEnabled:true,sslcommerzEnabled:false,stripeEnabled:false,currencyCode:'AED',currencySymbol:'د.إ'});
 const user=await UserModel.create({fullName:'Agent 2 Isolated Test',email:'agent2-no-delivery@example.invalid',
                                 isVerified:true,password:'disabled'});
 const booking=await BookingModel.create({userId:user._id,stationId:new mongoose.Types.ObjectId(),
              date:'2026-11-08',time:'11:00',totalAmount:60,status:'Upcoming'});
 const {default:NearestStationModel}=await import(pathToFileURL(join(source,'src/models/NearestStationModel.js')).href);
 const {default:VehicleBrandModel}=await import(pathToFileURL(join(source,'src/models/VehicleBrandModel.js')).href);
 const {default:VehicleModelItemModel}=await import(pathToFileURL(join(source,'src/models/VehicleModelItemModel.js')).href);
 const station=await NearestStationModel.create({name:'Agent 2 Disposable UAE Test Station',
   address:'Test-only Ras Al Khaimah',status:'Available',latitude:25.741,longitude:55.922,
   pricePerHourValue:60,taxPercent:5,
   slots:[{startTime:'12:00 PM',endTime:'01:00 PM'}]});
 const brand=await VehicleBrandModel.create({name:'Test Vehicle Brand'});
 const model=await VehicleModelItemModel.create({brandId:brand._id,name:'Test EV Model'});
 const bearer='Bearer '+EncodeToken(user.email,String(user._id));
 const admin=await AdminModel.create({name:'Agent 2 Test Administrator',email:'admin-agent2@example.invalid',password:'ephemeral-qa-value'});
 const adminBearer='Bearer '+jwt.sign({id:String(admin._id)},process.env.JWT_SECRET,{expiresIn:'20m'});
 server=await new Promise(done=>{const s=app.listen(0,'127.0.0.1',()=>done(s));});
 const base='http://127.0.0.1:'+server.address().port;
 let passed=0;
 async function request(method,path,body,authorized=false,extraHeaders={}){
  const res=await fetch(base+path,{method,headers:{
    ...(body===undefined?{}:{'content-type':'application/json'}),
    ...(authorized?{authorization:bearer}:{}),
    ...extraHeaders},
    ...(body===undefined?{}:{body:JSON.stringify(body)}),
    redirect:'manual'});
  let json=null;const bodyText=await res.text();try{json=JSON.parse(bodyText);}catch{}
  return {code:res.status,body:json,text:bodyText};
 }
 async function check(label,fn){await fn();passed++;console.log('PASS '+label);}
 await check('history cursor indexes preserve existing source-binding uniqueness',async()=>{
   const indexes=await ChargingSessionModel.collection.indexes();
   const owner=indexes.find(x=>x.name==='history_owner_source_cursor');
   const all=indexes.find(x=>x.name==='history_source_cursor');
   assert.deepEqual(owner?.key,{userId:1,sourceType:1,_id:-1});
   assert.deepEqual(all?.key,{sourceType:1,_id:-1});
   assert.equal(owner.unique===true,false);assert.equal(all.unique===true,false);
   assert.ok(indexes.some(x=>x.name==='uniq_linked_ocpp_source_session'&&x.unique===true));
   assert.ok(indexes.some(x=>x.name==='uniq_source_bound_ocpp_booking'&&x.unique===true));
 });
 await check('OCPP source-bound booking indexes are unique and audit clean',async()=>{
   const audit=await auditOcppSourceBindingIndexes(
     mongoose.connection.db.collection(ChargingSessionModel.collection.name));
   assert.equal(audit.ready,true);
   assert.equal(audit.hasUniqueSourceIndex,true);
   assert.equal(audit.hasUniqueBookingIndex,true);
 });
 await check('health uses isolated Mongo connection',async()=>{
  const r=await request('GET','/health');assert.equal(r.code,200);assert.equal(r.body.database,'connected');
 });
 await check('public app settings hide unsafe raw-card payment option',async()=>{
  const r=await request('GET','/api/v1/app/settings');assert.equal(r.code,200);
  assert.equal(r.body.data.savedCardEnabled,false);
  assert.equal(r.body.data.availableGateways.some(x=>x.id==='card'),false);
 });
 await check('station discovery requires a JWT',async()=>{
  const r=await request('GET','/api/v1/NearestStations');assert.equal(r.code,401);
 });
 await check('authenticated booking list works on isolated DB',async()=>{
  const r=await request('GET','/api/v1/Bookings',undefined,true);
  assert.equal(r.code,200);
 });
 await check('authenticated station discovery includes seeded location',async()=>{
  const x=await request('GET','/api/v1/NearestStations',undefined,true);
  assert.equal(x.code,200);assert.equal(x.body.status,'Success');
  assert.equal(x.body.data.some(s=>String(s._id)===String(station._id)),true);
 });
 await check('authenticated station details resolve seeded charger site',async()=>{
  const x=await request('GET','/api/v1/StationDetails/'+station._id,undefined,true);
  assert.equal(x.code,200);assert.equal(x.body.status,'Success');
 });
 const qrStation=await NearestStationModel.create({
   name:'Agent 2 Station QR Safeguard',address:'Test Only',status:'Available',
   latitude:25.753,longitude:55.948,qrToken:'agent2-qr-token-test',
   pricePerHourValue:45,slots:[{startTime:'11:00 AM',endTime:'12:00 PM'}],
 });
 const qrBooking=await BookingModel.create({
   userId:user._id,stationId:qrStation._id,stationName:qrStation.name,
   date:'2030-11-07',time:'11:00 AM',slotStart:'11:00 AM',slotEnd:'12:00 PM',
   totalAmount:45,isPaid:true,paymentStatus:'paid',hasArrived:true,status:'Upcoming',
 });
 await check('unpaid or incorrect QR token fails without starting a charger',async()=>{
   const wrong=await request('POST','/api/v1/ChargingSession/validate-qr',
     {stationId:String(qrStation._id),qrToken:'invalid-qr'},true);
   assert.equal(wrong.code,400);
   assert.equal(await ChargingSessionModel.countDocuments({bookingId:qrBooking._id}),0);
 });
 let qrSessionId;
 await check('valid QR verifies station but never claims charging has started',async()=>{
   const result=await request('POST','/api/v1/ChargingSession/validate-qr',
     {stationId:String(qrStation._id),qrToken:'agent2-qr-token-test'},true);
   assert.equal(result.code,200);
   assert.equal(result.body.data.requiresOcppConfirmation,true);
   assert.equal(result.body.data.canStartCharging,false);
   assert.equal(result.body.data.meterVerified,false);
   assert.equal(result.body.data.status,'NotStarted');
   qrSessionId=result.body.data.sessionId;
   const stored=await ChargingSessionModel.findById(qrSessionId);
   assert.equal(stored.status,'NotStarted');
 });
 await check('valid QR does not bypass physical start adapter or booked slot timing',async()=>{
   const start=await request('POST','/api/v1/ChargingSession/'+qrSessionId+'/start',{},true);
   assert.equal(start.code,400);
   const details=await request('GET','/api/v1/ChargingStation/'+qrBooking._id,
     undefined,true);
   assert.equal(details.code,400);
   assert.equal(details.body.data.tooEarly,true);
 });
 await check('customer can add an EV to the isolated garage',async()=>{
  const x=await request('POST','/api/v1/MyVehicles',{modelId:String(model._id),connectorType:'CCS',
                         batteryCapacityKwh:75},true);
  assert.equal(x.code,200);assert.equal(x.body.status,'Success');
 });
 await check('customer can create a real persisted booking from an available slot',async()=>{
  const x=await request('POST','/api/v1/Bookings',{stationId:String(station._id),
    date:'2026-11-08',slotStart:'12:00 PM',slotEnd:'01:00 PM',connectorType:'CCS'},true);
  assert.equal(x.code,200);assert.equal(x.body.status,'Success');
  assert.equal(x.body.data.totalAmount,63);
  const saved=await BookingModel.findById(x.body.data.bookingId);
  assert.equal(saved.isPaid,false);assert.equal(saved.totalAmount,63);
 });
 await check('duplicate booking for identical date and slot is rejected',async()=>{
  const x=await request('POST','/api/v1/Bookings',{stationId:String(station._id),
    date:'2026-11-08',slotStart:'12:00 PM',slotEnd:'01:00 PM',connectorType:'CCS'},true);
  assert.equal(x.code,400);
 });
 await check('simultaneous booking requests cannot double-book an occupied slot',async()=>{
  const body={stationId:String(station._id),date:'2026-11-09',slotStart:'12:00 PM',
    slotEnd:'01:00 PM',connectorType:'CCS'};
  const tries=await Promise.all(Array.from({length:10},()=>request('POST','/api/v1/Bookings',body,true)));
  assert.equal(tries.filter(x=>x.code===200).length,1);
  assert.equal(tries.filter(x=>x.code===400).length,9);
  assert.equal(await BookingModel.countDocuments({stationId:station._id,date:body.date,
    slotStart:body.slotStart,slotEnd:body.slotEnd,status:'Upcoming'}),1);
 });
 await check('cancelled booking releases the date/slot atomically',async()=>{
  const old=await BookingModel.findOne({stationId:station._id,date:'2026-11-09',status:'Upcoming'});
  assert.ok(old);
  const cancelled=await request('PUT','/api/v1/Bookings/'+old._id+'/cancel',{},true);
  assert.equal(cancelled.code,200);
  const res=await request('POST','/api/v1/Bookings',{stationId:String(station._id),date:'2026-11-09',
    slotStart:'12:00 PM',slotEnd:'01:00 PM',connectorType:'CCS'},true);
  assert.equal(res.code,200);
 });
 // Booking mutation safety: actual HTTP, real Mongo indexes, synthetic data only.
 const mutationStation=await NearestStationModel.create({name:'Mutation Test Only',
   address:'Loopback',status:'Available',latitude:25.7,longitude:55.9,
   pricePerHourValue:60,taxPercent:5,slots:[
     {startTime:'11:00 AM',endTime:'12:00 PM'},
     {startTime:'12:00 PM',endTime:'01:00 PM'},
     {startTime:'02:00 PM',endTime:'02:30 PM'}]});
 let mutationNumber=0;
 const mutationFixture=overrides=>BookingModel.create({userId:user._id,
   stationId:mutationStation._id,date:new Date(Date.UTC(2031,0,++mutationNumber)).toISOString().slice(0,10),
   time:'11:00 AM',slotStart:'11:00 AM',slotEnd:'12:00 PM',status:'Upcoming',
   isPaid:false,hasArrived:false,totalAmount:63,...overrides});
 const mutationBody=date=>({date,time:'12:00 PM',slotStart:'12:00 PM',slotEnd:'01:00 PM'});
 const mutationPath=(row,action)=>'/api/v1/Bookings/'+row._id+'/'+action;
 await check('unused unpaid booking reschedules with its original quote and exact slot',async()=>{
   const row=await mutationFixture({});
   const result=await request('PUT',mutationPath(row,'reschedule'),mutationBody('2031-04-01'),true);
   assert.equal(result.code,200);assert.equal(result.body.data.time,'12:00 PM');
   const stored=await BookingModel.findById(row._id);
   assert.equal(stored.date,'2031-04-01');assert.equal(stored.totalAmount,63);
   assert.equal(stored.isPaid,false);
 });
 await check('paid, arrived, pinned and uncertain-token bookings cannot release inventory',async()=>{
   for(const fields of [{isPaid:true},{hasArrived:true},{ocppChargerId:'retained-pin'},
       {ocppTokenHash:'a'.repeat(64)},{ocppTokenIssuedAt:new Date()}]){
     const row=await mutationFixture(fields);
     for(const action of ['reschedule','cancel']){
       const result=await request('PUT',mutationPath(row,action),mutationBody('2031-04-03'),true);
       assert.equal(result.code,400);
     }
     const stored=await BookingModel.findById(row._id);
     assert.equal(stored.date,row.date);assert.equal(stored.status,'Upcoming');
     assert.equal(stored.totalAmount,63);assert.equal(stored.isPaid,row.isPaid);
   }
 });
 await check('any existing session link blocks customer booking mutation without deleting proof',async()=>{
   const row=await mutationFixture({});
   const linked=await ChargingSessionModel.create({userId:user._id,bookingId:row._id,
     stationId:mutationStation._id,status:'NotStarted'});
   for(const action of ['reschedule','cancel'])
     assert.equal((await request('PUT',mutationPath(row,action),mutationBody('2031-04-03'),true)).code,400);
   assert.equal((await BookingModel.findById(row._id)).status,'Upcoming');
   assert.ok(await ChargingSessionModel.findById(linked._id));
 });
 await check('mismatched display time, changed duration and unavailable station preserve booking',async()=>{
   const row=await mutationFixture({});
   for(const body of [{...mutationBody('2031-04-03'),time:'03:00 PM'},
       {date:'2031-04-03',time:'02:00 PM',slotStart:'02:00 PM',slotEnd:'02:30 PM'},
       {...mutationBody('bad-date'),date:{$ne:'x'}}]){
     assert.equal((await request('PUT',mutationPath(row,'reschedule'),body,true)).code,400);
   }
   await NearestStationModel.updateOne({_id:mutationStation._id},{$set:{status:'Unavailable'}});
   try{
     assert.equal((await request('PUT',mutationPath(row,'reschedule'),mutationBody('2031-04-03'),true)).code,400);
   }finally{
     await NearestStationModel.updateOne({_id:mutationStation._id},{$set:{status:'Available'}});
   }
   assert.equal((await BookingModel.findById(row._id)).date,row.date);
 });
 await check('foreign booking mutations fail without changing owner or slot',async()=>{
   const row=await mutationFixture({userId:new mongoose.Types.ObjectId()});
   for(const action of ['reschedule','cancel'])
     assert.equal((await request('PUT',mutationPath(row,action),mutationBody('2031-04-03'),true)).code,400);
   assert.equal((await BookingModel.findById(row._id)).date,row.date);
 });
 await check('concurrent reschedules into one slot retain atomic station uniqueness',async()=>{
   const rows=await Promise.all([mutationFixture({}),mutationFixture({})]);
   const responses=await Promise.all(rows.map(row=>request('PUT',mutationPath(row,'reschedule'),
     mutationBody('2031-04-02'),true)));
   assert.equal(responses.filter(r=>r.code===200).length,1);
   assert.equal(responses.filter(r=>r.code===400).length,1);
   assert.equal(await BookingModel.countDocuments({stationId:mutationStation._id,
     date:'2031-04-02',slotStart:'12:00 PM',status:'Upcoming'}),1);
 });
 await check('late payment/arrival authorization wins against a stale customer cancellation',async()=>{
   const row=await mutationFixture({});
   const original=BookingModel.findOneAndUpdate;
   let intercepted=false;
   BookingModel.findOneAndUpdate=async function(filter,...args){
     if(String(filter._id)===String(row._id) && !intercepted){
       intercepted=true;
       await BookingModel.updateOne({_id:row._id},{$set:{isPaid:true,hasArrived:true,
         ocppTokenHash:'b'.repeat(64),ocppTokenIssuedAt:new Date()}});
     }
     return original.call(this,filter,...args);
   };
   try{
     assert.equal((await request('PUT',mutationPath(row,'cancel'),{},true)).code,400);
   }finally{BookingModel.findOneAndUpdate=original;}
   assert.equal(intercepted,true);
   const stored=await BookingModel.findById(row._id);
   assert.equal(stored.status,'Upcoming');assert.equal(stored.isPaid,true);
   assert.equal(stored.ocppTokenHash,'b'.repeat(64));
 });
 await check('booking calendar dates cannot omit year or normalize an impossible day',async()=>{
   for(const date of ['08 Nov, Sun','2026-02-30','2026-13-01']){
     const response=await request('POST','/api/v1/Bookings',{stationId:String(station._id),
       date,slotStart:'12:00 PM',slotEnd:'01:00 PM'},true);
     assert.equal(response.code,400);
     assert.equal(await BookingModel.countDocuments({stationId:station._id,date}),0);
   }
 });
 await check('overlapping station slot definitions cannot bypass exact-slot exclusivity',async()=>{
   const site=await NearestStationModel.create({name:'Overlap Safety Test',address:'Loopback',
     status:'Available',latitude:25.7,longitude:55.9,pricePerHourValue:60,
     slots:[{startTime:'12:00 PM',endTime:'01:00 PM'},{startTime:'12:30 PM',endTime:'01:30 PM'}]});
   const responses=await Promise.all(site.slots.map(slot=>request('POST','/api/v1/Bookings',{
     stationId:String(site._id),date:'2032-01-01',slotStart:slot.startTime,slotEnd:slot.endTime},true)));
   assert.equal(responses.every(r=>r.code===400),true);
   assert.equal(await BookingModel.countDocuments({stationId:site._id}),0);
   await NearestStationModel.updateOne({_id:site._id},{$set:{slots:[
     {startTime:'12:00 PM',endTime:'01:00 PM'},{startTime:'01:00 PM',endTime:'02:00 PM'}]}});
   assert.equal((await request('POST','/api/v1/Bookings',{stationId:String(site._id),
     date:'2032-01-01',slotStart:'12:00 PM',slotEnd:'01:00 PM'},true)).code,200);
 });
 await check('unresolved legacy date cannot silently collide with a new year-qualified reservation',async()=>{
   const site=await NearestStationModel.create({name:'Legacy Day Test',address:'Loopback',
     status:'Available',latitude:25.7,longitude:55.9,pricePerHourValue:60,
     slots:[{startTime:'12:00 PM',endTime:'01:00 PM'}]});
   const legacy=await BookingModel.create({userId:user._id,stationId:site._id,
     date:'08 Nov, Sun',time:'12:00 PM',slotStart:'12:00 PM',slotEnd:'01:00 PM',status:'Upcoming'});
   const body={stationId:String(site._id),date:'2032-11-08',slotStart:'12:00 PM',slotEnd:'01:00 PM'};
   assert.equal((await request('POST','/api/v1/Bookings',body,true)).code,400);
   assert.equal(await BookingModel.countDocuments({stationId:site._id}),1);
   // Explicit synthetic operator resolution; the guard never guesses the year.
   await BookingModel.updateOne({_id:legacy._id},{$set:{date:'2032-11-09'}});
   assert.equal((await request('POST','/api/v1/Bookings',body,true)).code,200);
 });
 await check('capacity preflight requires Admin role and accepts no query override',async()=>{
   const path='/api/v1/admin/stations/'+station._id+'/ocpp/reservation-readiness';
   assert.equal((await request('GET',path,undefined,false)).code,401);
   assert.equal((await request('GET',path,undefined,true)).code,401);
   const allowed=await request('GET',path,undefined,false,{authorization:adminBearer});
   assert.equal(allowed.code,200);assert.equal(allowed.body.data.readOnly,true);
   assert.equal(allowed.body.data.indexChangeAllowed,false);
   assert.equal(allowed.body.data.legacyStationIndexPresent,true);
   assert.equal(/ocppTokenHash|secretHash|userId/.test(JSON.stringify(allowed.body)),false);
   assert.equal((await request('GET',path+'?limit=0',undefined,false,{authorization:adminBearer})).code,400);
 });
 await check('authenticated booking payment options hide card vault',async()=>{
  const r=await request('GET','/api/v1/Bookings/'+booking._id+'/payment',undefined,true);
  assert.equal(r.code,200);
  assert.equal(r.body.data.availableGateways.some(x=>x.id==='card'),false);
  assert.equal(r.body.data.cards.length,0);
 });
 await check('unsupported card payment cannot mark booking paid',async()=>{
  const r=await request('POST','/api/v1/Bookings/'+booking._id+'/payment',{paymentMethod:'card'},true);
  assert.equal(r.code,400);
  const after=await BookingModel.findById(booking._id);
  assert.equal(after.isPaid,false);
 });
 await check('legacy PAN save rejects test card without writing it',async()=>{
  const r=await request('POST','/api/v1/PaymentCards',
    {cardHolder:'AGENT2 TEST',cardNumber:'4111111111111111',expiryDate:'12/28'},true);
  assert.equal(r.code,400);
  assert.equal(await PaymentCardModel.countDocuments({userId:user._id}),0);
 });
 await check('payment-status polling is forbidden without JWT',async()=>{
  const r=await request('GET','/api/v1/payment/status/booking/'+booking._id);
  assert.equal(r.code,401);
 });
 await check('payment-status polling returns record to its real owner',async()=>{
  const r=await request('GET','/api/v1/payment/status/booking/'+booking._id,undefined,true);
  assert.equal(r.code,200);assert.equal(r.body.data.isPaid,false);
 });
 await check('unverified browser success cannot mark paid',async()=>{
  const r=await request('POST','/api/v1/payment/sslcommerz/success',
    {value_a:'booking',value_b:String(booking._id),tran_id:'fake'});
  assert.equal(r.code,200);
  assert.match(r.text,/pending/i);
  assert.equal((await BookingModel.findById(booking._id)).isPaid,false);
 });
 await check('unsigned Stripe webhook cannot alter payment state',async()=>{
  const r=await request('POST','/api/v1/payment/stripe/webhook',
    {type:'payment_intent.succeeded',data:{object:{id:'pi_fake',status:'succeeded'}}});
  assert.equal(r.code,400);
  assert.equal((await BookingModel.findById(booking._id)).isPaid,false);
 });
 await check('unverified charging start cannot claim a physical OCPP start',async()=>{
  const session=await ChargingSessionModel.create({bookingId:booking._id,userId:user._id,
    stationId:station._id,status:'NotStarted'});
  const r=await request('POST','/api/v1/ChargingSession/'+session._id+'/start',{},true);
  assert.equal(r.code,400);
  assert.equal((await ChargingSessionModel.findById(session._id)).status,'NotStarted');
 });
 await check('polling a fake charging session never fabricates meter values',async()=>{
  const session=await ChargingSessionModel.create({bookingId:booking._id,userId:user._id,
    stationId:station._id,status:'Charging',kwhUsed:7,batteryPercent:40,
    startedAt:new Date(Date.now()-7200000),timeRemainingMs:3600000});
  const path='/api/v1/ChargingSession/'+session._id;
  const first=await request('GET',path+'/status',undefined,true);
  const second=await request('GET',path+'/status',undefined,true);
  assert.equal(first.code,200);assert.equal(second.code,200);
  assert.equal(first.body.data.meterVerified,false);
  assert.equal(first.body.data.kwhUsed,null);
  assert.equal(second.body.data.kwhUsed,null);
  const stored=await ChargingSessionModel.findById(session._id);
  assert.equal(stored.kwhUsed,7);
  assert.equal(stored.batteryPercent,40);
 });
 await check('unverified charging stop cannot create a synthetic invoice',async()=>{
  const session=await ChargingSessionModel.create({bookingId:booking._id,userId:user._id,
    stationId:station._id,status:'Charging',kwhUsed:12});
  const r=await request('POST','/api/v1/ChargingSession/'+session._id+'/stop',{},true);
  assert.equal(r.code,400);
  const stored=await ChargingSessionModel.findById(session._id);
  assert.equal(stored.status,'Charging');assert.equal(stored.totalAmount,0);
 });
 await check('unverified charging extensions cannot create billed charges',async()=>{
  const session=await ChargingSessionModel.create({bookingId:booking._id,userId:user._id,
    stationId:station._id,status:'Charging'});
  const r=await request('POST','/api/v1/ChargingSession/'+session._id+'/extend',{extendMins:30},true);
  assert.equal(r.code,400);
  const stored=await ChargingSessionModel.findById(session._id);
  assert.equal(stored.extensions.length,0);
 });
 await check('unverified completed sessions cannot generate trusted summaries',async()=>{
  const session=await ChargingSessionModel.create({bookingId:booking._id,userId:user._id,
    stationId:station._id,status:'Completed',kwhUsed:99});
  const r=await request('GET','/api/v1/ChargingSession/'+session._id+'/summary',undefined,true);
  assert.equal(r.code,400);
 });
 if(ocppHandler){
  if(!existsSync(ocppHandler) || ocppHandler.startsWith('/home/hasio/ev-deployment/'))
    throw new Error('OCPP handler must be a staged source copy');
  const {createProcessor}=await import(pathToFileURL(ocppHandler).href);
  const handle=await createProcessor(mongoose.connection.db);
  const db=mongoose.connection.db;
  const chargerId='agent2-ocpp201-test', token='agent2-disposable-token-201';
  const tokenHash=createHash('sha256').update(token).digest('hex');
  const expiresAt=new Date(Date.now()+3600000);
  await db.collection('ocpp_access_tokens').insertOne({chargerId,tokenHash,enabled:true,expiresAt});
  await db.collection('ocpp_tariffs').insertOne({chargerId,enabled:true,rateFilsPerKwh:75,vatPercent:5});
  const meter=value=>[{sampledValue:[{value:String(value),measurand:'Energy.Active.Import.Register',
                                      unitOfMeasure:{unit:'Wh'}}]}];
  const id='agent2-source-201';
  await check('invalid OCPP 2.0.1 token cannot create a source transaction',async()=>{
    const bad=await handle({chargerId},'ocpp2.0.1','TransactionEvent',{
      eventType:'Started',seqNo:0,transactionInfo:{transactionId:'unauthorized'},
      idToken:{idToken:'wrong-token'},meterValue:meter(1000)});
    assert.equal(bad.idTokenInfo.status,'Invalid');
    assert.equal(await db.collection('ocpp_sessions').countDocuments({chargerId}),0);
  });
  await check('OCPP 2.0.1 start stores approved token hash, not raw token',async()=>{
    const yes=await handle({chargerId},'ocpp2.0.1','TransactionEvent',{
      eventType:'Started',seqNo:0,transactionInfo:{transactionId:id},
      timestamp:new Date().toISOString(),idToken:{idToken:token},
      evse:{id:1,connectorId:1},meterValue:meter(1000)});
    assert.equal(yes.idTokenInfo.status,'Accepted');
    const doc=await db.collection('ocpp_sessions').findOne({chargerId,transactionId:id});
    assert.equal(doc.idTokenHash,tokenHash);
    assert.equal(doc.meterStartWh,1000);assert.equal(doc.status,'active');
    assert.equal(JSON.stringify(doc).includes(token),false);
  });
  await check('OCPP 2.0.1 consumed start token cannot start a second transaction',async()=>{
    const authorization=await handle({chargerId},'ocpp2.0.1','Authorize',
      {idToken:{idToken:token}});
    assert.equal(authorization.idTokenInfo.status,'Invalid');
    const reused=await handle({chargerId},'ocpp2.0.1','TransactionEvent',{
      eventType:'Started',seqNo:0,transactionInfo:{transactionId:'second-transaction-for-same-token'},
      idToken:{idToken:token},meterValue:meter(2000)});
    assert.equal(reused.idTokenInfo.status,'Invalid');
    assert.equal(await db.collection('ocpp_sessions').countDocuments({chargerId}),1);
    const stored=await db.collection('ocpp_access_tokens').findOne({chargerId,tokenHash});
    assert.equal(stored.enabled,false);
    assert.ok(stored.consumedAt instanceof Date);
  });
  await check('OCPP 2.0.1 metering/stop yields source-backed unpaid AED-fils calculation',async()=>{
    await handle({chargerId},'ocpp2.0.1','TransactionEvent',{
      eventType:'Updated',seqNo:1,transactionInfo:{transactionId:id},
      timestamp:new Date().toISOString(),meterValue:meter(6000)});
    await handle({chargerId},'ocpp2.0.1','TransactionEvent',{
      eventType:'Ended',seqNo:2,transactionInfo:{transactionId:id,stoppedReason:'EVDisconnected'},
      timestamp:new Date().toISOString(),meterValue:meter(8000)});
    const doc=await db.collection('ocpp_sessions').findOne({chargerId,transactionId:id});
    assert.equal(doc.status,'ended');assert.equal(doc.energyWh,7000);assert.equal(doc.energyKwh,7);
    assert.equal(doc.subtotalFils,525);assert.equal(doc.vatFils,26);assert.equal(doc.totalFils,551);
    assert.equal(doc.billingStatus,'calculated_unpaid');assert.equal(doc.paymentStatus,'not_collected');
  });
  await check('OCPP 2.0.1 duplicate sequence cannot change finalized source meters',async()=>{
    await handle({chargerId},'ocpp2.0.1','TransactionEvent',{
      eventType:'Updated',seqNo:1,transactionInfo:{transactionId:id},
      meterValue:meter(900000)});
    const doc=await db.collection('ocpp_sessions').findOne({chargerId,transactionId:id});
    assert.equal(doc.energyWh,7000);assert.equal(doc.paymentStatus,'not_collected');
  });
  const sourceDoc=await db.collection('ocpp_sessions').findOne({chargerId,transactionId:id});
  // This is a disposable QA fixture, NOT evidence of a real paid transaction.
  const approvedBooking=await BookingModel.create({userId:user._id,stationId:station._id,
    date:'2026-11-10',time:'12:00 PM',slotStart:'12:00 PM',slotEnd:'01:00 PM',
    totalAmount:63,isPaid:true,paymentStatus:'paid',hasArrived:true,status:'Upcoming',
    ocppTokenHash:tokenHash});
  await db.collection('ocpp_chargers').insertOne({stationId:String(station._id),chargerId,enabled:true,online:false});
  const linked=await ChargingSessionModel.create({userId:user._id,bookingId:approvedBooking._id,
    stationId:station._id,sourceType:'ocpp',sourceSessionId:String(sourceDoc._id),
    sourceChargerId:chargerId,sourceTransactionId:id,status:'NotStarted'});
  await check('linked completed source disables repeated QR scan on charging station screen',async()=>{
    const z=await request('GET','/api/v1/ChargingStation/'+approvedBooking._id,undefined,true);
    assert.equal(z.code,200);
    assert.equal(z.body.data.status,'Completed');
    assert.equal(z.body.data.canScan,false);
    assert.equal(z.body.data.sourceVerified,true);
    assert.equal(z.body.data.energyKwh,7);
  });
  await check('customer charging screen reads exact OCPP metering, not a generated estimate',async()=>{
    const r=await request('GET','/api/v1/ChargingSession/'+linked._id+'/status',undefined,true);
    assert.equal(r.code,200);assert.equal(r.body.data.sourceVerified,true);
    assert.equal(r.body.data.meterVerified,true);
    assert.equal(r.body.data.kwhUsed,7);
    assert.equal(r.body.data.chargingStatus,'Completed');
    assert.equal(r.body.data.paymentStatus,'not_collected');
    assert.equal(r.body.data.canStop,false);
  });
  await check('customer OCPP status becomes unverified if booking token proof changes',async()=>{
    approvedBooking.ocppTokenHash='b'.repeat(64);await approvedBooking.save();
    const r=await request('GET','/api/v1/ChargingSession/'+linked._id+'/status',undefined,true);
    assert.equal(r.code,200);assert.equal(r.body.data.sourceVerified,false);
    assert.equal(r.body.data.kwhUsed,null);
    approvedBooking.ocppTokenHash=tokenHash;await approvedBooking.save();
  });
  await check('different source session identifier cannot expose another meter reading',async()=>{
    linked.sourceSessionId=String(new mongoose.Types.ObjectId());await linked.save();
    const r=await request('GET','/api/v1/ChargingSession/'+linked._id+'/status',undefined,true);
    assert.equal(r.code,200);assert.equal(r.body.data.sourceVerified,false);
    linked.sourceSessionId=String(sourceDoc._id);await linked.save();
  });
  const adminStation=await NearestStationModel.create({name:'Agent 2 Authorized Booking Station',
    address:'Loopback OCPP Test Only',status:'Available',latitude:25.742,longitude:55.924,
    pricePerHourValue:60,taxPercent:5,slots:[{startTime:'03:00 PM',endTime:'04:00 PM'}]});
  const adminCharger='agent2-admin-bound-ocpp16';
  await db.collection('ocpp_chargers').insertOne({stationId:String(adminStation._id),
    chargerId:adminCharger,enabled:true,protocol:'ocpp1.6',controlApproved:false});
  await db.collection('ocpp_tariffs').insertOne({chargerId:adminCharger,enabled:true,
    rateFilsPerKwh:100,vatPercent:5});
  // This is explicitly test data; no actual merchant charge or arrived customer.
  const adminBooking=await BookingModel.create({userId:user._id,stationId:adminStation._id,
    date:'2026-11-11',time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
    totalAmount:63,status:'Upcoming',isPaid:true,paymentStatus:'paid',hasArrived:true});
  const issuePath='/api/v1/admin/bookings/'+adminBooking._id+'/ocpp/issue-token';
  await check('unprivileged customer cannot request one-time booking OCPP tokens',async()=>{
    const z=await request('POST',issuePath,{},true);
    assert.equal(z.code,401);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({chargerId:adminCharger}),0);
  });
  const opPath='/api/v1/admin/bookings/'+adminBooking._id+'/ocpp/operations';
  await check('customer cannot view Admin OCPP workflow state',async()=>{
    const z=await request('GET',opPath,undefined,true);
    assert.equal(z.code,401);
  });
  await check('Admin sees eligible booking, no existing credential or source',async()=>{
    const z=await request('GET',opPath,undefined,false,{authorization:adminBearer});
    assert.equal(z.code,200);
    assert.equal(z.body.data.canIssueToken,true);
    assert.equal(z.body.data.canFinalizeSource,false);
    assert.equal(z.body.data.authorizationIssued,false);
    assert.equal(JSON.stringify(z.body).includes('ocppTokenHash'),false);
  });
  let issuedToken;
  await check('authorized Admin issues one scoped, expiring OCPP token for an arrived booking',async()=>{
    const z=await request('POST',issuePath,{},false,{authorization:adminBearer});
    assert.equal(z.code,201);
    assert.equal(z.body.status,'Success');
    assert.equal(z.body.data.chargerId,adminCharger);
    issuedToken=z.body.data.oneTimeToken;
    assert.ok(typeof issuedToken==='string' && /^[0-9A-F]{20}$/.test(issuedToken));
    const hash=createHash('sha256').update(issuedToken).digest('hex');
    const saved=await BookingModel.findById(adminBooking._id);
    assert.equal(saved.ocppTokenHash,hash);
    assert.ok(saved.ocppTokenIssuedAt);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({chargerId:adminCharger,tokenHash:hash,enabled:true}),1);
  });
  await check('Admin workflow acknowledges issued credential without revealing token',async()=>{
    const z=await request('GET',opPath,undefined,false,{authorization:adminBearer});
    assert.equal(z.code,200);
    assert.equal(z.body.data.authorizationIssued,true);
    assert.equal(z.body.data.canIssueToken,false);
    assert.equal(z.body.data.canBindSource,true);
    assert.equal(JSON.stringify(z.body).includes(issuedToken),false);
    assert.equal(JSON.stringify(z.body).includes(createHash('sha256').update(issuedToken).digest('hex')),false);
  });
  await check('Admin duplicate token issuance rejected without adding another credential',async()=>{
    const z=await request('POST',issuePath,{},false,{authorization:adminBearer});
    assert.equal(z.code,409);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({chargerId:adminCharger}),1);
  });
  await check('simultaneous Admin token requests issue exactly one authorized credential',async()=>{
    const other=await BookingModel.create({userId:user._id,stationId:adminStation._id,
      date:'2026-11-13',time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
      totalAmount:63,status:'Upcoming',isPaid:true,hasArrived:true});
    const responses=await Promise.all(Array.from({length:8},()=>request('POST',
      '/api/v1/admin/bookings/'+other._id+'/ocpp/issue-token',{},false,
      {authorization:adminBearer})));
    assert.equal(responses.filter(x=>x.code===201).length,1);
    assert.equal(responses.filter(x=>x.code===409).length,7);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({
      chargerId:adminCharger,bookingId:String(other._id)}),1);
  });
  // Inject storage failures only into the disposable database. Simulate
  // both an insert rejected before commit and a lost acknowledgement after
  // commit, including unavailable cleanup. Never point this at a live DB.
  for(const renewal of [false,true]){
    for(const fault of ['before-insert','after-insert','after-insert-revoke-fails']){
      await check((renewal?'reissue':'initial issue')+' retains booking pin after '+fault,async()=>{
        const day=1+(renewal?3:0)+['before-insert','after-insert','after-insert-revoke-fails'].indexOf(fault);
        const oldHash=createHash('sha256').update('uncertain-old-'+day).digest('hex');
        const oldIssuedAt=new Date(Date.now()-30*60000);
        const candidate=await BookingModel.create({
          userId:user._id,stationId:adminStation._id,
          date:'2027-01-'+String(day).padStart(2,'0'),
          time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
          status:'Upcoming',isPaid:true,hasArrived:true,totalAmount:63,
          ...(renewal?{ocppChargerId:adminCharger,ocppTokenHash:oldHash,
            ocppTokenIssuedAt:oldIssuedAt}:{})
        });
        if(renewal)await db.collection('ocpp_access_tokens').insertOne({
          chargerId:adminCharger,tokenHash:oldHash,enabled:true,
          expiresAt:new Date(Date.now()-60000),bookingId:String(candidate._id)
        });
        const nativeCollection=db.collection;
        let attemptedHash=null,insertAttempts=0;
        db.collection=function(name,...args){
          const table=nativeCollection.call(this,name,...args);
          if(name!=='ocpp_access_tokens')return table;
          return new Proxy(table,{get(target,key){
            if(key==='insertOne')return async(document,...opts)=>{
              if(document.bookingId!==String(candidate._id))
                return target.insertOne(document,...opts);
              insertAttempts++;attemptedHash=document.tokenHash;
              if(fault==='before-insert')throw Error('injected insert unavailable');
              await target.insertOne(document,...opts);
              throw Error('injected acknowledgement lost after insert committed');
            };
            if(key==='updateMany')return async(filter,...opts)=>{
              if(fault==='after-insert-revoke-fails' && attemptedHash
                  && filter.tokenHash===attemptedHash)
                throw Error('injected revocation unavailable');
              return target.updateMany(filter,...opts);
            };
            const value=Reflect.get(target,key);
            return typeof value==='function'?value.bind(target):value;
          }});
        };
        let response;
        try{
          response=await request('POST','/api/v1/admin/bookings/'+candidate._id+
            '/ocpp/'+(renewal?'reissue-token':'issue-token'),{},false,
            {authorization:adminBearer});
        }finally{db.collection=nativeCollection;}
        assert.equal(response.code,503);
        assert.equal(insertAttempts,1);
        assert.equal(response.body.data?.oneTimeToken,undefined);
        const retained=await BookingModel.findById(candidate._id);
        assert.equal(retained.ocppTokenHash,attemptedHash);
        assert.equal(retained.ocppChargerId,adminCharger);
        assert.ok(retained.ocppTokenIssuedAt>oldIssuedAt);
        const table=db.collection('ocpp_access_tokens');
        const stored=await table.findOne({chargerId:adminCharger,tokenHash:attemptedHash});
        if(fault==='before-insert')assert.equal(stored,null);
        else assert.equal(stored.enabled,fault==='after-insert-revoke-fails');
        if(renewal)assert.equal((await table.findOne({tokenHash:oldHash})).enabled,false);
        const retries=await Promise.all(Array.from({length:8},()=>request('POST',
          '/api/v1/admin/bookings/'+candidate._id+'/ocpp/issue-token',{},false,
          {authorization:adminBearer})));
        assert.ok(retries.every(x=>x.code===409));
        const recover=await request('POST','/api/v1/admin/bookings/'+candidate._id+
          '/ocpp/reissue-token',{},false,{authorization:adminBearer});
        assert.equal(recover.code,409);
        const state=await request('GET','/api/v1/admin/bookings/'+candidate._id+
          '/ocpp/operations',undefined,false,{authorization:adminBearer});
        assert.equal(state.code,200);
        assert.equal(state.body.data.canIssueToken,false);
        assert.equal(state.body.data.canReissueToken,false);
        assert.equal(JSON.stringify(state.body).includes(attemptedHash),false);
        assert.equal(await table.countDocuments({bookingId:String(candidate._id),
          tokenHash:{$ne:oldHash}}),fault==='before-insert'?0:1);
      });
    }
  }
  await check('active unused booking token cannot be reissued early',async()=>{
    const response=await request('POST',
      '/api/v1/admin/bookings/'+adminBooking._id+'/ocpp/reissue-token',
      {},false,{authorization:adminBearer});
    assert.equal(response.code,409);
    assert.equal((await BookingModel.findById(adminBooking._id)).ocppTokenHash,
      createHash('sha256').update(issuedToken).digest('hex'));
  });
  const expiredHash=createHash('sha256').update('agent2-expired-unused-booking-token').digest('hex');
  const expiredBooking=await BookingModel.create({
    userId:user._id,stationId:adminStation._id,
    date:'2026-11-15',time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
    status:'Upcoming',isPaid:true,hasArrived:true,totalAmount:63,
    ocppChargerId:adminCharger,ocppTokenHash:expiredHash,
    ocppTokenIssuedAt:new Date(Date.now()-26*60000)
  });
  await db.collection('ocpp_access_tokens').insertOne({
    chargerId:adminCharger,tokenHash:expiredHash,enabled:true,
    expiresAt:new Date(Date.now()-60000),bookingId:String(expiredBooking._id)
  });
  const renewPath='/api/v1/admin/bookings/'+expiredBooking._id+'/ocpp/reissue-token';
  await check('Admin operation state identifies truly expired unused token',async()=>{
    const op=await request('GET',
      '/api/v1/admin/bookings/'+expiredBooking._id+'/ocpp/operations',
      undefined,false,{authorization:adminBearer});
    assert.equal(op.code,200);
    assert.equal(op.body.data.canReissueToken,true);
    assert.equal(op.body.data.canIssueToken,false);
  });
  await check('unprivileged customer cannot reissue booking charger authorization',async()=>{
    const response=await request('POST',renewPath,{},true);
    assert.equal(response.code,401);
  });
  let renewedToken='';
  await check('eight simultaneous reissue requests rotate expired token exactly once',async()=>{
    const responses=await Promise.all(Array.from({length:8},()=>request('POST',
      renewPath,{},false,{authorization:adminBearer})));
    assert.equal(responses.filter(x=>x.code===201).length,1);
    assert.equal(responses.filter(x=>x.code===409).length,7);
    renewedToken=responses.find(x=>x.code===201).body.data.oneTimeToken;
    assert.match(renewedToken,/^[0-9A-F]{20}$/);
    const newHash=createHash('sha256').update(renewedToken).digest('hex');
    const saved=await BookingModel.findById(expiredBooking._id);
    assert.equal(saved.ocppTokenHash,newHash);
    assert.equal(saved.ocppChargerId,adminCharger);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({
      chargerId:adminCharger,tokenHash:newHash,enabled:true}),1);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({
      chargerId:adminCharger,tokenHash:expiredHash,enabled:true}),0);
  });
  await check('revoked old token stays invalid while renewed token authorizes',async()=>{
    const old=await handle({chargerId:adminCharger},'ocpp1.6','Authorize',
      {idTag:'agent2-expired-unused-booking-token'});
    const current=await handle({chargerId:adminCharger},'ocpp1.6','Authorize',
      {idTag:renewedToken});
    assert.equal(old.idTagInfo.status,'Invalid');
    assert.equal(current.idTagInfo.status,'Accepted');
    const op=await request('GET','/api/v1/admin/bookings/'+expiredBooking._id+'/ocpp/operations',
      undefined,false,{authorization:adminBearer});
    assert.equal(op.body.data.canReissueToken,false);
    assert.equal(JSON.stringify(op.body).includes(renewedToken),false);
  });
  const consumedHash=createHash('sha256').update('agent2-already-consumed-booking-token').digest('hex');
  const consumedBooking=await BookingModel.create({
    userId:user._id,stationId:adminStation._id,
    date:'2026-11-16',time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
    status:'Upcoming',isPaid:true,hasArrived:true,totalAmount:63,
    ocppChargerId:adminCharger,ocppTokenHash:consumedHash,
    ocppTokenIssuedAt:new Date(Date.now()-30*60000)
  });
  await db.collection('ocpp_access_tokens').insertOne({
    chargerId:adminCharger,tokenHash:consumedHash,enabled:false,
    consumedAt:new Date(Date.now()-25*60000),
    expiresAt:new Date(Date.now()-10*60000),
    bookingId:String(consumedBooking._id)
  });
  await check('consumed token blocks reissue even if source linkage is delayed',async()=>{
    const op=await request('GET','/api/v1/admin/bookings/'+consumedBooking._id+'/ocpp/operations',
      undefined,false,{authorization:adminBearer});
    assert.equal(op.code,200);assert.equal(op.body.data.canReissueToken,false);
    const response=await request('POST',
      '/api/v1/admin/bookings/'+consumedBooking._id+'/ocpp/reissue-token',
      {},false,{authorization:adminBearer});
    assert.equal(response.code,409);
  });
  const existingSourceHash=createHash('sha256').update('agent2-source-observed-expired-token').digest('hex');
  const observedBooking=await BookingModel.create({
    userId:user._id,stationId:adminStation._id,
    date:'2026-11-17',time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
    status:'Upcoming',isPaid:true,hasArrived:true,totalAmount:63,
    ocppChargerId:adminCharger,ocppTokenHash:existingSourceHash,
    ocppTokenIssuedAt:new Date(Date.now()-40*60000)
  });
  await db.collection('ocpp_sessions').insertOne({
    chargerId:adminCharger,protocol:'ocpp1.6',transactionId:99999,
    idTagHash:existingSourceHash,status:'active',startedAt:new Date()
  });
  await check('source observation blocks reissue even without linked customer session',async()=>{
    const op=await request('GET','/api/v1/admin/bookings/'+observedBooking._id+'/ocpp/operations',
      undefined,false,{authorization:adminBearer});
    assert.equal(op.code,200);assert.equal(op.body.data.canReissueToken,false);
    const z=await request('POST',
      '/api/v1/admin/bookings/'+observedBooking._id+'/ocpp/reissue-token',
      {},false,{authorization:adminBearer});
    assert.equal(z.code,409);
  });
  const liveHash=createHash('sha256').update('agent2-unexpired-db-token').digest('hex');
  const liveBooking=await BookingModel.create({
    userId:user._id,stationId:adminStation._id,
    date:'2026-11-18',time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
    status:'Upcoming',isPaid:true,hasArrived:true,totalAmount:63,
    ocppChargerId:adminCharger,ocppTokenHash:liveHash,
    ocppTokenIssuedAt:new Date(Date.now()-40*60000)
  });
  await db.collection('ocpp_access_tokens').insertOne({
    chargerId:adminCharger,tokenHash:liveHash,enabled:true,
    expiresAt:new Date(Date.now()+120000),
    bookingId:String(liveBooking._id)
  });
  await check('database-valid token blocks reissue even with an old booking timestamp',async()=>{
    const op=await request('GET','/api/v1/admin/bookings/'+liveBooking._id+'/ocpp/operations',
      undefined,false,{authorization:adminBearer});
    assert.equal(op.code,200);assert.equal(op.body.data.canReissueToken,false);
    const z=await request('POST',
      '/api/v1/admin/bookings/'+liveBooking._id+'/ocpp/reissue-token',
      {},false,{authorization:adminBearer});
    assert.equal(z.code,409);
  });
  let adminOcppSession;
  await check('real OCPP 1.6 handler accepts Admin booking token and persists source meter events',async()=>{
    const starting=await handle({chargerId:adminCharger},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:issuedToken,timestamp:new Date().toISOString()});
    assert.equal(starting.idTagInfo.status,'Accepted');
    await handle({chargerId:adminCharger},'ocpp1.6','MeterValues',{
      transactionId:starting.transactionId,meterValue:meter(4000)});
    await handle({chargerId:adminCharger},'ocpp1.6','StopTransaction',{
      transactionId:starting.transactionId,meterStop:6000,
      timestamp:new Date().toISOString(),reason:'EVDisconnected'});
    adminOcppSession=await db.collection('ocpp_sessions').findOne({
      chargerId:adminCharger,transactionId:starting.transactionId,protocol:'ocpp1.6'});
    assert.ok(adminOcppSession);
    assert.equal(adminOcppSession.energyKwh,5);
    assert.equal(adminOcppSession.totalFils,525);
    assert.equal(adminOcppSession.paymentStatus,'not_collected');
  });
  const bindPath='/api/v1/admin/bookings/'+adminBooking._id+'/ocpp/bind-source';
  await check('customer without AdminAuth cannot bind a charging source transaction',async()=>{
    const z=await request('POST',bindPath,{sourceSessionId:String(adminOcppSession._id)},true);
    assert.equal(z.code,401);
  });
  await check('cancelled legacy booking cannot acquire a new OCPP source binding',async()=>{
    await BookingModel.updateOne({_id:adminBooking._id},{$set:{status:'Cancelled'}});
    try{
      const denied=await request('POST',bindPath,{sourceSessionId:String(adminOcppSession._id)},
        false,{authorization:adminBearer});
      assert.equal(denied.code,409);
      assert.equal(await ChargingSessionModel.countDocuments({bookingId:adminBooking._id}),0);
    }finally{
      await BookingModel.updateOne({_id:adminBooking._id},{$set:{status:'Upcoming'}});
    }
  });
  let adminLinkedSession;
  await check('twelve concurrent source-binding calls create exactly one booking session',async()=>{
    const replies=await Promise.all(Array.from({length:12},()=>request('POST',bindPath,
      {sourceSessionId:String(adminOcppSession._id)},false,
      {authorization:adminBearer})));
    assert.ok(replies.some(z=>z.code===200));
    assert.equal(replies.every(z=>[200,409].includes(z.code)),true);
    assert.equal(await ChargingSessionModel.countDocuments({
      bookingId:adminBooking._id,sourceType:'ocpp'}),1);
    const good=replies.filter(z=>z.code===200);
    assert.ok(good.every(z=>z.body.data.energyKwh===5));
    assert.ok(good.every(z=>z.body.data.financialSettlement==='not_collected'));
    adminLinkedSession=await ChargingSessionModel.findOne({
      bookingId:adminBooking._id,sourceType:'ocpp'});
    assert.equal(adminLinkedSession.sourceSessionId,String(adminOcppSession._id));
  });
  await check('repeated binding is idempotent and does not create another source',async()=>{
    const z=await request('POST',bindPath,{sourceSessionId:String(adminOcppSession._id)},
      false,{authorization:adminBearer});
    assert.equal(z.code,200);
    assert.equal(String(z.body.data.sessionId),String(adminLinkedSession._id));
    assert.equal(await ChargingSessionModel.countDocuments({
      bookingId:adminBooking._id,sourceType:'ocpp'}),1);
  });
  await check('a second source record cannot overwrite a booking already bound to OCPP',async()=>{
    const other={...adminOcppSession,_id:new mongoose.Types.ObjectId(),
      transactionId:88771,startedAt:new Date(Date.now()),createdAt:new Date()};
    await db.collection('ocpp_sessions').insertOne(other);
    const z=await request('POST',bindPath,{sourceSessionId:String(other._id)},
      false,{authorization:adminBearer});
    assert.equal(z.code,409);
    const existing=await ChargingSessionModel.findOne({bookingId:adminBooking._id,
      sourceType:'ocpp'});
    assert.equal(existing.sourceSessionId,String(adminOcppSession._id));
  });
  await check('Admin-linked OCPP session blocks QR rescan on station details',async()=>{
    const z=await request('GET','/api/v1/ChargingStation/'+adminBooking._id,undefined,true);
    assert.equal(z.code,200);
    assert.equal(z.body.data.status,'Completed');
    assert.equal(z.body.data.canScan,false);
    assert.equal(z.body.data.sourceVerified,true);
  });
  await check('Admin sees bound stopped session and actionable finalization',async()=>{
    const z=await request('GET',opPath,undefined,false,{authorization:adminBearer});
    assert.equal(z.code,200);
    assert.equal(z.body.data.sourceVerified,true);
    assert.equal(z.body.data.meterVerified,true);
    assert.equal(z.body.data.sourceEnded,true);
    assert.equal(z.body.data.energyKwh,5);
    assert.equal(z.body.data.canBindSource,false);
    assert.equal(z.body.data.canFinalizeSource,true);
    assert.equal(z.body.data.paymentStatus,'not_collected');
  });
  await check('customer sees meter-backed charging history from Admin-bound transaction',async()=>{
    const z=await request('GET','/api/v1/ChargingSession/'+adminLinkedSession._id+'/status',
      undefined,true);
    assert.equal(z.code,200);
    assert.equal(z.body.data.sourceVerified,true);
    assert.equal(z.body.data.kwhUsed,5);
    assert.equal(z.body.data.paymentStatus,'not_collected');
  });
  await check('customer booking stays Upcoming until source-backed Admin finalization',async()=>{
    const before=await BookingModel.findById(adminBooking._id);
    assert.equal(before.status,'Upcoming');
    const notAllowed=await request('POST',
      '/api/v1/admin/bookings/'+adminBooking._id+'/ocpp/finalize-source',{},true);
    assert.equal(notAllowed.code,401);
  });
  await check('Admin finalizes only a verified stopped OCPP1.6 transaction',async()=>{
    const r=await request('POST',
      '/api/v1/admin/bookings/'+adminBooking._id+'/ocpp/finalize-source',
      {},false,{authorization:adminBearer});
    assert.equal(r.code,200);
    assert.equal(r.body.data.bookingStatus,'Completed');
    assert.equal(r.body.data.paymentStatus,'not_collected');
    assert.equal(r.body.data.calculatedUnpaidAed,5.25);
    assert.equal((await BookingModel.findById(adminBooking._id)).status,'Completed');
  });
  await check('customer receives real source-backed OCPP1.6 charging summary',async()=>{
    const response=await request('GET','/api/v1/ChargingSession/'+adminLinkedSession._id+'/summary',
      undefined,true);
    assert.equal(response.code,200);
    assert.equal(response.body.status,'Success');
    assert.equal(response.body.data.energyDelivered,5);
    assert.equal(response.body.data.subtotalAed,5);
    assert.equal(response.body.data.vatAed,.25);
    assert.equal(response.body.data.totalAmount,5.25);
    assert.equal(response.body.data.currencyCode,'AED');
    assert.equal(response.body.data.billingStatus,'calculated_unpaid');
    assert.equal(response.body.data.paymentStatus,'not_collected');
    assert.equal(response.body.data.canPay,false);
    assert.equal(response.body.data.showReviewPrompt,false);
  });
  await check('blocked extension payment cannot charge an unverified source again',async()=>{
    const result=await request('POST','/api/v1/ChargingSession/'+adminLinkedSession._id+'/pay',
      {paymentMethod:'stripe'},true);
    assert.equal(result.code,400);
  });
  await check('Admin workflow shows completed booking and disables finalization',async()=>{
    const z=await request('GET',opPath,undefined,false,{authorization:adminBearer});
    assert.equal(z.code,200);
    assert.equal(z.body.data.bookingStatus,'Completed');
    assert.equal(z.body.data.canFinalizeSource,false);
    assert.equal(z.body.data.paymentStatus,'not_collected');
  });
  await check('repeated verified finalization cannot create duplicate billing',async()=>{
    const r=await request('POST',
      '/api/v1/admin/bookings/'+adminBooking._id+'/ocpp/finalize-source',
      {},false,{authorization:adminBearer});
    assert.equal(r.code,200);
    assert.equal(r.body.data.reused,true);
    assert.equal(r.body.data.paymentStatus,'not_collected');
  });
  await check('unauthorized user cannot access Admin source billing report',async()=>{
    const z=await request('GET','/api/v1/admin/ocpp/booking-sessions',undefined,true);
    assert.equal(z.code,401);
  });
  await check('Admin reports linked meter and unpaid AED amounts without fake receipts',async()=>{
    const z=await request('GET','/api/v1/admin/ocpp/booking-sessions',undefined,false,
      {authorization:adminBearer});
    assert.equal(z.code,200);
    assert.equal(z.body.status,'Success');
    assert.equal(z.body.data.currency,'AED');
    assert.equal(z.body.data.billingMode,'calculation_only_no_collection');
    assert.equal(z.body.data.totalCollectedAed,0);
    const found=z.body.data.entries.find(x=>x.bookingId===String(adminBooking._id));
    assert.ok(found);
    assert.equal(found.energyKwh,5);
    assert.equal(found.calculatedUnpaidAed,5.25);
    assert.equal(found.paymentStatus,'not_collected');
    assert.equal(JSON.stringify(z.body).includes(issuedToken),false);
  });
  await check('same OCPP source session cannot be linked into a different booking',async()=>{
    const second=await BookingModel.create({userId:user._id,stationId:adminStation._id,
      date:'2026-11-12',time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
      totalAmount:63,status:'Upcoming',isPaid:true,hasArrived:true,
      ocppTokenHash:createHash('sha256').update(issuedToken).digest('hex'),
      ocppTokenIssuedAt:new Date(Date.now()-1000)});
    const z=await request('POST','/api/v1/admin/bookings/'+second._id+'/ocpp/bind-source',
      {sourceSessionId:String(adminOcppSession._id)},false,{authorization:adminBearer});
    assert.notEqual(z.code,200);
  });
  const station20=await NearestStationModel.create({name:'Agent 2 OCPP201 Admin-Bound Station',
    address:'Loopback Only',status:'Available',latitude:25.75,longitude:55.94,
    pricePerHourValue:75,slots:[{startTime:'04:00 PM',endTime:'05:00 PM'}]});
  const adminCharger20='agent2-admin-bound-ocpp201';
  await db.collection('ocpp_chargers').insertOne({stationId:String(station20._id),
    chargerId:adminCharger20,enabled:true,protocol:'ocpp2.0.1',controlApproved:false});
  await db.collection('ocpp_tariffs').insertOne({chargerId:adminCharger20,enabled:true,
    rateFilsPerKwh:90,vatPercent:5});
  const booking20=await BookingModel.create({userId:user._id,stationId:station20._id,
    date:'2026-11-14',time:'04:00 PM',slotStart:'04:00 PM',slotEnd:'05:00 PM',
    totalAmount:77,isPaid:true,hasArrived:true,status:'Upcoming'});
  let token20;
  await check('Admin issues OCPP 2.0.1 booking-scoped authorization token',async()=>{
    const r=await request('POST','/api/v1/admin/bookings/'+booking20._id+'/ocpp/issue-token',{},false,
      {authorization:adminBearer});
    assert.equal(r.code,201);
    token20=r.body.data.oneTimeToken;
    assert.match(token20,/^[0-9A-F]{20}$/);
    assert.equal(r.body.data.chargerId,adminCharger20);
  });
  const tx20='agent2-admin-ocpp201-source';
  let bound20SessionId;
  await check('OCPP 2.0.1 accepts authorized Admin booking token and metered stop',async()=>{
    const start=await handle({chargerId:adminCharger20},'ocpp2.0.1','TransactionEvent',{
      eventType:'Started',seqNo:0,transactionInfo:{transactionId:tx20},
      timestamp:new Date().toISOString(),idToken:{idToken:token20},
      evse:{id:1,connectorId:1},meterValue:meter(1000)});
    assert.equal(start.idTokenInfo.status,'Accepted');
    const liveSource=await db.collection('ocpp_sessions').findOne({
      chargerId:adminCharger20,transactionId:tx20});
    const bound=await request('POST','/api/v1/admin/bookings/'+booking20._id+'/ocpp/bind-source',
      {sourceSessionId:String(liveSource._id)},false,{authorization:adminBearer});
    assert.equal(bound.code,200);
    bound20SessionId=bound.body.data.sessionId;
    const zeroMeter=await request('GET','/api/v1/ChargingSession/'+bound20SessionId+'/status',
      undefined,true);
    assert.equal(zeroMeter.code,200);
    assert.equal(zeroMeter.body.data.chargingStatus,'Charging');
    assert.equal(zeroMeter.body.data.kwhUsed,0);
    const stationScreen=await request('GET',
      '/api/v1/ChargingStation/'+booking20._id,undefined,true);
    assert.equal(stationScreen.code,200);
    assert.equal(stationScreen.body.data.status,'Charging');
    assert.equal(stationScreen.body.data.canScan,false);
    assert.equal(stationScreen.body.data.energyKwh,0);

    const summaryActive=await request('GET',
      '/api/v1/ChargingSession/'+bound20SessionId+'/summary',undefined,true);
    assert.equal(summaryActive.code,400);
    const premature=await request('POST',
      '/api/v1/admin/bookings/'+booking20._id+'/ocpp/finalize-source',
      {},false,{authorization:adminBearer});
    assert.equal(premature.code,409);
    assert.equal((await BookingModel.findById(booking20._id)).status,'Upcoming');
    await handle({chargerId:adminCharger20},'ocpp2.0.1','TransactionEvent',{
      eventType:'Updated',seqNo:1,transactionInfo:{transactionId:tx20},
      timestamp:new Date().toISOString(),meterValue:meter(6500)});
    const during=await request('GET','/api/v1/ChargingSession/'+bound20SessionId+'/status',
      undefined,true);
    assert.equal(during.code,200);
    assert.equal(during.body.data.chargingStatus,'Charging');
    assert.equal(during.body.data.kwhUsed,5.5);
    await handle({chargerId:adminCharger20},'ocpp2.0.1','TransactionEvent',{
      eventType:'Ended',seqNo:2,transactionInfo:{transactionId:tx20,stoppedReason:'EVDisconnected'},
      timestamp:new Date().toISOString(),meterValue:meter(9000)});
    const source=await db.collection('ocpp_sessions').findOne({
      chargerId:adminCharger20,transactionId:tx20});
    assert.equal(source.energyKwh,8);
    assert.equal(source.totalFils,756);
    assert.equal(source.paymentStatus,'not_collected');
  });
  await check('Admin binds 2.0.1 source to correct customer and customer reads 8 kWh',async()=>{
    const source=await db.collection('ocpp_sessions').findOne({
      chargerId:adminCharger20,transactionId:tx20});
    const binding=await request('POST','/api/v1/admin/bookings/'+booking20._id+'/ocpp/bind-source',
      {sourceSessionId:String(source._id)},false,{authorization:adminBearer});
    assert.equal(binding.code,200);
    assert.equal(binding.body.data.meterVerified,true);
    assert.equal(binding.body.data.sessionId,bound20SessionId);
    assert.equal(await ChargingSessionModel.countDocuments({sourceSessionId:String(source._id)}),1);
    const status=await request('GET','/api/v1/ChargingSession/'+binding.body.data.sessionId+'/status',
      undefined,true);
    assert.equal(status.code,200);
    assert.equal(status.body.data.sourceVerified,true);
    assert.equal(status.body.data.protocol,'ocpp2.0.1');
    assert.equal(status.body.data.kwhUsed,8);
    assert.equal(status.body.data.paymentStatus,'not_collected');
    const completed=await request('POST',
      '/api/v1/admin/bookings/'+booking20._id+'/ocpp/finalize-source',
      {},false,{authorization:adminBearer});
    assert.equal(completed.code,200);
    assert.equal(completed.body.data.bookingStatus,'Completed');
    assert.equal(completed.body.data.calculatedUnpaidAed,7.56);
    assert.equal((await BookingModel.findById(booking20._id)).status,'Completed');
    const summary=await request('GET',
      '/api/v1/ChargingSession/'+bound20SessionId+'/summary',undefined,true);
    assert.equal(summary.code,200);
    assert.equal(summary.body.data.energyDelivered,8);
    assert.equal(summary.body.data.costPerKwh,.90);
    assert.equal(summary.body.data.totalAmount,7.56);
    assert.equal(summary.body.data.paymentStatus,'not_collected');
    assert.equal(summary.body.data.needsPayment,false);
  });
  const {migrateChargerStationIndex,planChargerStationIndexMigration,
    planChargerStationRollback,rollbackChargerStationIndex}=await import(
    pathToFileURL(join(source,'src/utils/migrateChargerStationIndex.mjs')).href);
  const multiStation=await NearestStationModel.create({
    name:'Agent 2 Two Charger Station',address:'Isolated OCPP Test Station',
    status:'Available',latitude:25.76,longitude:55.96,pricePerHourValue:70,
    slots:[{startTime:'10:00 AM',endTime:'11:00 AM'}]});
  const multiPrimary='agent2-multi-primary',multiSecond='agent2-multi-secondary';
  const chargerTable=db.collection('ocpp_chargers');
  await chargerTable.createIndex({chargerId:1},{unique:true,name:'chargerId_1'});
  await chargerTable.createIndex({stationId:1},{unique:true,
    partialFilterExpression:{stationId:{$type:'string'}},name:'stationId_1'});
  await chargerTable.insertOne({stationId:String(multiStation._id),
    chargerId:multiPrimary,enabled:true,protocol:'ocpp1.6',
    online:false,controlApproved:false});
  const registration='/api/v1/admin/stations/'+multiStation._id+'/ocpp/chargers';
  await check('multi-charger registration requires explicit database index migration',async()=>{
    const response=await request('POST',registration,{
      chargerId:multiSecond,protocol:'ocpp1.6'},false,{authorization:adminBearer});
    assert.equal(response.code,409);
    assert.equal(await chargerTable.countDocuments({stationId:String(multiStation._id)}),1);
  });
  await check('read-only index preflight reports legacy station uniqueness',async()=>{
    const before=await planChargerStationIndexMigration(chargerTable);
    assert.equal(before.hasLegacyUniqueStationIndex,true);
    assert.equal(before.hasGlobalUniqueChargerIndex,true);
    assert.equal(before.blocked,false);
    await assert.rejects(()=>migrateChargerStationIndex(chargerTable),/approval required/);
    const still=await chargerTable.indexes();
    assert.ok(still.some(x=>x.unique===true && x.key?.stationId===1));
  });
  await check('staging index migration retains globally unique charger IDs',async()=>{
    const result=await migrateChargerStationIndex(chargerTable,{
      approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'
    });
    assert.equal(result.uniqueChargerIdIndexVerified,true);
    assert.equal(result.removedUniqueStationIndex,'stationId_1');
    const indexes=await chargerTable.indexes();
    assert.equal(indexes.some(x=>x.unique===true && x.key.stationId===1),false);
    assert.equal(indexes.some(x=>x.unique===true && x.key.chargerId===1),true);
  });
  await check('staging rollback can restore unique station index before extra charger exists',async()=>{
    const plan=await planChargerStationRollback(chargerTable);
    assert.equal(plan.blocked,false);
    await assert.rejects(()=>rollbackChargerStationIndex(chargerTable),/approval required/);
    const done=await rollbackChargerStationIndex(chargerTable,{
      approval:'ROLLBACK_DISPOSABLE_AGENT2_DB'
    });
    assert.equal(done.restoredUniqueStationIndex,true);
    assert.ok((await chargerTable.indexes()).some(x=>x.unique===true && x.key?.stationId===1));
    await migrateChargerStationIndex(chargerTable,{approval:'APPLY_TO_DISPOSABLE_AGENT2_DB'});
  });
  await check('customer without AdminAuth cannot register additional charger',async()=>{
    const response=await request('POST',registration,{chargerId:multiSecond,protocol:'ocpp1.6'},true);
    assert.equal(response.code,401);
  });
  await check('Admin registers second station charger without enabling remote control',async()=>{
    const response=await request('POST',registration,
      {chargerId:multiSecond,protocol:'ocpp1.6'},false,{authorization:adminBearer});
    assert.equal(response.code,201);
    assert.equal(response.body.data.chargerId,multiSecond);
    assert.equal(response.body.data.controlApproved,false);
    assert.equal(response.body.data.url,null);
    assert.equal(response.body.data.gatewayUrlConfigured,false);
    assert.ok(/^[a-f0-9]{40}$/.test(response.body.data.password));
    assert.equal(await chargerTable.countDocuments({stationId:String(multiStation._id)}),2);
    const rollbackPlan=await planChargerStationRollback(chargerTable);
    assert.equal(rollbackPlan.blocked,true);
    assert.ok(rollbackPlan.reasons.includes('multiple_chargers_share_station'));
    await assert.rejects(()=>rollbackChargerStationIndex(chargerTable,{
      approval:'ROLLBACK_DISPOSABLE_AGENT2_DB'
    }),/multiple_chargers_share_station/);
  });
  await check('configured WSS gateway base yields only an explicitly approved charger URL',async()=>{
    const secondStation=await NearestStationModel.create({name:'Agent 2 Explicit WSS Station',
      address:'Test Only',status:'Available',latitude:25.77,longitude:55.96,
      pricePerHourValue:40,slots:[{startTime:'08:00 AM',endTime:'09:00 AM'}]});
    process.env.OCPP_PUBLIC_WS_BASE='wss://configured-test.invalid/ocpp';
    try{
      const response=await request('POST','/api/v1/admin/stations/'+secondStation._id+'/ocpp/chargers',
        {chargerId:'agent2-approved-configured-wss',protocol:'ocpp1.6'},
        false,{authorization:adminBearer});
      assert.equal(response.code,201);
      assert.equal(response.body.data.url,
        'wss://configured-test.invalid/ocpp/agent2-approved-configured-wss');
      assert.equal(response.body.data.gatewayUrlConfigured,true);
    }finally{process.env.OCPP_PUBLIC_WS_BASE='';}
  });
  await check('duplicate global charger ID is rejected across stations',async()=>{
    const response=await request('POST',registration,
      {chargerId:multiSecond,protocol:'ocpp1.6'},false,{authorization:adminBearer});
    assert.equal(response.code,409);
  });
  await check('Admin sees both chargers without recovering passwords or hashes',async()=>{
    const response=await request('GET',registration,undefined,false,{authorization:adminBearer});
    assert.equal(response.code,200);
    assert.deepEqual(response.body.data.chargers.map(x=>x.chargerId).sort(),
      [multiPrimary,multiSecond].sort());
    assert.equal(JSON.stringify(response.body).includes('secretHash'),false);
    assert.equal(JSON.stringify(response.body).includes('password'),false);
  });
  await db.collection('ocpp_tariffs').insertMany([
    {chargerId:multiPrimary,enabled:true,rateFilsPerKwh:60,vatPercent:5},
    {chargerId:multiSecond,enabled:true,rateFilsPerKwh:100,vatPercent:5}
  ]);
  const multiBooking=await BookingModel.create({
    userId:user._id,stationId:multiStation._id,date:'2026-11-19',
    time:'10:00 AM',slotStart:'10:00 AM',slotEnd:'11:00 AM',
    status:'Upcoming',isPaid:true,hasArrived:true,paymentStatus:'paid',totalAmount:70
  });
  const multiIssue='/api/v1/admin/bookings/'+multiBooking._id+'/ocpp/issue-token';
  const multiOps='/api/v1/admin/bookings/'+multiBooking._id+'/ocpp/operations';
  await check('multi-charger booking cannot auto-assign first station charger',async()=>{
    const ready=await request('GET',multiOps,undefined,false,{authorization:adminBearer});
    assert.equal(ready.code,200);
    assert.equal(ready.body.data.requiresChargerSelection,true);
    assert.equal(ready.body.data.approvedChargers.length,2);
    assert.equal(ready.body.data.approvedChargerId,null);
    const noChoice=await request('POST',multiIssue,{},false,{authorization:adminBearer});
    assert.equal(noChoice.code,409);
    const wrong=await request('POST',multiIssue,{chargerId:'other-station-charger'},
      false,{authorization:adminBearer});
    assert.equal(wrong.code,409);
  });
  let multiToken;
  await check('Admin explicitly pins booking to second station charger',async()=>{
    const response=await request('POST',multiIssue,{chargerId:multiSecond},
      false,{authorization:adminBearer});
    assert.equal(response.code,201);
    assert.equal(response.body.data.chargerId,multiSecond);
    multiToken=response.body.data.oneTimeToken;
    assert.match(multiToken,/^[0-9A-F]{20}$/);
    const saved=await BookingModel.findById(multiBooking._id);
    assert.equal(saved.ocppChargerId,multiSecond);
    const ready=await request('GET',multiOps,undefined,false,{authorization:adminBearer});
    assert.equal(ready.body.data.selectedChargerId,multiSecond);
    assert.equal(ready.body.data.canBindSource,true);
    assert.equal(JSON.stringify(ready.body).includes(multiToken),false);
  });
  await check('pinned charger authorization cannot start on the other charger',async()=>{
    const denied=await handle({chargerId:multiPrimary},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:multiToken,timestamp:new Date().toISOString()});
    assert.equal(denied.idTagInfo.status,'Invalid');
    const valid=await handle({chargerId:multiSecond},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:multiToken,timestamp:new Date().toISOString()});
    assert.equal(valid.idTagInfo.status,'Accepted');
    await handle({chargerId:multiSecond},'ocpp1.6','StopTransaction',{
      transactionId:valid.transactionId,meterStop:8000,
      timestamp:new Date().toISOString(),reason:'EVDisconnected'});
  });
  await check('unrelated valid first-charger transaction cannot be bound to second-charger booking',async()=>{
    const otherToken='0123456789ABCDEF0456';
    await db.collection('ocpp_access_tokens').insertOne({
      chargerId:multiPrimary,tokenHash:createHash('sha256').update(otherToken).digest('hex'),
      enabled:true,expiresAt:new Date(Date.now()+3600000)
    });
    const started=await handle({chargerId:multiPrimary},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:2000,idTag:otherToken,timestamp:new Date().toISOString()});
    assert.equal(started.idTagInfo.status,'Accepted');
    await handle({chargerId:multiPrimary},'ocpp1.6','StopTransaction',{
      transactionId:started.transactionId,meterStop:5000,
      timestamp:new Date().toISOString(),reason:'EVDisconnected'});
    const wrongSource=await db.collection('ocpp_sessions').findOne({
      chargerId:multiPrimary,transactionId:started.transactionId});
    const denied=await request('POST',
      '/api/v1/admin/bookings/'+multiBooking._id+'/ocpp/bind-source',
      {sourceSessionId:String(wrongSource._id)},false,{authorization:adminBearer});
    assert.notEqual(denied.code,200);
    assert.equal(await ChargingSessionModel.countDocuments({bookingId:multiBooking._id,sourceType:'ocpp'}),0);
  });
  await check('pinned booking cannot request another charger authorization',async()=>{
    const response=await request('POST',multiIssue,{chargerId:multiPrimary},
      false,{authorization:adminBearer});
    assert.equal(response.code,409);
    const saved=await BookingModel.findById(multiBooking._id);
    assert.equal(saved.ocppChargerId,multiSecond);
  });
  await check('station source picker includes both chargers but not private tokens',async()=>{
    const response=await request('GET',
      '/api/v1/admin/stations/'+multiStation._id+'/ocpp/booking-sources',
      undefined,false,{authorization:adminBearer});
    assert.equal(response.code,200);
    assert.equal(response.body.data.sessions.some(x=>x.chargerId===multiSecond),true);
    assert.equal(response.body.data.sessions.some(x=>x.chargerId===multiPrimary),true);
    assert.equal(JSON.stringify(response.body).includes('idTagHash'),false);
  });
  await check('booking binds only to its pinned physical charger source',async()=>{
    const src=await db.collection('ocpp_sessions').findOne({chargerId:multiSecond,
      idTagHash:createHash('sha256').update(multiToken).digest('hex')});
    const response=await request('POST',
      '/api/v1/admin/bookings/'+multiBooking._id+'/ocpp/bind-source',
      {sourceSessionId:String(src._id)},false,{authorization:adminBearer});
    assert.equal(response.code,200);
    const customer=await request('GET','/api/v1/ChargingSession/'+response.body.data.sessionId+'/status',
      undefined,true);
    assert.equal(customer.code,200);
    assert.equal(customer.body.data.sourceVerified,true);
    assert.equal(customer.body.data.kwhUsed,7);
    assert.equal(customer.body.data.paymentStatus,'not_collected');
  });
  await check('customer cannot read station OCPP start exception report',async()=>{
    const path='/api/v1/admin/stations/'+multiStation._id+'/ocpp/start-exceptions';
    const response=await request('GET',path,undefined,true);
    assert.equal(response.code,401);
  });
  await check('Admin start audit ignores consumed tokens with existing OCPP source',async()=>{
    const tokenHash=createHash('sha256').update(multiToken).digest('hex');
    const used=await db.collection('ocpp_access_tokens').findOne({
      chargerId:multiSecond,tokenHash});
    assert.equal(used.enabled,false);
    assert.ok(used.consumedAt instanceof Date);
    const path='/api/v1/admin/stations/'+multiStation._id+'/ocpp/start-exceptions';
    const response=await request('GET',path,undefined,false,
      {authorization:adminBearer});
    assert.equal(response.code,200);
    assert.equal(response.body.data.readOnly,true);
    assert.ok(response.body.data.matchedSourceCount>=1);
    assert.equal(JSON.stringify(response.body).includes(tokenHash),false);
    assert.equal(JSON.stringify(response.body).includes(multiToken),false);
  });
  await check('Admin flags orphaned consumed credential without re-enabling it',async()=>{
    const orphanHash=createHash('sha256').update('agent2-intentionally-orphaned-credential').digest('hex');
    await db.collection('ocpp_access_tokens').insertOne({
      chargerId:multiPrimary,tokenHash:orphanHash,enabled:false,
      consumedAt:new Date(Date.now()-180000),bookingId:String(multiBooking._id)
    });
    const path='/api/v1/admin/stations/'+multiStation._id+'/ocpp/start-exceptions';
    const response=await request('GET',path,undefined,false,
      {authorization:adminBearer});
    assert.equal(response.code,200);
    assert.ok(response.body.data.orphanCount>=1);
    assert.ok(response.body.data.exceptions.some(x=>
      x.chargerId===multiPrimary && x.credentialRestored===false));
    assert.equal(JSON.stringify(response.body).includes(orphanHash),false);
    const record=await db.collection('ocpp_access_tokens').findOne({tokenHash:orphanHash});
    assert.equal(record.enabled,false);
  });
  await check('start audit rejects malformed pagination without broadening scope',async()=>{
    const path='/api/v1/admin/stations/'+multiStation._id+'/ocpp/start-exceptions';
    for(const query of ['?cursor=bad','?cursor[$ne]=x','?limit=0','?limit=101','?limit=2.5']){
      const r=await request('GET',path+query,undefined,false,{authorization:adminBearer});
      assert.equal(r.code,400);
    }
  });
  await check('paginated station audit preserves disabled-charger incidents and redacts credentials',async()=>{
    const table=db.collection('ocpp_access_tokens');
    const consumedAt=new Date(Date.now()-600000);
    const hashes=[];
    for(let i=0;i<5;i++){
      const tokenHash=createHash('sha256').update('audit-history-'+i).digest('hex');
      hashes.push(tokenHash);
      await table.insertOne({chargerId:multiPrimary,tokenHash,enabled:false,
        consumedAt,bookingId:'audit-history-'+i});
    }
    // Disabled does not mean the previous consumed credential is resolved.
    await db.collection('ocpp_chargers').updateOne({chargerId:multiPrimary},
      {$set:{enabled:false}});
    const foreignHash=createHash('sha256').update('foreign-audit-incident').digest('hex');
    await table.insertOne({chargerId:'not-in-this-station',tokenHash:foreignHash,
      enabled:false,consumedAt,bookingId:'foreign-audit-incident'});
    const path='/api/v1/admin/stations/'+multiStation._id+'/ocpp/start-exceptions';
    const incidents=[];let cursor=null,pages=0;
    try{
      do{
        const r=await request('GET',path+'?limit=2'+(cursor?'&cursor='+cursor:''),
          undefined,false,{authorization:adminBearer});
        assert.equal(r.code,200);assert.equal(r.body.data.readOnly,true);
        const page=r.body.data;
        assert.ok(page.scanned<=2);
        assert.equal(typeof page.hasMore,'boolean');
        for(const hash of [...hashes,foreignHash])assert.equal(JSON.stringify(r.body).includes(hash),false);
        assert.ok(page.exceptions.every(x=>x.chargerId!=='not-in-this-station'));
        incidents.push(...page.exceptions.filter(x=>x.bookingId?.startsWith('audit-history-')));
        pages++;
        if(!page.hasMore){assert.equal(page.nextCursor,null);break;}
        assert.match(page.nextCursor,/^[a-f0-9]{24}$/);
        assert.notEqual(page.nextCursor,cursor);
        cursor=page.nextCursor;
        assert.ok(pages<20,'pagination must terminate');
      }while(true);
      assert.equal(incidents.length,5);
      assert.equal(new Set(incidents.map(x=>x.bookingId)).size,5);
      assert.ok(pages>=3);
      assert.equal(await table.countDocuments({tokenHash:{$in:hashes},enabled:false}),5);
      assert.equal((await db.collection('ocpp_chargers').findOne({chargerId:multiPrimary})).enabled,false);
    }finally{
      await db.collection('ocpp_chargers').updateOne({chargerId:multiPrimary},
        {$set:{enabled:true}});
    }
  });
  const historyLink=await ChargingSessionModel.findOne({bookingId:multiBooking._id}).lean();
  const historyPath='/api/v1/ChargingSession/history';
  const historyDetail=historyPath+'/'+historyLink._id;
  await check('source history routes require their customer and Admin roles',async()=>{
    assert.equal((await request('GET',historyPath,undefined,false)).code,401);
    assert.equal((await request('GET','/api/v1/admin/ocpp/charging-history',undefined,true)).code,401);
    const allowed=await request('GET','/api/v1/admin/ocpp/charging-history',undefined,false,
      {authorization:adminBearer});
    assert.equal(allowed.code,200);
  });
  const historyForeign=await UserModel.create({fullName:'History Foreign Test',
    email:'history-foreign@example.invalid',isVerified:true,password:'disabled'});
  const foreignBearer='Bearer '+EncodeToken(historyForeign.email,String(historyForeign._id));
  await check('customer source detail enforces ownership and returns redacted unpaid proof',async()=>{
    const denied=await request('GET',historyDetail,undefined,false,{authorization:foreignBearer});
    assert.equal(denied.code,404);
    const allowed=await request('GET',historyDetail,undefined,true);
    assert.equal(allowed.code,200);
    const row=allowed.body.data;
    assert.equal(row.state,'ended');assert.equal(row.energyKwh,7);
    assert.equal(row.paymentStatus,'not_collected');assert.equal(row.readOnly,true);
    assert.ok(allowed.body.data.durationSeconds>=0);
    assert.equal(/ocppTokenHash|idTagHash|idTokenHash|oneTimeToken|secretHash/.test(
      JSON.stringify(allowed.body)),false);
  });
  await check('history seek pages contain only owned links and never repeat rows',async()=>{
    const foreignBooking=await BookingModel.create({
      userId:historyForeign._id,stationId:multiStation._id,date:'2027-03-01',
      time:'03:00 PM',status:'Upcoming',isPaid:true,hasArrived:true,
      ocppChargerId:multiSecond,ocppTokenHash:'f'.repeat(64)
    });
    const foreignLink=await ChargingSessionModel.create({
      userId:historyForeign._id,bookingId:foreignBooking._id,stationId:multiStation._id,
      sourceType:'ocpp',sourceSessionId:String(new mongoose.Types.ObjectId()),
      sourceChargerId:multiSecond,sourceTransactionId:'foreign-unverified'
    });
    let cursor=null,pages=0;const ids=[];
    do{
      const result=await request('GET',historyPath+'?limit=2'+(cursor?'&cursor='+cursor:''),
        undefined,true);
      assert.equal(result.code,200);
      const page=result.body.data;
      assert.ok(page.scanned<=2);assert.equal(page.readOnly,true);
      for(const row of page.entries){
        assert.notEqual(row.sessionId,String(foreignLink._id));
        assert.equal(String((await ChargingSessionModel.findById(row.sessionId)).userId),String(user._id));
        ids.push(row.sessionId);
      }
      pages++;
      if(!page.hasMore)break;
      assert.match(page.nextCursor,/^[a-f0-9]{24}$/);
      assert.notEqual(page.nextCursor,cursor);cursor=page.nextCursor;
      assert.ok(pages<50);
    }while(true);
    assert.ok(ids.includes(String(historyLink._id)));
    assert.equal(new Set(ids).size,ids.length);
  });
  await check('disabled charger preserves historical source evidence without re-enabling it',async()=>{
    await db.collection('ocpp_chargers').updateOne({chargerId:multiSecond},{$set:{enabled:false}});
    try{
      const response=await request('GET',historyDetail,undefined,true);
      assert.equal(response.code,200);assert.equal(response.body.data.state,'ended');
      assert.equal(response.body.data.energyKwh,7);
      assert.equal(response.body.data.chargerEnabled,false);
      assert.equal((await db.collection('ocpp_chargers').findOne({chargerId:multiSecond})).enabled,false);
    }finally{
      await db.collection('ocpp_chargers').updateOne({chargerId:multiSecond},{$set:{enabled:true}});
    }
  });
  await check('history recomputes proof and removes telemetry after source tampering',async()=>{
    const sourceId=new mongoose.Types.ObjectId(historyLink.sourceSessionId);
    const original=await db.collection('ocpp_sessions').findOne({_id:sourceId});
    await db.collection('ocpp_sessions').updateOne({_id:sourceId},{$set:{energyWh:999999}});
    try{
      const response=await request('GET',historyDetail,undefined,true);
      assert.equal(response.code,200);assert.equal(response.body.data.state,'unverified');
      assert.equal(response.body.data.energyKwh,null);
      assert.equal(response.body.data.totalFils,null);
      assert.equal(response.body.data.transactionId,null);
    }finally{
      await db.collection('ocpp_sessions').updateOne({_id:sourceId},{$set:{energyWh:original.energyWh}});
    }
  });
  await check('history rejects malformed cursor, oversized pages and owner override queries',async()=>{
    for(const suffix of ['?cursor=bad','?limit=101','?limit=0','?userId='+historyForeign._id]){
      assert.equal((await request('GET',historyPath+suffix,undefined,true)).code,400);
    }
    assert.equal((await request('GET',historyPath+'/bad',undefined,true)).code,400);
  });
  for(const protocol of ['ocpp1.6','ocpp2.0.1']){
    const scopedCharger='resource-scope-'+protocol.replaceAll('.','-');
    const selectedScope={protocol,connectorId:2,evseId:protocol==='ocpp2.0.1'?3:null};
    const scopedStation=await NearestStationModel.create({name:'Scoped Test Only '+protocol,
      address:'Loopback',status:'Available',latitude:25.7,longitude:55.9});
    await db.collection('ocpp_chargers').insertOne({stationId:String(scopedStation._id),
      chargerId:scopedCharger,protocol,enabled:true});
    await db.collection('ocpp_tariffs').insertOne({chargerId:scopedCharger,enabled:true,
      rateFilsPerKwh:100,vatPercent:5});
    const scopedBooking=await BookingModel.create({userId:user._id,stationId:scopedStation._id,
      status:'Upcoming',isPaid:true,hasArrived:true,date:'scope-test',time:'11:00 AM'});
    const scopedPath='/api/v1/admin/bookings/'+scopedBooking._id+'/ocpp/';
    let credential,transactionId,scopedSource,scopedSession;
    await check(protocol+' pins requested connector scope in booking and credential',async()=>{
      const reply=await request('POST',scopedPath+'issue-token',{
        chargerId:scopedCharger,resourceScope:selectedScope},false,{authorization:adminBearer});
      assert.equal(reply.code,201);credential=reply.body.data.oneTimeToken;
      assert.equal(reply.body.data.connectorScoped,true);
      assert.deepEqual(reply.body.data.resourceScope,selectedScope);
      assert.deepEqual((await BookingModel.findById(scopedBooking._id)).ocppResourceScope,selectedScope);
      const token=await db.collection('ocpp_access_tokens').findOne({bookingId:String(scopedBooking._id)});
      assert.deepEqual(token.resourceScope,selectedScope);assert.equal(token.enabled,true);
    });
    await check(protocol+' reissue cannot redirect scope and retains it on safe renewal',async()=>{
      const expired=new Date(Date.now()-25*60000);
      await BookingModel.updateOne({_id:scopedBooking._id},{$set:{ocppTokenIssuedAt:expired}});
      await db.collection('ocpp_access_tokens').updateMany({bookingId:String(scopedBooking._id)},
        {$set:{expiresAt:expired}});
      const denied=await request('POST',scopedPath+'reissue-token',{
        resourceScope:{...selectedScope,connectorId:3}},false,{authorization:adminBearer});
      assert.equal(denied.code,409);
      const renewed=await request('POST',scopedPath+'reissue-token',{},false,{authorization:adminBearer});
      assert.equal(renewed.code,201);assert.deepEqual(renewed.body.data.resourceScope,selectedScope);
      assert.notEqual(renewed.body.data.oneTimeToken,credential);credential=renewed.body.data.oneTimeToken;
    });
    const start=scope=>protocol==='ocpp1.6'
      ?handle({chargerId:scopedCharger},protocol,'StartTransaction',{
        idTag:credential,connectorId:scope.connectorId,meterStart:0,timestamp:new Date().toISOString()})
      :handle({chargerId:scopedCharger},protocol,'TransactionEvent',{
        eventType:'Started',seqNo:0,transactionInfo:{transactionId:'scope-transaction'},
        idToken:{idToken:credential,type:'Central'},timestamp:new Date().toISOString(),
        evse:{id:scope.evseId,connectorId:scope.connectorId},meterValue:meter(0)});
    await check(protocol+' wrong connector or EVSE cannot consume the scoped credential',async()=>{
      const denied=await start({...selectedScope,connectorId:3});
      assert.equal((denied.idTagInfo||denied.idTokenInfo).status,'Invalid');
      if(protocol==='ocpp2.0.1'){
        assert.equal((await start({...selectedScope,evseId:4})).idTokenInfo.status,'Invalid');
        assert.equal((await start({...selectedScope,evseId:null})).idTokenInfo.status,'Invalid');
      }
      const digest=createHash('sha256').update(credential).digest('hex');
      const stored=await db.collection('ocpp_access_tokens').findOne({chargerId:scopedCharger,tokenHash:digest});
      assert.equal(stored.enabled,true);assert.equal(stored.consumedAt,undefined);
      assert.equal(await db.collection('ocpp_sessions').countDocuments({chargerId:scopedCharger}),0);
    });
    let progressSeq=0;
    const feed=async samples=>protocol==='ocpp1.6'
      ?handle({chargerId:scopedCharger},protocol,'MeterValues',{transactionId,connectorId:2,meterValue:samples})
      :handle({chargerId:scopedCharger},protocol,'TransactionEvent',{eventType:'Updated',seqNo:++progressSeq,
        transactionInfo:{transactionId},evse:{id:3,connectorId:2},meterValue:samples});
    await check(protocol+' exact scoped resource starts and rejects conflicting meter identity',async()=>{
      const accepted=await start(selectedScope);
      assert.equal((accepted.idTagInfo||accepted.idTokenInfo).status,'Accepted');
      scopedSource=await db.collection('ocpp_sessions').findOne({chargerId:scopedCharger});
      transactionId=scopedSource.transactionId;assert.equal(scopedSource.connectorId,2);
      if(protocol==='ocpp1.6'){
        await assert.rejects(()=>handle({chargerId:scopedCharger},protocol,'MeterValues',{
          transactionId,connectorId:3,meterValue:meter(9000)}),/Connector/);
      }else{
        await assert.rejects(()=>handle({chargerId:scopedCharger},protocol,'TransactionEvent',{
          eventType:'Updated',seqNo:1,transactionInfo:{transactionId},
          evse:{id:4,connectorId:2},meterValue:meter(9000)}),/EVSE/);
      }
    });
    await check(protocol+' accepted receipts reject coerced meters and expose provisional unpaid progress',async()=>{
      const olderReceipt=new Date(Date.now()-30000);
      await db.collection('ocpp_sessions').updateOne({_id:scopedSource._id},{$set:{
        startedAt:new Date(Date.now()-60000),lastMeterReceivedAt:olderReceipt}});
      for(const value of [null,false,true,' ',{},[],'0x100']){
        await feed([{sampledValue:[{value,unit:'Wh',unitOfMeasure:{unit:'Wh'}}]}]);
        const unchanged=await db.collection('ocpp_sessions').findOne({_id:scopedSource._id});
        assert.equal(unchanged.energyWh,0);assert.equal(unchanged.lastMeterReceivedAt.valueOf(),olderReceipt.valueOf());
      }
      await feed([{sampledValue:[{value:'1000000000000',unit:'kWh',unitOfMeasure:{unit:'kWh'}}]}]);
      assert.equal((await db.collection('ocpp_sessions').findOne({_id:scopedSource._id})).energyWh,0);
      await feed(meter(3000));
      const linked=await request('POST',scopedPath+'bind-source',{
        sourceSessionId:String(scopedSource._id)},false,{authorization:adminBearer});
      assert.equal(linked.code,200);scopedSession=linked.body.data.sessionId;
      const status=await request('GET','/api/v1/ChargingSession/'+scopedSession+'/status',undefined,true);
      assert.equal(status.code,200);assert.equal(status.body.data.provisionalCostVerified,true);
      assert.equal(status.body.data.provisionalTotalFils,315);assert.equal(status.body.data.totalFils,null);
      assert.equal(status.body.data.paymentStatus,'not_collected');assert.equal(status.body.data.readOnly,true);
      assert.equal(status.body.data.meterFreshness,'recent');assert.ok(status.body.data.meterReceivedAt);
      const stable=await db.collection('ocpp_sessions').findOne({_id:scopedSource._id});
      await feed([]);
      const noMeter=await db.collection('ocpp_sessions').findOne({_id:scopedSource._id});
      assert.equal(noMeter.lastMeterReceivedAt.valueOf(),stable.lastMeterReceivedAt.valueOf());
      if(process.env.AGENT2_HISTORY_FLUTTER){
        const env={...process.env,AGENT2_PROGRESS_API_URL:base,
          AGENT2_PROGRESS_TOKEN:bearer,AGENT2_ACTIVE_SESSION:scopedSession};
        delete env.AGENT2_HISTORY_API_URL;delete env.AGENT2_NEW_BOOKING_STATION;
        const child=spawn(process.env.AGENT2_HISTORY_FLUTTER,
          ['test','--no-pub','test/source_history_api_test.dart','--reporter','expanded'],{
            cwd:process.env.AGENT2_HISTORY_APP,stdio:'inherit',env,timeout:180000});
        const code=await new Promise((done,reject)=>{child.once('error',reject);child.once('exit',done);});
        assert.equal(code,0,'Actual native active-source progress failed');
      }
    });
    await check(protocol+' source binding and customer history qualify the selected connector',async()=>{
      if(protocol==='ocpp1.6'){
        await handle({chargerId:scopedCharger},protocol,'StopTransaction',{
          transactionId,meterStop:1000000000001,timestamp:new Date().toISOString()});
        assert.equal((await db.collection('ocpp_sessions').findOne({_id:scopedSource._id})).status,'active');
        await handle({chargerId:scopedCharger},protocol,'StopTransaction',{
          transactionId,meterStop:5000,timestamp:new Date().toISOString()});
      }else{
        await handle({chargerId:scopedCharger},protocol,'TransactionEvent',{
          eventType:'Ended',seqNo:++progressSeq,transactionInfo:{transactionId},
          evse:{id:3,connectorId:2},timestamp:new Date().toISOString(),meterValue:meter(5000)});
      }
      scopedSource=await db.collection('ocpp_sessions').findOne({_id:scopedSource._id});
      assert.equal(scopedSource.energyWh,5000);assert.equal(scopedSource.totalFils,525);

      const linked=await request('POST',scopedPath+'bind-source',{
        sourceSessionId:String(scopedSource._id)},false,{authorization:adminBearer});
      assert.equal(linked.code,200);scopedSession=linked.body.data.sessionId;
      const history=await request('GET','/api/v1/ChargingSession/history/'+scopedSession,undefined,true);
      assert.equal(history.code,200);assert.equal(history.body.data.resourceVerified,true);
      assert.deepEqual(history.body.data.resourceScope,selectedScope);
      assert.equal(history.body.data.energyKwh,5);
    });
    await check(protocol+' changed source connector redacts reads and blocks finalization',async()=>{
      await db.collection('ocpp_sessions').updateOne({_id:scopedSource._id},{$set:{connectorId:3}});
      try{
        const status=await request('GET','/api/v1/ChargingSession/'+scopedSession+'/status',undefined,true);
        assert.equal(status.body.data.sourceVerified,false);assert.equal(status.body.data.kwhUsed,null);
        const history=await request('GET','/api/v1/ChargingSession/history/'+scopedSession,undefined,true);
        assert.equal(history.body.data.state,'unverified');assert.equal(history.body.data.totalFils,null);
        const finalized=await request('POST',scopedPath+'finalize-source',{},false,{authorization:adminBearer});
        assert.equal(finalized.code,409);
      }finally{await db.collection('ocpp_sessions').updateOne({_id:scopedSource._id},{$set:{connectorId:2}});}
      assert.equal((await request('POST',scopedPath+'finalize-source',{},false,
        {authorization:adminBearer})).code,200);
    });
  }
  const charger16='agent2-ocpp16-test',token16='0123456789ABCDEF0123';
  const hash16=createHash('sha256').update(token16).digest('hex');
  await db.collection('ocpp_access_tokens').insertOne({chargerId:charger16,tokenHash:hash16,
    enabled:true,expiresAt:new Date(Date.now()+3600000)});
  await db.collection('ocpp_tariffs').insertOne({chargerId:charger16,enabled:true,rateFilsPerKwh:100,vatPercent:5});
  await check('OCPP1.6 oversized start tag is rejected without source creation or token consumption',async()=>{
    const raw='AGENT2-OVERSIZED-BOOKING-TOKEN';
    const digest=createHash('sha256').update(raw).digest('hex');
    await db.collection('ocpp_access_tokens').insertOne({
      chargerId:charger16,tokenHash:digest,enabled:true,
      expiresAt:new Date(Date.now()+3600000)
    });
    const result=await handle({chargerId:charger16},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:raw,timestamp:new Date().toISOString()
    });
    assert.equal(result.idTagInfo.status,'Invalid');
    assert.equal(await db.collection('ocpp_sessions').countDocuments({chargerId:charger16}),0);
    assert.equal((await db.collection('ocpp_access_tokens').findOne({tokenHash:digest})).enabled,true);
  });
  await check('OCPP1.6 authorization preflight does not consume credential',async()=>{
    const auth=await handle({chargerId:charger16},'ocpp1.6','Authorize',{idTag:token16});
    assert.equal(auth.idTagInfo.status,'Accepted');
    const stored=await db.collection('ocpp_access_tokens').findOne({chargerId:charger16,tokenHash:hash16});
    assert.equal(stored.enabled,true);
    assert.equal(stored.consumedAt,undefined);
  });
  await check('OCPP 1.6 authorized source start/stop produces a distinct unpaid transaction',async()=>{
    const begin=await handle({chargerId:charger16},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:token16,timestamp:new Date().toISOString()});
    assert.equal(begin.idTagInfo.status,'Accepted');
    assert.equal(typeof begin.transactionId,'number');
    const retry=await handle({chargerId:charger16},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:token16,timestamp:new Date().toISOString()});
    assert.equal(retry.idTagInfo.status,'Accepted');
    assert.equal(retry.transactionId,begin.transactionId);
    const differentConnector=await handle({chargerId:charger16},'ocpp1.6','StartTransaction',{
      connectorId:2,meterStart:1000,idTag:token16,timestamp:new Date().toISOString()});
    assert.equal(differentConnector.idTagInfo.status,'Invalid');
    await handle({chargerId:charger16},'ocpp1.6','MeterValues',{
      transactionId:begin.transactionId,meterValue:meter(2000)});
    await handle({chargerId:charger16},'ocpp1.6','StopTransaction',{
      transactionId:begin.transactionId,meterStop:4000,
      timestamp:new Date().toISOString(),reason:'EVDisconnected'});
    const doc=await db.collection('ocpp_sessions').findOne({chargerId:charger16,transactionId:begin.transactionId});
    assert.equal(doc.idTagHash,hash16);assert.equal(doc.energyWh,3000);
    assert.equal(doc.totalFils,315);assert.equal(doc.billingStatus,'calculated_unpaid');
    assert.equal(doc.paymentStatus,'not_collected');
  });
  await check('OCPP1.6 completed token cannot start another paid transaction',async()=>{
    const reused=await handle({chargerId:charger16},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:token16,timestamp:new Date().toISOString()});
    assert.equal(reused.idTagInfo.status,'Invalid');
    assert.equal(await db.collection('ocpp_sessions').countDocuments({chargerId:charger16}),1);
  });
  if(process.env.AGENT2_REAL_ADMIN_UI_URL){
    const {runActualAdminBrowser}=await import('./test_actual_admin_browser.mjs');
    const uiStation=await NearestStationModel.create({
      name:'Actual Browser Disposable Station',address:'Loopback only',
      status:'Available',latitude:25.741,longitude:55.922,pricePerHourValue:60,
      taxPercent:5,slots:[{startTime:'03:00 PM',endTime:'04:00 PM'}]
    });
    const uiCharger='agent2-actual-browser-only';
    await db.collection('ocpp_chargers').insertOne({
      stationId:String(uiStation._id),chargerId:uiCharger,enabled:true,
      protocol:'ocpp1.6',controlApproved:false
    });
    await db.collection('ocpp_tariffs').insertOne({
      chargerId:uiCharger,enabled:true,rateFilsPerKwh:100,vatPercent:5
    });
    // Synthetic prepayment/arrival fixture: never represents a PSP capture.
    const uiBooking=await BookingModel.create({
      userId:user._id,stationId:uiStation._id,date:'2027-02-01',
      time:'03:00 PM',slotStart:'03:00 PM',slotEnd:'04:00 PM',
      status:'Upcoming',isPaid:true,hasArrived:true,totalAmount:63
    });
    await check('real browser completes disposable Express/Mongo/OCPP booking journey',async()=>{
      await runActualAdminBrowser({
        uiUrl:process.env.AGENT2_REAL_ADMIN_UI_URL,apiUrl:base,adminToken:adminBearer.slice(7),
        bookingId:String(uiBooking._id),chargerId:uiCharger,
        createSource:async rawToken=>{
          const auth=await handle({chargerId:uiCharger},'ocpp1.6','Authorize',{idTag:rawToken});
          assert.equal(auth.idTagInfo.status,'Accepted');
          const start=await handle({chargerId:uiCharger},'ocpp1.6','StartTransaction',{
            connectorId:1,meterStart:1000,idTag:rawToken,timestamp:new Date().toISOString()
          });
          assert.equal(start.idTagInfo.status,'Accepted');
          await handle({chargerId:uiCharger},'ocpp1.6','MeterValues',{
            transactionId:start.transactionId,meterValue:meter(4000)
          });
          await handle({chargerId:uiCharger},'ocpp1.6','StopTransaction',{
            transactionId:start.transactionId,meterStop:6000,
            timestamp:new Date().toISOString(),reason:'EVDisconnected'
          });
          const stored=await db.collection('ocpp_sessions').findOne({
            chargerId:uiCharger,transactionId:start.transactionId
          });
          assert.equal(stored.energyWh,5000);
          assert.equal(stored.paymentStatus,'not_collected');
          return String(stored._id);
        },
        verifyResult:async sourceId=>{
          const saved=await BookingModel.findById(uiBooking._id);
          assert.equal(saved.status,'Completed');
          assert.deepEqual(saved.ocppResourceScope,{protocol:'ocpp1.6',connectorId:1,evseId:null});
          const linked=await ChargingSessionModel.findOne({bookingId:uiBooking._id});
          assert.equal(linked.sourceSessionId,sourceId);
          assert.equal(await ChargingSessionModel.countDocuments({bookingId:uiBooking._id}),1);
          assert.equal(await db.collection('ocpp_sessions').countDocuments({chargerId:uiCharger}),1);
          const customer=await request('GET','/api/v1/ChargingSession/'+linked._id+'/status',
            undefined,true);
          assert.equal(customer.code,200);
          assert.equal(customer.body.data.sourceVerified,true);
          assert.equal(customer.body.data.kwhUsed,5);
          assert.equal(customer.body.data.paymentStatus,'not_collected');
          const summary=await request('GET','/api/v1/ChargingSession/'+linked._id+'/summary',
            undefined,true);
          assert.equal(summary.code,200);
          // Verify canonical source fils separately; never infer collected cash.
          const stored=await db.collection('ocpp_sessions').findOne({
            _id:new mongoose.Types.ObjectId(sourceId)
          });
          assert.equal(stored.totalFils,525);
          assert.equal(stored.billingStatus,'calculated_unpaid');
        }
      });
    });
  }
 }
 console.log('ISOLATED_EXPRESS_DEMO_TESTS_PASSED '+passed);
}finally{
 if(server)await new Promise(done=>server.close(done));
 await mongoose.disconnect().catch(()=>{});
 mongod.kill('SIGTERM');
 await Promise.race([new Promise(done=>mongod.once('exit',done)),
   new Promise(done=>setTimeout(done,2000))]);
 rmSync(data,{recursive:true,force:true});
}

