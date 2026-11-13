import 'dart:math';
import 'package:flutter/material.dart';
import 'api.dart';
import 'vendor_forms.dart';

class PhoneVerificationView extends StatefulWidget {
  final EvApi api;
  final VoidCallback? onExpired;
  const PhoneVerificationView({super.key, required this.api, this.onExpired});
  @override
  State<PhoneVerificationView> createState() => _PhoneVerificationState();
}

class _PhoneVerificationState extends State<PhoneVerificationView> with WidgetsBindingObserver {
  final phone = TextEditingController(), password = TextEditingController(), code = TextEditingController();
  Map<String, dynamic>? status, challenge;
  String? intentKey, intentPhone, message;
  bool busy = false, consent = false, background = false;
  late bool Function() ownerFence;
  int generation = 0;
  String t(String en, String ar) => Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    ownerFence = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }
  bool current(int version) => mounted && !background && ownerFence() && generation == version;
  void scrub() { phone.clear(); password.clear(); code.clear(); consent = false; intentKey = intentPhone = null; }
  Future<void> act(Future<void> Function(int version) task) async {
    if (busy || background || !ownerFence()) return;
    final version = generation;
    setState(() { busy = true; message = null; });
    try { await task(version); }
    catch (error) {
      if (!current(version)) return;
      if (error is ApiException && [401, 419].contains(error.status)) {
        scrub(); status = challenge = null; widget.onExpired?.call();
      }
      if (mounted) setState(() => message = t('Request not confirmed. Refresh before retrying; check credentials, code and limits.',
        'لم يتأكد الطلب. حدّث الحالة قبل المحاولة وتحقق من بياناتك والرمز وحدود الطلبات.'));
    } finally { if (current(version)) setState(() => busy = false); }
  }
  Future<void> read(int version) async {
    final data = await widget.api.request('account/phone');
    if (!current(version)) return;
    if (data is! Map || data['available'] is! bool || data['verified'] is! bool) throw const ApiException(502, 'Invalid verification status');
    setState(() {
      status = Map<String, dynamic>.from(data);
      challenge = data['challenge'] is Map ? Map<String, dynamic>.from(data['challenge'] as Map) : null;
    });
  }
  Future<void> load() => act(read);
  Future<void> start() async {
    final value = vendorDigits(phone.text.trim());
    if (busy) return;
    if (!consent || !RegExp(r'^\+[1-9][0-9]{7,14}$').hasMatch(value) || password.text.isEmpty) {
      setState(() => message = t('Enter an international phone number, your current password and SMS consent.', 'أدخل رقماً دولياً وكلمة مرورك الحالية ووافق على طلب الرسالة.')); return;
    }
    if (intentPhone != null && intentPhone != value) {
      setState(() => message = t('Reopen this screen before changing an uncertain request.', 'أعد فتح الشاشة قبل تغيير طلب غير مؤكد.')); return;
    }
    intentPhone = value;
    intentKey ??= List.generate(24, (_) => Random.secure().nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    final credential = password.text; password.clear();
    await act((version) async {
      final data = await widget.api.request('account/phone/challenges', method: 'POST', body: {
        'phone': intentPhone, 'password': credential, 'consent': true,
        'locale': Localizations.localeOf(context).languageCode == 'ar' ? 'ar' : 'en', 'idempotency_key': intentKey,
      });
      if (!current(version)) return;
      if (data is! Map || data['id'] is! String || !['pending','uncertain'].contains(data['status'])) throw const ApiException(502, 'Invalid challenge');
      setState(() { challenge = Map<String, dynamic>.from(data); intentKey = intentPhone = null; scrub(); });
    });
  }
  Future<void> confirm() async {
    final value = vendorDigits(code.text.trim()), id = challenge?['id'];
    if (busy || id is! String) return;
    if (!RegExp(r'^[0-9]{4,10}$').hasMatch(value)) {
      setState(() => message = t('Enter the 4–10 digit verification code.', 'أدخل رمز التحقق المكون من ٤ إلى ١٠ أرقام.')); return;
    }
    code.clear();
    await act((version) async {
      final data = await widget.api.request('account/phone/challenges/${Uri.encodeComponent(id)}/confirm', method: 'POST', body: {'code': value});
      if (!current(version)) return;
      if (data is! Map || data['verified'] is! bool) throw const ApiException(502, 'Invalid verification response');
      if (data['verified'] == true) { await read(version); }
      else setState(() { challenge = {...?challenge, 'status': data['status']};
        message = t('Phone has not been verified. Check your code or refresh status.', 'لم يتم تأكيد الرقم. تحقق من الرمز أو حدّث الحالة.'); });
    });
  }
  Future<void> remove() async {
    if (busy) return;
    if (password.text.isEmpty) { setState(() => message = t('Enter your current password.', 'أدخل كلمة المرور الحالية.')); return; }
    final credential = password.text; password.clear();
    await act((version) async { await widget.api.request('account/phone', method: 'DELETE', body: {'password': credential});
      if (!current(version)) return;
      scrub(); intentKey = intentPhone = null; await read(version); });
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      if (!background) return;
      setState(() { background = false; busy = false; }); load();
    } else {
      generation++; scrub(); status = challenge = null;
      if (mounted) setState(() { background = true; busy = false; });
    }
  }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('Verify your phone', 'التحقق من رقم الهاتف'))),
    body: background || !ownerFence() ? const SizedBox.shrink() : ListView(padding: const EdgeInsets.all(16), children: [
      Text(t('Confirms contact ownership; does not enable SMS sign-in or two-factor authentication.', 'يؤكد ملكية رقم الاتصال ولا يفعّل تسجيل الدخول بالرسائل أو المصادقة الثنائية.')),
      if (busy) const LinearProgressIndicator(),
      if (message != null) Semantics(liveRegion: true, child: Text(message!)),
      OutlinedButton(onPressed: busy ? null : load, child: Text(t('Refresh verification status', 'تحديث حالة التحقق'))),
      if (status?['verified'] == true) Text(t('Verified phone: ', 'رقم مؤكد: ') + '${status!['phone_mask']}'),
      if (status?['available'] == false) Text(t('SMS verification delivery is unavailable.', 'إرسال رسائل التحقق غير متاح.')),
      if (status?['available'] == true) ...[
        TextField(key: const Key('phone-number'), controller: phone, enabled: !busy, maxLength: 16, keyboardType: TextInputType.phone,
          textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: t('Phone (+country code)', 'الهاتف (+رمز الدولة)'))),
        CheckboxListTile(value: consent, onChanged: busy ? null : (value) => setState(() => consent = value!),
          title: Text(t('I request a verification SMS to this number.', 'أطلب إرسال رسالة تحقق إلى هذا الرقم.'))),
      ],
      if (status?['available'] == true || status?['verified'] == true)
        TextField(key: const Key('phone-password'), controller: password, enabled: !busy, obscureText: true, autocorrect: false, enableSuggestions: false,
          maxLength: 128, decoration: InputDecoration(labelText: t('Current password', 'كلمة المرور الحالية'))),
      if (status?['available'] == true) FilledButton(onPressed: busy || !consent ? null : start, child: Text(t('Request verification SMS', 'طلب رسالة تحقق'))),
      if (challenge?['status'] == 'uncertain') Text(t('Request outcome is uncertain. No automatic resend will occur.', 'نتيجة الطلب غير مؤكدة. لن يعاد الإرسال تلقائياً.')),
      if (status?['available'] == true && challenge?['status'] == 'pending') ...[
        TextField(key: const Key('phone-code'), controller: code, enabled: !busy, keyboardType: TextInputType.number, maxLength: 10,
          autofillHints: const [AutofillHints.oneTimeCode], decoration: InputDecoration(labelText: t('SMS verification code', 'رمز التحقق من الرسالة'))),
        FilledButton(onPressed: busy ? null : confirm, child: Text(t('Confirm phone', 'تأكيد رقم الهاتف'))),
      ],
      if (status?['verified'] == true) OutlinedButton(onPressed: busy ? null : remove, child: Text(t('Remove saved phone', 'إزالة الرقم المحفوظ'))),
    ]),
  );
  @override
  void dispose() { generation++; WidgetsBinding.instance.removeObserver(this); phone.dispose(); password.dispose(); code.dispose(); super.dispose(); }
}
