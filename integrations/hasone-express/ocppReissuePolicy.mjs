/**
 * Conservative reissue policy for expired, never-used charger authorizations.
 * A consumed token, source transaction or live source binding always blocks it.
 * 20-minute TTL plus 2-minute settling grace avoids racing expiry cleanup.
 */
export const OCPP_TOKEN_TTL_MS=20*60*1000;
export const OCPP_TOKEN_REISSUE_GRACE_MS=2*60*1000;
export function canReissueOcppToken({
  booking,approvedChargerId,sourceLinked=false,
  sourceObserved=false,tokenConsumed=false,tokenStillValid=false,now=new Date()
}={}){
  if(!booking || !approvedChargerId
      || booking.ocppChargerId!==approvedChargerId
      || booking.isPaid!==true || booking.hasArrived!==true
      || booking.status!=='Upcoming')return false;
  if(typeof booking.ocppTokenHash!=='string'
      || !/^[a-f0-9]{64}$/i.test(booking.ocppTokenHash))return false;
  if(booking.ocppTokenIssuedAt===null || booking.ocppTokenIssuedAt===undefined
      || booking.ocppTokenIssuedAt==='')return false;
  const issued=new Date(booking.ocppTokenIssuedAt);
  if(!Number.isFinite(issued.valueOf())
      || !(now instanceof Date) || !Number.isFinite(now.valueOf()))return false;
  if(now.valueOf()<issued.valueOf()+OCPP_TOKEN_TTL_MS+OCPP_TOKEN_REISSUE_GRACE_MS)
    return false;
  if(sourceLinked || sourceObserved || tokenConsumed || tokenStillValid)return false;
  return true;
}
