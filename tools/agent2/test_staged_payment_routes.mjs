#!/usr/bin/env node
// Agent 2: execute the *staged* payment routes with local in-memory models.
// NO production imports, database connections, payments, or physical chargers.
import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,writeFileSync,copyFileSync,symlinkSync,rmSync,existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve,dirname} from 'node:path';
import {pathToFileURL} from 'node:url';

const staged=resolve(process.argv[2]??'.'), dependencies=resolve(process.argv[3]??'.');
const src=join(staged,'src/routes/paymentRoutes.js'), guard=join(staged,'src/utils/paymentIntegrity.mjs');
if(!existsSync(src)||!existsSync(guard)||!existsSync(join(dependencies,'node_modules/express/index.js'))){
 console.error('Pass an isolated STAGED source copy and Express dependency root, not production.');process.exit(2);
}
const dir=mkdtempSync(join(tmpdir(),'agent2-route-test-'));
function make(p,content){const dest=join(dir,p);mkdirSync(dirname(dest),{recursive:true});writeFileSync(dest,content);}
try{
 make('package.json','{"type":"module"}');
 copyFileSync(src,join(dir,'src/routes/paymentRoutes.js')); // parent directories from make below
 copyFileSync(guard,join(dir,'src/utils/paymentIntegrity.mjs'));
 symlinkSync(join(dependencies,'node_modules'),join(dir,'node_modules'),'dir');
 make('src/models/BookingModel.js',"export default class BookingModel{static async findById(id){return globalThis.h.bookings.get(id)??null}static async findByIdAndUpdate(){throw Error('unverified write')}}");
 make('src/models/ChargingSessionModel.js',"export default class ChargingSessionModel{static async findById(id){return globalThis.h.sessions.get(id)??null}static async findByIdAndUpdate(){throw Error('unverified write')}}");
 make('src/services/paymentService.js',"export async function validateSSLCommerzIPN(){return globalThis.h.ssl;}export async function getPaymentSettings(){return {currencyCode:'AED'}}export async function verifyStripeWebhook(raw,sig){return sig==='agent2-fixture-signature'?JSON.parse(raw.toString('utf8')):null}");
 make('src/middlewares/authverification.js',"export function AuthVerification(req,res,next){if(req.headers.authorization!=='Bearer agent2-test')return res.status(401).json({error:'auth'});req.headers.user_id=globalThis.h.owner;next()}");
 const {default:express}=await import(pathToFileURL(join(dir,'node_modules/express/index.js')).href);
 const {default:router}=await import(pathToFileURL(join(dir,'src/routes/paymentRoutes.js')).href);
 const app=express();
 app.use(express.json({verify:(req,_res,bytes)=>{if(req.originalUrl.split('?')[0]==='/api/v1/payment/stripe/webhook')req.stripeRawBody=Buffer.from(bytes);}}));
 app.use('/api/v1',router);
 const server=await new Promise(done=>{const s=app.listen(0,'127.0.0.1',()=>done(s));});
 try{
  const base='http://127.0.0.1:'+server.address().port+'/api/v1/payment';
  const id='5dafe9c3f2e48b1234aabcd1', owner='5dafe9c3f2e48b1234aabcd2';
  const transaction='booking_'+id+'_1794021000000';
  const h=globalThis.h={bookings:new Map(),sessions:new Map(),ssl:null,owner,saves:0};
  function booking(){const b={_id:id,userId:owner,totalAmount:60,isPaid:false,status:'Upcoming',paymentGateway:'sslcommerz',paymentStatus:'pending',transactionId:transaction,async save(){h.saves++;}};h.bookings.set(id,b);h.saves=0;return b;}
  const note=()=>({status:'VALID',value_a:'booking',value_b:id,tran_id:transaction});
  const valid=()=>({status:'VALID',amount:60,currency:'AED',tran_id:transaction});
  const http=(path,body,headers={})=>fetch(base+path,{method:body===undefined?'GET':'POST',headers:{...(body===undefined?{}:{'content-type':'application/json'}),...headers},...(body===undefined?{}:{body:JSON.stringify(body)}),redirect:'manual'});
  let passed=0;
  async function test(label,fn){await fn();passed++;console.log('PASS '+label);}
  await test('browser success redirect cannot settle',async()=>{const b=booking();const res=await http('/sslcommerz/success',note());assert.equal(res.status,200);assert.match(await res.text(),/pending/i);assert.equal(b.isPaid,false);assert.equal(h.saves,0);});
  await test('unverified IPN cannot settle',async()=>{const b=booking();h.ssl=false;const res=await http('/sslcommerz/ipn',note());assert.equal(res.status,400);assert.equal(b.isPaid,false);assert.equal(h.saves,0);});
  await test('provider amount mismatch cannot settle',async()=>{const b=booking();h.ssl={...valid(),amount:1};const res=await http('/sslcommerz/ipn',note());assert.equal(res.status,400);assert.equal(b.isPaid,false);});
  await test('validated IPN settles bound booking',async()=>{const b=booking();h.ssl=valid();const res=await http('/sslcommerz/ipn',note());assert.equal(res.status,200);assert.equal(b.isPaid,true);assert.equal(h.saves,1);});
  await test('tampered reference rejected',async()=>{const b=booking();h.ssl=valid();const res=await http('/sslcommerz/ipn',{...note(),value_b:owner});assert.equal(res.status,400);assert.equal(b.isPaid,false);});
  await test('payment-status endpoint requires authentication',async()=>{booking();const res=await http('/status/booking/'+id);assert.equal(res.status,401);});
  await test('foreign user cannot read payment status',async()=>{booking();h.owner='5dafe9c3f2e48b1234aabcd3';const res=await http('/status/booking/'+id,undefined,{authorization:'Bearer agent2-test'});assert.equal(res.status,404);});
  await test('record owner can read payment status',async()=>{booking();h.owner=owner;const res=await http('/status/booking/'+id,undefined,{authorization:'Bearer agent2-test'});assert.equal(res.status,200);});
  const event=(amount)=>({type:'payment_intent.succeeded',data:{object:{id:'pi_fixture',status:'succeeded',metadata:{paymentType:'booking',referenceId:id},amount_received:amount,currency:'aed'}}});
  await test('unsigned Stripe event cannot settle',async()=>{const b=booking();b.paymentGateway='stripe';b.stripePaymentIntentId='pi_fixture';const res=await http('/stripe/webhook',event(6000));assert.equal(res.status,400);assert.equal(b.isPaid,false);assert.equal(h.saves,0);});
  await test('wrong Stripe amount cannot settle',async()=>{const b=booking();b.paymentGateway='stripe';b.stripePaymentIntentId='pi_fixture';const res=await http('/stripe/webhook',event(100),{'stripe-signature':'agent2-fixture-signature'});assert.equal(res.status,400);assert.equal(b.isPaid,false);});
  await test('verified Stripe event settles the correct booking',async()=>{const b=booking();b.paymentGateway='stripe';b.stripePaymentIntentId='pi_fixture';const res=await http('/stripe/webhook',event(6000),{'stripe-signature':'agent2-fixture-signature'});assert.equal(res.status,200);assert.equal(b.isPaid,true);assert.equal(h.saves,1);});
  console.log('STAGED_ROUTE_TESTS_PASSED '+passed);
 }finally{await new Promise((yes,no)=>server.close(e=>e?no(e):yes()));}
}finally{rmSync(dir,{recursive:true,force:true});}
