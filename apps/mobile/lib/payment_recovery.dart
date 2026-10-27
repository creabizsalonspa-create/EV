import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

const recoveryEn = {
  'title': 'Payment recovery',
  'notices': 'Subscription notices',
  'stage': 'Reminder stage',
  'payment_due': 'Payment due: review your subscription checkout',
  'recovery_required':
      'Review the existing uncertain payment before further action',
  'settled': 'Invoice settled',
  'cancelled': 'Subscription cancelled',
  'dunningNotice':
      'In-app reminders only. No automatic collection was attempted. Current invoice status comes from the server.',
  'notice':
      'Test-provider evidence only. A completed operation does not necessarily mean a paid invoice. Inspecting status never initiates a new charge or refund.',
  'empty': 'No payment recovery operations.',
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
  'amount': 'Amount in minor units',
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
  'title': 'استرداد عمليات الدفع',
  'notices': 'تنبيهات الاشتراك',
  'stage': 'مرحلة التذكير',
  'payment_due': 'دفعة مستحقة: راجع دفع الاشتراك',
  'recovery_required': 'راجع عملية الدفع غير المؤكدة قبل أي إجراء آخر',
  'settled': 'سُددت الفاتورة',
  'cancelled': 'أُلغي الاشتراك',
  'dunningNotice':
      'تذكيرات داخل التطبيق فقط. لم تُجر محاولة تحصيل تلقائية. حالة الفاتورة الحالية مصدرها الخادم.',
  'notice':
      'أدلة مزود الاختبار فقط. اكتمال العملية لا يعني بالضرورة سداد الفاتورة. فحص الحالة لا ينشئ دفعة أو استرداداً جديداً.',
  'empty': 'لا توجد عمليات دفع للمراجعة.',
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
  'amount': 'المبلغ بالوحدات الصغرى',
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

class _RecoveryState extends State<PaymentRecoveryView> {
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false, uncertain = false;
  int generation = 0, target = 1;
  String t(String key) => recoveryText(context, key);
  String get endpoint => widget.subscriptionNotices
      ? 'commercial/subscription-notices'
      : widget.intent == null
      ? 'commercial/payment-recovery'
      : 'commercial/payment-recovery/${widget.intent}/events';
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
    data = null;
    error = e;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int page = 1}) async {
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
      if (mounted && generation == epoch) setState(() => data = result);
    } catch (e) {
      if (mounted && generation == epoch) setState(() => fail(e));
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  Future<void> inspect(int id) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t('inspect')),
        content: Text(t('confirm')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: Text(t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: Text(t('inspect')),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() {
      busy = true;
      error = null;
      uncertain = false;
    });
    try {
      await widget.api.request(
        'commercial/payment-recovery/$id/reconcile',
        method: 'POST',
        body: {},
      );
      if (mounted) await load(page: target);
    } catch (e) {
      // Lost responses and holds never cause automatic POST replay. A refresh
      // is a GET and cannot create another financial operation.
      if (mounted)
        setState(() {
          uncertain = true;
          fail(e);
        });
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t(
            widget.subscriptionNotices
                ? 'notices'
                : widget.intent == null
                ? 'title'
                : 'history',
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () => load(page: target),
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
              onPressed: busy ? null : () => load(page: target),
              child: Text(t('refresh')),
            ),
            if (page != null && page.items.isEmpty)
              Text(t(widget.intent == null ? 'empty' : 'none')),
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
                          '${t('amount')}: ${row['total_minor']} ${row['currency']}',
                        ),
                        Text('${row['created_at']}'),
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
                        Text('${row['created_at'] ?? ''}'),
                        if (widget.intent == null) ...[
                          Text(
                            '${t('scope')}: ${t('${row['scope']}')} · ${t('kind')}: ${t(row['kind'] == 'cancel' ? 'cancelOperation' : '${row['kind']}')}',
                          ),
                          Text(
                            '${t('amount')}: ${row['amount_minor']} ${row['currency']}',
                          ),
                          // Deliberate allowlist: never render arbitrary server JSON or
                          // private provider identity, lease, observation or fingerprint.
                          Wrap(
                            spacing: 8,
                            children: [
                              TextButton(
                                onPressed: busy
                                    ? null
                                    : () => Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => PaymentRecoveryView(
                                            api: widget.api,
                                            onExpired: widget.onExpired,
                                            intent: row['id'] as int,
                                          ),
                                        ),
                                      ),
                                child: Text(t('history')),
                              ),
                              if (row['provider'] == 'stripe_test' &&
                                  row['status'] != 'completed')
                                OutlinedButton(
                                  onPressed: busy
                                      ? null
                                      : () => inspect(row['id'] as int),
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
                    onPressed: !busy && page.current > 1
                        ? () => load(page: page.current - 1)
                        : null,
                    child: Text(t('previous')),
                  ),
                  Text('${page.current} / ${page.last}'),
                  TextButton(
                    onPressed: !busy && page.current < page.last
                        ? () => load(page: page.current + 1)
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
