import test from 'node:test';
import assert from 'node:assert/strict';
import {verifiedSslSettlement, verifiedSslReplay, verifiedStripeSettlement, verifiedStripeReplay, belongsToAuthenticatedUser} from '../paymentIntegrity.mjs';
const id = '5dafe9c3f2e48b1234aabcd1';
const who = '5dafe9c3f2e48b1234aabcd2';
const trx = `booking_${id}_1794021000000`;
const booking = () => ({_id:id, userId:who, totalAmount:60, status:'Upcoming', paymentGateway:'sslcommerz', transactionId:trx, paymentStatus:'pending', isPaid:false});
const notice = () => ({value_a:'booking',value_b:id,tran_id:trx,status:'VALID'});
const gateway = () => ({status:'VALID',tran_id:trx,amount:'60.00',currency:'AED'});
const ssl = (overrides={}) => verifiedSslSettlement({notice:notice(),gateway:gateway(),record:booking(),kind:'booking',currency:'AED', ...overrides});
test('verified provider SSL notification with bound transaction/amount/currency passes',()=>assert.equal(ssl(),true));
test('missing or boolean validation fails closed',()=>{assert.equal(ssl({gateway:false}),false);assert.equal(ssl({gateway:true}),false);});
test('tampered reference or transaction cannot mark another booking paid',()=>{assert.equal(ssl({notice:{...notice(),value_b:who}}),false);assert.equal(ssl({notice:{...notice(),tran_id:'fake'}}),false);});
test('replay and failed/changed status are rejected',()=>{assert.equal(ssl({record:{...booking(),isPaid:true}}),false);assert.equal(ssl({notice:{...notice(),status:'FAILED'}}),false);});
test('wrong amount and currency are rejected',()=>{assert.equal(ssl({gateway:{...gateway(),amount:'0.01'}}),false);assert.equal(ssl({gateway:{...gateway(),currency:'USD'}}),false);});
test('SSL sessions use distinct stored extension transaction and amount',()=>{
 const sx=`session_${id}_1794021000000`;
 const record={_id:id, status:'Stopped',paymentGateway:'sslcommerz',extensionPaymentStatus:'pending',extendSessionCharge:20,extensionTransactionId:sx};
 const n={value_a:'session',value_b:id,status:'VALID',tran_id:sx};
 const g={status:'VALID',tran_id:sx,amount:'20.00',currency:'AED'};
 assert.equal(verifiedSslSettlement({notice:n,gateway:g,record,kind:'session',currency:'AED'}),true);
 assert.equal(verifiedSslSettlement({notice:n,gateway:g,record:{...record,status:'Charging'},kind:'session',currency:'AED'}),false);
});
const piId='pi_3aaaaaaaaaaaa';
const stripe = () => ({...booking(),paymentGateway:'stripe',stripePaymentIntentId:piId});
const intent = () => ({id:piId,status:'succeeded',metadata:{paymentType:'booking',referenceId:id},amount_received:6000,currency:'aed'});
const st = overrides=>verifiedStripeSettlement({intent:intent(),record:stripe(),kind:'booking',currency:'AED',...overrides});
test('verified Stripe intent with bound identity/amount/currency passes',()=>assert.equal(st(),true));
test('Stripe wrong intent, metadata, amount and currency are rejected',()=>{
 assert.equal(st({intent:{...intent(),id:'pi_different'}}),false);
 assert.equal(st({intent:{...intent(),metadata:{paymentType:'booking',referenceId:who}}}),false);
 assert.equal(st({intent:{...intent(),amount_received:1}}),false);
 assert.equal(st({intent:{...intent(),currency:'usd'}}),false);
});
test('Stripe pending transaction cannot be reused as a new payment',()=>{
 assert.equal(st({record:{...stripe(),paymentStatus:'paid'}}),false);
 assert.equal(st({record:{...stripe(),stripePaymentIntentId:''}}),false);
});
test('only authenticated record owner can poll payment status',()=>{
 assert.equal(belongsToAuthenticatedUser({userId:who},who),true);
 assert.equal(belongsToAuthenticatedUser({userId:who},id),false);
 assert.equal(belongsToAuthenticatedUser({userId:who},undefined),false);
});
test('valid provider confirmation cannot reopen a completed booking',()=>assert.equal(ssl({record:{...booking(),status:'Completed'}}),false));
test('Stripe completed booking cannot be billed again',()=>assert.equal(st({record:{...stripe(),status:'Completed'}}),false));
test('invalid amount and missing receipt currency always fail closed',()=>{
 assert.equal(ssl({gateway:{...gateway(),amount:'not-a-number'}}),false);
 assert.equal(ssl({gateway:{...gateway(),currency:undefined}}),false);
});

test('verified SSL retry acknowledges a settled booking without creating a new settlement',()=>{
  const paid={...booking(),isPaid:true,paymentStatus:'paid'};
  assert.equal(verifiedSslReplay({notice:notice(),gateway:gateway(),record:paid,kind:'booking',currency:'AED'}),true);
  assert.equal(verifiedSslSettlement({notice:notice(),gateway:gateway(),record:paid,kind:'booking',currency:'AED'}),false);
});
test('SSL retry rejects mismatched transaction and forged currency',()=>{
  const paid={...booking(),isPaid:true,paymentStatus:'paid'};
  assert.equal(verifiedSslReplay({notice:{...notice(),tran_id:'fake'},gateway:gateway(),record:paid,kind:'booking',currency:'AED'}),false);
  assert.equal(verifiedSslReplay({notice:notice(),gateway:{...gateway(),currency:'USD'},record:paid,kind:'booking',currency:'AED'}),false);
});
test('verified Stripe retry acknowledges settled booking with matching transaction',()=>{
  const paid={...stripe(),isPaid:true,paymentStatus:'paid',transactionId:piId};
  assert.equal(verifiedStripeReplay({intent:intent(),record:paid,kind:'booking',currency:'AED'}),true);
  assert.equal(verifiedStripeSettlement({intent:intent(),record:paid,kind:'booking',currency:'AED'}),false);
});
test('Stripe retry rejects a different settled transaction',()=>{
  const paid={...stripe(),isPaid:true,paymentStatus:'paid',transactionId:'pi_other'};
  assert.equal(verifiedStripeReplay({intent:intent(),record:paid,kind:'booking',currency:'AED'}),false);
});
