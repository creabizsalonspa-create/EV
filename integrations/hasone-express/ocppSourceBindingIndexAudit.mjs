/**
 * Read-only integrity audit for existing source-bound charging sessions.
 *
 * No DB connection, data mutation or hardware control in the audit.
 * The optional index writer is restricted to Agent 2's disposable database.
 */
const singleIndex=(i,...keys)=>Object.keys(i.key||{}).length===keys.length
  && keys.every(k=>i.key[k]===1);
const sourceIndexName='uniq_linked_ocpp_source_session';
const bookingIndexName='uniq_source_bound_ocpp_booking';
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
 const sourceUnique=indexes.some(i=>i.unique && singleIndex(i,'sourceSessionId'));
 const bookingUnique=indexes.some(i=>i.unique && singleIndex(i,'bookingId','userId'));
 const reasons=[];
 if(duplicateSources.length)reasons.push('duplicate_ocpp_source_session');
 if(duplicateBookings.length)reasons.push('duplicate_booking_source_binding');
 return {ready:reasons.length===0,reasons,sourceBoundRecordCount:count,
   hasUniqueSourceIndex:sourceUnique,hasUniqueBookingIndex:bookingUnique,
   requiredIndexNames:[sourceIndexName,bookingIndexName]};
}
export async function ensureStagingOcppSourceBindingIndexes(collection,{approval}={}){
 const dbName=collection?.dbName||collection?.namespace?.split?.('.')[0];
 if(dbName!=='agent2_demo_ephemeral')throw Error('Refusing non-staging OCPP index write');
 if(approval!=='APPROVE_DISPOSABLE_BINDING_INDEXES')
   throw Error('Explicit disposable source-binding index approval required');
 const pre=await auditOcppSourceBindingIndexes(collection);
 if(!pre.ready)throw Error('Source-binding duplicates block indexes: '+pre.reasons.join(','));
 await collection.createIndex({sourceSessionId:1},{
  unique:true,name:sourceIndexName,
  partialFilterExpression:{sourceSessionId:{$gt:''}}
 });
 await collection.createIndex({bookingId:1,userId:1},{
  unique:true,name:bookingIndexName,
  partialFilterExpression:{sourceType:'ocpp',sourceSessionId:{$gt:''}}
 });
 return auditOcppSourceBindingIndexes(collection);
}
