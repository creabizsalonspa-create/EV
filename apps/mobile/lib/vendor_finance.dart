import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'api.dart';
import 'strings.dart';
import 'financial_documents.dart';
import 'financial_operation_store.dart';

const vendorFinanceEn = {
  'title': 'Vendor finance',
  'notice':
      'Canonical test-provider records only. Invoice allocations, settlement approval and provider outcomes are distinct; none proves a real bank payout.',
  'earnings': 'Earnings overview',
  'accounts': 'Payout accounts',
  'payouts': 'Payout history',
  'settlements': 'Settlements',
  'empty': 'No records.',
  'refresh': 'Refresh',
  'next': 'Next',
  'previous': 'Previous',
  'consent': 'Review recipient consent',
  'revoke': 'Revoke recipient',
  'onboarding': 'Hosted recipient enrollment',
  'version': 'Consent version',
  'provider': 'Provider',
  'status': 'Status',
  'confirm': 'Confirm',
  'cancel': 'Cancel',
  'consentNotice':
      'I authorize this configured TEST payout recipient under the displayed consent version. I understand that verification and payout execution require separate authorized server decisions.',
  'revokeNotice':
      'Revoke this recipient for future payout use? Existing financial history and uncertain payout operations remain preserved.',
  'onboardingNotice':
      'Request a private Stripe TEST enrollment link for this configured vendor-owned recipient. Opening it shares data with Stripe. Returning does not verify a bank or execute a payout.',
  'pendingOperation':
      'An operation is unconfirmed. Refresh account/history before explicitly resending the same saved intent. It is never replayed automatically.',
  'done':
      'Server confirmed the operation. Refresh authoritative status; no real bank payment is claimed.',
  'open': 'Open Stripe TEST enrollment',
  'expired': 'The hosted link expired. Close and request a fresh link.',
  'prepared': 'Prepared',
  'uncertain': 'Uncertain',
  'pending': 'Pending',
  'simulated_paid': 'Simulated payout',
  'reconfiguration':
      'Updated recipient configuration is required for new consent terms. Historical consent is preserved.',
  'pending_consent': 'Awaiting consent',
  'pending_verification': 'Awaiting verification',
  'active': 'Active test recipient',
  'revoked': 'Revoked',
  'draft': 'Draft',
  'approved': 'Approved',
  'submitted': 'Submitted',
  'executing': 'Executing',
  'needs_review': 'Needs review',
  'unknown': 'Unknown',
  'paid': 'Test-provider paid outcome',
  'failed': 'Failed',
  'cancelled': 'Cancelled',
  'returned': 'Returned',
  'invoiced_minor': 'Invoiced',
  'paid_minor': 'Captured receipts',
  'refunded_minor': 'Confirmed refunds',
  'outstanding_minor': 'Outstanding',
  'net_vendor_allocated_minor': 'Net vendor invoice allocation',
  'net_commission_allocated_minor': 'Net commission allocation',
  'detail': 'Details',
  'events': 'Audit history',
  'statement': 'Private statement',
  'currency': 'Currency',
  'balance': 'Payable statement',
  'available_minor': 'Eligible payable balance',
  'requestSettlement': 'Request settlement review',
  'requestNotice':
      'Request a settlement from the current server-calculated eligible balance. Approval and payout execution are separate authorized operations. This does not move real money.',
};
const vendorFinanceAr = {
  'title': 'مالية المورد',
  'notice':
      'سجلات أصلية لمزودي الاختبار فقط. تخصيصات الفواتير والموافقة على التسوية ونتائج المزود حالات مستقلة ولا تثبت تحويلًا مصرفياً حقيقياً.',
  'earnings': 'ملخص الإيرادات',
  'accounts': 'حسابات التحويل',
  'payouts': 'سجل التحويلات',
  'settlements': 'التسويات',
  'empty': 'لا توجد سجلات.',
  'refresh': 'تحديث',
  'next': 'التالي',
  'previous': 'السابق',
  'consent': 'مراجعة موافقة المستفيد',
  'revoke': 'إلغاء تفويض المستفيد',
  'onboarding': 'تسجيل المستفيد المستضاف',
  'version': 'إصدار الموافقة',
  'provider': 'المزود',
  'status': 'الحالة',
  'confirm': 'تأكيد',
  'cancel': 'إلغاء',
  'consentNotice':
      'أفوض مستفيد التحويل التجريبي المهيأ وفق إصدار الموافقة المعروض. أدرك أن التحقق والتنفيذ يتطلبان قرارات خادم مستقلة ومصرحاً بها.',
  'revokeNotice':
      'إلغاء تفويض المستفيد للتحويلات اللاحقة؟ تُحفظ السجلات المالية والعمليات غير المؤكدة الحالية.',
  'onboardingNotice':
      'طلب رابط تسجيل خاص لدى Stripe TEST لهذا المستفيد المملوك للمورد. تفتح مشاركة البيانات مع Stripe ولا تعني العودة التحقق المصرفي أو تنفيذ تحويل.',
  'pendingOperation':
      'توجد عملية غير مؤكدة. حدّث الحساب والسجل قبل إعادة إرسال النية المحفوظة نفسها صراحةً. لا تُعاد تلقائياً.',
  'done':
      'أكد الخادم العملية. حدّث الحالة الأصلية؛ لا يُدعى حصول دفع مصرفي حقيقي.',
  'open': 'فتح تسجيل Stripe TEST',
  'expired': 'انتهت صلاحية الرابط. أغلق واطلب رابطاً جديداً.',
  'prepared': 'مُعد',
  'uncertain': 'غير مؤكد',
  'pending': 'معلق',
  'simulated_paid': 'تحويل محاكى',
  'reconfiguration':
      'تلزم تهيئة مستفيد محدثة لشروط الموافقة الجديدة. تُحفظ الموافقة السابقة.',
  'pending_consent': 'بانتظار الموافقة',
  'pending_verification': 'بانتظار التحقق',
  'active': 'مستفيد تجريبي فعال',
  'revoked': 'ملغى',
  'draft': 'مسودة',
  'approved': 'معتمد',
  'submitted': 'مُرسل',
  'executing': 'قيد التنفيذ',
  'needs_review': 'يحتاج مراجعة',
  'unknown': 'غير معروف',
  'paid': 'نتيجة دفع من مزود الاختبار',
  'failed': 'فشل',
  'cancelled': 'ملغى',
  'returned': 'مُعاد',
  'invoiced_minor': 'قيمة الفواتير',
  'paid_minor': 'المقبوضات المؤكدة',
  'refunded_minor': 'الاستردادات المؤكدة',
  'outstanding_minor': 'المتبقي',
  'net_vendor_allocated_minor': 'صافي تخصيص المورد بالفواتير',
  'net_commission_allocated_minor': 'صافي تخصيص العمولة',
  'detail': 'التفاصيل',
  'events': 'سجل التدقيق',
  'statement': 'كشف خاص',
  'currency': 'العملة',
  'balance': 'كشف المستحقات',
  'available_minor': 'الرصيد المستحق المؤهل',
  'requestSettlement': 'طلب مراجعة التسوية',
  'requestNotice':
      'طلب تسوية من الرصيد المؤهل المحسوب بالخادم. الموافقة وتنفيذ التحويل عمليتان مستقلتان ومصرح بهما. لا يحرك هذا أموالاً فعلية.',
};
String vendorFinanceText(BuildContext c, String k) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? vendorFinanceAr
        : vendorFinanceEn)[k] ??
    vendorFinanceEn['unknown']!;
