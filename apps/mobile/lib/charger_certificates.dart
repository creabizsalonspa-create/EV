import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'api.dart';
import 'financial_operation_store.dart';

/// Reuses encrypted durable request identities; this creates no financial record.
class ChargerCertificatesPage extends StatefulWidget {
  final EvApi api;
  final String endpoint;
  final int profileId;
  final VoidCallback onExpired;
  final FinancialOperationStore? store;
  const ChargerCertificatesPage({
    super.key,
    required this.api,
    required this.endpoint,
    required this.profileId,
    required this.onExpired,
    this.store,
  });
  @override
  State<ChargerCertificatesPage> createState() => _CertificatesState();
}

class _CertificatesState extends State<ChargerCertificatesPage>
    with WidgetsBindingObserver {
  late final bool Function() current;
  final pem = TextEditingController(),
      reason = TextEditingController(),
      issuers = TextEditingController();
  Map<String, dynamic>? data;
  FinancialOperation? operation;
  int? actor;
  bool busy = false, consent = false;
  int generation = 0;
  String message = '';
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool get pending => operation?.pending != null;
  @override
  void initState() {
    super.initState();
    current = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    pem.dispose();
    issuers.dispose();
    reason.dispose();
    operation = null;
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      generation++;
      pem.clear();
      issuers.clear();
      reason.clear();
      setState(() {
        data = null;
        operation = null;
        actor = null;
        consent = false;
        busy = false;
        message = '';
      });
    }
  }

  void failed(Object error) {
    if (!mounted || !current()) return;
    setState(
      () => message = t(
        'Operation unavailable or unconfirmed. Retry the saved request or explicitly resolve/cancel it before another action.',
        'العملية غير متاحة أو غير مؤكدة. أعد الطلب المحفوظ أو تحقق منه وألغه قبل إجراء آخر.',
      ),
    );
    if (error is ApiException && [401, 419].contains(error.status))
      widget.onExpired();
  }

  Future<void> load({int page = 1}) async {
    if (!current()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      operation = null;
      message = '';
    });
    try {
      final me = await widget.api.request('me');
      final result = await widget.api.request(
        widget.endpoint,
        query: {'page': '$page'},
      );
      if (!mounted || !current() || epoch != generation) return;
      if (result['schema_version'] != 1 ||
          result['profile_id'] != widget.profileId ||
          result['credential_version'] is! int ||
          result['device_commands_sent'] != false ||
          result['connection_close_requested'] != false ||
          result['gateway_recheck_may_disconnect'] != true)
        throw const FormatException('Unverified certificate workspace');
      FinancialOperation? op;
      if (result['can_manage'] == true) {
        if (!['vendor', 'admin'].contains(me['role']) || me['id'] is! int)
          throw const FormatException('Invalid certificate actor');
        op = FinancialOperation(
          widget.store ?? DeviceFinancialOperationStore(),
          '${widget.api.base.origin}:${me['id']}:charger-certificates:${widget.profileId}',
          isCurrent: current,
        );
        await op.restore();
        if (!mounted || !current() || epoch != generation) return;
        final saved = op.pending?['body'];
        if (saved != null &&
            (saved['expected_actor_id'] != me['id'] ||
                saved['expected_profile_id'] != widget.profileId ||
                !['enroll', 'revoke'].contains(saved['client_action'])))
          throw const FormatException('Saved certificate scope changed');
      }
      setState(() {
        data = Map<String, dynamic>.from(result);
        actor = me['id'];
        operation = op;
      });
    } catch (e) {
      if (epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation)
        setState(() {
          busy = false;
          if (!current()) data = null;
        });
    }
  }

  void checked(
    dynamic result,
    Map<String, dynamic> body,
    String key, {
    bool resolving = false,
  }) {
    if (result['actor_id'] != actor ||
        result['profile_id'] != widget.profileId ||
        result['request_key'] != key ||
        result['action'] != body['client_action'] ||
        result['device_commands_sent'] != false ||
        result['connection_close_requested'] != false ||
        result['gateway_recheck_may_disconnect'] != true ||
        result['result_version'] is! int)
      throw const FormatException('Unverified operation receipt');
    if (result['state'] == 'cancelled') {
      if (!resolving) throw const FormatException('Unexpected cancellation');
      return;
    }
    if (result['state'] != 'completed' ||
        result['certificate']?['profile_id'] != widget.profileId ||
        result['certificate_id'] != result['certificate']?['id'])
      throw const FormatException('Unverified certificate receipt');
    if (body['client_action'] == 'revoke' &&
        result['certificate_id'] != body['certificate_id'])
      throw const FormatException('Wrong certificate');
    if (body['client_action'] == 'enroll') {
      final der = base64Decode(
        (body['public_certificate'] as String)
            .replaceAll('-----BEGIN CERTIFICATE-----', '')
            .replaceAll('-----END CERTIFICATE-----', '')
            .replaceAll(RegExp(r'\s'), ''),
      );
      if (result['certificate']['fingerprint'] !=
          sha256.convert(der).toString())
        throw const FormatException('Wrong certificate bytes');
    }
  }

  Future<void> change(String action, {int? certificateId}) async {
    if (busy || !current() || operation == null || data == null) return;
    if (!pending && (!consent || reason.text.trim().length < 10)) return;
    if (!pending &&
        action == 'enroll' &&
        (!pem.text.trim().startsWith('-----BEGIN CERTIFICATE-----') ||
            pem.text.contains('PRIVATE KEY'))) {
      pem.clear();
      issuers.clear();
      failed(const FormatException('Public certificate only'));
      return;
    }
    if (!pending && issuers.text.contains('PRIVATE KEY')) {
      issuers.clear();
      failed(const FormatException('Public issuer certificates only'));
      return;
    }
    final epoch = generation;
    setState(() {
      busy = true;
      message = '';
    });
    try {
      final old = operation!.pending?['body'];
      final body = await operation!.prepare(
        old == null
            ? {
                'client_action': action,
                'expected_actor_id': actor,
                'expected_profile_id': widget.profileId,
                'expected_version': data!['credential_version'],
                'reason': reason.text.trim(),
                'consent': true,
                if (action == 'enroll') 'public_certificate': pem.text.trim(),
                if (action == 'enroll' && issuers.text.trim().isNotEmpty)
                  'issuer_chain': issuers.text.trim(),
                if (action == 'revoke') 'certificate_id': certificateId,
              }
            : Map<String, dynamic>.from(old),
      );
      if (!mounted || !current() || epoch != generation) return;
      final wire = Map<String, dynamic>.from(body)..remove('client_action');
      final result = await widget.api.request(
        '${widget.endpoint}/${body['client_action']}',
        method: 'POST',
        body: wire,
      );
      if (!mounted || !current() || epoch != generation) return;
      checked(result, body, body['idempotency_key']);
      await operation!.confirmed();
      if (!mounted || !current() || epoch != generation) return;
      pem.clear();
      issuers.clear();
      reason.clear();
      consent = false;
      await load();
    } catch (e) {
      if (epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> resolve() async {
    if (busy || !current() || !pending) return;
    final epoch = generation;
    setState(() => busy = true);
    try {
      final saved = operation!.pending!,
          body = Map<String, dynamic>.from(saved['body']);
      final result = await widget.api.request(
        '${widget.endpoint}/resolve',
        method: 'POST',
        body: {
          'idempotency_key': saved['key'],
          'expected_actor_id': actor,
          'expected_profile_id': widget.profileId,
          'expected_action': body['client_action'],
          'cancel_unissued': true,
        },
      );
      if (!mounted || !current() || epoch != generation) return;
      checked(result, body, saved['key'], resolving: true);
      await operation!.confirmed();
      if (!mounted || !current() || epoch != generation) return;
      await load();
    } catch (e) {
      if (epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  String state(dynamic value) => switch (value) {
    'current' => t('Current', 'حالية'),
    'expiring' => t('Expiring soon', 'تنتهي قريباً'),
    'expired' => t('Expired', 'منتهية'),
    'revoked' => t('Revoked locally', 'مبطلة محلياً'),
    'superseded' => t('Superseded locally', 'استُبدلت محلياً'),
    'registered' => t('Registered', 'مسجلة'),
    _ => t('Trust requires review', 'الثقة تتطلب مراجعة'),
  };
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('Charger certificates', 'شهادات الشاحن'))),
    body: !current()
        ? Text(t('Sign in again.', 'سجّل الدخول مجددًا.'))
        : ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(
                t(
                  'Public client certificates only. Enrollment invalidates handshake credentials; the gateway may disconnect at its next policy recheck. No certificate installation or charging-stop command is sent. Local revocation does not publish a CA CRL/OCSP response.',
                  'شهادات عميل عامة فقط. يُبطل التسجيل بيانات الاتصال وقد تقطع البوابة الاتصال عند إعادة التحقق. لا يُرسل أمر تثبيت أو إيقاف شحن. لا ينشر الإبطال المحلي استجابة إبطال من جهة الإصدار.',
                ),
              ),
              TextButton(
                onPressed: busy ? null : () => load(),
                child: Text(t('Refresh certificates', 'تحديث الشهادات')),
              ),
              if (busy) const LinearProgressIndicator(),
              if (message.isNotEmpty) Text(message),
              if (pending) ...[
                Text(
                  t(
                    'Saved certificate operation needs resolution. No automatic resubmission.',
                    'تحتاج عملية الشهادة المحفوظة إلى التحقق. لا إعادة إرسال تلقائية.',
                  ),
                ),
                TextButton(
                  onPressed: busy
                      ? null
                      : () => change(
                          operation!.pending!['body']['client_action'],
                        ),
                  child: Text(t('Retry saved request', 'إعادة الطلب المحفوظ')),
                ),
                TextButton(
                  onPressed: busy ? null : resolve,
                  child: Text(
                    t(
                      'Resolve result or cancel unissued request',
                      'التحقق من النتيجة أو إلغاء الطلب غير المنفذ',
                    ),
                  ),
                ),
              ],
              if (data != null) ...[
                Text('${data!['identity']} · ${data!['credential_version']}'),
                if (data!['can_manage'] == true && !pending) ...[
                  TextField(
                    controller: reason,
                    maxLength: 1000,
                    decoration: InputDecoration(
                      labelText: t(
                        'Certificate operation reason',
                        'سبب عملية الشهادة',
                      ),
                    ),
                  ),
                  CheckboxListTile(
                    value: consent,
                    onChanged: busy
                        ? null
                        : (value) => setState(() => consent = value == true),
                    title: Text(
                      t(
                        'I confirm the local admission change and credential invalidation.',
                        'أؤكد تغيير سياسة الاتصال المحلية وإبطال بيانات الاتصال.',
                      ),
                    ),
                  ),
                  if (data!['enrollment_enabled'] == true) ...[
                    TextField(
                      controller: pem,
                      maxLength: 16384,
                      maxLines: 5,
                      autocorrect: false,
                      enableSuggestions: false,
                      decoration: InputDecoration(
                        labelText: t(
                          'Public PEM client certificate',
                          'شهادة العميل العامة بصيغة PEM',
                        ),
                      ),
                    ),
                    TextField(
                      controller: issuers,
                      maxLength: 49152,
                      maxLines: 4,
                      autocorrect: false,
                      enableSuggestions: false,
                      decoration: InputDecoration(
                        labelText: t(
                          'Intermediate issuer certificates (optional)',
                          'شهادات جهات الإصدار الوسيطة (اختيارية)',
                        ),
                      ),
                    ),
                    Text(
                      t(
                        'Submitted issuers must link to a server-pinned root; they do not add trust. Issuer blocks are checked locally without contacting CA revocation services.',
                        'يجب أن ترتبط جهات الإصدار المرسلة بجذر موثوق مهيأ على الخادم؛ لا تضيف ثقة جديدة. تُطبق سياسة الحظر محلياً دون الاتصال بخدمات إبطال جهة الإصدار.',
                      ),
                    ),
                    FilledButton(
                      onPressed: busy || !consent
                          ? null
                          : () => change('enroll'),
                      child: Text(
                        t(
                          'Enroll or renew public certificate',
                          'تسجيل الشهادة العامة أو تجديدها',
                        ),
                      ),
                    ),
                  ],
                ],
                for (final cert in data!['data'] as List)
                  Card(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${cert['identity']} · ${state(cert['state'])}'),
                        Text('${cert['fingerprint']}'),
                        Text(
                          '${t('Issuer', 'جهة الإصدار')}: ${cert['issuer_fingerprint']}',
                        ),
                        Text(
                          '${t('Pinned root', 'الجذر الموثوق')}: ${cert['trust_anchor_fingerprint'] ?? cert['issuer_fingerprint']}',
                        ),
                        Text(
                          '${t('Intermediate certificates', 'الشهادات الوسيطة')}: ${cert['chain_length'] ?? 0}',
                        ),
                        Text(
                          '${cert['not_before']} → ${cert['not_after']} UTC',
                        ),
                        if (data!['can_manage'] == true &&
                            !pending &&
                            cert['state'] != 'revoked')
                          TextButton(
                            onPressed: busy || !consent
                                ? null
                                : () => change(
                                    'revoke',
                                    certificateId: cert['id'],
                                  ),
                            child: Text(
                              t(
                                'Revoke certificate locally',
                                'إبطال الشهادة محلياً',
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                if (data!['current_page'] > 1)
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => load(page: data!['current_page'] - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                if (data!['current_page'] < data!['last_page'])
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => load(page: data!['current_page'] + 1),
                    child: Text(t('Next', 'التالي')),
                  ),
              ],
            ],
          ),
  );
}
