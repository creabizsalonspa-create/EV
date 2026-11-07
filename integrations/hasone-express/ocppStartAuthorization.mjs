/**
 * OCPP transaction-start authorization for the separately deployed gateway.
 *
 * No raw token is persisted. The conditional Mongo findOneAndUpdate is a
 * single atomic claim; simultaneous starts cannot both consume a credential.
 * Calling OCPP Authorize MUST stay read-only and must not call this helper.
 */
import {createHash} from 'node:crypto';
import {normalizeResourceScope,tokenResourceMatches} from './ocppResourceScope.mjs';
export const hashOcppToken=raw=>{
  if(typeof raw!=='string' || raw.length<8 || raw.length>64)return null;
  return createHash('sha256').update(raw,'utf8').digest('hex');
};
export async function claimOcppStartToken(tokens,chargerId,raw,now=new Date(),resourceScope=null){
  const tokenHash=hashOcppToken(raw);
  if(!tokenHash || typeof chargerId!=='string' || !/^[A-Za-z0-9_-]{1,64}$/.test(chargerId)
      || !(now instanceof Date) || !Number.isFinite(now.valueOf())){
    return {accepted:false,tokenHash:null};
  }
  // Atomic compare-and-swap. Never use findOne + updateOne here.
  // Remove expiresAt on claim so Mongo's TTL index cannot erase the durable
  // consumed-token evidence before an operator checks for safe reissue.
  if(resourceScope!=null && !normalizeResourceScope(resourceScope))
    return {accepted:false,tokenHash:null};
  const scopeQuery=resourceScope==null?{resourceScope:null}:{$or:[
    {resourceScope:null},
    {'resourceScope.protocol':resourceScope.protocol,
     'resourceScope.connectorId':resourceScope.connectorId,
     'resourceScope.evseId':resourceScope.evseId??null}
  ]};
  const response=await tokens.findOneAndUpdate({
    ...scopeQuery,
    chargerId,tokenHash,enabled:true,consumedAt:{$exists:false},
    expiresAt:{$gt:now}
  },{$set:{enabled:false,consumedAt:now},$unset:{expiresAt:''}},
    {returnDocument:'after'});
  // MongoDB driver versions differ: some return a document directly, while
  // others return { value: document|null, lastErrorObject, ok }. The latter
  // wrapper is truthy even on a failed match. Never accept it as a token.
  const claimed=response && Object.hasOwn(response,'value')
    ?response.value:response;
  const accepted=!!(claimed && claimed.chargerId===chargerId
    && claimed.tokenHash===tokenHash && claimed.enabled===false
    && claimed.consumedAt instanceof Date
    && Number.isFinite(claimed.consumedAt.valueOf())
    && tokenResourceMatches(claimed,resourceScope));
  return {accepted,tokenHash:accepted?tokenHash:null};
}
/** Reuse only the SAME active OCPP1.6 source session after lost reply. */
export function sameActiveOcpp16Retry(session,{
  chargerId,tokenHash,connectorId,meterStart,now=new Date()
}={}){
  // A retry is only justified shortly after the original start response.
  // No stale active source may be re-authorized hours later with the old tag.
  const createdAt=session?.createdAt instanceof Date
    ?session.createdAt:new Date(session?.createdAt??NaN);
  const age=now instanceof Date && Number.isFinite(now.valueOf())
    ?now.getTime()-createdAt.getTime():NaN;
  return Number.isFinite(age) && age>=0 && age<=120000
    && !!session && session.status==='active' && session.protocol==='ocpp1.6'
    && session.chargerId===chargerId && session.idTagHash===tokenHash
    && Number.isInteger(connectorId) && connectorId>=1 && connectorId<=64
    && session.connectorId===connectorId
    && Number.isInteger(meterStart) && meterStart>=0 && meterStart<=1e12
    && session.meterStartWh===Math.round(meterStart)
    && Number.isInteger(session.transactionId);
}
