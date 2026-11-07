import test from 'node:test';
import assert from 'node:assert/strict';
import {normalizeResourceScope,sameResourceScope,sourceResourceScope,
 selectBookingResourceScope,tokenResourceMatches} from '../ocppResourceScope.mjs';
import {verifyOcppSource} from '../ocppSourceEvidence.mjs';
const one={protocol:'ocpp1.6',connectorId:2,evseId:null};
const two={protocol:'ocpp2.0.1',connectorId:2,evseId:3};
test('resource identity is protocol-specific and never coerces user input',()=>{
 assert.deepEqual(normalizeResourceScope(one),one);
 assert.deepEqual(normalizeResourceScope(two),two);
 for(const scope of [null,[],{...one,connectorId:'2'},{...one,connectorId:0},
   {...one,connectorId:65},{...one,evseId:1},{...two,evseId:null},
   {...two,evseId:'3'},{...two,protocol:'unknown'},{...one,command:'start'}])
   assert.equal(normalizeResourceScope(scope),null);
});
test('an assigned connector cannot be silently switched or downgraded',()=>{
 const booking={ocppResourceScope:two},charger={protocol:two.protocol};
 assert.deepEqual(selectBookingResourceScope(booking,charger,{}),{ok:true,scope:two});
 for(const scope of [null,one,{...two,connectorId:3},{...two,evseId:4}])
   assert.equal(selectBookingResourceScope(booking,charger,{resourceScope:scope}).ok,false);
 assert.equal(selectBookingResourceScope(booking,{protocol:'ocpp1.6'},{}).ok,false);
 assert.equal(selectBookingResourceScope({ocppResourceScope:{}},charger,{}).ok,false);
});
test('legacy authority is explicitly unscoped; only a valid explicit selection upgrades it',()=>{
 assert.deepEqual(selectBookingResourceScope({},{}),{ok:true,scope:null});
 assert.deepEqual(selectBookingResourceScope({},{protocol:'ocpp1.6'},{resourceScope:one}),
   {ok:true,scope:one});
 assert.equal(selectBookingResourceScope({}, {}, []).ok,false);
 assert.equal(tokenResourceMatches({resourceScope:two},one),false);
 assert.equal(tokenResourceMatches({resourceScope:two},two),true);
 assert.equal(tokenResourceMatches({resourceScope:{}},two),false);
 assert.equal(tokenResourceMatches({},two),true);
 assert.equal(sameResourceScope(one,two),false);
});
test('source resource identity rejects partial, conflicting and cross-protocol locators',()=>{
 assert.deepEqual(sourceResourceScope({...one}),one);
 assert.deepEqual(sourceResourceScope({...two}),two);
 assert.equal(sourceResourceScope({...one,evseId:3}),null);
 assert.equal(sourceResourceScope({...two,connectorId:null}),null);
});
test('source proof requires the booking-selected connector before disclosing metering',()=>{
 const token='a'.repeat(64);
 const source={_id:'source',chargerId:'charger',transactionId:'tx',...two,
   idTokenHash:token,status:'active',startedAt:'2026-11-07T10:00:00Z',
   meterStartWh:1000,meterLastWh:6000,energyWh:5000,energyKwh:5,
   paymentStatus:'not_collected',billingStatus:'provisional_unpaid'};
 const binding={sourceId:'source',chargerId:'charger',transactionId:'tx',tokenHash:token,
   resourceScope:two};
 const proof=verifyOcppSource(source,binding);
 assert.equal(proof.resourceVerified,true);assert.equal(proof.energyKwh,5);
 assert.equal(verifyOcppSource({...source,connectorId:3},binding),null);
 assert.equal(verifyOcppSource({...source,evseId:4},binding),null);
 const legacy=verifyOcppSource(source,{...binding,resourceScope:null});
 assert.equal(legacy.resourceVerified,false);assert.equal(legacy.resourceScope,null);
});
