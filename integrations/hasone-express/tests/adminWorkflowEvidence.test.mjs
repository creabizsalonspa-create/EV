import test from 'node:test';
import assert from 'node:assert/strict';
import {validOcppWorkflowState,validOcppAuthorizationReceipt} from '../../hasone-next-admin/ocppWorkflowEvidence.mjs';
const bookingId='507f1f77bcf86cd799439011',stationId='507f1f77bcf86cd799439012';
const expected={bookingId,chargerId:'CH-1'};
const receipt=()=>({status:'Success',data:{bookingId,chargerId:'CH-1',oneTimeToken:'A'.repeat(20),
 expiresAt:'2026-11-08T10:20:00.000Z',chargingSessionConfirmed:false,connectorScoped:false,resourceScope:null}});
const state=()=>({status:'Success',data:{bookingId,stationId,bookingStatus:'Upcoming',calculatedUnpaidAed:null,paymentStatus:null,approvedChargerId:'CH-1',selectedChargerId:'CH-1',
 approvedChargers:[{chargerId:'CH-1',protocol:'ocpp1.6',online:false}],bookingPrepaymentRecorded:true,
 arrivalRecorded:true,authorizationIssued:false,sourceVerified:false,meterVerified:false,sourceEnded:false,
 canIssueToken:true,canReissueToken:false,canBindSource:false,canFinalizeSource:false,needsPaymentReconciliation:false,
 sourceSessionId:null,energyKwh:null,resourceScope:null}});
test('authorization receipt must match exact booking, charger and unscoped intent before secret delivery',()=>{
 assert.equal(validOcppAuthorizationReceipt(receipt(),expected),true);
 for(const patch of [{bookingId:stationId},{bookingId:undefined},{chargerId:'CH-other'},
  {oneTimeToken:'bad'},{chargingSessionConfirmed:true},{connectorScoped:true},{resourceScope:undefined},
  {expiresAt:'2026-02-31T01:00:00Z'},{expiresAt:null},{expiresAt:'2026-11-08T24:00:00Z'},
  {reissued:true}])assert.equal(validOcppAuthorizationReceipt({...receipt(),data:{...receipt().data,...patch}},expected),false);
});
test('connector and EVSE receipt cannot redirect, downgrade or upgrade the requested authority',()=>{
 for(const resourceScope of [{protocol:'ocpp1.6',connectorId:2,evseId:null},{protocol:'ocpp2.0.1',connectorId:2,evseId:3}]){
  const reply={...receipt(),data:{...receipt().data,connectorScoped:true,resourceScope}};
  assert.equal(validOcppAuthorizationReceipt(reply,{...expected,resourceScope}),true);
  for(const patch of [{connectorId:1},{evseId:4},{protocol:'ocpp9'},{connectorId:'2'}])
   assert.equal(validOcppAuthorizationReceipt({...reply,data:{...reply.data,resourceScope:{...resourceScope,...patch}}},{...expected,resourceScope}),false);
  assert.equal(validOcppAuthorizationReceipt(reply,expected),false);
  assert.equal(validOcppAuthorizationReceipt(receipt(),{...expected,resourceScope}),false);
 }
});
test('reissue receipt must confirm reissue of the same requested booking and resource',()=>{
 assert.equal(validOcppAuthorizationReceipt({...receipt(),data:{...receipt().data,reissued:true}},{...expected,reissued:true}),true);
 assert.equal(validOcppAuthorizationReceipt(receipt(),{...expected,reissued:true}),false);
});
test('operations cannot switch booking/station context or invent malformed charger choices',()=>{
 assert.equal(validOcppWorkflowState(state(),{bookingId,stationId}),true);
 for(const patch of [{bookingId:stationId},{stationId:bookingId},{approvedChargers:null},
  {approvedChargers:[...state().data.approvedChargers,...state().data.approvedChargers]},
  {approvedChargers:[{chargerId:'CH-1',protocol:null,online:false}]},{approvedChargerId:'unapproved'},
  {canIssueToken:'true'},{sourceVerified:true},{meterVerified:true},{sourceEnded:true},
  {resourceScope:{protocol:'ocpp1.6',connectorId:0,evseId:null}}])
  assert.equal(validOcppWorkflowState({...state(),data:{...state().data,...patch}},{bookingId,stationId}),false);
});
test('review states can retain a removed customer charger without exposing another approved source',()=>{
 const data={...state().data,approvedChargers:[],approvedChargerId:null,selectedChargerId:'CH-removed',canIssueToken:false};
 assert.equal(validOcppWorkflowState({status:'Success',data},{bookingId,stationId}),true);
});

test('unqualified operations cannot display injected metering or financial totals',()=>{
 for(const patch of [{energyKwh:9},{calculatedUnpaidAed:10},{provisionalUnpaidAed:10},
  {paymentStatus:'paid'},{bookingStatus:'unknown'}])
  assert.equal(validOcppWorkflowState({...state(),data:{...state().data,...patch}},{bookingId,stationId}),false);
});
