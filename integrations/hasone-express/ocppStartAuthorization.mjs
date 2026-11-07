/**
 * OCPP transaction-start authorization for the separately deployed gateway.
 *
 * No raw token is persisted. The conditional Mongo findOneAndUpdate is a
 * single atomic claim; simultaneous starts cannot both consume a credential.
 * Calling OCPP Authorize MUST stay read-only and must not call this helper.
 */
import {createHash} from 'node:crypto';
export const hashOcppToken=raw=>{
  if(typeof raw!=='string' || raw.length<8 || raw.length>64)return null;
  return createHash('sha256').update(raw,'utf8').digest('hex');
};
export async function claimOcppStartToken(tokens,chargerId,raw,now=new Date()){
  const tokenHash=hashOcppToken(raw);
  if(!tokenHash || typeof chargerId!=='string' || !/^[A-Za-z0-9_-]{1,64}$/.test(chargerId)
      || !(now instanceof Date) || !Number.isFinite(now.valueOf())){
    return {accepted:false,tokenHash:null};
  }
  // Atomic compare-and-swap. Never use findOne + updateOne here.
  const claimed=await tokens.findOneAndUpdate({
    chargerId,tokenHash,enabled:true,consumedAt:{$exists:false},
    expiresAt:{$gt:now}
  },{$set:{enabled:false,consumedAt:now}},{returnDocument:'after'});
  return {accepted:!!claimed,tokenHash:claimed?tokenHash:null};
}
/** Reuse only the SAME active OCPP1.6 source session after lost reply. */
export function sameActiveOcpp16Retry(session,{chargerId,tokenHash,connectorId,meterStart}={}){
  return !!session && session.status==='active' && session.protocol==='ocpp1.6'
    && session.chargerId===chargerId && session.idTagHash===tokenHash
    && Number.isInteger(connectorId) && connectorId>=1 && connectorId<=64
    && session.connectorId===connectorId
    && Number.isInteger(meterStart) && meterStart>=0 && meterStart<=1e12
    && session.meterStartWh===Math.round(meterStart)
    && Number.isInteger(session.transactionId);
}
