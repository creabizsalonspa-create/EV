#!/usr/bin/env python3
"""Make the customer charging summary read authoritative OCPP metering.

This is a source-level patcher for an isolated staged Express/Mongo copy.
It does not deploy to production, charge customers or control real hardware.
"""
from pathlib import Path
import sys

def patch(root):
    root=root.resolve()
    if str(root)=='/home/hasio/ev-deployment' or str(root).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Refusing production directory')
    file=root/'src/services/userService.js'
    if not file.is_file():
        raise RuntimeError('Complete staged Express customer service required')
    source=file.read_text(encoding='utf8')
    begin='// ─── Screen 6: GET /ChargingSession/:id/summary'
    end='// ─── POST /ChargingSession/:id/pay'
    start=source.find(begin)
    stop=source.find(end,start+len(begin))
    if start<0 or stop<0 or start>=stop or source.find(begin,start+1)>=0:
        raise RuntimeError('Customer summary service boundary changed')
    replacement='''// ─── Screen 6: GET /ChargingSession/:id/summary ──────────────────────────
// Read source-bound metered charging and unpaid billing evidence, not simulated totals.
// Booking reservation prepayment and source metered charge must be reconciled in
// the canonical finance ledger before any second customer collection.
export const GetChargingSummaryService = async (user_id, sessionId) => {
  try {
    const session = await ChargingSessionModel.findOne({_id:sessionId,userId:user_id});
    if (!session) return {status:"fail",message:"Session not found."};
    const proof = await readCustomerOcppSource(user_id,session);
    if (!proof?.sourceVerified || !proof.meterVerified) {
      return {status:"fail",message:"No verified OCPP metering for this booking."};
    }
    if (!proof.sessionEnded || proof.billingStatus!=="calculated_unpaid"
        || proof.totalFils===null) {
      return {status:"fail",message:"Charging has not ended with an auditable metered total."};
    }
    const settings=await AppSettingsModel.getSettings();
    return {
      status:"Success",
      message:"Source-confirmed charging summary; payment reconciliation pending.",
      data:{
        sessionId:session._id,
        bookingId:session.bookingId,
        heading:"Charging Stopped",
        subHeading:"Metered energy recorded. Payment reconciliation pending.",
        chargingStatus:"Completed",
        source:"ocpp",protocol:proof.protocol,
        sourceVerified:true,meterVerified:true,
        energyDelivered:proof.energyKwh,energyWh:proof.energyWh,
        costPerKwh:proof.rateAedPerKwh,
        vatPercent:proof.vatPercent,
        subtotalAed:proof.subtotalFils/100,
        vatAed:proof.vatFils/100,
        totalAmount:proof.totalFils/100,
        calculatedUnpaidAed:proof.totalFils/100,
        extendSessionCharge:0,
        currencyCode:"AED",currencySymbol:settings.currencyCode==="AED"?settings.currencySymbol:"د.إ",
        currencyIcon:settings.currencyCode==="AED"?settings.currencyIcon:"payments",
        billingMode:"calculation_only_no_collection",
        billingStatus:"calculated_unpaid",
        paymentStatus:"not_collected",
        needsPayment:false,
        canPay:false,
        showReviewPrompt:false,
      }
    };
  } catch {
    return {status:"fail",message:"Charging summary unavailable."};
  }
};

'''
    source=source[:start]+replacement+source[stop:]
    # The source-backed payment policy must be implemented in the canonical ledger.
    paystart=source.find('export const PayChargingSessionService = async (user_id, sessionId, body = {}) => {')
    payend=source.find('export const ',paystart+12)
    if paystart<0:
        raise RuntimeError('Extension payment handler moved')
    if payend<0:
        raise RuntimeError('Unable to verify extension payment function boundary')
    source=source[:paystart]+'''export const PayChargingSessionService = async () => ({
  status:"fail",
  message:"Metered charging payment reconciliation is not enabled.",
});

'''+source[payend:]
    file.write_text(source,encoding='utf8')
    print('STAGED_OCPP_CUSTOMER_SUMMARY_SOURCE_BACKED; no production changes')

if __name__=='__main__':
    if len(sys.argv)!=2:
        raise SystemExit('Usage: stage_ocpp_customer_summary.py STAGED_EXPRESS_SOURCE')
    patch(Path(sys.argv[1]))
