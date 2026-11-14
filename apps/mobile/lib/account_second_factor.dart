import 'package:flutter/material.dart';
import 'api.dart';
import 'vendor_forms.dart';

class AccountSecondFactorView extends StatefulWidget {
  final EvApi api;
  final VoidCallback? onExpired;
  const AccountSecondFactorView({super.key, required this.api, this.onExpired});
  @override
  State<AccountSecondFactorView> createState() => _AccountSecondFactorState();
}
class _AccountSecondFactorState extends State<AccountSecondFactorView> with WidgetsBindingObserver {
  final password = TextEditingController(), code = TextEditingController();
  Map<String, dynamic>? status;
  String? seed, message;
  List<String> recovery = [];
  bool busy = false, background = false;
  int generation = 0;
  late bool Function() owner;
  String t(String en, String ar) => Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool current(int ticket) => mounted && owner() && !background && ticket == generation;
  @override
  void initState() { super.initState(); owner = widget.api.sessionFence(); WidgetsBinding.instance.addObserver(this); refresh(); }
  Map<String, dynamic> valid(dynamic data) {
    if (data is! Map || data['enabled'] is! bool || data['pending'] is! bool) throw const ApiException(502, 'Invalid authenticator status');
    return Map<String, dynamic>.from(data);
  }
  Future<void> act(Future<void> Function(int) task) async {
    if (busy || background || !owner()) return;
    final ticket = generation;
    setState(() { busy = true; message = null; });
    try { await task(ticket); }
    catch (error) {
      if (!current(ticket)) return;
      if (error is ApiException && [401, 419].contains(error.status)) { seed = null; recovery = []; password.clear(); code.clear(); widget.onExpired?.call(); }
      if (mounted) setState(() => message = t('Action not confirmed. Refresh and use your current password plus a fresh authenticator or unused recovery code.',
        'لم يتأكد الإجراء. حدّث الحالة واستخدم كلمة مرورك الحالية ورمز تحقق جديداً أو رمز استرداد غير مستخدم.'));
    } finally { if (current(ticket)) setState(() => busy = false); }
  }
  Future<void> refresh() => act((ticket) async {
    final data = valid(await widget.api.request('account/second-factor'));
    if (current(ticket)) setState(() { status = data; seed = null; recovery = []; });
  });
  Future<void> change(String action) async {
    if (busy || password.text.isEmpty || (action != 'prepare' && code.text.trim().isEmpty)) {
      if (!busy) setState(() => message = t('Enter your current password and the required code.', 'أدخل كلمة المرور الحالية والرمز المطلوب.')); return;
    }
    final body = <String, dynamic>{'password': password.text, if (action != 'prepare') 'code': vendorDigits(code.text.trim())};
    password.clear(); code.clear();
    await act((ticket) async {
      final result = await widget.api.request('account/second-factor/$action', method: 'POST', body: body);
      if (!current(ticket)) return;
      String? nextSeed;
      var nextRecovery = <String>[];
      if (action == 'prepare') {
        if (result is! Map || result['secret'] is! String || !RegExp(r'^[A-Z2-7]{32}$').hasMatch(result['secret'] as String)) throw const ApiException(502, 'Invalid setup');
        nextSeed = result['secret'] as String;
      } else {
        if (result is! Map || result['recovery_codes'] is! List) throw const ApiException(502, 'Invalid recovery response');
        nextRecovery = List<String>.from(result['recovery_codes'] as List);
      }
      if (result is Map && result['reauthentication_required'] == true) {
        setState(() { status = {'enabled': result['enabled'] == true, 'pending': false}; seed = nextSeed; recovery = nextRecovery;
          message = t('Save your codes, then sign in again before another action.', 'احفظ رموزك ثم سجّل الدخول مجدداً قبل أي إجراء آخر.'); }); return;
      }
      final data = valid(await widget.api.request('account/second-factor'));
      if (!current(ticket)) return;
      setState(() { status = data; seed = nextSeed; recovery = nextRecovery; });
    });
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      if (background) { setState(() { background = false; busy = false; }); refresh(); }
    } else {
      generation++; password.clear(); code.clear(); seed = null; recovery = []; status = null;
      if (mounted) setState(() { background = true; busy = false; });
    }
  }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('Authenticator two-factor authentication', 'المصادقة الثنائية بتطبيق التحقق'))),
    body: background || !owner() ? const SizedBox.shrink() : ListView(padding: const EdgeInsets.all(16), children: [
      if (busy) const LinearProgressIndicator(),
      if (message != null) Semantics(liveRegion: true, child: Text(message!)),
      OutlinedButton(onPressed: busy ? null : refresh, child: Text(t('Refresh authenticator status', 'تحديث حالة تطبيق التحقق'))),
      if (status != null) Text(status!['enabled'] == true ? t('Authenticator is enabled.', 'تطبيق التحقق مفعّل.') : t('Authenticator is not enabled.', 'تطبيق التحقق غير مفعّل.')),
      if (status?['enabled'] == true && status?['recovery_remaining'] is int) Text(t('Recovery codes remaining: ', 'رموز الاسترداد المتبقية: ') + '${status!['recovery_remaining']}'),
      if (seed != null) ...[
        Text(t('Add this secret to your authenticator, then confirm a fresh code. Never share it.', 'أضف هذا المفتاح إلى تطبيق التحقق ثم أكّد برمز جديد. لا تشاركه.')),
        SelectableText(seed!, key: const Key('factor-secret'), textDirection: TextDirection.ltr),
      ],
      if (recovery.isNotEmpty) ...[
        Text(t('Save these one-time recovery codes now. They cannot be shown again.', 'احفظ رموز الاسترداد الآن. كل رمز يستخدم مرة واحدة ولن يعرض مجدداً.')),
        SelectableText(recovery.join('\n'), key: const Key('factor-recovery'), textDirection: TextDirection.ltr),
      ],
      if (status != null) ...[
        TextField(key: const Key('factor-password'), controller: password, enabled: !busy, obscureText: true, autocorrect: false, enableSuggestions: false,
          maxLength: 128, decoration: InputDecoration(labelText: t('Current password', 'كلمة المرور الحالية'))),
        if (status!['pending'] == true || status!['enabled'] == true)
          TextField(key: const Key('factor-code'), controller: code, enabled: !busy, obscureText: true, autocorrect: false, enableSuggestions: false,
            maxLength: 128, decoration: InputDecoration(labelText: status!['enabled'] == true ? t('Fresh authenticator or unused recovery code', 'رمز تحقق جديد أو رمز استرداد غير مستخدم') : t('Fresh authenticator code', 'رمز تحقق جديد'))),
        if (status!['enabled'] != true) FilledButton(onPressed: busy ? null : () => change('prepare'), child: Text(t('Prepare authenticator setup', 'إعداد تطبيق التحقق'))),
        if (status!['pending'] == true && status!['enabled'] != true) FilledButton(onPressed: busy ? null : () => change('enable'), child: Text(t('Enable authenticator', 'تفعيل تطبيق التحقق'))),
        if (status!['enabled'] == true) ...[
          FilledButton(onPressed: busy ? null : () => change('recovery-codes'), child: Text(t('Replace recovery codes', 'استبدال رموز الاسترداد'))),
          OutlinedButton(onPressed: busy ? null : () => change('disable'), child: Text(t('Disable authenticator', 'إيقاف تطبيق التحقق'))),
        ],
      ],
    ]),
  );
  @override
  void dispose() { generation++; WidgetsBinding.instance.removeObserver(this); password.dispose(); code.dispose(); super.dispose(); }
}
