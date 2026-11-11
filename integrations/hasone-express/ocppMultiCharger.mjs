/**
 * Deterministic booking -> physical charger selection.
 *
 * A multi-charger station never silently assigns the first charger.
 * Once a booking has a source-bound charger the assignment is immutable.
 */
const safeChargerId = id=>typeof id==="string" && /^[A-Za-z0-9_-]{1,64}$/.test(id);
export function chooseBookingCharger({bookedChargerId="",reservedChargerId="",requestChargerId="",chargers=[]}={}){
  if(!Array.isArray(chargers) || chargers.length>128)
    return {charger:null,reason:"approved_charger_registry_requires_review",choices:[]};
  const active=chargers.filter(c=>c?.enabled===true);
  const unique=new Map();
  for(const charger of active){
    // Do not discard malformed active records and silently choose a different
    // charger, or guess a protocol for authorization. Operator review is needed.
    if(!safeChargerId(charger.chargerId) ||
        !["ocpp1.6","ocpp2.0.1"].includes(charger.protocol) || unique.has(charger.chargerId))
      return {charger:null,reason:"approved_charger_registry_requires_review",choices:[]};
    unique.set(charger.chargerId,charger);
  }
  const choices=[...unique.values()].sort((a,b)=>a.chargerId.localeCompare(b.chargerId));
  if(reservedChargerId && (!safeChargerId(reservedChargerId)||
      (bookedChargerId && bookedChargerId!==reservedChargerId)))
    return {charger:null,reason:"reservation_charger_mismatch",choices};
  bookedChargerId=bookedChargerId||reservedChargerId;
  if(bookedChargerId && !safeChargerId(bookedChargerId))
    return {charger:null,reason:"invalid_existing_booking_charger",choices};
  if(requestChargerId && !safeChargerId(requestChargerId))
    return {charger:null,reason:"invalid_requested_charger",choices};
  if(bookedChargerId && requestChargerId && bookedChargerId!==requestChargerId)
    return {charger:null,reason:"booking_charger_already_pinned",choices};
  const selected=requestChargerId||bookedChargerId;
  if(selected){
    const charger=unique.get(selected)||null;
    return {charger,reason:charger?null:"approved_charger_unavailable",choices};
  }
  return {charger:choices.length===1?choices[0]:null,
    reason:choices.length===1?null:choices.length===0?"no_approved_charger":"explicit_selection_required",
    choices};
}
export function sourceMatchesBookingCharger({booking,chargerId,chargers}){
  const result=chooseBookingCharger({
    bookedChargerId:booking?.ocppChargerId||"",reservedChargerId:booking?.reservedChargerId||"",chargers
  });
  return result.charger?.chargerId===chargerId;
}
