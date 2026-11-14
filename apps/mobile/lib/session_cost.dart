import 'package:flutter/material.dart';

bool validSessionCost(dynamic v) {
  if (v is! Map || v['evidence'] != 'SIMULATED' || v['scope'] != 'CANONICAL_SESSION_QUOTE' || v['physical_charging_verified'] != false || v['real_payment_processed'] != false || v['external_fees_included'] != false || v['spending_cap'] != false || v['automatic_stop'] != false || !['estimated','invoiced'].contains(v['state'])) return false;
  if (v['currency'] is! String || !RegExp(r'^[A-Z]{3}$').hasMatch(v['currency'] as String) || v['minor_units'] is! int || v['minor_units'] < 0 || v['minor_units'] > 3 || v['tax_bps'] is! int || v['tax_bps'] < 0 || v['tax_bps'] > 10000) return false;
  BigInt? number(String key) {final s=v[key];return s is String && RegExp(r'^(0|[1-9][0-9]{0,18})$').hasMatch(s)?BigInt.tryParse(s):null;}
  final energy=number('energy_wh'),rate=number('rate_minor_per_kwh'),subtotal=number('subtotal_minor'),tax=number('tax_minor'),total=number('total_minor');
  if (energy==null || rate==null || subtotal==null || tax==null || total==null) return false;
  if (rate>BigInt.from(1000000) || total>BigInt.parse('9223372036854775807') || !['APPLICATION_SIMULATOR','RECENT','STALE','UNKNOWN'].contains(v['meter_freshness_at_read']) || v['as_of'] is! String || !RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$').hasMatch(v['as_of'] as String)) return false;
  final computed=(energy*rate+BigInt.from(500))~/BigInt.from(1000),computedTax=(subtotal*BigInt.from(v['tax_bps'] as int)+BigInt.from(5000))~/BigInt.from(10000);
  return computed==subtotal && computedTax==tax && subtotal+tax==total && (v['state']!='invoiced' || v['invoice_id'] is int && v['invoice_id']>0);
}
String sessionCostText(dynamic value,bool arabic) {
  if (value is Map && value['state']=='awaiting_meter') return arabic?'بانتظار دليل العداد؛ لا يتوفر تقدير تكلفة.':'Waiting for meter evidence; no cost estimate.';
  if (!validSessionCost(value)) return arabic?'تقدير التكلفة غير متاح؛ حدّث السجل وراجع دليل العداد.':'Cost estimate unavailable; refresh and inspect meter evidence.';
  final v=Map<String,dynamic>.from(value as Map), digits=value['minor_units'] as int;
  String amount(String key) {final s=(v[key] as String).padLeft(digits+1,'0');return '${v['currency']} ${digits==0?s:'${s.substring(0,s.length-digits)}.${s.substring(s.length-digits)}'}';}
  final label=v['state']=='invoiced'?(arabic?'إجمالي فاتورة المحاكاة':'Simulated invoice total'):(arabic?'تقدير التكلفة المسجل':'Recorded cost estimate');
  final source=v['meter_freshness_at_read']=='APPLICATION_SIMULATOR'?(arabic?'عداد محاكاة يدوي':'Manual simulator meter'):'${arabic?'وقت رصد عداد المصدر':'Source meter recorded at'}: ${v['source_meter_at']??'—'}';
  final warning=v['meter_freshness_at_read']=='STALE'?(arabic?' آخر دليل مصدر قديم عند القراءة.':' Source evidence was stale at this read.'):v['meter_freshness_at_read']=='UNKNOWN'?(arabic?' حداثة المصدر غير معروفة.':' Source freshness is unknown.'):'';
  return '$label: ${amount('total_minor')} · ${arabic?'الضريبة':'Tax'}: ${amount('tax_minor')} · ${v['energy_wh']} Wh\n$source$warning\n${arabic?'وقت القراءة':'As of'}: ${v['as_of']??'—'}\n${arabic?'التقدير ليس حداً للإنفاق ولا يثبت دفعاً حقيقياً. الرسوم الخارجية غير مشمولة.':'An estimate is not a spending cap or proof of real payment. External fees are excluded.'}';
}
class SessionCostPanel extends StatelessWidget {
  final dynamic value;
  const SessionCostPanel({super.key,required this.value});
  @override
  Widget build(BuildContext context) => Text(sessionCostText(value,Localizations.localeOf(context).languageCode=='ar'));
}
