import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';

bool validPrechargeEstimate(dynamic value) {
  if (value is! Map || value['version'] != 1 || value['evidence'] != 'SIMULATED' || value['currency'] is! String || !RegExp(r'^[A-Z]{3}$').hasMatch(value['currency'] as String) || value['minor_units'] is! int || value['minor_units'] < 0 || value['minor_units'] > 3 || value['energy_wh'] is! int || value['energy_wh'] < 1 || value['energy_wh'] > 1000000 || value['tax_bps'] is! int || value['tax_bps'] < 0 || value['tax_bps'] > 10000 || value['external_fees_included'] != false || value['automatic_stop'] != false || value['final_total_depends_on_meter'] != true || value['session_fee_minor'] != '0' || value['idle_fee_minor'] != '0') return false;
  BigInt? amount(String name) {
    final text = value[name];
    return text is String && RegExp(r'^(0|[1-9][0-9]{0,15})$').hasMatch(text) ? BigInt.tryParse(text) : null;
  }
  final rate = amount('rate_minor_per_kwh'), subtotal = amount('subtotal_minor'), tax = amount('tax_minor'), total = amount('total_minor');
  final expiry = value['expires_at'];
  if (rate == null || subtotal == null || tax == null || total == null || rate > BigInt.from(1000000) || expiry is! String || !RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$').hasMatch(expiry) || DateTime.tryParse(expiry) == null) return false;
  final computed = (BigInt.from(value['energy_wh'] as int) * rate + BigInt.from(500)) ~/ BigInt.from(1000);
  final computedTax = (computed * BigInt.from(value['tax_bps'] as int) + BigInt.from(5000)) ~/ BigInt.from(10000);
  return computed == subtotal && computedTax == tax && subtotal + tax == total;
}

String prechargeAmount(String value, int digits) {
  final padded = value.padLeft(digits + 1, '0');
  return digits == 0 ? padded : '${padded.substring(0, padded.length - digits)}.${padded.substring(padded.length - digits)}';
}

String prechargeText(Map<String,dynamic> value, bool arabic) {
  if (!validPrechargeEstimate(value)) throw const FormatException('Unsupported pricing estimate');
  String money(String name) => '${value['currency']} ${prechargeAmount(value[name] as String, value['minor_units'] as int)}';
  return arabic
    ? 'تقدير محاكاة للشحن: ${value['energy_wh']} Wh\nالسعر لكل kWh: ${money('rate_minor_per_kwh')}\nالطاقة: ${money('subtotal_minor')}\nالضريبة: ${money('tax_minor')}\nالإجمالي التقديري: ${money('total_minor')}\nرسوم الجلسة والخمول في المحاكاة: 0. لا تشمل رسوم المواقف أو الدخول الخارجية. يعتمد المبلغ النهائي على العداد الفعلي للجلسة؛ هذا ليس حداً للإنفاق ولا يوقف الشحن تلقائياً. لا يحدث دفع حقيقي.\nتنتهي صلاحية التأكيد: ${value['expires_at']}'
    : 'Simulated charging estimate: ${value['energy_wh']} Wh\nRate per kWh: ${money('rate_minor_per_kwh')}\nEnergy: ${money('subtotal_minor')}\nTax: ${money('tax_minor')}\nEstimated total: ${money('total_minor')}\nSimulator session/idle fees: 0. External parking/access fees are excluded. Final cost depends on the session meter; this is not a spending cap and does not automatically stop charging. No real payment occurs.\nConfirmation expires: ${value['expires_at']}';
}

Future<bool> reviewPrechargePricing(BuildContext context, EvApi api, int reservation) async =>
  await showDialog<bool>(context:context, builder:(_) => PrechargePricingDialog(api:api, reservation:reservation)) ?? false;

class PrechargePricingDialog extends StatefulWidget {
  final EvApi api;
  final int reservation;
  const PrechargePricingDialog({super.key, required this.api, required this.reservation});
  @override
  State<PrechargePricingDialog> createState() => _PrechargePricingDialogState();
}
class _PrechargePricingDialogState extends State<PrechargePricingDialog> with WidgetsBindingObserver {
  final energy = TextEditingController(text:'10000');
  Map<String,dynamic>? estimate;
  String? token;
  Object? error;
  bool busy = false, recorded = false;
  int generation = 0;
  late final bool Function() ownSession;
  @override
  void initState() { super.initState(); ownSession = widget.api.sessionFence(); WidgetsBinding.instance.addObserver(this); }
  @override
  void dispose() { generation++; WidgetsBinding.instance.removeObserver(this); energy.dispose(); token = null; estimate = null; super.dispose(); }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      generation++;
      if (mounted) setState(() { token = null; estimate = null; busy = false; error = null; });
    }
  }
  Future<void> load() async {
    if (!ownSession()) { setState(() { token = null; estimate = null; error = const ApiException(401,'Session changed'); }); return; }
    final amount = decimalMinor(energy.text,0,maximum:1000000);
    if (amount == null || amount < 1) {
      setState(() => error = const ApiException(422,'Invalid energy')); return;
    }
    final epoch = ++generation, current = ownSession;
    setState(() { busy = true; estimate = null; token = null; error = null; recorded = false; });
    try {
      final reply = await widget.api.request('reservations/${widget.reservation}/price-estimate',method:'POST',body:{'energy_wh':amount});
      if (!mounted || epoch != generation || !current()) return;
      if (reply is Map && reply['start_already_recorded'] == true) { setState(() => recorded = true); return; }
      if (reply is! Map || !validPrechargeEstimate(reply['estimate']) || reply['estimate']['energy_wh'] != amount || reply['quote_token'] is! String || (reply['quote_token'] as String).isEmpty || (reply['quote_token'] as String).length > 16384) throw const ApiException(503,'Invalid estimate');
      setState(() { estimate = Map<String,dynamic>.from(reply['estimate'] as Map); token = reply['quote_token'] as String; });
    } catch (e) { if (mounted && epoch == generation && current()) setState(() => error = e); }
    finally { if (mounted && epoch == generation) setState(() => busy = false); }
  }
  Future<void> accept() async {
    if (!ownSession()) { setState(() { token = null; estimate = null; error = const ApiException(401,'Session changed'); }); return; }
    final confirmation = token;
    if (confirmation == null || busy) return;
    final epoch = ++generation, current = ownSession;
    setState(() { busy = true; error = null; });
    try {
      await widget.api.request('reservations/${widget.reservation}/price-consent',method:'POST',body:{'quote_token':confirmation,'accepted':true});
      if (mounted && epoch == generation && current()) Navigator.of(context).pop(true);
    } catch (e) { if (mounted && epoch == generation && current()) setState(() { error = e; token = null; estimate = null; }); }
    finally { if (mounted && epoch == generation) setState(() => busy = false); }
  }
  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    String tr(String en,String ar) => s.arabic ? ar : en;
    return AlertDialog(
      title:Text(tr('Review simulated charging price','مراجعة سعر محاكاة الشحن')),
      content:SingleChildScrollView(child:Column(mainAxisSize:MainAxisSize.min,children:[
        TextField(controller:energy,enabled:!busy,keyboardType:TextInputType.number,maxLength:7,
          decoration:InputDecoration(labelText:tr('Estimated energy (Wh)','الطاقة المتوقعة (Wh)')),
          onChanged:(_) { generation++; setState(() { token = null; estimate = null; error = null; }); }),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(s.t(errorKey(error!))),
        if (recorded) Text(tr('A session is already recorded. Refresh session history; do not submit another start.','تم تسجيل جلسة بالفعل. حدّث سجل الجلسات ولا ترسل طلب بدء آخر.')),
        if (estimate != null) Text(prechargeText(estimate!,s.arabic)),
      ])),
      actions:[
        TextButton(onPressed:() => Navigator.of(context).pop(false),child:Text(s.t('cancel'))),
        TextButton(onPressed:busy ? null : load,child:Text(tr('Get estimate','عرض التقدير'))),
        FilledButton(onPressed:busy || token == null ? null : accept,child:Text(tr('Accept price','قبول السعر'))),
      ],
    );
  }
}
