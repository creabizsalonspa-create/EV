/**
 * Auditable MongoDB OCPP charger-index migration with read-only preflight.
 *
 * This module never connects to a database. The caller injects an authorized
 * MongoDB Collection. Mutations are blocked by default, and intended only
 * for the disposable Agent 2 staging DB. Never run on a live charger DB
 * without an approved backup, maintenance window and separate migration.
 *
 * Multi-charger state: chargerId unique, stationId non-unique indexed.
 * Legacy state: stationId unique, chargerId unique.
 */
const sameIndex = (idx,field) => idx?.key?.[field] === 1
  && Object.keys(idx.key).length === 1;
const safeDbName = collection => collection?.dbName
  || collection?.namespace?.split?.('.')[0] || '';
const stageOnly = collection => {
  if (safeDbName(collection) !== 'agent2_demo_ephemeral') {
    throw new Error('Refusing index mutation outside the isolated Agent 2 database');
  }
};
const response = (phase,blocked,reasons,indexes,stats) => ({
  phase,blocked,reasons,existingIndexes:indexes.map(i=>({
    name:i.name,unique:!!i.unique,key:i.key
  })),
  ...stats
});
async function preflight(collection) {
  const indexes = await collection.indexes();
  const count=await collection.countDocuments({});
  // Both duplicate checks are bounded to one finding and avoid exposing IDs.
  const duplicateChargers=await collection.aggregate([
    {$match:{chargerId:{$type:'string'}}},
    {$group:{_id:'$chargerId',n:{$sum:1}}},
    {$match:{n:{$gt:1}}},{$limit:1}
  ]).toArray();
  const duplicateStations=await collection.aggregate([
    {$match:{stationId:{$type:'string'}}},
    {$group:{_id:'$stationId',n:{$sum:1}}},
    {$match:{n:{$gt:1}}},{$limit:1}
  ]).toArray();
  const missingChargerIds = await collection.countDocuments({
    $or:[{chargerId:{$exists:false}},{chargerId:null},{chargerId:''}]
  });
  const missingStationIds = await collection.countDocuments({
    $or:[{stationId:{$exists:false}},{stationId:null},{stationId:''}]
  });
  const stationUnique=indexes.find(x=>sameIndex(x,'stationId')&&x.unique);
  const chargerUnique=indexes.find(x=>sameIndex(x,'chargerId')&&x.unique);
  const stationLookup=indexes.find(x=>sameIndex(x,'stationId')&&!x.unique);
  return {indexes,count,duplicateChargerIds:duplicateChargers.length>0,
    duplicateStationIds:duplicateStations.length>0,
    missingChargerIds,missingStationIds,stationUnique,chargerUnique,stationLookup};
}
/** Read-only migration plan. Does not create or drop indexes. */
export async function planChargerStationIndexMigration(collection){
  const p=await preflight(collection);
  const reasons=[];
  if(p.missingChargerIds)reasons.push('missing_charger_id');
  if(p.missingStationIds)reasons.push('missing_station_id');
  if(p.duplicateChargerIds)reasons.push('duplicate_charger_ids');
  if(!p.chargerUnique && p.count>0)reasons.push('global_charger_id_index_not_enforced');
  return response('multi_charger_preflight',reasons.length>0,reasons,p.indexes,{
    records:p.count,hasLegacyUniqueStationIndex:!!p.stationUnique,
    hasGlobalUniqueChargerIndex:!!p.chargerUnique,
    hasMultiStationLookup:!!p.stationLookup,
    duplicateStationIds:p.duplicateStationIds,
    missingChargerIds:p.missingChargerIds,missingStationIds:p.missingStationIds
  });
}
/**
 * Apply only to disposable test database, with explicit confirmation.
 * Production deployment requires a separate reviewed maintenance procedure.
 */
export async function migrateChargerStationIndex(collection,{approval}={}){
  stageOnly(collection);
  if(approval!=='APPLY_TO_DISPOSABLE_AGENT2_DB') {
    throw new Error('Explicit disposable staging migration approval required');
  }
  const plan=await planChargerStationIndexMigration(collection);
  if(plan.blocked)throw new Error('Charger index preflight blocked: '+plan.reasons.join(','));
  const state=await preflight(collection);
  if(!state.chargerUnique) {
    await collection.createIndex({chargerId:1},{unique:true,name:'chargerId_1'});
  }
  if(!state.stationUnique) {
    if(!state.stationLookup) await collection.createIndex({stationId:1},{name:'station_lookup_multi'});
    return {removedUniqueStationIndex:null,uniqueChargerIdIndexVerified:true,
      multiChargerStationLookupIndex:true};
  }
  await collection.dropIndex(state.stationUnique.name);
  try{
    await collection.createIndex({stationId:1},{name:'station_lookup_multi'});
  }catch(err){
    // Only safe if no additional charger was registered after the drop.
    const after=await preflight(collection);
    if(!after.duplicateStationIds) {
      try{
        await collection.createIndex({stationId:1},{
          unique:true,name:state.stationUnique.name,
          ...(state.stationUnique.partialFilterExpression
              ?{partialFilterExpression:state.stationUnique.partialFilterExpression}:{})
        });
      }catch{/* Preserve original error; manual recovery required. */}
    }
    throw err;
  }
  return {removedUniqueStationIndex:state.stationUnique.name,
    uniqueChargerIdIndexVerified:true,multiChargerStationLookupIndex:true};
}
/** Read-only rollback plan: duplicates make unique-station rollback impossible. */
export async function planChargerStationRollback(collection){
  const p=await preflight(collection);
  const reasons=[];
  if(p.duplicateStationIds)reasons.push('multiple_chargers_share_station');
  if(p.missingStationIds)reasons.push('missing_station_id');
  if(!p.chargerUnique)reasons.push('global_charger_id_uniqueness_missing');
  return response('rollback_preflight',reasons.length>0,reasons,p.indexes,{
    records:p.count,hasLegacyUniqueStationIndex:!!p.stationUnique,
    duplicateStationIds:p.duplicateStationIds
  });
}
/**
 * Reapply unique stationId index only while this remains possible.
 * Must be run after removing extra chargers, in an isolated test database.
 */
export async function rollbackChargerStationIndex(collection,{approval}={}){
  stageOnly(collection);
  if(approval!=='ROLLBACK_DISPOSABLE_AGENT2_DB')
    throw new Error('Explicit disposable staging rollback approval required');
  const p=await planChargerStationRollback(collection);
  if(p.blocked)throw new Error('Rollback blocked: '+p.reasons.join(','));
  const state=await preflight(collection);
  if(!state.stationUnique){
    if(state.stationLookup)await collection.dropIndex(state.stationLookup.name);
    try{
      await collection.createIndex({stationId:1},{unique:true,
        name:'stationId_1',partialFilterExpression:{stationId:{$type:'string'}}});
    }catch(error){
      if(state.stationLookup){
        try{await collection.createIndex({stationId:1},{name:'station_lookup_multi'});}
        catch{/* original failure preserved */}
      }
      throw error;
    }
  }
  return {restoredUniqueStationIndex:true,records:state.count};
}