String nativeFinancialAmount(
  BuildContext c,
  Map<String, dynamic> row,
  String field,
) {
  final value = row[field],
      digits = row['minor_units'],
      currency = row['currency'];
  if (value is! int ||
      digits is! int ||
      digits < 0 ||
      digits > 3 ||
      currency is! String ||
      !RegExp(r'^[A-Z]{3}$').hasMatch(currency))
    throw const FormatException('Unsupported authoritative amount.');
  var raw = value.toString();
  final sign = raw.startsWith('-') ? '-' : '';
  if (sign.isNotEmpty) raw = raw.substring(1);
  raw = raw.padLeft(digits + 1, '0');
  var result =
      sign +
      (digits == 0
          ? raw
          : '${raw.substring(0, raw.length - digits)}.${raw.substring(raw.length - digits)}');
  if (Localizations.localeOf(c).languageCode == 'ar')
    result = result.runes
        .map(
          (n) => n >= 48 && n <= 57
              ? String.fromCharCode(0x660 + n - 48)
              : n == 46
              ? '٫'
              : String.fromCharCode(n),
        )
        .join();
  return '$result $currency';
}

class VendorFinanceView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const VendorFinanceView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<VendorFinanceView> createState() => _VendorFinanceState();
}

class _VendorFinanceState extends State<VendorFinanceView> {
  String section = 'earnings';
  int generation = 0, target = 1;
  bool busy = false;
  Object? error;
  List<Map<String, dynamic>>? groups;
  ApiPage<Map<String, dynamic>>? page;
  String t(String k) => vendorFinanceText(context, k);
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

