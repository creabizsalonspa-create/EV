import 'dart:async';

import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';
import 'reservation_times.dart';
import 'strings.dart';
import 'subscriptions.dart';
import 'financial_documents.dart';

const recoveryEn = {
  'subscriptionDetail': 'Review subscription',
  'invoiceDocument': 'Private invoice document',
  'title': 'Payment recovery',
  'notices': 'Subscription notices',
  'stage': 'Reminder stage',
  'payment_due': 'Payment due: review your subscription checkout',
  'recovery_required':
      'Review the existing uncertain payment before further action',
  'settled': 'Invoice settled',
  'cancelled': 'Subscription cancelled',
  'dunningNotice':
      'These in-app reminders do not initiate collection. Check renewal collection for payment attempts. Current invoice status comes from the server.',
  'notice':
      'Test-provider evidence only. A completed operation does not necessarily mean a paid invoice. Inspecting status never initiates a new charge or refund.',
  'empty': 'No payment recovery operations.',
  'emptyNotices': 'No subscription notices.',
  'hidden': 'Private payment recovery details were cleared.',
  'refresh': 'Refresh status',
  'inspect': 'Inspect provider status',
  'confirm':
      'Read the configured provider status for this operation? This cannot initiate a new payment or refund.',
  'cancel': 'Cancel',
  'history': 'Recovery history',
  'unknown':
      'Outcome remains uncertain. Review history and refresh before any further action.',
  'none': 'No recorded events.',
  'next': 'Next',
  'previous': 'Previous',
  'amount': 'Amount',
  'scope': 'Scope',
  'kind': 'Operation',
  'status': 'Status',
  'charging': 'Charging',
  'subscription': 'Subscription',
  'authorize': 'Authorization',
  'capture': 'Capture',
  'refund': 'Refund',
  'cancelOperation': 'Cancellation',
  'prepared': 'Prepared',
  'executing': 'Executing',
  'needs_review': 'Needs review',
  'pending': 'Pending',
  'in_flight': 'In flight',
  'uncertain': 'Uncertain',
  'completed': 'Operation completed',
  'failed': 'Failed',
};
const recoveryAr = {
  'subscriptionDetail': 'مراجعة الاشتراك',
  'invoiceDocument': 'مستند الفاتورة الخاص',
  'title': 'استرداد عمليات الدفع',
  'notices': 'تنبيهات الاشتراك',
  'stage': 'مرحلة التذكير',
  'payment_due': 'دفعة مستحقة: راجع دفع الاشتراك',
  'recovery_required': 'راجع عملية الدفع غير المؤكدة قبل أي إجراء آخر',
  'settled': 'سُددت الفاتورة',
  'cancelled': 'أُلغي الاشتراك',
  'dunningNotice':
      'لا تبدأ هذه التذكيرات التحصيل. راجع تحصيل التجديد لمعرفة محاولات الدفع. حالة الفاتورة الحالية مصدرها الخادم.',
  'notice':
      'أدلة مزود الاختبار فقط. اكتمال العملية لا يعني بالضرورة سداد الفاتورة. فحص الحالة لا ينشئ دفعة أو استرداداً جديداً.',
  'empty': 'لا توجد عمليات دفع للمراجعة.',
  'emptyNotices': 'لا توجد تنبيهات اشتراك.',
  'hidden': 'مُسحت تفاصيل استرداد حالة الدفع الخاصة.',
  'refresh': 'تحديث الحالة',
  'inspect': 'فحص حالة مزود الدفع',
  'confirm':
      'قراءة حالة هذه العملية من المزود المهيأ؟ لا ينشئ ذلك دفعة أو استرداداً جديداً.',
  'cancel': 'إلغاء',
  'history': 'سجل الاسترداد',
  'unknown':
      'النتيجة ما زالت غير مؤكدة. راجع السجل وحدّث الحالة قبل أي إجراء آخر.',
  'none': 'لا توجد أحداث مسجلة.',
  'next': 'التالي',
  'previous': 'السابق',
  'amount': 'المبلغ',
  'scope': 'النطاق',
  'kind': 'العملية',
  'status': 'الحالة',
  'charging': 'الشحن',
  'subscription': 'الاشتراك',
  'authorize': 'التفويض',
  'capture': 'التحصيل',
  'refund': 'استرداد مبلغ',
  'cancelOperation': 'الإلغاء',
  'prepared': 'مهيأة',
  'executing': 'قيد التنفيذ',
  'needs_review': 'تحتاج مراجعة',
  'pending': 'معلقة',
  'in_flight': 'قيد التنفيذ',
  'uncertain': 'غير مؤكدة',
  'completed': 'اكتملت العملية',
  'failed': 'فشلت',
};
String recoveryText(BuildContext c, String key) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? recoveryAr
        : recoveryEn)[key] ??
    key;

