import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';

bool validQuoteExpiry(dynamic raw) {
  if (raw is! String) return false;
  final match=RegExp(r'^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.(\d{6})Z$').firstMatch(raw);
  if (match == null) return false;
  final fields=[
    for(var index=1;index<=6;index++) int.parse(match.group(index)!),
  ];
  // DateTime.tryParse may silently normalize February 30 to March 2.
  final utc=DateTime.utc(fields[0],fields[1],fields[2],
      fields[3],fields[4],fields[5]);
  return utc.year==fields[0] && utc.month==fields[1] &&
      utc.day==fields[2] && utc.hour==fields[3] &&
      utc.minute==fields[4] && utc.second==fields[5] &&
      DateTime.tryParse(raw)!=null;
}

bool freshPrechargeEstimate(Map<String,dynamic> value) =>
    validPrechargeEstimate(value) &&
    DateTime.parse(value['expires_at'] as String).toUtc()
        .isAfter(DateTime.now().toUtc());

String? _receiptInstant(dynamic value) {
  if (value is! String) return null;
  final match=RegExp(r'^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?|T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?Z)$').firstMatch(value);
  if (match == null) return null;
  final normalized='${match.group(1)}T${match.group(2) ?? match.group(4)}.${(match.group(3) ?? match.group(5) ?? '').padRight(6,'0')}Z';
  return validQuoteExpiry(normalized) ? normalized : null;
}

bool confirmedPrechargeReceipt(dynamic receipt, Map<String,dynamic> estimate) {
  if(receipt is! Map || receipt['id'] is! int || receipt['id']<1) return false;
  final accepted = _receiptInstant(receipt['accepted_at']);
  final until = _receiptInstant(receipt['valid_until']);
  return accepted != null && until != null &&
      until == estimate['expires_at'] && accepted.compareTo(until) < 0;
}

