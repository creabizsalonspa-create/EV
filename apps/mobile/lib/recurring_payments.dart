import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'api.dart';
import 'strings.dart';
import 'invoice_history.dart';
import 'money_input.dart';

const recurringEn = {
  'active': 'Active',
  'revoked': 'Revoked',
  'pending': 'Pending',
  'prepared': 'Prepared',
  'executing': 'Executing',
  'uncertain': 'Uncertain',
  'completed': 'Completed',
  'expired': 'Expired',
  'cancelled': 'Cancelled',
  'collected': 'Collected',
  'action_required': 'Action required',
  'retry_scheduled': 'Retry scheduled',
  'permanent_failure': 'Permanent failure',
  'payment_method_required': 'Payment method required',
  'recovery_required': 'Recovery required',
  'past_due': 'Past due',
  'insufficient_funds': 'Insufficient funds',
  'authentication_required': 'Authentication required',
  'provider_or_state_unresolved': 'Provider or state unresolved',
  'consent_unavailable': 'Consent unavailable',
  'provider_consent_verified': 'Provider consent verified',
  'consent_recorded': 'Consent recorded',
  'default_selected': 'Default selected',
  'recurring_consented': 'Recurring consented',
  'recurring_disabled': 'Recurring disabled',
  'provider_callback_verified': 'Provider callback verified',

  'paid': 'Paid',
  'unpaid': 'Unpaid',
  'receipt': 'Payment receipt',
  'credit': 'Credit note',
  'title': 'Saved payment authorizations',
  'notice':
      'Test provider only. Card entry is hosted by the provider. Revocation stops future collection; uncertain payments still require reconciliation.',
  'methods': 'Payment methods',
  'enrollments': 'Enrollments',
  'subscriptions': 'Renewal collection',
  'events': 'Consent history',
  'empty': 'No records',
  'refresh': 'Refresh status',
  'next': 'Next',
  'previous': 'Previous',
  'consent':
      'I consent to saving this provider payment authorization for future subscription collection.',
  'add': 'Add with hosted provider',
  'continue': 'Continue securely with provider',
  'unavailable':
      'Hosted provider enrollment is not configured. Existing consent can still be revoked.',
  'default': 'Default method',
  'setDefault': 'Set default',
  'revoke': 'Revoke authorization',
  'remove': 'Remove from provider',
  'inspect': 'Inspect hosted enrollment',
  'expiry': 'Consent expires',
  'enable': 'Authorize automatic collection',
  'disable': 'Disable automatic collection',
  'select': 'Choose verified payment method',
  'recurringConsent': 'I authorize recurring collection for this subscription.',
  'authenticate': 'Authenticate existing payment',
  'unsupported':
      'No hosted action is available. Refresh or contact support; do not start another payment.',
  'enabled': 'Automatic collection enabled',
  'disabled': 'Automatic collection disabled',
  'history': 'Invoice and payment history',
  'cancel': 'Cancel',
  'confirm': 'Confirm',
  'unknown':
      'Request outcome may be uncertain. Inspect the existing enrollment or payment before starting another.',
};
const recurringAr = {
  'active': 'نشط',
  'revoked': 'ملغى',
  'pending': 'قيد الانتظار',
  'prepared': 'مُعدّ',
  'executing': 'قيد التنفيذ',
  'uncertain': 'غير مؤكد',
  'completed': 'مكتمل',
  'expired': 'منتهي',
  'cancelled': 'ملغى',
  'collected': 'محصّل',
  'action_required': 'إجراء مطلوب',
  'retry_scheduled': 'إعادة المحاولة مجدولة',
  'permanent_failure': 'تعذر التحصيل',
  'payment_method_required': 'وسيلة دفع مطلوبة',
  'recovery_required': 'مطابقة مطلوبة',
  'past_due': 'متأخر',
  'insufficient_funds': 'رصيد غير كافٍ',
  'authentication_required': 'التوثيق مطلوب',
  'provider_or_state_unresolved': 'نتيجة المزود غير مؤكدة',
  'consent_unavailable': 'التفويض غير متاح',
  'provider_consent_verified': 'تم التحقق من التفويض لدى المزود',
  'consent_recorded': 'تم تسجيل الموافقة',
  'default_selected': 'تم اختيار الوسيلة الافتراضية',
  'recurring_consented': 'تم تفويض التحصيل المتكرر',
  'recurring_disabled': 'تم تعطيل التحصيل المتكرر',
  'provider_callback_verified': 'تم التحقق من إشعار المزود',

  'paid': 'مدفوعة',
  'unpaid': 'غير مدفوعة',
  'receipt': 'إيصال دفع',
  'credit': 'إشعار دائن',
  'title': 'تفويضات الدفع المحفوظة',
  'notice':
      'مزود اختبار فقط. تُدخل بيانات البطاقة على موقع المزود. إلغاء التفويض يوقف التحصيل المستقبلي؛ تبقى الدفعات غير المؤكدة بحاجة للمطابقة.',
  'methods': 'وسائل الدفع',
  'enrollments': 'التسجيلات',
  'subscriptions': 'تحصيل التجديد',
  'events': 'سجل التفويض',
  'empty': 'لا توجد سجلات',
  'refresh': 'تحديث الحالة',
  'next': 'التالي',
  'previous': 'السابق',
  'consent': 'أوافق على حفظ تفويض الدفع لدى المزود لتحصيل الاشتراكات مستقبلاً.',
  'add': 'إضافة عبر موقع المزود',
  'continue': 'المتابعة الآمنة مع المزود',
  'unavailable': 'تسجيل المزود غير مهيأ. يمكنك إلغاء التفويض المحفوظ.',
  'default': 'وسيلة افتراضية',
  'setDefault': 'تعيين افتراضية',
  'revoke': 'إلغاء التفويض',
  'remove': 'إزالة من المزود',
  'inspect': 'فحص التسجيل لدى المزود',
  'expiry': 'انتهاء التفويض',
  'enable': 'تفويض التحصيل التلقائي',
  'disable': 'تعطيل التحصيل التلقائي',
  'select': 'اختيار وسيلة دفع متحققة',
  'recurringConsent': 'أفوض التحصيل المتكرر لهذا الاشتراك.',
  'authenticate': 'توثيق الدفعة الحالية',
  'unsupported':
      'لا يتوفر إجراء لدى المزود. حدّث الحالة أو اتصل بالدعم؛ لا تبدأ دفعة أخرى.',
  'enabled': 'التحصيل التلقائي مفعّل',
  'disabled': 'التحصيل التلقائي معطّل',
  'history': 'سجل الفواتير والمدفوعات',
  'cancel': 'إلغاء',
  'confirm': 'تأكيد',
  'unknown':
      'قد تكون نتيجة الطلب غير مؤكدة. افحص التسجيل أو الدفعة الحالية قبل بدء أخرى.',
};