/// Reads canonical recovery evidence. Never accepts payment credentials and
/// never retries a mutation, charges a card or infers invoice settlement.
class PaymentRecoveryView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int? intent;
  final bool subscriptionNotices;
  const PaymentRecoveryView({
    super.key,
    required this.api,
    required this.onExpired,
    this.intent,
    this.subscriptionNotices = false,
  });
  @override
  State<PaymentRecoveryView> createState() => _RecoveryState();
}

class _RecoveryState extends State<PaymentRecoveryView>
    with WidgetsBindingObserver {
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false, uncertain = false, confirming = false, foreground = true;
  int generation = 0, target = 1;
  late bool Function() authorized;
  StreamSubscription<int>? sessionChanges;
  String t(String key) => recoveryText(context, key);
  bool get active => mounted && foreground && authorized();
  bool current(int epoch) => active && epoch == generation;
  bool canAct(int epoch) => current(epoch) && !busy && !confirming;
  String get endpoint => widget.subscriptionNotices
      ? 'commercial/subscription-notices'
      : widget.intent == null
      ? 'commercial/payment-recovery'
      : 'commercial/payment-recovery/${widget.intent}/events';
  @override
  void initState() {
    super.initState();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    bindSession();
    if (active) unawaited(load());
  }

  void bindSession() {
    unawaited(sessionChanges?.cancel());
    final api = widget.api;
    authorized = api.sessionFence();
    sessionChanges = api.sessionEvents.listen((_) {
      if (mounted && identical(api, widget.api) && !authorized()) {
        setState(clear);
      }
    });
  }

  void clear() {
    generation++;
    target = 1;
    data = null;
    error = null;
    busy = false;
    uncertain = false;
    confirming = false;
  }

  @override
  void didUpdateWidget(covariant PaymentRecoveryView oldWidget) {
    super.didUpdateWidget(oldWidget);
    final apiChanged = !identical(oldWidget.api, widget.api);
    if (apiChanged ||
        oldWidget.intent != widget.intent ||
        oldWidget.subscriptionNotices != widget.subscriptionNotices) {
      clear();
      if (apiChanged) bindSession();
      if (active) unawaited(load());
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (foreground == resumed) return;
    setState(() {
      foreground = resumed;
      if (!resumed) clear();
    });
  }

  @override
  void dispose() {
    generation++;
    unawaited(sessionChanges?.cancel());
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void fail(Object e) {
    data = null;
    error = e;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int page = 1}) async {
    if (!canAct(generation)) return;
    final epoch = ++generation;
    target = page;
    setState(() {
      busy = true;
      error = null;
      data = null;
    });
    try {
      final result = ApiPage.fromJson(
        await widget.api.request(endpoint, query: {'page': '$page'}),
        (v) => v,
      );
      if (!current(epoch)) return;
      if (widget.intent == null) {
        for (final row in result.items) {
          final amount =
              row[widget.subscriptionNotices ? 'total_minor' : 'amount_minor'];
          final digits = row['minor_units'];
          if (amount is! int ||
              amount < 0 ||
              digits is! int ||
              digits < 0 ||
              digits > 3 ||
              row['currency'] is! String ||
              !RegExp(r'^[A-Z]{3}$').hasMatch(row['currency'])) {
            throw const ApiException(502, 'Unsupported financial precision.');
          }
        }
      }
      setState(() {
        data = result;
        uncertain = false;
      });
    } catch (e) {
      if (current(epoch)) setState(() => fail(e));
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> inspect(int id, int reviewEpoch) async {
    if (!canAct(reviewEpoch)) return;
    final api = widget.api;
    final originalPage = target;
    setState(() => confirming = true);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(recoveryText(c, 'inspect')),
        content: Text(recoveryText(c, 'confirm')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: Text(recoveryText(c, 'cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: Text(recoveryText(c, 'inspect')),
          ),
        ],
      ),
    );
    if (!current(reviewEpoch)) return;
    setState(() => confirming = false);
    if (confirmed != true) return;
    final operation = ++generation;
    setState(() {
      busy = true;
      error = null;
      uncertain = false;
    });
    try {
      await api.request(
        'commercial/payment-recovery/$id/reconcile',
        method: 'POST',
        body: {},
      );
      if (current(operation)) {
        setState(() => busy = false);
        await load(page: originalPage);
      }
    } catch (e) {
      // Lost responses and holds never cause automatic POST replay. A refresh
      // is a GET and cannot create another financial operation.
      if (current(operation)) {
        setState(() {
          uncertain = true;
          fail(e);
        });
      }
    } finally {
      if (current(operation)) setState(() => busy = false);
    }
  }

  Future<void> openRelated(
    int epoch,
    Widget Function(EvApi, VoidCallback) destination, {
    bool refresh = false,
  }) async {
    if (!canAct(epoch)) return;
    final api = widget.api;
    final onExpired = widget.onExpired;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (routeContext) => current(epoch)
            ? destination(api, onExpired)
            : Scaffold(
                body: Center(child: Text(recoveryText(routeContext, 'hidden'))),
              ),
      ),
    );
    if (refresh && canAct(epoch)) await load(page: target);
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = active ? data : null;
    final epoch = generation;
    final ready = canAct(epoch);
    Future<void> refresh(int page) async {
      if (canAct(epoch)) await load(page: page);
    }

    final appBar = AppBar(
      title: Text(
        t(
          widget.subscriptionNotices
              ? 'notices'
              : widget.intent == null
              ? 'title'
              : 'history',
        ),
      ),
    );
    if (!active) {
      return Scaffold(
        appBar: appBar,
        body: Center(child: Text(t('hidden'))),
      );
    }
    return Scaffold(
      appBar: appBar,
      body: RefreshIndicator(
        onRefresh: () => refresh(target),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            Text(t(widget.subscriptionNotices ? 'dunningNotice' : 'notice')),
            if (busy) const LinearProgressIndicator(),
            if (uncertain)
              Semantics(liveRegion: true, child: Text(t('unknown'))),
            if (error != null) Text(s.t(errorKey(error!))),
            TextButton(
              onPressed: ready ? () => refresh(target) : null,
              child: Text(t('refresh')),
            ),
            if (page != null && page.items.isEmpty)
              Text(
                t(
                  widget.subscriptionNotices
                      ? 'emptyNotices'
                      : widget.intent == null
                      ? 'empty'
                      : 'none',
                ),
              ),
            for (final row in page?.items ?? <Map<String, dynamic>>[])
              if (widget.subscriptionNotices)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${row['number']}'),
                        Text('${t('stage')}: ${row['stage']}'),
                        Text(t('${row['attention']}')),
                        Text(
                          '${t('amount')}: ${minorDecimal(row['total_minor'] as int, row['minor_units'] as int)} ${row['currency']}',
                        ),
                        Text(serverEventTime(context, row['created_at'])),
                        if (row['subscription_id'] is int &&
                            row['subscription_id'] > 0)
                          OutlinedButton(
                            onPressed: !ready
                                ? null
                                : () => openRelated(
                                    epoch,
                                    (api, onExpired) => SubscriptionDetailView(
                                      api: api,
                                      onExpired: onExpired,
                                      id: row['subscription_id'] as int,
                                    ),
                                    refresh: true,
                                  ),
                            child: Text(t('subscriptionDetail')),
                          ),
                        if (row['invoice_id'] is int && row['invoice_id'] > 0)
                          OutlinedButton(
                            onPressed: !ready
                                ? null
                                : () => openRelated(
                                    epoch,
                                    (api, onExpired) => FinancialDocumentsView(
                                      api: api,
                                      onExpired: onExpired,
                                      sourceKind: 'subscription_invoice',
                                      sourceId: row['invoice_id'] as int,
                                    ),
                                    refresh: true,
                                  ),
                            child: Text(t('invoiceDocument')),
                          ),
                      ],
                    ),
                  ),
                )
              else
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '#${row['id']} · ${t('status')}: ${t('${row['status']}')}',
                        ),
                        Text(serverEventTime(context, row['created_at'])),
                        if (widget.intent == null) ...[
                          Text(
                            '${t('scope')}: ${t('${row['scope']}')} · ${t('kind')}: ${t(row['kind'] == 'cancel' ? 'cancelOperation' : '${row['kind']}')}',
                          ),
                          Text(
                            '${t('amount')}: ${minorDecimal(row['amount_minor'] as int, row['minor_units'] as int)} ${row['currency']}',
                          ),
                          // Deliberate allowlist: never render arbitrary server JSON or
                          // private provider identity, lease, observation or fingerprint.
                          Wrap(
                            spacing: 8,
                            children: [
                              TextButton(
                                onPressed: !ready
                                    ? null
                                    : () => openRelated(
                                        epoch,
                                        (api, onExpired) => PaymentRecoveryView(
                                          api: api,
                                          onExpired: onExpired,
                                          intent: row['id'] as int,
                                        ),
                                      ),
                                child: Text(t('history')),
                              ),
                              if (row['provider'] == 'stripe_test' &&
                                  row['status'] != 'completed')
                                OutlinedButton(
                                  onPressed: ready
                                      ? () => inspect(row['id'] as int, epoch)
                                      : null,
                                  child: Text(t('inspect')),
                                ),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
            if (page != null)
              Wrap(
                spacing: 12,
                children: [
                  TextButton(
                    onPressed: ready && page.current > 1
                        ? () => refresh(page.current - 1)
                        : null,
                    child: Text(t('previous')),
                  ),
                  Text('${page.current} / ${page.last}'),
                  TextButton(
                    onPressed: ready && page.current < page.last
                        ? () => refresh(page.current + 1)
                        : null,
                    child: Text(t('next')),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }
}
