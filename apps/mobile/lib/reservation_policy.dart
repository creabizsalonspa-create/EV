import 'package:flutter/material.dart';

bool supportedReservationTerms(Map<String,dynamic>? terms) {
  if (terms == null) return false;
  return terms['version']==1 && terms['slot_minutes']==30 &&
    terms['arrival_before_minutes']==15 && terms['unused_expiry_minutes']==30 && terms['no_show_fee_minor']==0 &&
    terms['cancellation_fee_minor']==0 && terms['cancellation_rule']=='BEFORE_ADMITTED_OR_UNCERTAIN_START' &&
    terms['occupancy_basis']=='CANONICAL_LOGICAL_SESSION_ONLY' && terms['occupancy_enforcement']=='START_ADMISSION' && terms['physical_occupancy_verified']==false &&
    terms['unresolved_session_requires_reconciliation']==true && terms['automatic_stop']==false && terms['evidence']=='SIMULATED_BUSINESS_POLICY';
}

String reservationPolicyText(bool arabic, {bool priceAcceptanceRequired = false}) => (arabic
    ? 'موعد 30 دقيقة. يبدأ تسجيل الوصول الذاتي قبل الموعد بـ15 دقيقة. ينتهي الحجز غير المستخدم بنهاية الموعد ما لم تتطلب عملية غير مؤكدة المراجعة. الإلغاء متاح قبل بدء شحن مقبول أو غير مؤكد. رسوم الإلغاء وعدم الحضور صفر. الجلسات الجارية أو غير المؤكدة تمنع بدء جلسة أخرى لنفس المورد حتى اكتمالها أو تسويتها. لا إيقاف فعلي تلقائي ولا غرامة مالية. الحجز لا يضمن المكان الفعلي أو توفر الشاحن مباشرة.'
    : '30-minute slot. Self-reported arrival opens 15 minutes before the slot. Unused bookings expire at slot end unless an uncertain operation requires review. Cancel before an admitted or uncertain charging start. Cancellation/no-show fees are zero. Active/uncertain sessions block starting another session on the same resource until completed or reconciled. No automatic physical stop or financial penalty. A booking does not guarantee physical space or live charger availability.') + (priceAcceptanceRequired ? (arabic ? ' راجع أسعار محاكاة الشحن الحالية واقبلها صراحة قبل البدء؛ وهذا منفصل عن تأكيد الحجز.' : ' Review and explicitly accept current simulated charging prices before starting; this is separate from booking confirmation.') : '');

class ReservationPolicyPanel extends StatelessWidget {
  final Map<String,dynamic> terms;
  const ReservationPolicyPanel({super.key,required this.terms});
  @override
  Widget build(BuildContext context) => supportedReservationTerms(terms) ? ExpansionTile(
    title:Text(Localizations.localeOf(context).languageCode=='ar'?'شروط الحجز المسجلة - النسخة 1':'Recorded reservation terms - version 1'),
    children:[Padding(padding:const EdgeInsets.all(12),child:Text(reservationPolicyText(Localizations.localeOf(context).languageCode=='ar',priceAcceptanceRequired:terms['price_acceptance_required']==true)))],
  ):const SizedBox.shrink();
}
