/** Resolve one registered physical identity, never the first matching row.
 * Charger IDs are globally unique at provisioning. A duplicate in another
 * station is also ambiguous; do not hide it with a station/enabled filter.
 * This read never changes registry records or grants control/availability.
 */
export async function readUniqueOcppCharger(collection,{stationId,chargerId,requireEnabled=false}={}){
 if(typeof stationId!=='string'||!stationId||stationId.length>128||
    typeof chargerId!=='string'||!/^[A-Za-z0-9_-]{1,64}$/.test(chargerId)||
    typeof requireEnabled!=='boolean')return null;
 const rows=await collection.find({chargerId},{projection:{
   _id:0,stationId:1,chargerId:1,protocol:1,enabled:1,connectors:1
 }}).limit(2).toArray();
 if(rows.length!==1)return null;
 const row=rows[0];
 if(!row||row.stationId!==stationId||row.chargerId!==chargerId||
    !['ocpp1.6','ocpp2.0.1'].includes(row.protocol)||
    typeof row.enabled!=='boolean'||(requireEnabled&&!row.enabled))return null;
 return row;
}
