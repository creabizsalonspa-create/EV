/**
 * Explicit OCPP index migration; never called from an HTTP request.
 * This code is only exercised against disposable DBs by Agent 2 tests until
 * a separate production change/backup window is approved.
 */
export async function migrateChargerStationIndex(collection){
 const indexes=await collection.indexes();
 const uniqueStation=indexes.find(i=>i.unique===true &&
   Object.keys(i.key||{}).length===1 && i.key.stationId===1);
 const existing=await collection.aggregate([
  {$match:{chargerId:{$type:'string'}}},
  {$group:{_id:'$chargerId',n:{$sum:1}}},
  {$match:{n:{$gt:1}}},{$limit:1}
 ]).toArray();
 if(existing.length)throw new Error('Duplicate charger IDs: abort migration');
 await collection.createIndex({chargerId:1},{unique:true,name:'chargerId_1'});
 if(uniqueStation)await collection.dropIndex(uniqueStation.name);
 await collection.createIndex({stationId:1},{name:'station_lookup_multi'});
 return {removedUniqueStationIndex:uniqueStation?.name||null,
   uniqueChargerIdIndexVerified:true,multiChargerStationLookupIndex:true};
}