  Future<void> load({int number = 1}) async {
    final epoch = ++generation;
    target = number;
    setState(() {
      busy = true;
      error = null;
      page = null;
      groups = null;
    });
    try {
      final me = await widget.api.request('me');
      if (me['role'] != 'vendor')
        throw const ApiException(403, 'Vendor role required.');
      final path = section == 'earnings'
          ? 'finance/report'
          : section == 'accounts'
          ? 'commercial/payout-accounts'
          : section == 'payouts'
          ? 'commercial/payouts'
          : 'commercial/settlements';
      final result = await widget.api.request(
        path,
        query: section == 'earnings' ? const {} : {'page': '$number'},
      );
      if (!mounted || epoch != generation) return;
      if (section == 'earnings') {
        if (result['real_money_movement'] != false || result['groups'] is! List)
          throw const FormatException('Unsupported report evidence.');
        final rows = (result['groups'] as List)
            .map((r) => Map<String, dynamic>.from(r as Map))
            .toList();
        for (final row in rows) {
          if (row['evidence'] != 'SIMULATED')
            throw const FormatException('Unsupported report evidence.');
          for (final field in [
            'invoiced_minor',
            'paid_minor',
            'refunded_minor',
            'outstanding_minor',
            'net_vendor_allocated_minor',
            'net_commission_allocated_minor',
          ]) {
            nativeFinancialAmount(context, row, field);
          }
        }
        groups = rows;
      } else {
        final parsed = ApiPage.fromJson(result, (r) => r);
        for (final row in parsed.items) {
          if (row['id'] is! int || row['vendor_id'] != me['id'])
            throw const FormatException('Unsupported owner evidence.');
          if (section == 'accounts') {
            if (![
                  'MOCK_PAYOUT_ACCOUNT',
                  'STRIPE_TEST_ACCOUNT',
                ].contains(row['evidence']) ||
                row['real_money_moved'] != false)
              throw const FormatException('Unsupported recipient evidence.');
          } else {
            nativeFinancialAmount(context, row, 'amount_minor');
            if (section == 'payouts' && row['real_money_moved'] != false)
              throw const FormatException('Unsupported payout evidence.');
          }
        }
        page = parsed;
      }
    } catch (e) {
      if (mounted && epoch == generation) {
        error = e;
        page = null;
        groups = null;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> action(Map<String, dynamic> row, String kind) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => PayoutRecipientActionView(
          api: widget.api,
          onExpired: widget.onExpired,
          account: row,
          action: kind,
        ),
      ),
    );
    if (mounted) await load(number: target);
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('title'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        DropdownButtonFormField<String>(
          initialValue: section,
          isExpanded: true,
          items: [
            for (final k in ['earnings', 'accounts', 'payouts', 'settlements'])
              DropdownMenuItem(value: k, child: Text(t(k))),
          ],
          onChanged: busy
              ? null
              : (v) {
                  section = v!;
                  load();
                },
        ),
        OutlinedButton(
          onPressed: busy ? null : () => load(number: target),
          child: Text(t('refresh')),
        ),
        if (busy) const Center(child: CircularProgressIndicator()),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (groups != null) ...[
          if (groups!.isEmpty) Text(t('empty')),
          for (final row in groups!)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      row['currency'] as String,
                      style: Theme.of(c).textTheme.titleLarge,
                    ),
                    for (final field in [
                      'invoiced_minor',
                      'paid_minor',
                      'refunded_minor',
                      'outstanding_minor',
                      'net_vendor_allocated_minor',
                      'net_commission_allocated_minor',
                    ])
                      Text(
                        '${t(field)}: ${nativeFinancialAmount(c, row, field)}',
                      ),
                    OutlinedButton(
                      onPressed: () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => VendorPayableView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                              currency: row['currency'] as String,
                            ),
                          ),
                        );
                        if (mounted) load(number: target);
                      },
                      child: Text(t('balance')),
                    ),
                  ],
                ),
              ),
            ),
        ],
        if (page != null) ...[
          if (page!.items.isEmpty) Text(t('empty')),
          for (final row in page!.items)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '#${row['id']} · ${t('${row['status'] ?? row['state']}')}',
                    ),
                    if (section == 'accounts') ...[
                      Text(
                        '${t('provider')}: ${row['provider']} · ${row['currency']}',
                      ),
                      if (row['consent_version'] != null &&
                          row['required_consent_version'] !=
                              row['consent_version'])
                        Text(t('reconfiguration')),
                      if (row['consent_version'] != null)
                        Text('${t('version')}: ${row['consent_version']}'),
                      if (row['status'] != 'revoked')
                        Wrap(
                          spacing: 12,
                          runSpacing: 8,
                          children: [
                            if (row['required_consent_version'] is String &&
                                row['consent_version'] == null)
                              OutlinedButton(
                                onPressed: busy
                                    ? null
                                    : () => action(row, 'consent'),
                                child: Text(t('consent')),
                              ),
                            OutlinedButton(
                              onPressed: busy
                                  ? null
                                  : () => action(row, 'revoke'),
                              child: Text(t('revoke')),
                            ),
                            if (row['provider'] == 'stripe_test' &&
                                [
                                  'pending_verification',
                                  'active',
                                ].contains(row['status']))
                              OutlinedButton(
                                onPressed: busy
                                    ? null
                                    : () => action(row, 'onboarding'),
                                child: Text(t('onboarding')),
                              ),
                          ],
                        ),
                    ] else ...[
                      Text(nativeFinancialAmount(c, row, 'amount_minor')),
                      OutlinedButton(
                        onPressed: busy
                            ? null
                            : () => Navigator.of(c).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => VendorFinanceDetailView(
                                    api: widget.api,
                                    onExpired: widget.onExpired,
                                    id: row['id'] as int,
                                    payout: section == 'payouts',
                                  ),
                                ),
                              ),
                        child: Text(t('detail')),
                      ),
                      if (section == 'settlements')
                        OutlinedButton(
                          onPressed: busy
                              ? null
                              : () => Navigator.of(c).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => FinancialDocumentsView(
                                      api: widget.api,
                                      onExpired: widget.onExpired,
                                      sourceKind: 'vendor_settlement',
                                      sourceId: row['id'] as int,
                                    ),
                                  ),
                                ),
                          child: Text(t('statement')),
                        ),
                    ],
                  ],
                ),
              ),
            ),
          Wrap(
            spacing: 12,
            children: [
              OutlinedButton(
                onPressed: busy || page!.current <= 1
                    ? null
                    : () => load(number: page!.current - 1),
                child: Text(t('previous')),
              ),
              Text('${page!.current} / ${page!.last}'),
              OutlinedButton(
                onPressed: busy || page!.current >= page!.last
                    ? null
                    : () => load(number: page!.current + 1),
                child: Text(t('next')),
              ),
            ],
          ),
        ],
      ],
    ),
  );
}

