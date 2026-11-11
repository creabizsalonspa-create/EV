/** Protocol-specific physical resource identity; never a claim of availability. */
const positive=value=>Number.isInteger(value) && value>=1 && value<=64;
export function normalizeResourceScope(scope){
 if(!scope || typeof scope!=='object' || Array.isArray(scope) ||
    Object.keys(scope).some(k=>!['protocol','connectorId','evseId'].includes(k)) ||
    !positive(scope.connectorId))return null;
 if(scope.protocol==='ocpp1.6' && scope.evseId==null)
   return {protocol:scope.protocol,connectorId:scope.connectorId,evseId:null};
 if(scope.protocol==='ocpp2.0.1' && positive(scope.evseId))
   return {protocol:scope.protocol,connectorId:scope.connectorId,evseId:scope.evseId};
 return null;
}
export function sameResourceScope(left,right){
 const a=normalizeResourceScope(left),b=normalizeResourceScope(right);
 return !!a && !!b && a.protocol===b.protocol &&
   a.connectorId===b.connectorId && a.evseId===b.evseId;
}
export function sourceResourceScope(source){
 if(source?.protocol==='ocpp1.6' && source.evseId!=null && source.evseId!==0)return null;
 return normalizeResourceScope({protocol:source?.protocol,
   connectorId:source?.connectorId,evseId:source?.protocol==='ocpp1.6'?null:source?.evseId});
}
export function selectBookingResourceScope(booking,charger,body={}){
 if(!body || typeof body!=='object' || Array.isArray(body) ||
    !['ocpp1.6','ocpp2.0.1'].includes(charger?.protocol))return {ok:false,scope:null};
 const pinned=booking?.ocppResourceScope;
 const supplied=Object.hasOwn(body,'resourceScope');
 const requested=supplied?normalizeResourceScope(body.resourceScope):null;
 if((pinned!=null && !normalizeResourceScope(pinned)) ||
    (supplied && !requested) ||
    (pinned!=null && supplied && !sameResourceScope(pinned,requested)))
   return {ok:false,scope:null};
 const scope=pinned!=null?normalizeResourceScope(pinned):requested;
 if(scope && scope.protocol!==charger.protocol)return {ok:false,scope:null};
 return {ok:true,scope};
}
/** Missing scope is legacy charger-wide authority, never connector verified. */
export function tokenResourceMatches(token,actual){
 return token?.resourceScope==null || sameResourceScope(token.resourceScope,actual);
}
