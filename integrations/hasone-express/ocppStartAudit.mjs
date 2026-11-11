/**
 * Read-only OCPP start exception audit.
 * A token marked consumed but without a corresponding source transaction
 * may indicate a crashed worker or interrupted charger handshake.
 * Never re-enable a credential or infer successful charging from token state.
 */
const validId=id=>typeof id==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(id);
const dateMs=value=>{
 const n=new Date(value).getTime();
 return Number.isFinite(n)?n:null;
};
export async function auditConsumedStarts(db,{
  chargerIds,limit=50,now=new Date(),minAgeMs=120000,beforeId=null
}={}){
 if(!Array.isArray(chargerIds) || chargerIds.length===0 ||
   chargerIds.length>128 || !chargerIds.every(validId) ||
   !(now instanceof Date) || !Number.isFinite(now.getTime()) ||
   !Number.isInteger(limit)||limit<1||limit>100 ||
   !Number.isInteger(minAgeMs)||minAgeMs<120000 ||
   (beforeId!==null && (typeof beforeId?.toHexString!=='function' ||
     !/^[a-f0-9]{24}$/i.test(beforeId.toHexString()))))
   throw new Error('Invalid scoped charger audit request');
 const ids=[...new Set(chargerIds)];
 // Seek by immutable Mongo identity rather than an offset or non-unique
 // timestamp. New insertions cannot shift or duplicate later pages.
 const rows=await db.collection('ocpp_access_tokens').find({
   chargerId:{$in:ids},consumedAt:{$exists:true},enabled:false,
   ...(beforeId===null?{}:{_id:{$lt:beforeId}})
 }).sort({_id:-1}).limit(limit+1).toArray();
 const consumed=rows.slice(0,limit);
 const hasMore=rows.length>limit;
 const nextCursor=hasMore?String(consumed.at(-1)._id):null;
 const exceptions=[];
 let verifiedSources=0,pending=0;
 for(const token of consumed){
   if(!validId(token.chargerId) ||
     typeof token.tokenHash!=='string'||!/^[a-f0-9]{64}$/i.test(token.tokenHash))
      continue;
   const consumedMs=dateMs(token.consumedAt);
   if(consumedMs===null)continue;
   // A consumed token can correspond to either OCPP protocol, never
   // a source from a different charger sharing a hash.
   const source=await db.collection('ocpp_sessions').findOne({
     chargerId:token.chargerId,
     $or:[{idTagHash:token.tokenHash},{idTokenHash:token.tokenHash}]
   },{projection:{_id:1,protocol:1,transactionId:1,status:1}});
   if(source){verifiedSources++;continue;}
   const ageMs=now.getTime()-consumedMs;
   if(ageMs<minAgeMs || ageMs<0){pending++;continue;}
   exceptions.push({
     chargerId:token.chargerId,
     bookingId:typeof token.bookingId==='string'?token.bookingId:null,
     consumedAt:new Date(consumedMs).toISOString(),
     issue:'consumed_without_source_transaction',
     severity:'investigate',
     action:'Review charger logs and database before issuing any new authorization.',
     credentialRestored:false
   });
 }
 return {scanned:consumed.length,matchedSourceCount:verifiedSources,
   withinGracePeriodCount:pending,orphanCount:exceptions.length,
   exceptions,readOnly:true,hasMore,nextCursor};
}
