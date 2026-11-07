#!/usr/bin/env node
/**
 * Agent 2: run actual staged Express app against a disposable loopback-only mongod.
 * No real merchant credentials, no production DB, no actual OCPP commands.
 *
 * node tools/agent2/run_isolated_backend_demo.mjs STAGED_SOURCE EXPRESS_DEPENDENCY_ROOT
 */
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdtempSync,mkdirSync,rmSync,existsSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {createServer} from 'node:net';
import {pathToFileURL} from 'node:url';
const source=resolve(process.argv[2]??'.'), dependencies=resolve(process.argv[3]??'.');
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
 const {default:AppSettingsModel}=await import(pathToFileURL(join(source,'src/models/AppSettingsModel.js')).href);
 const {default:PaymentCardModel}=await import(pathToFileURL(join(source,'src/models/PaymentCardModel.js')).href);
 const {EncodeToken}=await import(pathToFileURL(join(source,'src/utils/tokenHelper.js')).href);
 await AppSettingsModel.create({key:'main',savedCardEnabled:true,sslcommerzEnabled:false,stripeEnabled:false,currencyCode:'AED',currencySymbol:'د.إ'});
 const user=await UserModel.create({fullName:'Agent 2 Isolated Test',email:'agent2-no-delivery@example.invalid',
                                 isVerified:true,password:'disabled'});
 const booking=await BookingModel.create({userId:user._id,stationId:new mongoose.Types.ObjectId(),
              date:'2026-11-08',time:'11:00',totalAmount:60,status:'Upcoming'});
 const bearer='Bearer '+EncodeToken(user.email,String(user._id));
 server=await new Promise(done=>{const s=app.listen(0,'127.0.0.1',()=>done(s));});
 const base='http://127.0.0.1:'+server.address().port;
 let passed=0;
 async function request(method,path,body,authorized=false){
  const res=await fetch(base+path,{method,headers:{
    ...(body===undefined?{}:{'content-type':'application/json'}),
    ...(authorized?{authorization:bearer}:{})},
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
 console.log('ISOLATED_EXPRESS_DEMO_TESTS_PASSED '+passed);
}finally{
 if(server)await new Promise(done=>server.close(done));
 await mongoose.disconnect().catch(()=>{});
 mongod.kill('SIGTERM');
 await Promise.race([new Promise(done=>mongod.once('exit',done)),
   new Promise(done=>setTimeout(done,2000))]);
 rmSync(data,{recursive:true,force:true});
}
