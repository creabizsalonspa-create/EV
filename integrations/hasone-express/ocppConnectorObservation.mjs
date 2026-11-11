import {sourceResourceScope} from './ocppResourceScope.mjs';

/** Read-only connector report, qualified to this active source transaction.
 * Receipt age is server observation age, not proof of current availability.
 * Legacy device updatedAt and station-level reports never become a receipt.
 */
export function presentConnectorObservation(source,charger,{now=new Date()}={}){
 const scope=sourceResourceScope(source);
 if(!scope || source.status!=='active' || !charger || charger.enabled!==true ||
    charger.chargerId!==source.chargerId || !(source.startedReceivedAt instanceof Date) ||
    !Number.isFinite(source.startedReceivedAt.valueOf()) || !(now instanceof Date) ||
    !Number.isFinite(now.valueOf()))return null;
 const key=(scope.evseId??0)+'_'+scope.connectorId;
 const row=charger.connectors?.[key];
 if(!row || row.protocol!==scope.protocol || row.connectorId!==scope.connectorId ||
    row.evseId!==scope.evseId || !(row.receivedAt instanceof Date) ||
    !Number.isFinite(row.receivedAt.valueOf()) || row.receivedAt<source.startedReceivedAt || row.receivedAt>now)return null;
 const allowed=scope.protocol==='ocpp1.6'?
   ['Available','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted']:
   ['Available','Occupied','Reserved','Unavailable','Faulted'];
 if(!allowed.includes(row.status))return null;
 const receiptAgeSeconds=Math.floor((now-row.receivedAt)/1000);
 return {qualified:true,source:'gateway_connector_report',protocol:scope.protocol,
   connectorId:scope.connectorId,evseId:scope.evseId,status:row.status,
   receivedAt:row.receivedAt.toISOString(),receiptAgeSeconds,
   freshness:receiptAgeSeconds<=120?'recent':'stale'};
}