class PayoutRecipientActionView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final Map<String, dynamic> account;
  final String action;
  final FinancialOperationStore? store;
  final Future<bool> Function(Uri)? launch;
  const PayoutRecipientActionView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.account,
    required this.action,
    this.store,
    this.launch,
  });
  @override
  State<PayoutRecipientActionView> createState() => _RecipientActionState();
}

class _RecipientActionState extends State<PayoutRecipientActionView> {
  FinancialOperation? operation;
  Object? error;
  bool busy = true, consent = false, confirmed = false;
  int generation = 0;
  Uri? link;
  int? expires;
  String t(String k) => vendorFinanceText(context, k);
  Map<String, dynamic> get body => widget.action == 'consent'
      ? {
          'consent': true,
          'consent_version': widget.account['required_consent_version'],
        }
      : {};
  @override
  void initState() {
    super.initState();
    restore();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> restore() async {
    final epoch = ++generation;
    try {
      final me = await widget.api.request('me');
      if (me['role'] != 'vendor' || me['id'] != widget.account['vendor_id'])
        throw const ApiException(403, 'Recipient owner required.');
      if (!['consent', 'revoke', 'onboarding'].contains(widget.action) ||
          (widget.action == 'consent' &&
              widget.account['required_consent_version'] is! String))
        throw const FormatException('Unsupported recipient operation.');
      final scope =
          '${widget.api.base.origin}:${me['id']}:recipient:${widget.account['id']}:${widget.action}:${widget.action == 'consent' ? widget.account['required_consent_version'] : ''}';
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        scope,
      );
      await op.restore();
      if (mounted && generation == epoch) operation = op;
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (!consent) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final payload = await operation!.prepare(body);
      if (!mounted || generation != epoch) return;
      final result = await widget.api.request(
        'commercial/payout-accounts/${widget.account['id']}/${widget.action}',
        method: 'POST',
        body: payload,
      );
      if (!mounted || generation != epoch) return;
      if (widget.action == 'onboarding') {
        final uri = Uri.tryParse('${result['url']}');
        final expiry = result['expires_at'];
        if (uri == null ||
            uri.scheme != 'https' ||
            uri.host != 'connect.stripe.com' ||
            uri.userInfo.isNotEmpty ||
            uri.hasPort ||
            uri.hasFragment ||
            expiry is! int ||
            expiry <= DateTime.now().millisecondsSinceEpoch ~/ 1000 ||
            expiry > DateTime.now().millisecondsSinceEpoch ~/ 1000 + 3600 ||
            result['evidence'] != 'STRIPE_TEST_HOSTED_ONBOARDING' ||
            result['real_money_moved'] != false)
          throw const ApiException(502, 'Untrusted onboarding response.');
        link = uri;
        expires = expiry;
      } else {
        final row = result['account'];
        if (row is! Map ||
            row['id'] != widget.account['id'] ||
            row['vendor_id'] != widget.account['vendor_id'] ||
            row['real_money_moved'] != false)
          throw const ApiException(502, 'Unconfirmed recipient receipt.');
        if (widget.action == 'revoke' && row['status'] != 'revoked')
          throw const ApiException(502, 'Unconfirmed revocation.');
        if (widget.action == 'consent' &&
            row['consent_version'] != body['consent_version'])
          throw const ApiException(502, 'Unconfirmed consent version.');
      }
      confirmed = true;
      await operation!.confirmed();
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  Future<void> open() async {
    if (link == null ||
        expires == null ||
        expires! <= DateTime.now().millisecondsSinceEpoch ~/ 1000) {
      setState(
        () => error = const ApiException(409, 'Expired enrollment link.'),
      );
      return;
    }
    final epoch = generation;
    try {
      final launched =
          await (widget.launch?.call(link!) ??
              launchUrl(link!, mode: LaunchMode.externalApplication));
      if (!launched) throw const ApiException(0, 'Could not launch.');
    } catch (e) {
      if (mounted && epoch == generation) setState(() => error = e);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t(widget.action))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          t(
            widget.action == 'consent'
                ? 'consentNotice'
                : widget.action == 'revoke'
                ? 'revokeNotice'
                : 'onboardingNotice',
          ),
        ),
        if (widget.action == 'consent')
          Text(
            '${t('version')}: ${widget.account['required_consent_version']}',
          ),
        if (operation?.pending != null && !confirmed)
          Text(t('pendingOperation')),
        if (confirmed) Text(t('done')),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (busy) const LinearProgressIndicator(),
        CheckboxListTile(
          value: consent,
          onChanged: busy || confirmed
              ? null
              : (v) => setState(() => consent = v!),
          title: Text(t('confirm')),
        ),
        FilledButton(
          onPressed: busy || confirmed || !consent || operation == null
              ? null
              : submit,
          child: Text(t(widget.action)),
        ),
        if (link != null)
          OutlinedButton(onPressed: busy ? null : open, child: Text(t('open'))),
      ],
    ),
  );
}