bool validPrechargeEstimate(dynamic value) {
  if (value is! Map || value['version'] != 1 || value['evidence'] != 'SIMULATED' || value['currency'] is! String || !RegExp(r'^[A-Z]{3}$').hasMatch(value['currency'] as String) || value['minor_units'] is! int || value['minor_units'] < 0 || value['minor_units'] > 3 || value['energy_wh'] is! int || value['energy_wh'] < 1 || value['energy_wh'] > 1000000 || value['tax_bps'] is! int || value['tax_bps'] < 0 || value['tax_bps'] > 10000 || value['external_fees_included'] != false || value['automatic_stop'] != false || value['final_total_depends_on_meter'] != true || value['session_fee_minor'] != '0' || value['idle_fee_minor'] != '0') return false;
  BigInt? amount(String name) {
    final text = value[name];
    return text is String && RegExp(r'^(0|[1-9][0-9]{0,15})$').hasMatch(text) ? BigInt.tryParse(text) : null;
  }
  final rate = amount('rate_minor_per_kwh'), subtotal = amount('subtotal_minor'), tax = amount('tax_minor'), total = amount('total_minor');
  final expiry = value['expires_at'];
  if (rate == null || subtotal == null || tax == null || total == null || rate > BigInt.from(1000000) || !validQuoteExpiry(expiry)) return false;
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
  bool busy = false, recorded = false, foreground = true;
  int generation = 0;
  late final bool Function() ownSession;
  StreamSubscription<int>? sessionSubscription;
  bool retired = false;
  bool get active => mounted && foreground && !retired && ownSession();
  bool get top => ModalRoute.of(context)?.isCurrent ?? true;
  void redact() {
    token = null; estimate = null; error = null;
    recorded = false; busy = false;
    energy.text = '10000';
  }
  @override
  void initState() {
    super.initState();
    ownSession = widget.api.sessionFence();
    final lifecycle=WidgetsBinding.instance.lifecycleState;
    foreground=lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    sessionSubscription = widget.api.sessionEvents.listen((_) {
      if (mounted && !ownSession()) {
        setState(() { retired = true; generation++; redact(); });
      }
    });
  }
  @override
  void didUpdateWidget(covariant PrechargePricingDialog oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api,widget.api) ||
        oldWidget.reservation != widget.reservation || !ownSession()) {
      generation++;
      retired = true;
      redact();
    }
  }
  @override
  void dispose() { generation++; unawaited(sessionSubscription?.cancel()); WidgetsBinding.instance.removeObserver(this); energy.dispose(); token = null; estimate = null; super.dispose(); }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    final resumed=state==AppLifecycleState.resumed;
    if (resumed == foreground) return;
    setState(() {
      foreground=resumed;
      if (!resumed) {
        generation++;
        redact();
      }
    });
    // No automatic private pricing POST merely because the OS resumed.
  }
  Future<void> load() async {
    if (!active || !top || busy) return;
    final amount = decimalMinor(energy.text,0,maximum:1000000);
    if (amount == null || amount < 1) {
      setState(() => error = const ApiException(422,'Invalid energy')); return;
    }
    final epoch = ++generation, api = widget.api, reservation = widget.reservation;
    bool current() => active && epoch == generation;
    setState(() { busy = true; estimate = null; token = null; error = null; recorded = false; });
    try {
      final reply = await api.request('reservations/$reservation/price-estimate',method:'POST',body:{'energy_wh':amount});
      if (!mounted || epoch != generation || !current()) return;
      if (reply is Map && reply['start_already_recorded'] == true) { setState(() => recorded = true); return; }
      if (reply is! Map || !validPrechargeEstimate(reply['estimate']) ||
          reply['estimate']['energy_wh'] != amount ||
          reply['quote_token'] is! String ||
          (reply['quote_token'] as String).isEmpty ||
          (reply['quote_token'] as String).length > 16384)
        throw const ApiException(503,'Invalid estimate');
      if (!freshPrechargeEstimate(Map<String,dynamic>.from(reply['estimate'] as Map)))
        throw const ApiException(409,'Price confirmation expired; request a fresh quote');
      setState(() { estimate = Map<String,dynamic>.from(reply['estimate'] as Map); token = reply['quote_token'] as String; });
    } catch (e) { if (mounted && epoch == generation && current()) setState(() => error = e); }
    finally { if (mounted && epoch == generation) setState(() => busy = false); }
  }
  Future<void> accept() async {
    if (!active || !top || busy) return;
    final confirmation = token;
    final reviewedEstimate = estimate;
    if (confirmation == null || reviewedEstimate == null) return;
    // Even a fully reviewed quote becomes invalid at its exact expiry.
    if (!freshPrechargeEstimate(reviewedEstimate)) {
      generation++;
      setState(() {
        token = null; estimate = null;
        error = const ApiException(409,'Price confirmation expired; request a fresh quote');
      });
      return;
    }
    final epoch = ++generation, api = widget.api, reservation = widget.reservation;
    bool current() => active && epoch == generation;
    setState(() { busy = true; error = null; });
    try {
      final receipt=await api.request('reservations/$reservation/price-consent',
          method:'POST',body:{'quote_token':confirmation,'accepted':true});
      if (!mounted || epoch != generation || !current() || !foreground) return;
      if (!confirmedPrechargeReceipt(receipt,reviewedEstimate)) {
        throw const ApiException(503,'Price consent not confirmed; check session history before retrying');
      }
      if (top) { Navigator.of(context).pop(true); }
      else { setState(() { token = null; estimate = null; error = const ApiException(409,'Review current price again'); }); }
    } catch (e) { if (mounted && epoch == generation && current()) setState(() { error = e; token = null; estimate = null; }); }
    finally { if (mounted && epoch == generation) setState(() => busy = false); }
  }
  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    final authorized=active;
    final rendered = generation, reviewed = estimate, reviewedToken = token;
    String tr(String en,String ar) => s.arabic ? ar : en;
    return AlertDialog(
      title:Text(tr('Review simulated charging price','مراجعة سعر محاكاة الشحن')),
      content:SingleChildScrollView(child:Column(mainAxisSize:MainAxisSize.min,children:[
        TextField(controller:energy,enabled:authorized && !busy,
          keyboardType:TextInputType.number,maxLength:7,
          decoration:InputDecoration(labelText:tr('Estimated energy (Wh)','الطاقة المتوقعة (Wh)')),
          onChanged:(_) { if (!active || busy) return; generation++; setState(() { token = null; estimate = null; error = null; }); }),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(s.t(errorKey(error!))),
        if (recorded) Text(tr('A session is already recorded. Refresh session history; do not submit another start.','تم تسجيل جلسة بالفعل. حدّث سجل الجلسات ولا ترسل طلب بدء آخر.')),
        if (estimate != null && authorized) Text(prechargeText(estimate!,s.arabic)),
      ])),
      actions:[
        TextButton(onPressed:() => Navigator.of(context).pop(false),child:Text(s.t('cancel'))),
        TextButton(onPressed:!authorized || busy ? null : load,
          child:Text(tr('Get estimate','عرض التقدير'))),
        FilledButton(onPressed:!authorized || busy || token == null ||
            estimate == null ? null : () {
              if (!ownSession() && mounted) {
                setState(() { retired = true; generation++; redact(); });
                return;
              }
              if (active && top && rendered == generation && identical(reviewed,estimate) && reviewedToken == token) accept();
            },
          child:Text(tr('Accept price','قبول السعر'))),
      ],
    );
  }
}
