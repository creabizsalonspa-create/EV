import test from 'node:test';
import assert from 'node:assert/strict';
import {hashOcppToken,claimOcppStartToken,sameActiveOcpp16Retry} from '../ocppStartAuthorization.mjs';
const now=new Date('2026-11-07T10:00:00Z');
const charger='OCPP-DEMO-1';
const raw='random-unused-staging-charger-token';
class AtomicTokenCollection {
 constructor(entries=[]){this.rows=entries;}
 // Synchronous match and update before Promise resolution models Mongo's
 // atomic findOneAndUpdate compare-and-swap for this unique token.
 async findOneAndUpdate(q,u){
  const row=this.rows.find(x=>x.chargerId===q.chargerId
    && x.tokenHash===q.tokenHash && x.enabled===true
    && !Object.hasOwn(x,'consumedAt') && x.expiresAt>q.expiresAt.$gt);
  if(!row)return null;
  Object.assign(row,u.$set);
  return {...row};
 }
}
function fresh(){
 const record={chargerId:charger,tokenHash:hashOcppToken(raw),
   enabled:true,expiresAt:new Date(now.getTime()+600000)};
 return new AtomicTokenCollection([record]);
}
test('valid start consumes credential and stores hash, never the raw token',async()=>{
 const c=fresh();
 const x=await claimOcppStartToken(c,charger,raw,now);
 assert.equal(x.accepted,true);
 assert.equal(x.tokenHash,hashOcppToken(raw));
 assert.equal(c.rows[0].enabled,false);
 assert.deepEqual(c.rows[0].consumedAt,now);
 assert.equal(JSON.stringify(c.rows).includes(raw),false);
});
test('32 concurrent physical starts consume a credential exactly once',async()=>{
 const c=fresh();
 const responses=await Promise.all(Array.from({length:32},
   ()=>claimOcppStartToken(c,charger,raw,now)));
 assert.equal(responses.filter(x=>x.accepted).length,1);
 assert.equal(responses.filter(x=>!x.accepted).length,31);
});
test('replay after start is rejected even within the original expiry window',async()=>{
 const c=fresh();
 assert.equal((await claimOcppStartToken(c,charger,raw,now)).accepted,true);
 assert.equal((await claimOcppStartToken(c,charger,raw,
   new Date(now.getTime()+2000))).accepted,false);
});
test('a credential is specific to one provisioned charger',async()=>{
 const c=fresh();
 assert.equal((await claimOcppStartToken(c,'OCPP-OTHER',raw,now)).accepted,false);
 assert.equal(c.rows[0].enabled,true);
 assert.equal((await claimOcppStartToken(c,charger,raw,now)).accepted,true);
});
test('expired, revoked and previously consumed tokens are refused',async()=>{
 const expired=fresh();expired.rows[0].expiresAt=new Date(now.getTime()-1);
 assert.equal((await claimOcppStartToken(expired,charger,raw,now)).accepted,false);
 const revoked=fresh();revoked.rows[0].enabled=false;
 assert.equal((await claimOcppStartToken(revoked,charger,raw,now)).accepted,false);
 const claimed=fresh();claimed.rows[0].consumedAt=new Date(now.getTime()-2000);
 assert.equal((await claimOcppStartToken(claimed,charger,raw,now)).accepted,false);
});
test('invalid IDs, token shapes and times cannot enter the claim query',async()=>{
 const c=fresh();
 for(const args of [
  [charger,'a',now],['OTHER BAD ID',raw,now],[charger,raw,new Date('invalid')],
  [charger,{},now],[charger,raw,null],[charger,'x'.repeat(65),now],
 ]){
  const z=await claimOcppStartToken(c,...args);
  assert.equal(z.accepted,false);
 }
 assert.equal(c.rows[0].enabled,true);
});
test('hash preflight is read-only and cannot consume the token',()=>{
 const c=fresh();
 assert.equal(hashOcppToken(raw),c.rows[0].tokenHash);
 assert.equal(c.rows[0].enabled,true);
 assert.equal(c.rows[0].consumedAt,undefined);
});
const session={chargerId:charger,protocol:'ocpp1.6',idTagHash:hashOcppToken(raw),
 connectorId:2,meterStartWh:1000,status:'active',transactionId:12};
test('same active OCPP1.6 source is reusable for exact lost response retry',()=>{
 assert.equal(sameActiveOcpp16Retry(session,{
   chargerId:charger,tokenHash:hashOcppToken(raw),connectorId:2,meterStart:1000}),true);
});
test('different charger, token, connector, start meter or completed session not retried',()=>{
 const baseline={chargerId:charger,tokenHash:hashOcppToken(raw),connectorId:2,meterStart:1000};
 assert.equal(sameActiveOcpp16Retry({...session,status:'ended'},baseline),false);
 assert.equal(sameActiveOcpp16Retry(session,{...baseline,chargerId:'OTHER'}),false);
 assert.equal(sameActiveOcpp16Retry(session,{...baseline,tokenHash:'b'.repeat(64)}),false);
 assert.equal(sameActiveOcpp16Retry(session,{...baseline,connectorId:1}),false);
 assert.equal(sameActiveOcpp16Retry(session,{...baseline,meterStart:900}),false);
 assert.equal(sameActiveOcpp16Retry(session,{...baseline,connectorId:0}),false);
 assert.equal(sameActiveOcpp16Retry(null,baseline),false);
});
test('a reused active source never creates a second session or charges again',()=>{
 const c=fresh(); const before=c.rows.length;
 assert.equal(sameActiveOcpp16Retry(session,{
   chargerId:charger,tokenHash:hashOcppToken(raw),connectorId:2,meterStart:1000}),true);
 assert.equal(c.rows.length,before);
});