class VendorFinanceDetailView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int id;
  final bool payout;
  const VendorFinanceDetailView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.id,
    required this.payout,
  });
  @override
  State<VendorFinanceDetailView> createState() => _FinanceDetailState();
}

class _FinanceDetailState extends State<VendorFinanceDetailView> {
  Map<String, dynamic>? value;
  Object? error;
  bool busy = false;
  int generation = 0;
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
      value = null;
      error = null;
    });
    try {
      final result = Map<String, dynamic>.from(
        await widget.api.request(
              'commercial/${widget.payout ? 'payouts' : 'settlements'}/${widget.id}',
            )
            as Map,
      );
      if (!mounted || epoch != generation) return;
      final row = result[widget.payout ? 'payout' : 'settlement'];
      if (row is! Map ||
          row['id'] != widget.id ||
          (widget.payout
              ? result['real_money_moved'] != false
              : result['real_payout_enabled'] != false))
        throw const FormatException('Unsupported financial evidence.');
      nativeFinancialAmount(
        context,
        Map<String, dynamic>.from(row),
        'amount_minor',
      );
      if (result['events'] is! List ||
          (result['events'] as List).any(
            (event) =>
                event is! Map ||
                event['action'] is! String ||
                event['created_at'] is! String,
          ))
        throw const FormatException('Unsupported audit history.');
      if (mounted && epoch == generation) setState(() => value = result);
    } catch (e) {
      if (mounted && epoch == generation) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    final t = (String k) => vendorFinanceText(c, k);
    final row = value?[widget.payout ? 'payout' : 'settlement'];
    return Scaffold(
      appBar: AppBar(title: Text(t('detail'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          OutlinedButton(
            onPressed: busy ? null : load,
            child: Text(t('refresh')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (row != null) ...[
            Text('#${row['id']} · ${t('${row['status'] ?? row['state']}')}'),
            Text(
              nativeFinancialAmount(
                c,
                Map<String, dynamic>.from(row),
                'amount_minor',
              ),
            ),
            Text(t('events')),
            for (final event in value!['events'] as List? ?? [])
              ListTile(
                title: Text(t('${event['action']}')),
                subtitle: Text('${event['created_at'] ?? ''}'),
              ),
          ],
        ],
      ),
    );
  }
}

class VendorPayableView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String currency;
  final FinancialOperationStore? store;
  const VendorPayableView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.currency,
    this.store,
  });
  @override
  State<VendorPayableView> createState() => _VendorPayableState();
}

