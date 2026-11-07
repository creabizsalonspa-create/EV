import test from 'node:test';
import assert from 'node:assert/strict';
import {canReissueOcppToken,OCPP_TOKEN_TTL_MS,
  OCPP_TOKEN_REISSUE_GRACE_MS} from '../ocppReissuePolicy.mjs';
const now=new Date('2026-11-07T10:00:00.000Z');
const charger='CH-RAK-1';
const oldBooking=()=>({
  _id:'507f1f77bcf86cd799439011',ocppChargerId:charger,
  isPaid:true,hasArrived:true,status:'Upcoming',
  ocppTokenHash:'a'.repeat(64),
  ocppTokenIssuedAt:new Date(now.getTime()-OCPP_TOKEN_TTL_MS-OCPP_TOKEN_REISSUE_GRACE_MS-1)
});
const params=()=>({booking:oldBooking(),approvedChargerId:charger,now});
test('expired and unstarted booking can be reauthorized after settling grace',()=>{
 assert.equal(canReissueOcppToken(params()),true);
});
test('current and recently expired credentials cannot be reissued',()=>{
 const p=params();
 p.booking.ocppTokenIssuedAt=new Date(now.getTime()-OCPP_TOKEN_TTL_MS+1);
 assert.equal(canReissueOcppToken(p),false);
 p.booking.ocppTokenIssuedAt=new Date(now.getTime()-OCPP_TOKEN_TTL_MS-1);
 assert.equal(canReissueOcppToken(p),false);
});
test('both OCPP 1.6 and 2.0.1 source observations block reissue',()=>{
 assert.equal(canReissueOcppToken({...params(),sourceObserved:true}),false);
});
test('source binding blocks token reissue even if the raw token has expired',()=>{
 assert.equal(canReissueOcppToken({...params(),sourceLinked:true}),false);
});
test('consumed credential blocks reissue during unsettled source persistence',()=>{
 assert.equal(canReissueOcppToken({...params(),tokenConsumed:true}),false);
});
test('different or disabled charger cannot receive a new booking token',()=>{
 assert.equal(canReissueOcppToken({...params(),approvedChargerId:'CH-DIFFERENT'}),false);
 assert.equal(canReissueOcppToken({...params(),approvedChargerId:''}),false);
});
test('unpaid, unarrived, cancelled and completed bookings are ineligible',()=>{
 for(const patch of [{isPaid:false},{hasArrived:false},{status:'Cancelled'},
   {status:'Completed'}]){
  assert.equal(canReissueOcppToken({...params(),booking:{...oldBooking(),...patch}}),false);
 }
});
test('missing or malformed old token hash and date are ineligible',()=>{
 for(const patch of [{ocppTokenHash:'bad'},{ocppTokenHash:null},
   {ocppTokenIssuedAt:null},{ocppTokenIssuedAt:'bad'}]){
  assert.equal(canReissueOcppToken({...params(),booking:{...oldBooking(),...patch}}),false);
 }
});
test('invalid or backward clock cannot reactivate a credential',()=>{
 assert.equal(canReissueOcppToken({...params(),now:new Date('invalid')}),false);
 assert.equal(canReissueOcppToken({...params(),now:new Date('2026-11-01T00:00:00Z')}),false);
});
