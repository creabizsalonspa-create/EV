/** Approved charger identity only. Does not assert connector inventory/live availability/capacity. */
export const validReservationCharger=id=>typeof id==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(id);
export function reservationChargerChoices(rows){
 if(!Array.isArray(rows)||rows.length>64)return null;
 const seen=new Set(),choices=[];
 for(const row of rows){
  if(row?.enabled!==true || !validReservationCharger(row.chargerId) ||
      !['ocpp1.6','ocpp2.0.1'].includes(row.protocol)||seen.has(row.chargerId))return null;
  seen.add(row.chargerId);choices.push({chargerId:row.chargerId,protocol:row.protocol});
 }
 return choices.sort((a,b)=>a.chargerId.localeCompare(b.chargerId));
}
export function selectReservationCharger(rows,requested){
 const choices=reservationChargerChoices(rows);
 if(!choices)return {ok:false,message:'Approved charger records need operator review.'};
 if(requested!==undefined&&requested!==''&&!validReservationCharger(requested))
  return {ok:false,message:'Choose a valid approved charger.'};
 if(requested){
  const found=choices.find(c=>c.chargerId===requested);
  return found?{ok:true,chargerId:found.chargerId}:{ok:false,message:'Selected charger is not approved for this station.'};
 }
 if(choices.length>1)return {ok:false,message:'Choose a specific charger for this station.'};
 // Existing stations with no gateway mapping retain explicitly unscoped legacy bookings.
 return {ok:true,chargerId:choices[0]?.chargerId??''};
}