bool trustedPaymentUrl(Uri u) =>
    u.scheme == 'https' &&
    ['checkout.stripe.com', 'hooks.stripe.com'].contains(u.host) &&
    u.userInfo.isEmpty &&
    (!u.hasPort || u.port == 443);

class RecurringPaymentsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final Future<bool> Function(Uri)? openProvider;
  const RecurringPaymentsView({
    super.key,
    required this.api,
    required this.onExpired,
    this.openProvider,
  });
  @override
  State<RecurringPaymentsView> createState() => _RecurringState();
}

class _RecurringState extends State<RecurringPaymentsView> {
  String kind = 'methods';
  int page = 1, generation = 0;
  bool busy = false, consent = false, uncertain = false;
  Object? error;
  Map<String, dynamic>? result;
  Uri? hosted;
  String? enrollmentKey;
  String t(String k) =>
      (Localizations.localeOf(context).languageCode == 'ar'
          ? recurringAr
          : recurringEn)[k] ??
      k;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  void fail(Object e) {
    error = e;
    result = null;
    hosted = null;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      result = null;
      hosted = null;
    });
    try {
      final endpoint = {
        'methods': 'payment-methods',
        'enrollments': 'payment-enrollments',
        'subscriptions': 'recurring-subscriptions',
        'events': 'recurring-events',
      }[kind]!;
      final r = Map<String, dynamic>.from(
        await widget.api.request(
              'commercial/$endpoint',
              query: {'page': '$page'},
            )
            as Map,
      );
      if (mounted && epoch == generation) setState(() => result = r);
    } catch (e) {
      if (mounted && epoch == generation) setState(() => fail(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  void actionUrl(dynamic value) {
    if (value == null) return;
    final u = Uri.tryParse(value as String);
    if (u == null || !trustedPaymentUrl(u))
      throw const ApiException(502, 'Untrusted provider URL');
    hosted = u;
  }

  Future<void> mutate(
    String path, {
    String method = 'POST',
    Map<String, dynamic>? body,
    bool enrollment = false,
  }) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      hosted = null;
    });
    try {
      final r = Map<String, dynamic>.from(
        await widget.api.request(path, method: method, body: body ?? {}) as Map,
      );
      if (!mounted || epoch != generation) return;
      Uri? next;
      actionUrl(r['hosted_url']);
      next = hosted;
      if (enrollment) {
        enrollmentKey = null;
        kind = 'enrollments';
        page = 1;
        consent = false;
      }
      await load();
      if (mounted)
        setState(() {
          hosted = next;
          uncertain = false;
        });
    } catch (e) {
      if (mounted && epoch == generation)
        setState(() {
          fail(e);
          uncertain = true;
        });
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> enable(Map<String, dynamic> sub) async {
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      final m = Map<String, dynamic>.from(
        await widget.api.request('commercial/payment-methods') as Map,
      );
      if (!mounted || epoch != generation) return;
      final methods = (m['methods']['data'] as List)
          .cast<Map>()
          .where((r) => r['status'] == 'active' && r['usable'] != false)
          .toList();
      if (methods.isEmpty || m['collection_available'] != true)
        throw ApiException(409, t('unavailable'));
      int selected = methods.first['id'] as int;
      bool accepted = false;
      final yes = await showDialog<bool>(
        context: context,
        builder: (context) => StatefulBuilder(
          builder: (context, update) => AlertDialog(
            title: Text(t('enable')),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                DropdownButtonFormField<int>(
                  initialValue: selected,
                  decoration: InputDecoration(labelText: t('select')),
                  items: methods
                      .map(
                        (r) => DropdownMenuItem(
                          value: r['id'] as int,
                          child: Text('${r['brand']} •••• ${r['last_four']}'),
                        ),
                      )
                      .toList(),
                  onChanged: (v) => update(() => selected = v!),
                ),
                CheckboxListTile(
                  value: accepted,
                  title: Text(t('recurringConsent')),
                  onChanged: (v) => update(() => accepted = v == true),
                ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: Text(t('cancel')),
              ),
              FilledButton(
                onPressed: accepted ? () => Navigator.pop(context, true) : null,
                child: Text(t('confirm')),
              ),
            ],
          ),
        ),
      );
      if (!mounted || epoch != generation || yes != true) return;
      await mutate(
        'commercial/subscriptions/${sub['id']}/collection-policy',
        method: 'PUT',
        body: {
          'enabled': true,
          'mandate_id': selected,
          'consent': true,
          'expected_version': sub['collection_policy']?['version'] ?? 0,
        },
      );
    } catch (e) {
      if (mounted && epoch == generation) setState(() => fail(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget btn(String key, VoidCallback fn) =>
      TextButton(onPressed: busy ? null : fn, child: Text(t(key)));
  @override
  Widget build(BuildContext context) {
    final data = result == null
        ? null
        : kind == 'methods'
        ? result!['methods'] as Map
        : result!;
    final rows = (data?['data'] as List? ?? []).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(t('title'))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(t('notice')),
            DropdownButton<String>(
              value: kind,
              isExpanded: true,
              items: ['methods', 'enrollments', 'subscriptions', 'events']
                  .map((k) => DropdownMenuItem(value: k, child: Text(t(k))))
                  .toList(),
              onChanged: busy
                  ? null
                  : (v) {
                      kind = v!;
                      page = 1;
                      load();
                    },
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null)
              Text(
                EvStrings.of(context).t(errorKey(error!)),
                key: const Key('recurring-error'),
              ),
            if (uncertain) Text(t('unknown')),
            if (hosted != null)
              btn('continue', () async {
                try {
                  final ok =
                      await (widget.openProvider?.call(hosted!) ??
                          launchUrl(
                            hosted!,
                            mode: LaunchMode.externalApplication,
                          ));
                  if (!ok && mounted)
                    setState(() => error = ApiException(503, t('unsupported')));
                } catch (e) {
                  if (mounted) setState(() => error = e);
                }
              }),
            if (result != null && kind == 'methods') ...[
              if (result!['hosted_available'] == true) ...[
                CheckboxListTile(
                  value: consent,
                  title: Text(t('consent')),
                  onChanged: busy
                      ? null
                      : (v) => setState(() => consent = v == true),
                ),
                FilledButton(
                  onPressed: !busy && consent
                      ? () {
                          enrollmentKey ??=
                              'native-${DateTime.now().microsecondsSinceEpoch}';
                          mutate(
                            'commercial/payment-enrollments',
                            body: {
                              'consent': true,
                              'consent_version': result!['consent_version'],
                              'idempotency_key': enrollmentKey,
                            },
                            enrollment: true,
                          );
                        }
                      : null,
                  child: Text(t('add')),
                ),
              ] else
                Text(t('unavailable')),
            ],
            if (result != null && rows.isEmpty) Text(t('empty')),
            for (final row in rows)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (kind == 'methods') ...[
                        Text(
                          '${row['brand']} •••• ${row['last_four']}',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        Text('${row['exp_month']}/${row['exp_year']}'),
                        Text(t('${row['status']}')),
                        Text('${t('expiry')}: ${row['consent_expires_at']}'),
                        if (row['id'] == result!['default_method_id'])
                          Text(t('default')),
                        if (row['status'] == 'active')
                          Wrap(
                            children: [
                              btn(
                                'setDefault',
                                () => mutate(
                                  'commercial/payment-methods/${row['id']}/default',
                                  body: {
                                    'expected_version': result!['version'],
                                  },
                                ),
                              ),
                              btn(
                                'revoke',
                                () => mutate(
                                  'commercial/payment-methods/${row['id']}',
                                  method: 'DELETE',
                                  body: {'expected_version': row['version']},
                                ),
                              ),
                            ],
                          )
                        else if (row['detach_status'] != 'detached')
                          btn(
                            'remove',
                            () => mutate(
                              'commercial/payment-methods/${row['id']}/detach',
                            ),
                          ),
                      ] else if (kind == 'enrollments') ...[
                        Text('#${row['id']} · ${row['status']}'),
                        Text('${row['expires_at']}'),
                        btn(
                          'inspect',
                          () => mutate(
                            'commercial/payment-enrollments/${row['id']}/inspect',
                          ),
                        ),
                      ] else if (kind == 'subscriptions') ...[
                        btn(
                          'history',
                          () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => SubscriptionPaymentHistory(
                                api: widget.api,
                                subscription: row['id'] as int,
                                onExpired: widget.onExpired,
                              ),
                            ),
                          ),
                        ),
                        Text('#${row['id']} · ${row['status']}'),
                        Text(
                          t(
                            row['collection_policy']?['enabled'] == true ||
                                    row['collection_policy']?['enabled'] == 1
                                ? 'enabled'
                                : 'disabled',
                          ),
                        ),
                        if (row['latest_collection'] != null) ...[
                          Text(t('${row['latest_collection']['state']}')),
                          Text(
                            '${row['latest_collection']['failure_code'] ?? ''}',
                          ),
                          Text(
                            '${row['latest_collection']['next_attempt_at'] ?? ''}',
                          ),
                        ],
                        if (row['collection_policy']?['enabled'] == true ||
                            row['collection_policy']?['enabled'] == 1)
                          btn(
                            'disable',
                            () => mutate(
                              'commercial/subscriptions/${row['id']}/collection-policy',
                              method: 'PUT',
                              body: {
                                'enabled': false,
                                'expected_version':
                                    row['collection_policy']['version'],
                              },
                            ),
                          )
                        else if (row['status'] != 'cancelled')
                          btn(
                            'enable',
                            () => enable(Map<String, dynamic>.from(row)),
                          ),
                        if (row['latest_collection']?['state'] ==
                            'action_required')
                          btn(
                            'authenticate',
                            () => mutate(
                              'commercial/subscription-checkouts/${row['latest_collection']['checkout_id']}/authentication',
                            ),
                          ),
                      ] else ...[
                        Text('${row['subject']}'),
                        Text(t('${row['kind']}')),
                        Text('${row['created_at']}'),
                      ],
                    ],
                  ),
                ),
              ),
            Wrap(
              children: [
                if (page > 1)
                  btn('previous', () {
                    page--;
                    load();
                  }),
                if (data != null && page < (data['last_page'] as int))
                  btn('next', () {
                    page++;
                    load();
                  }),
                btn('refresh', load),
              ],
            ),
            btn(
              'history',
              () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => InvoiceHistoryView(
                    api: widget.api,
                    onExpired: widget.onExpired,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Displays immutable invoices, receipts and credits from the canonical ledger.
class SubscriptionPaymentHistory extends StatefulWidget {
  final EvApi api;
  final int subscription;
  final VoidCallback onExpired;
  const SubscriptionPaymentHistory({
    super.key,
    required this.api,
    required this.subscription,
    required this.onExpired,
  });
  @override
  State<SubscriptionPaymentHistory> createState() =>
      _SubscriptionHistoryState();
}

class _SubscriptionHistoryState extends State<SubscriptionPaymentHistory> {
  Map<String, dynamic>? data;
  Object? error;
  bool busy = false;
  int generation = 0;
  String t(String k) =>
      (Localizations.localeOf(context).languageCode == 'ar'
          ? recurringAr
          : recurringEn)[k] ??
      k;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final r = Map<String, dynamic>.from(
        await widget.api.request(
              'commercial/subscriptions/${widget.subscription}',
            )
            as Map,
      );
      for (final i in r['invoices'] as List) {
        if (i['total_minor'] is! int ||
            i['minor_units'] is! int ||
            i['minor_units'] < 0 ||
            i['minor_units'] > 3 ||
            i['total_minor'] < 0)
          throw const ApiException(502, 'Unsupported monetary precision');
      }
      for (final row in [...r['receipts'] as List, ...r['credits'] as List]) {
        if (row['amount_minor'] is! int || row['amount_minor'] < 0)
          throw const ApiException(502, 'Unsupported monetary precision');
      }
      if (mounted && epoch == generation) setState(() => data = r);
    } catch (e) {
      if (mounted && epoch == generation) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(t('history'))),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(context).t(errorKey(error!))),
          if (data != null)
            for (final i in data!['invoices'] as List)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${i['number']}'),
                      Text(
                        '${i['currency']} ${minorDecimal(i['total_minor'] as int, i['minor_units'] as int)}',
                      ),
                      Text(t(i['paid'] == true ? 'paid' : 'unpaid')),
                      Text('${i['period_start']} — ${i['period_end']}'),
                      for (final receipt in (data!['receipts'] as List).where(
                        (r) => r['invoice_id'] == i['id'],
                      ))
                        Text(
                          '${t('receipt')} #${receipt['id']} · ${i['currency']} ${minorDecimal(receipt['amount_minor'] as int, i['minor_units'] as int)} · ${receipt['evidence']}',
                        ),
                      for (final credit in (data!['credits'] as List).where(
                        (r) => r['invoice_id'] == i['id'],
                      ))
                        Text(
                          '${t('credit')} #${credit['id']} · ${i['currency']} ${minorDecimal(credit['amount_minor'] as int, i['minor_units'] as int)}',
                        ),
                    ],
                  ),
                ),
              ),
          TextButton(onPressed: busy ? null : load, child: Text(t('refresh'))),
        ],
      ),
    ),
  );
}
