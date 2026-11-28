import 'dart:async';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'api.dart';
import 'strings.dart';
import 'financial_documents.dart';
import 'financial_operation_store.dart';
import 'vendor_earnings_csv.dart';
import 'vendor_settlement_csv.dart';
import 'vendor_revenue_cohorts.dart';
import 'invoice_reconciliation.dart';

const vendorFinanceEn = {
  'title': 'Vendor finance',
  'notice':
      'Canonical test-provider records only. Invoice allocations, settlement approval and provider outcomes are distinct; none proves a real bank payout.',
  'earnings': 'Earnings overview',
  'accounts': 'Payout accounts',
  'payouts': 'Payout history',
  'settlements': 'Settlements',
  'settlementCurrency': 'Settlement currency',
  'settlementState': 'Settlement status',
  'settlementAllStates': 'Any status',
  'settlementFrom': 'From UTC (YYYY-MM-DD)',
  'settlementTo': 'Through UTC (YYYY-MM-DD)',
  'settlementFilter': 'Filter settlements',
  'settlementExport': 'Preview settlement page CSV',
  'settlementInvalidFilters': 'Enter a three-letter currency and valid dates in order.',
  'provider_paid': 'Test-provider payout',
  'rejected': 'Rejected',
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
  'settlementCurrency': 'عملة التسوية',
  'settlementState': 'حالة التسوية',
  'settlementAllStates': 'جميع الحالات',
  'settlementFrom': 'من تاريخ UTC (YYYY-MM-DD)',
  'settlementTo': 'إلى تاريخ UTC (YYYY-MM-DD)',
  'settlementFilter': 'تصفية التسويات',
  'settlementExport': 'معاينة صفحة التسويات CSV',
  'settlementInvalidFilters': 'أدخل رمز عملة من ثلاثة أحرف وتواريخ صحيحة بالترتيب.',
  'provider_paid': 'صرف عبر مزود الاختبار',
  'rejected': 'مرفوض',
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

class _VendorFinanceState extends State<VendorFinanceView>
    with WidgetsBindingObserver {
  static const settlementStates = [
    'pending', 'approved', 'rejected', 'simulated_paid', 'provider_paid',
  ];

  String section = 'earnings';
  final settlementCurrency = TextEditingController();
  final settlementFrom = TextEditingController();
  final settlementTo = TextEditingController();
  var settlementState = '';
  Map<String, String> settlementFilters = const {};
  String? settlementCsv;
  bool invalidSettlementFilter = false, settlementFiltersEdited = false;
  int generation = 0, target = 1;
  bool busy = false, foreground = true, hidden = false;
  late bool Function() authorized;
  StreamSubscription<int>? sessionEvents;

  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted) return;
      setState(() {
        clearPrivate();
      });
    });
  }

  bool active(int n, bool Function() current) =>
      mounted && foreground && authorized() && current() && n == generation;
  void clearPrivate() {
    generation++;
    groups = null;
    page = null;
    error = null;
    busy = false;
    target = 1;
    settlementCurrency.clear();
    settlementFrom.clear();
    settlementTo.clear();
    settlementState = '';
    settlementFilters = const {};
    settlementCsv = null;
    invalidSettlementFilter = false;
    settlementFiltersEdited = false;
    hidden = true;
  }

  Object? error;
  List<Map<String, dynamic>>? groups;
  ApiPage<Map<String, dynamic>>? page;
  String t(String k) => vendorFinanceText(context, k);
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    observe(widget.api);
    foreground =
        WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    load();
  }

  @override
  void didUpdateWidget(covariant VendorFinanceView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api) {
      observe(widget.api);
      clearPrivate();
      authorized = widget.api.sessionFence();
      final replacement = widget.api;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted &&
            foreground &&
            identical(widget.api, replacement) &&
            authorized())
          load();
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) clearPrivate();
    });
  }

  @override
  void dispose() {
    sessionEvents?.cancel();
    generation++;
    settlementCurrency.dispose();
    settlementFrom.dispose();
    settlementTo.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int number = 1}) async {
    if (!foreground || !authorized()) return;
    final epoch = ++generation, current = widget.api.sessionFence();
    target = number;
    setState(() {
      busy = true;
      hidden = false;
      error = null;
      page = null;
      groups = null;
      settlementCsv = null;
    });
    try {
      final me = await widget.api.request('me');
      if (!active(epoch, current)) return;
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
        query: section == 'earnings'
            ? const {}
            : {
                'page': '$number',
                if (section == 'settlements') ...settlementFilters,
              },
      );
      if (!active(epoch, current)) return;
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
        if (section == 'settlements' &&
            (parsed.current != number || parsed.items.length > 25)) {
          throw const FormatException('Invalid settlement history page.');
        }
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
            if (section == 'settlements') {
              final created = row['created_at'];
              final date = created is String && created.length >= 10
                  ? created.substring(0, 10)
                  : null;
              final from = settlementFilters['from'];
              final to = settlementFilters['to'];
              if (!settlementStates.contains(row['state']) ||
                  (settlementFilters['state'] != null &&
                      row['state'] != settlementFilters['state']) ||
                  (settlementFilters['currency'] != null &&
                      row['currency'] != settlementFilters['currency']) ||
                  (from != null && (date == null || date.compareTo(from) < 0)) ||
                  (to != null && (date == null || date.compareTo(to) > 0))) {
                throw const FormatException('Settlement history scope changed.');
              }
            }
          }
        }
        page = parsed;
      }
    } catch (e) {
      if (active(epoch, current)) {
        error = e;
        page = null;
        groups = null;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (active(epoch, current)) setState(() => busy = false);
    }
  }

  void editSettlementFilters() {
    settlementFiltersEdited = true;
    settlementCsv = null;
  }

  void filterSettlements() {
    if (!foreground || !authorized() || busy) return;
    final currency = settlementCurrency.text.trim().toUpperCase();
    final from = settlementFrom.text.trim();
    final to = settlementTo.text.trim();

    bool date(String value) {
      if (value.isEmpty) return true;
      if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value)) return false;
      final parsed = DateTime.tryParse('${value}T00:00:00Z');
      return parsed != null && parsed.toIso8601String().startsWith('${value}T');
    }

    if ((currency.isNotEmpty && !RegExp(r'^[A-Z]{3}$').hasMatch(currency)) ||
        !date(from) || !date(to) ||
        (from.isNotEmpty && to.isNotEmpty && from.compareTo(to) > 0)) {
      setState(() {
        invalidSettlementFilter = true;
        page = null;
        settlementCsv = null;
      });
      return;
    }
    setState(() {
      invalidSettlementFilter = false;
      settlementFiltersEdited = false;
      settlementFilters = {
        if (currency.isNotEmpty) 'currency': currency,
        if (settlementState.isNotEmpty) 'state': settlementState,
        if (from.isNotEmpty) 'from': from,
        if (to.isNotEmpty) 'to': to,
      };
    });
    load();
  }

  Future<void> viewSettlementCsv() async {
    final source = page;
    final current = widget.api.sessionFence();
    final request = generation;
    if (busy || source == null || section != 'settlements' ||
        settlementFiltersEdited || !active(request, current)) return;
    setState(() {
      busy = true;
      error = null;
      settlementCsv = null;
    });
    try {
      final document = await widget.api.downloadVendorSettlementPage({
        ...settlementFilters,
        'page': '${source.current}',
      });
      if (active(request, current)) {
        final text = VendorSettlementCsv.verifiedText(document, source.items);
        setState(() => settlementCsv = text);
      }
    } catch (e) {
      if (active(request, current)) {
        setState(() => error = e);
        if (e is ApiException && e.endsCurrentSession) widget.onExpired();
      }
    } finally {
      if (mounted && foreground && request == generation) {
        setState(() {
          if (!current()) clearPrivate();
          busy = false;
        });
      }
    }
  }

  Future<T?> openRoute<T>(MaterialPageRoute<T> route) {
    if (!mounted || !foreground || !authorized()) return Future<T?>.value();
    final sourceApi = widget.api, current = widget.api.sessionFence();
    return Navigator.of(context).push<T>(
      MaterialPageRoute<T>(
        settings: route.settings,
        fullscreenDialog: route.fullscreenDialog,
        maintainState: route.maintainState,
        builder: (routeContext) {
          if (!mounted ||
              !foreground ||
              !identical(widget.api, sourceApi) ||
              !authorized() ||
              !current()) {
            return Scaffold(
              body: Center(
                child: Text(EvStrings.of(routeContext).t('expired')),
              ),
            );
          }
          return route.builder(routeContext);
        },
      ),
    );
  }

  Future<void> action(Map<String, dynamic> row, String kind) async {
    await openRoute(
      MaterialPageRoute<void>(
        builder: (_) => PayoutRecipientActionView(
          api: widget.api,
          onExpired: widget.onExpired,
          account: row,
          action: kind,
        ),
      ),
    );
    if (mounted && foreground && authorized()) await load(number: target);
  }

  @override
  Widget build(BuildContext c) {
    final sourceApi = widget.api, current = widget.api.sessionFence();
    Future<T?> openForActor<T>(MaterialPageRoute<T> route) {
      if (!identical(widget.api, sourceApi) || !current())
        return Future<T?>.value();
      return openRoute(route);
    }

    Future<void> actForActor(Map<String, dynamic> row, String kind) async {
      if (!identical(widget.api, sourceApi) || !current()) return;
      await action(row, kind);
    }

    if (!authorized())
      return Scaffold(
        appBar: AppBar(title: Text(t('title'))),
        body: Center(child: Text(EvStrings.of(c).t('expired'))),
      );
    return Scaffold(
      appBar: AppBar(title: Text(t('title'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          if (hidden)
            Text(
              Localizations.localeOf(c).languageCode == 'ar'
                  ? 'مُسحت السجلات الخاصة. حدّث لاستعادتها.'
                  : 'Private records cleared. Refresh to reload.',
            ),
          DropdownButtonFormField<String>(
            initialValue: section,
            isExpanded: true,
            items: [
              for (final k in [
                'earnings',
                'accounts',
                'payouts',
                'settlements',
              ])
                DropdownMenuItem(value: k, child: Text(t(k))),
            ],
            onChanged: busy
                ? null
                : (v) {
                    section = v!;
                    load();
                  },
          ),
          if (section == 'settlements') ...[
            TextField(
              controller: settlementCurrency,
              enabled: !busy,
              onChanged: (_) => setState(editSettlementFilters),
              textCapitalization: TextCapitalization.characters,
              decoration: InputDecoration(labelText: t('settlementCurrency')),
            ),
            DropdownButtonFormField<String>(
              initialValue: settlementState,
              decoration: InputDecoration(labelText: t('settlementState')),
              items: [
                DropdownMenuItem(value: '', child: Text(t('settlementAllStates'))),
                for (final state in settlementStates)
                  DropdownMenuItem(value: state, child: Text(t(state))),
              ],
              onChanged: busy ? null : (value) {
                if (authorized() && foreground && current()) {
                  setState(() {
                    settlementState = value ?? '';
                    editSettlementFilters();
                  });
                }
              },
            ),
            for (final field in [
              (settlementFrom, 'settlementFrom'),
              (settlementTo, 'settlementTo'),
            ])
              TextField(
                controller: field.$1,
                enabled: !busy,
                onChanged: (_) => setState(editSettlementFilters),
                keyboardType: TextInputType.datetime,
                decoration: InputDecoration(labelText: t(field.$2)),
              ),
            OutlinedButton(
              onPressed: busy ? null : () {
                if (identical(widget.api, sourceApi) && current()) filterSettlements();
              },
              child: Text(t('settlementFilter')),
            ),
            if (invalidSettlementFilter) Text(t('settlementInvalidFilters')),
            if (page != null && !settlementFiltersEdited)
              OutlinedButton(
                onPressed: busy ? null : () {
                  if (identical(widget.api, sourceApi) && current()) viewSettlementCsv();
                },
                child: Text(t('settlementExport')),
              ),
            if (settlementCsv != null)
              SelectableText(settlementCsv!, key: const Key('settlement-csv-preview')),
          ],
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
                        key: ValueKey('vendor-revenue-${row['currency']}'),
                        onPressed: () => openForActor(
                          MaterialPageRoute<void>(
                            builder: (_) => VendorRevenueCohortView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                              currency: row['currency'] as String,
                            ),
                          ),
                        ),
                        child: Text(
                          Localizations.localeOf(c).languageCode == 'ar'
                              ? 'لوحة الإيرادات حسب تاريخ الفاتورة'
                              : 'Revenue by invoice date',
                        ),
                      ),
                      OutlinedButton(
                        key: ValueKey('vendor-itemized-${row['currency']}'),
                        onPressed: () => openForActor(
                          MaterialPageRoute<void>(
                            builder: (_) => VendorEarningsView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                              currency: row['currency'] as String,
                            ),
                          ),
                        ),
                        child: Text(
                          Localizations.localeOf(c).languageCode == 'ar'
                              ? 'إيرادات الفواتير التفصيلية'
                              : 'Itemized invoice earnings',
                        ),
                      ),
                      OutlinedButton(
                        key: ValueKey('vendor-reconciliation-${row['currency']}'),
                        onPressed: () => openForActor(
                          MaterialPageRoute<void>(
                            builder: (_) => InvoiceReconciliationView(
                              api: widget.api,
                              onExpired: widget.onExpired,
                              currency: row['currency'] as String,
                            ),
                          ),
                        ),
                        child: Text(
                          Localizations.localeOf(c).languageCode == 'ar'
                              ? 'تدقيق تسوية الفواتير الداخلية'
                              : 'Internal invoice reconciliation',
                        ),
                      ),
                      OutlinedButton(
                        onPressed: () async {
                          await openForActor(
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
                                      : () => actForActor(row, 'consent'),
                                  child: Text(t('consent')),
                                ),
                              OutlinedButton(
                                onPressed: busy
                                    ? null
                                    : () => actForActor(row, 'revoke'),
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
                                      : () => actForActor(row, 'onboarding'),
                                  child: Text(t('onboarding')),
                                ),
                            ],
                          ),
                      ] else ...[
                        Text(nativeFinancialAmount(c, row, 'amount_minor')),
                        OutlinedButton(
                          onPressed: busy
                              ? null
                              : () => openForActor(
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
                                : () => openForActor(
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

class _RecipientActionState extends State<PayoutRecipientActionView> with WidgetsBindingObserver {
  late bool Function() authorized;
  late final Map<String,dynamic> reviewedAccount;
  late final String reviewedAction;
  StreamSubscription<int>? sessionEvents;
  bool foreground = true, retired = false, launching = false;
  bool get active => mounted && foreground && !retired && authorized();
  bool current(int epoch) => active && generation == epoch;
  bool get top => ModalRoute.of(context)?.isCurrent ?? true;
  void redact() {
    operation = null; consent = false; confirmed = false;
    link = null; expires = null; error = null; busy = false; launching = false;
  }
  FinancialOperation? operation;
  Object? error;
  bool busy = true, consent = false, confirmed = false;
  int generation = 0;
  Uri? link;
  int? expires;
  String t(String k) => vendorFinanceText(context, k);
  Map<String, dynamic> get body => reviewedAction == 'consent'
      ? {
          'consent': true,
          'consent_version': reviewedAccount['required_consent_version'],
        }
      : {};
  @override
  void initState() {
    super.initState();
    reviewedAccount = Map<String,dynamic>.unmodifiable(widget.account);
    reviewedAction = widget.action;
    final api = widget.api;
    authorized = api.sessionFence();
    final state = WidgetsBinding.instance.lifecycleState;
    foreground = state == null || state == AppLifecycleState.resumed;
    retired = !foreground;
    WidgetsBinding.instance.addObserver(this);
    sessionEvents = api.sessionEvents.listen((_) {
      if (mounted && !authorized()) setState(() { retired = true; redact(); });
    });
    if (active) restore();
  }

  @override
  void didUpdateWidget(covariant PayoutRecipientActionView old) {
    super.didUpdateWidget(old);
    if (!identical(old.api,widget.api) || !identical(old.store,widget.store) ||
        reviewedAction != widget.action || ['id','vendor_id','provider','currency',
          'minor_units','status','required_consent_version','consent_version']
          .any((k)=>reviewedAccount[k] != widget.account[k])) {
      generation++; retired = true; redact();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    final next = state == AppLifecycleState.resumed;
    if (next == foreground) return;
    setState(() {
      foreground = next;
      if (!next) { generation++; retired = true; redact(); }
    });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionEvents?.cancel());
    super.dispose();
  }

  Future<void> restore() async {
    if (!active) return;
    final epoch = ++generation, api = widget.api;
    try {
      final me = await api.request('me');
      if (!current(epoch)) return;
      if (me['role'] != 'vendor' || me['id'] is! int || me['id'] <= 0 ||
          me['id'] != reviewedAccount['vendor_id'] ||
          reviewedAccount['id'] is! int || reviewedAccount['id'] <= 0)
        throw const ApiException(403, 'Recipient owner required.');
      if (!['consent', 'revoke', 'onboarding'].contains(reviewedAction) ||
          (reviewedAction == 'consent' &&
              (reviewedAccount['required_consent_version'] is! String ||
               (reviewedAccount['required_consent_version'] as String).isEmpty)) ||
          (reviewedAction == 'onboarding' && reviewedAccount['provider'] != 'stripe_test'))
        throw const FormatException('Unsupported recipient operation.');
      final scope =
          '${api.base.origin}:${me['id']}:recipient:${reviewedAccount['id']}:$reviewedAction:${reviewedAction == 'consent' ? reviewedAccount['required_consent_version'] : ''}';
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        scope,
        isCurrent: () => active && identical(api,widget.api),
      );
      await op.restore();
      if (current(epoch)) operation = op;
    } catch (e) {
      if (current(epoch)) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) widget.onExpired();
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (!active || !top || busy || confirmed || !consent || operation == null) return;
    final epoch = ++generation, api = widget.api, op = operation!, reviewedBody = body;
    setState(() {
      busy = true;
      consent = false;
      error = null;
    });
    try {
      final payload = await op.prepare(reviewedBody);
      if (!current(epoch) || !top || !identical(op,operation)) return;
      final result = await api.request(
        'commercial/payout-accounts/${reviewedAccount['id']}/$reviewedAction',
        method: 'POST',
        body: payload,
      );
      if (!current(epoch)) return;
      Uri? receivedLink;
      int? receivedExpiry;
      if (reviewedAction == 'onboarding') {
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
        receivedLink = uri;
        receivedExpiry = expiry;
      } else {
        final row = result['account'];
        if (row is! Map ||
            row['id'] != reviewedAccount['id'] ||
            row['vendor_id'] != reviewedAccount['vendor_id'] ||
            row['provider'] != reviewedAccount['provider'] ||
            row['currency'] != reviewedAccount['currency'] ||
            row['minor_units'] != reviewedAccount['minor_units'] ||
            row['real_money_moved'] != false)
          throw const ApiException(502, 'Unconfirmed recipient receipt.');
        if (reviewedAction == 'revoke' && row['status'] != 'revoked')
          throw const ApiException(502, 'Unconfirmed revocation.');
        if (reviewedAction == 'consent' &&
            (row['consent_version'] != reviewedBody['consent_version'] ||
             !['pending_verification','active'].contains(row['status'])))
          throw const ApiException(502, 'Unconfirmed consent version.');
      }
      await op.confirmed();
      if (!current(epoch)) return;
      confirmed = true;
      link = receivedLink;
      expires = receivedExpiry;
    } catch (e) {
      if (current(epoch)) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) widget.onExpired();
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> open() async {
    if (!active || !top || busy || launching || !confirmed) return;
    if (link == null ||
        expires == null ||
        expires! <= DateTime.now().millisecondsSinceEpoch ~/ 1000) {
      setState(
        () => error = const ApiException(409, 'Expired enrollment link.'),
      );
      return;
    }
    final epoch = generation, uri = link!;
    setState(() => launching = true);
    try {
      final launched =
          await (widget.launch?.call(uri) ??
              launchUrl(uri, mode: LaunchMode.externalApplication));
      if (!current(epoch)) return;
      if (!launched) throw const ApiException(0, 'Could not launch.');
      setState(() { link = null; expires = null; });
    } catch (e) {
      if (current(epoch)) setState(() => error = e);
    } finally {
      if (current(epoch)) setState(() => launching = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    final rendered = generation;
    bool usable() => current(rendered) && top && !busy && !launching;
    if (!active) return Scaffold(appBar:AppBar(title:Text(t(reviewedAction))),
      body:Center(child:Text(t('notice'))));
    return Scaffold(
    appBar: AppBar(title: Text(t(reviewedAction))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          t(
            reviewedAction == 'consent'
                ? 'consentNotice'
                : reviewedAction == 'revoke'
                ? 'revokeNotice'
                : 'onboardingNotice',
          ),
        ),
        if (reviewedAction == 'consent')
          Text(
            '${t('version')}: ${reviewedAccount['required_consent_version']}',
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
              : (v) { if (usable()) setState(() => consent = v == true); },
          title: Text(t('confirm')),
        ),
        FilledButton(
          onPressed: busy || confirmed || !consent || operation == null
              ? null
              : () { if (usable()) submit(); },
          child: Text(t(reviewedAction)),
        ),
        if (link != null)
          OutlinedButton(onPressed: busy || launching ? null : () { if (usable()) open(); }, child: Text(t('open'))),
      ],
    ),
  );
  }
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

class _VendorPayableState extends State<VendorPayableView> with WidgetsBindingObserver {
  late bool Function() authorized;
  StreamSubscription<int>? sessionEvents;
  bool foreground = true, retired = false;
  bool get active => mounted && foreground && !retired && authorized();
  bool current(int epoch) => active && epoch == generation;
  bool get top => ModalRoute.of(context)?.isCurrent ?? true;
  void redact() {
    statement = null; operation = null; owner = 0;
    consent = false; done = false; busy = false; error = null;
  }
  Map<String, dynamic>? statement;
  FinancialOperation? operation;
  int owner = 0, generation = 0;
  bool busy = false, consent = false, done = false;
  Object? error;
  String t(String k) => vendorFinanceText(context, k);
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    final api = widget.api;
    sessionEvents = api.sessionEvents.listen((_) {
      if (mounted && identical(api,widget.api) && !authorized()) {
        setState(() { retired = true; redact(); });
      }
    });
    if (active) load();
  }

  @override
  void didUpdateWidget(covariant VendorPayableView old) {
    super.didUpdateWidget(old);
    if (!identical(old.api,widget.api) || old.currency != widget.currency ||
        !identical(old.store,widget.store)) {
      generation++; retired = true; redact();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    final next = state == AppLifecycleState.resumed;
    if (next == foreground) return;
    setState(() { foreground = next; generation++; redact(); });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionEvents?.cancel());
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
    if (!active || busy) return;
    final epoch = ++generation, api = widget.api, currency = widget.currency;
    setState(() {
      busy = true;
      error = null;
      statement = null;
      consent = false;
    });
    try {
      final me = await api.request('me');
      if (!current(epoch)) return;
      if (me['role'] != 'vendor' ||
          me['id'] is! int ||
          me['id'] <= 0 ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(currency))
        throw const ApiException(403, 'Vendor role required.');
      final actor = me['id'] as int;
      if (owner != 0 && owner != actor)
        throw const FormatException('Vendor scope changed.');
      final data = Map<String, dynamic>.from(
        await api.request(
              'commercial/vendors/$actor/statement',
              query: {'currency': currency},
            )
            as Map,
      );
      if (!current(epoch)) return;
      if (data['vendor_id'] != actor ||
          data['currency'] != currency ||
          data['real_payout_enabled'] != false ||
          data['evidence'] != 'SIMULATED' ||
          data['eligible_to_request'] is! bool)
        throw const FormatException('Unsupported payable evidence.');
      nativeFinancialAmount(context, data, 'available_minor');
      final op =
          operation ??
          FinancialOperation(
            widget.store ?? DeviceFinancialOperationStore(),
            '${api.base.origin}:$actor:settlement:$currency',
            isCurrent: () => active && identical(api,widget.api),
          );
      if (operation == null) await op.restore();
      if (!current(epoch)) return;
      owner = actor;
      operation = op;
      statement = data;
    } catch (e) {
      if (current(epoch)) fail(e);
      else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) widget.onExpired();
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (!active || !top || busy || !consent || operation == null || done ||
        statement == null || (statement!['eligible_to_request'] != true && operation!.pending == null)) return;
    final epoch = ++generation, api = widget.api, actor = owner, currency = widget.currency,
        op = operation!, reviewed = statement!;
    setState(() {
      busy = true;
      consent = false;
      error = null;
    });
    try {
      final body = await op.prepare({'currency': currency});
      if (!current(epoch) || !top || !identical(op,operation) || !identical(reviewed,statement)) return;
      final result = await api.request(
        'commercial/vendors/$actor/settlements',
        method: 'POST',
        body: body,
      );
      if (!current(epoch)) return;
      if (result is! Map ||
          result['id'] is! int || result['id'] <= 0 ||
          result['vendor_id'] != actor ||
          result['currency'] != currency ||
          result['minor_units'] != reviewed['minor_units'] ||
          result['evidence'] != 'SIMULATED')
        throw const FormatException('Unsupported settlement receipt.');
      nativeFinancialAmount(
        context,
        Map<String, dynamic>.from(result),
        'amount_minor',
      );
      await op.confirmed();
      if (current(epoch))
        setState(() {
          done = true;
          consent = false;
        });
    } catch (e) {
      if (current(epoch)) {
        fail(e);
        // The durable original intent remains. A new review is mandatory.
        statement = null;
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) widget.onExpired();
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    final rendered = generation;
    bool usable() => current(rendered) && top && !busy;
    if (!active) return Scaffold(appBar:AppBar(title:Text(t('balance'))),
      body:Center(child:Text(t('notice'))));
    return Scaffold(
    appBar: AppBar(title: Text(t('balance'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        Text(t('requestNotice')),
        OutlinedButton(
          onPressed: busy ? null : () { if (usable()) load(); },
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
            onChanged: busy ? null : (v) { if (usable()) setState(() => consent = v == true); },
            title: Text(t('requestNotice')),
          ),
          FilledButton(
            onPressed: busy || !consent || operation == null ? null : () { if (usable()) submit(); },
            child: Text(t('requestSettlement')),
          ),
        ],
      ],
    ),
  );
  }
}

/// Read-only projection of the canonical settlement service, never a ledger.
class VendorEarningsSnapshot {
  static const amounts = <String, (String, String)>{
    'subtotal_minor': ('Subtotal', 'المجموع الفرعي'),
    'tax_minor': ('Tax', 'الضريبة'),
    'commission_minor': ('Commission', 'العمولة'),
    'vendor_allocated_minor': ('Vendor allocation', 'حصة المورد'),
    'paid_minor': ('Recorded payments', 'المدفوعات المسجلة'),
    'refunded_minor': ('Recorded refunds', 'المبالغ المستردة المسجلة'),
    'vendor_refunded_minor': (
      'Vendor refund allocation',
      'حصة المورد المستردة',
    ),
    'unpaid_credited_minor': (
      'Noncash invoice credits',
      'أرصدة الفواتير غير النقدية',
    ),
    'net_collected_minor': (
      'Net collected source amount',
      'صافي التحصيل بحسب المصدر',
    ),
    'amount_due_minor': ('Amount due', 'المبلغ المستحق'),
    'pending_reserved_minor': (
      'Pending settlement reservations',
      'حجوزات التسوية المعلقة',
    ),
    'completed_settlement_minor': (
      'Completed settlement records',
      'سجلات التسوية المكتملة',
    ),
    'available_minor': (
      'Invoice availability from server',
      'المتاح لهذه الفاتورة بحسب الخادم',
    ),
  };
  final Map<String, dynamic> source;
  final List<Map<String, dynamic>> rows;
  VendorEarningsSnapshot._(this.source, this.rows);

  factory VendorEarningsSnapshot.parse(
    dynamic raw, {
    required int vendorId,
    required String currency,
    required int page,
  }) {
    bool integer(dynamic n) => n is int && n.abs() <= 9007199254740991;
    bool precision(dynamic n) => n is int && n >= 0 && n <= 3;
    Never invalid() =>
        throw const FormatException('Unsupported earnings evidence.');
    if (raw is! Map ||
        vendorId < 1 ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
        raw['vendor_id'] != vendorId ||
        raw['currency'] != currency ||
        raw['evidence'] != 'SIMULATED_CANONICAL_SETTLEMENT' ||
        raw['real_payout_enabled'] != false ||
        raw['external_psp_or_bank_verified'] != false ||
        raw['overall_balance_scope'] !=
            'ALL_VENDOR_INVOICES_AND_APPROVED_ADJUSTMENTS' ||
        !precision(raw['minor_units']) ||
        !integer(raw['overall_available_minor']) ||
        !integer(raw['hold_days']) ||
        raw['hold_days'] < 0 ||
        raw['data'] is! List ||
        !integer(raw['current_page']) ||
        raw['current_page'] != page ||
        page < 1 ||
        !integer(raw['per_page']) ||
        raw['per_page'] < 1 ||
        raw['per_page'] > 25 ||
        !integer(raw['total']) ||
        raw['total'] < 0 ||
        !integer(raw['last_page']))
      invalid();
    final total = raw['total'] as int, size = raw['per_page'] as int;
    final last = total == 0 ? 1 : (total + size - 1) ~/ size;
    final remaining = total - (page - 1) * size;
    final expected = remaining < 0
        ? 0
        : remaining > size
        ? size
        : remaining;
    if (raw['last_page'] != last ||
        page > last ||
        (raw['data'] as List).length != expected)
      invalid();
    final rows = <Map<String, dynamic>>[], ids = <int>{};
    for (final row in raw['data'] as List) {
      if (row is! Map ||
          !integer(row['invoice_id']) ||
          row['invoice_id'] < 1 ||
          !ids.add(row['invoice_id'] as int) ||
          row['currency'] != currency ||
          !precision(row['minor_units']) ||
          row['invoice_number'] is! String ||
          (row['invoice_number'] as String).isEmpty ||
          (row['invoice_number'] as String).length > 150 ||
          row['evidence'] is! String ||
          !RegExp(r'^[A-Z][A-Z0-9_]{0,63}$').hasMatch(row['evidence']) ||
          amounts.keys.any((field) => !integer(row[field])))
        invalid();
      rows.add(
        Map<String, dynamic>.unmodifiable(Map<String, dynamic>.from(row)),
      );
    }
    final immutableRows = List<Map<String, dynamic>>.unmodifiable(rows);
    return VendorEarningsSnapshot._(
      Map<String, dynamic>.unmodifiable({
        ...Map<String, dynamic>.from(raw),
        'data': immutableRows,
      }),
      immutableRows,
    );
  }
}

class VendorEarningsView extends StatefulWidget {
  final EvApi api;
  final String currency;
  final VoidCallback onExpired;
  const VendorEarningsView({
    super.key,
    required this.api,
    required this.currency,
    required this.onExpired,
  });
  @override
  State<VendorEarningsView> createState() => _VendorEarningsState();
}

class _VendorEarningsState extends State<VendorEarningsView>
    with WidgetsBindingObserver {
  VendorEarningsSnapshot? report;
  String? csvPreview;
  bool Function()? session;
  StreamSubscription<int>? sessionEvents;

  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted) return;
      setState(() {
        generation++;
        report = null;
        csvPreview = null;
        error = null;
        busy = false;
        hidden = true;
        session = null;
      });
    });
  }

  Object? error;
  bool busy = false, foreground = true, hidden = false;
  int generation = 0;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    observe(widget.api);
    foreground =
        WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    hidden = !foreground;
    load();
  }

  @override
  void didUpdateWidget(covariant VendorEarningsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.currency != widget.currency) {
      if (oldWidget.api != widget.api) observe(widget.api);
      generation++;
      report = null;
      csvPreview = null;
      error = null;
      if (foreground) {
        final api = widget.api, currency = widget.currency;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground && identical(widget.api, api) &&
              widget.currency == currency) load();
        });
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) {
        generation++;
        report = null;
        csvPreview = null;
        error = null;
        busy = false;
        hidden = true;
      }
    });
  }

  @override
  void dispose() {
    sessionEvents?.cancel();
    generation++;
    report = null;
    csvPreview = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    if (!foreground) return;
    final epoch = ++generation, current = widget.api.sessionFence();
    session = current;
    setState(() {
      busy = true;
      error = null;
      report = null;
      csvPreview = null;
      hidden = false;
    });
    bool active() => mounted && foreground && generation == epoch && current();
    try {
      if (!RegExp(r'^[A-Z]{3}$').hasMatch(widget.currency))
        throw const FormatException('Invalid currency.');
      final me = await widget.api.request('me');
      if (!active()) return;
      if (me['role'] != 'vendor')
        throw const ApiException(403, 'Vendor role required.');
      if (me['id'] is! int || me['id'] <= 0)
        throw const FormatException('Invalid account.');
      final raw = await widget.api.request(
        'commercial/vendors/${me['id']}/earnings',
        query: {'currency': widget.currency, 'page': '$page', 'per_page': '20'},
      );
      if (!active()) return;
      final data = VendorEarningsSnapshot.parse(
        raw,
        vendorId: me['id'],
        currency: widget.currency,
        page: page,
      );
      setState(() => report = data);
    } catch (e) {
      if (!active()) return;
      setState(() => error = e);
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
    } finally {
      if (mounted && foreground && generation == epoch) {
        // An account switch can make the old fence false without causing
        // a widget rebuild. Clear its old spinner, invoices and CSV preview;
        // never deliver the stale report or expire the newer session.
        setState(() {
          busy = false;
          if (!current()) {
            report = null;
            csvPreview = null;
            error = null;
            hidden = true;
          }
        });
      }
    }
  }

  /// Copyable in-memory export of the currently visible private invoice
  /// page. Every CSV cell must match the source JSON snapshot.
  Future<void> viewCsv() async {
    final data = report;
    final current = session;
    if (!foreground || busy || data == null || current == null || !current()) {
      return;
    }
    final epoch = generation;
    setState(() {
      busy = true;
      error = null;
      csvPreview = null;
    });
    bool active() => mounted && foreground && generation == epoch &&
        current();
    try {
      final vendorId = data.source['vendor_id'] as int;
      final page = data.source['current_page'] as int;
      final document = await widget.api.downloadVendorEarningsPage(
        vendorId,
        currency: widget.currency,
        page: page,
        perPage: data.source['per_page'] as int,
      );
      if (!active()) return;
      final copy = VendorEarningsCsv.verifiedText(
        document,
        vendorId: vendorId,
        currency: widget.currency,
        page: page,
        source: data.source,
        rows: data.rows,
      );
      if (active()) setState(() => csvPreview = copy);
    } catch (e) {
      if (!active()) return;
      setState(() => error = e);
      if (e is ApiException && [401, 419].contains(e.status)) {
        widget.onExpired();
      }
    } finally {
      if (mounted && foreground && generation == epoch) {
        // An account switch can make the old fence false without causing
        // a widget rebuild. Clear its old spinner, invoices and CSV preview;
        // never deliver the stale report or expire the newer session.
        setState(() {
          busy = false;
          if (!current()) {
            report = null;
            csvPreview = null;
            error = null;
            hidden = true;
          }
        });
      }
    }
  }

  @override
  Widget build(BuildContext c) {
    final current = session?.call() ?? false;
    final data = current ? report : null;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t('Itemized invoice earnings', 'إيرادات الفواتير التفصيلية'),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Internal source records only. No verified bank/PSP receipt or real payout authorization.',
              'سجلات المصدر الداخلية فقط. لا تثبت إيصالاً مصرفياً أو من مزود الدفع ولا تمنح صلاحية صرف حقيقي.',
            ),
          ),
          const SizedBox(height: 12),
          OutlinedButton(
            key: const Key('vendor-earnings-refresh'),
            onPressed: (busy && current) || !foreground ? null : () => load(),
            child: Text(t('Refresh source records', 'تحديث سجلات المصدر')),
          ),
          if (busy && current) const Center(child: CircularProgressIndicator()),
          if (error != null && current)
            Text(
              EvStrings.of(c).t(errorKey(error!)),
              key: const Key('vendor-earnings-error'),
            ),
          if (hidden || !current)
            Text(
              t(
                'Private records cleared. Refresh after returning to this account.',
                'مُسحت السجلات الخاصة. حدّث بعد العودة إلى هذا الحساب.',
              ),
            ),
          if (data != null) ...[
            OutlinedButton(
              key: const Key('vendor-earnings-export'),
              onPressed: busy ? null : viewCsv,
              child: Text(t(
                'View copyable CSV for this invoice page',
                'عرض CSV قابل للنسخ لصفحة الفواتير هذه',
              )),
            ),
            if (csvPreview != null) ...[
              Text(
                t(
                  'Private source CSV, current invoice page only. No bank or PSP verification. Copy manually only to a trusted destination.',
                  'CSV خاص بالمصدر لهذه الصفحة فقط. لا يوجد تحقق مصرفي أو من مزود الدفع. انسخه يدوياً إلى وجهة موثوقة فقط.',
                ),
              ),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: SelectableText(
                  csvPreview!,
                  key: const Key('vendor-earnings-csv-preview'),
                ),
              ),
            ],
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      t(
                        'Overall server-reported availability',
                        'إجمالي المتاح بحسب الخادم',
                      ),
                      style: Theme.of(c).textTheme.titleMedium,
                    ),
                    Text(
                      nativeFinancialAmount(
                        c,
                        data.source,
                        'overall_available_minor',
                      ),
                      key: const Key('vendor-earnings-overall'),
                    ),
                    Text(
                      t(
                        'All vendor invoices and approved adjustments; this is not the total of the displayed page.',
                        'يشمل جميع فواتير المورد والتعديلات المعتمدة؛ وليس مجموع الصفحة المعروضة.',
                      ),
                    ),
                    Text(
                      '${t('Hold days', 'أيام الحجز')}: ${data.source['hold_days']}',
                    ),
                  ],
                ),
              ),
            ),
            if (data.rows.isEmpty)
              Text(
                t(
                  'No invoice records in this currency.',
                  'لا توجد سجلات فواتير بهذه العملة.',
                ),
              ),
            for (final row in data.rows)
              Card(
                key: ValueKey('vendor-earnings-invoice-${row['invoice_id']}'),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        row['invoice_number'] as String,
                        style: Theme.of(c).textTheme.titleMedium,
                      ),
                      Text(
                        '${t('Original invoice evidence', 'مصدر الفاتورة الأصلي')}: ${row['evidence']}',
                      ),
                      for (final field
                          in VendorEarningsSnapshot.amounts.entries)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(
                            '${t(field.value.$1, field.value.$2)}: ${nativeFinancialAmount(c, row, field.key)}',
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            Text(
              '${t('Page', 'الصفحة')} ${data.source['current_page']} / ${data.source['last_page']} · ${data.source['total']}',
            ),
            Wrap(
              spacing: 12,
              children: [
                OutlinedButton(
                  key: const Key('vendor-earnings-previous'),
                  onPressed: busy || data.source['current_page'] <= 1
                      ? null
                      : () => load(page: data.source['current_page'] - 1),
                  child: Text(t('Previous', 'السابق')),
                ),
                OutlinedButton(
                  key: const Key('vendor-earnings-next'),
                  onPressed:
                      busy ||
                          data.source['current_page'] >=
                              data.source['last_page']
                      ? null
                      : () => load(page: data.source['current_page'] + 1),
                  child: Text(t('Next', 'التالي')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
