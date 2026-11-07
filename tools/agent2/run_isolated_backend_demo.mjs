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
  let issuedToken;
  await check('authorized Admin issues one scoped, expiring OCPP token for an arrived booking',async()=>{
    const z=await request('POST',issuePath,{},false,{authorization:adminBearer});
    assert.equal(z.code,201);
    assert.equal(z.body.status,'Success');
    assert.equal(z.body.data.chargerId,adminCharger);
    issuedToken=z.body.data.oneTimeToken;
    assert.ok(typeof issuedToken==='string' && /^[0-9a-f]{48}$/.test(issuedToken));
    const hash=createHash('sha256').update(issuedToken).digest('hex');
    const saved=await BookingModel.findById(adminBooking._id);
    assert.equal(saved.ocppTokenHash,hash);
    assert.ok(saved.ocppTokenIssuedAt);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({chargerId:adminCharger,tokenHash:hash,enabled:true}),1);
  });
  await check('Admin duplicate token issuance rejected without adding another credential',async()=>{
    const z=await request('POST',issuePath,{},false,{authorization:adminBearer});
    assert.equal(z.code,409);
    assert.equal(await db.collection('ocpp_access_tokens').countDocuments({chargerId:adminCharger}),1);
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
  let adminLinkedSession;
  await check('Admin binds approved, token-matched source exactly to the booking',async()=>{
    const z=await request('POST',bindPath,{sourceSessionId:String(adminOcppSession._id)},false,
      {authorization:adminBearer});
    assert.equal(z.code,200);
    assert.equal(z.body.status,'Success');
    assert.equal(z.body.data.energyKwh,5);
    assert.equal(z.body.data.financialSettlement,'not_collected');
    adminLinkedSession=await ChargingSessionModel.findById(z.body.data.sessionId);
    assert.equal(adminLinkedSession.sourceSessionId,String(adminOcppSession._id));
  });
  await check('customer sees meter-backed charging history from Admin-bound transaction',async()=>{
    const z=await request('GET','/api/v1/ChargingSession/'+adminLinkedSession._id+'/status',
      undefined,true);
    assert.equal(z.code,200);
    assert.equal(z.body.data.sourceVerified,true);
    assert.equal(z.body.data.kwhUsed,5);
    assert.equal(z.body.data.paymentStatus,'not_collected');
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
  const charger16='agent2-ocpp16-test',token16='agent2-disposable-token-16';
  const hash16=createHash('sha256').update(token16).digest('hex');
  await db.collection('ocpp_access_tokens').insertOne({chargerId:charger16,tokenHash:hash16,
    enabled:true,expiresAt:new Date(Date.now()+3600000)});
  await db.collection('ocpp_tariffs').insertOne({chargerId:charger16,enabled:true,rateFilsPerKwh:100,vatPercent:5});
  await check('OCPP 1.6 authorized source start/stop produces a distinct unpaid transaction',async()=>{
    const begin=await handle({chargerId:charger16},'ocpp1.6','StartTransaction',{
      connectorId:1,meterStart:1000,idTag:token16,timestamp:new Date().toISOString()});
    assert.equal(begin.idTagInfo.status,'Accepted');
    assert.equal(typeof begin.transactionId,'number');
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
