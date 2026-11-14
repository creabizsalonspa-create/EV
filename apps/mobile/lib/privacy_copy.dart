import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'api.dart';

/// Explicit owner reauthentication; private data never leaves application memory.
class PrivacyCopyPage extends StatefulWidget {
  final EvApi api;
  final int requestId;
  final VoidCallback onExpired;
  const PrivacyCopyPage({
    super.key,
    required this.api,
    required this.requestId,
    required this.onExpired,
  });
  @override
  State<PrivacyCopyPage> createState() => _PrivacyCopyState();
}

class _PrivacyCopyState extends State<PrivacyCopyPage>
    with WidgetsBindingObserver {
  final password = TextEditingController(), code = TextEditingController();
  late bool Function() current;
  Timer? fence;
  String? copy, message;
  bool busy = false, hidden = false;
  int generation = 0;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    current = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    fence = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && !current() && !hidden) clear();
    });
  }

  @override
  void didUpdateWidget(covariant PrivacyCopyPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.requestId != widget.requestId) {
      clear();
      current = widget.api.sessionFence();
    }
  }

  void clear() {
    generation++;
    password.clear();
    code.clear();
    if (mounted)
      setState(() {
        copy = null;
        message = null;
        busy = false;
        hidden = true;
      });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) clear();
  }

  @override
  void dispose() {
    generation++;
    fence?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    password.dispose();
    code.dispose();
    super.dispose();
  }

  Future<void> run({bool discard = false}) async {
    if (busy || hidden || !current()) return;
    final epoch = generation;
    final body = discard
        ? <String, dynamic>{}
        : {'password': password.text, 'second_factor_code': code.text};
    password.clear();
    code.clear();
    setState(() {
      busy = true;
      copy = null;
      message = null;
    });
    try {
      final result = await widget.api.request(
        'account/privacy-requests/${widget.requestId}/access-copy${discard ? '/revoke' : ''}',
        method: 'POST',
        body: body,
      );
      if (!mounted || epoch != generation || !current()) return;
      if (discard) {
        setState(() {
          hidden = true;
          message = t('Private copy discarded.', 'تم إتلاف النسخة الخاصة.');
        });
        return;
      }
      if (result is! Map ||
          result['schema_version'] != 1 ||
          result['request_id'] != widget.requestId ||
          result['scope'] != 'self_service_account_copy' ||
          result['sections'] is! Map)
        throw const FormatException('Unverified personal copy');
      setState(() => copy = const JsonEncoder.withIndent('  ').convert(result));
    } catch (e) {
      if (!mounted || epoch != generation || !current()) return;
      setState(
        () => message = t(
          'Copy unavailable. Check your password/new authenticator code, then refresh request expiry.',
          'النسخة غير متاحة. تحقق من كلمة المرور ورمز المصادقة الجديد ثم حدّث صلاحية الطلب.',
        ),
      );
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(t('Private account copy', 'نسخة الحساب الخاصة')),
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          t(
            'This personal account copy excludes credentials, other people and internal evidence. Financial records are preserved. Private data clears when you leave the foreground; reopen from request history.',
            'تستثني النسخة بيانات الدخول والأشخاص الآخرين والأدلة الداخلية. تُحفظ السجلات المالية. تُمسح البيانات الخاصة عند مغادرة الواجهة؛ أعد الفتح من سجل الطلبات.',
          ),
        ),
        if (!hidden && current()) ...[
          TextField(
            controller: password,
            obscureText: true,
            enableSuggestions: false,
            autocorrect: false,
            decoration: InputDecoration(
              labelText: t('Current password', 'كلمة المرور الحالية'),
            ),
          ),
          TextField(
            controller: code,
            obscureText: true,
            enableSuggestions: false,
            autocorrect: false,
            maxLength: 64,
            decoration: InputDecoration(
              labelText: t(
                'Authenticator/recovery code if enabled',
                'رمز المصادقة أو الاسترداد إن فُعّل',
              ),
            ),
          ),
          TextButton(
            onPressed: busy ? null : () => run(),
            child: Text(t('View private copy', 'عرض النسخة الخاصة')),
          ),
          TextButton(
            onPressed: busy ? null : () => run(discard: true),
            child: Text(t('Discard private copy', 'إتلاف النسخة الخاصة')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (copy != null) SelectableText(copy!),
        ] else
          Text(
            t(
              'Private copy closed. Return to request history.',
              'أُغلقت النسخة الخاصة. ارجع إلى سجل الطلبات.',
            ),
          ),
        if (message != null) Text(message!),
      ],
    ),
  );
}
