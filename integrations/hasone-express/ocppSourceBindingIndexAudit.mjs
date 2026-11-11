/**
 * Read-only integrity audit for existing source-bound charging sessions.
 *
 * No DB connection, data mutation or hardware control in the audit.
 * The optional index writer is restricted to Agent 2's disposable database.
 * ready describes preflight blockers, not index presence; only
 * bindingUniquenessEnforced confirms both reviewed uniqueness protections.
 */
const singleIndex=(i,...keys)=>Object.keys(i.key||{}).length===keys.length
  && keys.every(k=>i.key[k]===1);
const sourceIndexName='uniq_linked_ocpp_source_session';
const bookingIndexName='uniq_source_bound_ocpp_booking';
const sourceFilter={sourceSessionId:{$gt:''}};
const bookingFilter={sourceType:'ocpp',sourceSessionId:{$gt:''}};
// Only reviewed canonical filters or an unfiltered unique index establish
// coverage. Field names alone do not prove that OCPP rows are indexed.
const equalFilter=(a,b)=>{
 if(a===b)return true;
 if(!a||!b||typeof a!=='object'||typeof b!=='object'||Array.isArray(a)||Array.isArray(b))return false;
 const keys=Object.keys(a);
 return keys.length===Object.keys(b).length&&keys.every(k=>Object.hasOwn(b,k)&&equalFilter(a[k],b[k]));
};
const covers=(index,filter)=>index.unique===true&&
 (!Object.hasOwn(index,'partialFilterExpression')||equalFilter(index.partialFilterExpression,filter));

export async function auditOcppSourceBindingIndexes(collection){
 const scoped={sourceType:'ocpp',sourceSessionId:{$type:'string',$gt:''}};
 const count=await collection.countDocuments(scoped);
 const duplicateSources=await collection.aggregate([
  {$match:scoped},{$group:{_id:'$sourceSessionId',count:{$sum:1}}},
  {$match:{count:{$gt:1}}},{$limit:1}
 ]).toArray();
 const duplicateBookings=await collection.aggregate([
  {$match:scoped},{$group:{_id:{bookingId:'$bookingId',userId:'$userId'},count:{$sum:1}}},
  {$match:{count:{$gt:1}}},{$limit:1}
 ]).toArray();
 const indexes=await collection.indexes();
 const sourceUnique=indexes.some(i=>singleIndex(i,'sourceSessionId')&&covers(i,sourceFilter));
 const bookingUnique=indexes.some(i=>singleIndex(i,'bookingId','userId')&&covers(i,bookingFilter));
 const review=indexes.filter(i=>
   (i.name===sourceIndexName&&(!singleIndex(i,'sourceSessionId')||!covers(i,sourceFilter)))||
   (i.name===bookingIndexName&&(!singleIndex(i,'bookingId','userId')||!covers(i,bookingFilter)))||
   (i.unique===true&&singleIndex(i,'sourceSessionId')&&!covers(i,sourceFilter))||
   (i.unique===true&&singleIndex(i,'bookingId','userId')&&!covers(i,bookingFilter)));
 const reasons=[];
 if(review.length)reasons.push('source_binding_index_requires_review');
 if(duplicateSources.length)reasons.push('duplicate_ocpp_source_session');
 if(duplicateBookings.length)reasons.push('duplicate_booking_source_binding');
 return {ready:reasons.length===0,reasons,sourceBoundRecordCount:count,
   hasUniqueSourceIndex:sourceUnique,hasUniqueBookingIndex:bookingUnique,
   bindingUniquenessEnforced:sourceUnique&&bookingUnique,
   indexReviewRequired:review.length>0,
   unreviewedIndexNames:review.slice(0,10).map(i=>String(i.name??'').slice(0,128)),
   requiredIndexNames:[sourceIndexName,bookingIndexName]};
}
export async function ensureStagingOcppSourceBindingIndexes(collection,{approval}={}){
 const dbName=collection?.dbName||collection?.namespace?.split?.('.')[0];
 if(dbName!=='agent2_demo_ephemeral')throw Error('Refusing non-staging OCPP index write');
 if(approval!=='APPROVE_DISPOSABLE_BINDING_INDEXES')
   throw Error('Explicit disposable source-binding index approval required');
 const pre=await auditOcppSourceBindingIndexes(collection);
 if(!pre.ready)throw Error('Source-binding preflight blocked by duplicates or index review: '+pre.reasons.join(','));
 if(!pre.hasUniqueSourceIndex)await collection.createIndex({sourceSessionId:1},{
  unique:true,name:sourceIndexName,
  partialFilterExpression:{sourceSessionId:{$gt:''}}
 });
 if(!pre.hasUniqueBookingIndex)await collection.createIndex({bookingId:1,userId:1},{
  unique:true,name:bookingIndexName,
  partialFilterExpression:{sourceType:'ocpp',sourceSessionId:{$gt:''}}
 });
 const after=await auditOcppSourceBindingIndexes(collection);
 if(!after.ready||!after.bindingUniquenessEnforced)throw Error('Source-binding index enforcement could not be verified');
 return after;
}
