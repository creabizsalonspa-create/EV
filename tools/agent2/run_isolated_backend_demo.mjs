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
    assert.ok(typeof issuedToken==='string' && /^[0-9a-f]{48}$/.test(issuedToken));
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
  const {migrateChargerStationIndex}=await import(
    pathToFileURL(join(source,'src/utils/migrateChargerStationIndex.mjs')).href);
  const multiStation=await NearestStationModel.create({
    name:'Agent 2 Two Charger Station',address:'Isolated OCPP Test Station',
    status:'Available',latitude:25.76,longitude:55.96,pricePerHourValue:70,
    slots:[{startTime:'10:00 AM',endTime:'11:00 AM'}]});
  const multiPrimary='agent2-multi-primary',multiSecond='agent2-multi-secondary';
  const chargerTable=db.collection('ocpp_chargers');
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
  await check('staging index migration retains globally unique charger IDs',async()=>{
    const result=await migrateChargerStationIndex(chargerTable);
    assert.equal(result.uniqueChargerIdIndexVerified,true);
    assert.equal(result.removedUniqueStationIndex,'stationId_1');
    const indexes=await chargerTable.indexes();
    assert.equal(indexes.some(x=>x.unique===true && x.key.stationId===1),false);
    assert.equal(indexes.some(x=>x.unique===true && x.key.chargerId===1),true);
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
    assert.ok(/^[a-f0-9]{40}$/.test(response.body.data.password));
    assert.equal(await chargerTable.countDocuments({stationId:String(multiStation._id)}),2);
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
    const otherToken='agent2-unrelated-first-charger-token';
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
  const charger16='agent2-ocpp16-test',token16='agent2-disposable-token-16';
  const hash16=createHash('sha256').update(token16).digest('hex');
  await db.collection('ocpp_access_tokens').insertOne({chargerId:charger16,tokenHash:hash16,
    enabled:true,expiresAt:new Date(Date.now()+3600000)});
  await db.collection('ocpp_tariffs').insertOne({chargerId:charger16,enabled:true,rateFilsPerKwh:100,vatPercent:5});
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