class _VendorPayableState extends State<VendorPayableView> {
  Map<String, dynamic>? statement;
  FinancialOperation? operation;
  int owner = 0, generation = 0;
  bool busy = false, consent = false, done = false;
  Object? error;
  String t(String k) => vendorFinanceText(context, k);
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
    if (e is ApiException && [401, 419].contains(e.status)) {
      statement = null;
      operation = null;
      widget.onExpired();
    }
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      statement = null;
    });
    try {
      final me = await widget.api.request('me');
      if (me['role'] != 'vendor' ||
          me['id'] is! int ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(widget.currency))
        throw const ApiException(403, 'Vendor role required.');
      owner = me['id'] as int;
      final data = Map<String, dynamic>.from(
        await widget.api.request(
              'commercial/vendors/$owner/statement',
              query: {'currency': widget.currency},
            )
            as Map,
      );
      if (!mounted || epoch != generation) return;
      if (data['vendor_id'] != owner ||
          data['currency'] != widget.currency ||
          data['real_payout_enabled'] != false ||
          data['evidence'] != 'SIMULATED' ||
          data['eligible_to_request'] is! bool)
        throw const FormatException('Unsupported payable evidence.');
      nativeFinancialAmount(context, data, 'available_minor');
      final op =
          operation ??
          FinancialOperation(
            widget.store ?? DeviceFinancialOperationStore(),
            '${widget.api.base.origin}:$owner:settlement:${widget.currency}',
          );
      if (operation == null) await op.restore();
      if (!mounted || epoch != generation) return;
      operation = op;
      statement = data;
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (busy || !consent || operation == null || done) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await operation!.prepare({'currency': widget.currency});
      final result = await widget.api.request(
        'commercial/vendors/$owner/settlements',
        method: 'POST',
        body: body,
      );
      if (result is! Map ||
          result['id'] is! int ||
          result['vendor_id'] != owner ||
          result['currency'] != widget.currency ||
          result['evidence'] != 'SIMULATED')
        throw const FormatException('Unsupported settlement receipt.');
      if (!mounted) return;
      nativeFinancialAmount(
        context,
        Map<String, dynamic>.from(result),
        'amount_minor',
      );
      await operation!.confirmed();
      if (mounted)
        setState(() {
          done = true;
          consent = false;
        });
    } catch (e) {
      if (mounted) fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('balance'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        Text(t('requestNotice')),
        OutlinedButton(
          onPressed: busy ? null : load,
          child: Text(t('refresh')),
        ),
        if (busy) const Center(child: CircularProgressIndicator()),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (statement != null)
          Text(
            '${t('available_minor')}: ${nativeFinancialAmount(c, statement!, 'available_minor')}',
          ),
        if (operation?.pending != null) Text(t('pendingOperation')),
        if (done) Text(t('done')),
        if (!done &&
            statement != null &&
            (statement!['eligible_to_request'] == true ||
                operation?.pending != null)) ...[
          CheckboxListTile(
            value: consent,
            onChanged: busy ? null : (v) => setState(() => consent = v!),
            title: Text(t('requestNotice')),
          ),
          FilledButton(
            onPressed: busy || !consent || operation == null ? null : submit,
            child: Text(t('requestSettlement')),
          ),
        ],
      ],
    ),
  );
}
