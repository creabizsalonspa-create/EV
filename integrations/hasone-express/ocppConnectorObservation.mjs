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
 // OCPP 1.6 fault codes are protocol enums, not arbitrary gateway prose.
 // A non-Faulted status carrying an active device fault cannot become a
 // healthy-looking customer observation; classify the receipt as unknown.
 // Legacy receipts without errorCode remain fault/unknown observations;
 // they must never advertise OCPP 1.6 Available as verified source health.
 if(scope.protocol==='ocpp1.6' && row.status==='Available' &&
    row.errorCode!=='NoError')return null;
 if(scope.protocol==='ocpp1.6' && row.errorCode!=null){
   const errors=new Set(['NoError','ConnectorLockFailure','EVCommunicationError',
     'GroundFailure','HighTemperature','InternalError','LocalListConflict',
     'OtherError','OverCurrentFailure','OverVoltage','PowerMeterFailure',
     'PowerSwitchFailure','ReaderFailure','ResetFailure','UnderVoltage','WeakSignal']);
   if(typeof row.errorCode!=='string' || !errors.has(row.errorCode) ||
      (row.status==='Faulted' && row.errorCode==='NoError') ||
      (row.status!=='Faulted' && row.errorCode!=='NoError'))return null;
 }
 const receiptAgeSeconds=Math.floor((now-row.receivedAt)/1000);
 return {qualified:true,source:'gateway_connector_report',protocol:scope.protocol,
   connectorId:scope.connectorId,evseId:scope.evseId,status:row.status,
   receivedAt:row.receivedAt.toISOString(),receiptAgeSeconds,
   freshness:receiptAgeSeconds<=120?'recent':'stale'};
}
