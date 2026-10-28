import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'financial_operation_store.dart';
import 'financial_documents.dart';
import 'vendor_finance.dart' show nativeFinancialAmount;
import 'recurring_payments.dart';
import 'payment_recovery.dart';

const subscriptionEn = {
  'title': 'Subscriptions',
  'plans': 'Available plans',
  'mine': 'My subscriptions',
  'notice':
      'Test-provider billing only. Enrollment creates an invoice; authorization is not payment. Benefits are enabled only by authoritative server receipts. Plan changes apply at the next cycle; no immediate proration is promised.',
  'price': 'Published price before tax',
  'month': 'Monthly',
  'year': 'Yearly',
  'enroll': 'Enroll in this plan',
  'change_plan': 'Schedule this plan',
  'cancel_at_period_end': 'Cancel at period end',
  'cancel_now': 'Cancel now',
  'resume': 'Resume renewal',
  'renew': 'Check due renewal',
  'detail': 'Details',
  'refresh': 'Refresh',
  'next': 'Next',
  'previous': 'Previous',
  'empty': 'No records.',
  'confirm': 'Confirm saved intent',
  'consent':
      'I reviewed this operation. It does not capture a payment or prove active benefits.',
  'pendingIntent':
      'An unconfirmed intent is saved. Review source history before explicitly resending the same exact request. No automatic retry or replacement identity.',
  'done': 'Server confirmed. Refresh the subscription for its current state.',
  'invoices': 'Invoices',
  'documents': 'Private document',
  'history': 'Lifecycle history',
  'benefits': 'Server-confirmed active benefits',
  'noBenefits': 'Benefits are not active',
  'payments': 'Saved payment authorizations and collection',
  'recovery': 'Inspect uncertain payments',
  'notices': 'Payment reminders',
  'active': 'Active',
  'pending': 'Pending payment',
  'past_due': 'Past due',
  'cancelled': 'Cancelled',
  'unknown': 'Unknown state',
  'paid': 'Receipt confirmed',
  'unpaid': 'No receipt',
  'end': 'Current period end (UTC)',
  'scheduled': 'Next plan ID',
  'cancelling': 'Cancellation scheduled at period end',
  'subscribed': 'Enrolled',
  'plan_change_scheduled': 'Plan change scheduled',
  'renewal_invoiced': 'Renewal invoice created',
  'cancelled_on_expiry': 'Cancelled on expiry',
};
const subscriptionAr = {
  'title': 'الاشتراكات',
  'plans': 'الخطط المتاحة',
  'mine': 'اشتراكاتي',
  'notice':
      'فوترة مزود اختبار فقط. التسجيل ينشئ فاتورة ولا يعني التفويض السداد. يفعّل الخادم المزايا بناءً على إيصالات مؤكدة فقط. تغيير الخطة للدورة التالية ولا يعد بتناسب فوري.',
  'price': 'السعر المنشور قبل الضريبة',
  'month': 'شهري',
  'year': 'سنوي',
  'enroll': 'التسجيل بهذه الخطة',
  'change_plan': 'جدولة هذه الخطة',
  'cancel_at_period_end': 'الإلغاء عند نهاية الفترة',
  'cancel_now': 'الإلغاء الآن',
  'resume': 'استئناف التجديد',
  'renew': 'فحص التجديد المستحق',
  'detail': 'التفاصيل',
  'refresh': 'تحديث',
  'next': 'التالي',
  'previous': 'السابق',
  'empty': 'لا توجد سجلات.',
  'confirm': 'تأكيد النية المحفوظة',
  'consent': 'راجعت العملية. لا تحصّل دفعة ولا تثبت تفعيل المزايا.',
  'pendingIntent':
      'نية غير مؤكدة محفوظة. راجع السجل قبل إعادة إرسال الطلب نفسه صراحةً. لا إعادة تلقائية أو معرف بديل.',
  'done': 'أكد الخادم. حدّث الاشتراك لمعرفة حالته الحالية.',
  'invoices': 'الفواتير',
  'documents': 'مستند خاص',
  'history': 'سجل الاشتراك',
  'benefits': 'المزايا الفعالة المؤكدة من الخادم',
  'noBenefits': 'المزايا غير فعالة',
  'payments': 'تفويضات الدفع المحفوظة والتحصيل',
  'recovery': 'فحص الدفعات غير المؤكدة',
  'notices': 'تذكيرات الدفع',
  'active': 'فعال',
  'pending': 'بانتظار السداد',
  'past_due': 'متأخر',
  'cancelled': 'ملغى',
  'unknown': 'حالة غير معروفة',
  'paid': 'إيصال مؤكد',
  'unpaid': 'لا يوجد إيصال',
  'end': 'نهاية الفترة الحالية (UTC)',
  'scheduled': 'معرف الخطة التالية',
  'cancelling': 'الإلغاء مجدول عند نهاية الفترة',
  'subscribed': 'تم التسجيل',
  'plan_change_scheduled': 'تمت جدولة تغيير الخطة',
  'renewal_invoiced': 'أُنشئت فاتورة التجديد',
  'cancelled_on_expiry': 'أُلغي عند الانتهاء',
};
String subscriptionText(BuildContext c, String k) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? subscriptionAr
        : subscriptionEn)[k] ??
    subscriptionEn['unknown']!;

/// Server summary validation is shared by reads and mutation acknowledgements.
/// A response for another subscription/owner never clears a pending operation.
Map<String, dynamic> subscriptionSummary(dynamic value, int owner, {int? id}) {
  final data = Map<String, dynamic>.from(value as Map);
  final sub = data['subscription'];
  if (sub is! Map ||
      sub['id'] is! int ||
      sub['id'] < 1 ||
      sub['user_id'] != owner ||
      (id != null && sub['id'] != id) ||
      data['real_payment_processed'] != false ||
      data['ledger_balanced'] != true ||
      data['entitlements_active'] is! bool ||
      data['entitlements'] is! Map && data['entitlements'] is! List ||
      data['invoices'] is! List ||
      data['plan'] is! Map) {
    throw const FormatException('Unsupported subscription evidence.');
  }
  for (final raw in data['invoices'] as List) {
    if (raw is! Map ||
        raw['id'] is! int ||
        raw['subscription_id'] != sub['id'] ||
        raw['paid'] is! bool) {
      throw const FormatException('Unsupported invoice evidence.');
    }
  }
  return data;
}

List<String> subscriptionActions(Map<String, dynamic> sub, {DateTime? now}) {
  if (sub['status'] == 'cancelled') return const [];
  final end = DateTime.tryParse('${sub['period_end']}');
  final current =
      sub['status'] == 'active' &&
      end != null &&
      end.isAfter(now ?? DateTime.now());
  return [
    if (current && sub['cancel_at_period_end'] == false) 'change_plan',
    if (current && sub['cancel_at_period_end'] == false) 'cancel_at_period_end',
    if (current && sub['cancel_at_period_end'] == true) 'resume',
    'cancel_now',
  ];
}

class SubscriptionsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int? changeSubscription;
  const SubscriptionsView({
    super.key,
    required this.api,
    required this.onExpired,
    this.changeSubscription,
  });
  @override
  State<SubscriptionsView> createState() => _SubscriptionsState();
}

class _SubscriptionsState extends State<SubscriptionsView> {
  bool plans = true, busy = false;
  int generation = 0, number = 1;
  Object? error;
  ApiPage<Map<String, dynamic>>? page;
  String t(String k) => subscriptionText(context, k);
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

  Future<void> load({int target = 1}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      page = null;
      number = target;
    });
    try {
      final me = await widget.api.request('me');
      if (!['customer', 'vendor'].contains(me['role']))
        throw const ApiException(403, 'Role required.');
      final raw = await widget.api.request(
        'commercial/${plans ? 'plans' : 'subscriptions'}',
        query: {'page': '$target'},
      );
      if (!mounted || epoch != generation) return;
      final parsed = ApiPage.fromJson(raw, (r) => r);
      for (final r in parsed.items) {
        if (r['id'] is! int || r['id'] < 1)
          throw const FormatException('Invalid source.');
        if (plans) {
          if (r['audience'] != me['role'] ||
              r['available'] != true ||
              !['month', 'year'].contains(r['interval']))
            throw const FormatException('Invalid plan.');
          nativeFinancialAmount(context, r, 'price_minor');
        } else if (r['user_id'] != me['id']) {
          throw const FormatException('Invalid owner.');
        }
      }
      page = parsed;
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

  Future<void> open(Map<String, dynamic> row) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => plans
            ? SubscriptionActionView(
                api: widget.api,
                onExpired: widget.onExpired,
                action: widget.changeSubscription == null
                    ? 'enroll'
                    : 'change_plan',
                subscriptionId: widget.changeSubscription,
                plan: row,
              )
            : SubscriptionDetailView(
                api: widget.api,
                onExpired: widget.onExpired,
                id: row['id'] as int,
              ),
      ),
    );
    if (mounted) load(target: number);
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('title'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (widget.changeSubscription == null)
          Wrap(
            spacing: 8,
            children: [
              for (final p in [true, false])
                ChoiceChip(
                  label: Text(t(p ? 'plans' : 'mine')),
                  selected: plans == p,
                  onSelected: busy
                      ? null
                      : (_) {
                          plans = p;
                          load();
                        },
                ),
            ],
          ),
        OutlinedButton(
          onPressed: busy ? null : () => load(target: number),
          child: Text(t('refresh')),
        ),
        if (busy) const Center(child: CircularProgressIndicator()),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (page != null) ...[
          if (page!.items.isEmpty) Text(t('empty')),
          for (final r in page!.items)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      plans
                          ? '${r['name']}'
                          : '#${r['id']} · ${t('${r['status']}')}',
                    ),
                    if (plans) ...[
                      Text(
                        '${t('price')}: ${nativeFinancialAmount(c, r, 'price_minor')}',
                      ),
                      Text(t('${r['interval']}')),
                      Text('${r['entitlements']}'),
                    ],
                    OutlinedButton(
                      onPressed: busy ? null : () => open(r),
                      child: Text(
                        t(
                          plans
                              ? (widget.changeSubscription == null
                                    ? 'enroll'
                                    : 'change_plan')
                              : 'detail',
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton(
                onPressed: busy || number <= 1
                    ? null
                    : () => load(target: number - 1),
                child: Text(t('previous')),
              ),
              Text('$number / ${page!.last}'),
              OutlinedButton(
                onPressed: busy || number >= page!.last
                    ? null
                    : () => load(target: number + 1),
                child: Text(t('next')),
              ),
            ],
          ),
        ],
      ],
    ),
  );
}

class SubscriptionActionView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int? subscriptionId;
  final Map<String, dynamic>? plan;
  final String action;
  final FinancialOperationStore? store;
  const SubscriptionActionView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.action,
    this.subscriptionId,
    this.plan,
    this.store,
  });
  @override
  State<SubscriptionActionView> createState() => _SubscriptionActionState();
}

class _SubscriptionActionState extends State<SubscriptionActionView> {
  bool busy = true, consent = false, done = false, blocked = false;
  Object? error;
  int owner = 0;
  FinancialOperation? operation;
  String t(String k) => subscriptionText(context, k);
  @override
  void initState() {
    super.initState();
    initialize();
  }

  Future<void> initialize() async {
    try {
      if (![
            'enroll',
            'change_plan',
            'cancel_at_period_end',
            'cancel_now',
            'resume',
          ].contains(widget.action) ||
          (widget.action != 'enroll' &&
              (widget.subscriptionId == null || widget.subscriptionId! < 1)) ||
          (['enroll', 'change_plan'].contains(widget.action) &&
              widget.plan?['id'] is! int))
        throw const FormatException('Invalid operation.');
      final me = await widget.api.request('me');
      if (!['customer', 'vendor'].contains(me['role']) || me['id'] is! int)
        throw const ApiException(403, 'Role required.');
      owner = me['id'] as int;
      if (widget.subscriptionId != null)
        subscriptionSummary(
          await widget.api.request(
            'commercial/subscriptions/${widget.subscriptionId}',
          ),
          owner,
          id: widget.subscriptionId,
        );
      if (!mounted) return;
      operation = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}|$owner|subscription|${widget.subscriptionId ?? 0}|${widget.action}',
      );
      await operation!.restore();
      final original = operation!.pending?['body'];
      if (original is Map &&
          original['plan_id'] != null &&
          original['plan_id'] != widget.plan?['id']) {
        blocked = true;
        throw const ApiException(
          409,
          'Resolve the original plan intent first.',
        );
      }
    } catch (e) {
      if (mounted) fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  void fail(Object e) {
    error = e;
    if (e is ApiException && [401, 419].contains(e.status)) {
      operation = null;
      widget.onExpired();
    }
  }

  Future<void> submit() async {
    if (busy || !consent || operation == null || blocked) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = operation!.pending == null
          ? <String, dynamic>{
              if (widget.action == 'enroll' || widget.action == 'change_plan')
                'plan_id': widget.plan!['id'],
              if (widget.action != 'enroll') 'action': widget.action,
            }
          : Map<String, dynamic>.from(operation!.pending!['body'] as Map);
      final saved = await operation!.prepare(body);
      final raw = await widget.api.request(
        widget.action == 'enroll'
            ? 'commercial/subscriptions'
            : 'commercial/subscriptions/${widget.subscriptionId}/change',
        method: 'POST',
        body: saved,
      );
      final summary = subscriptionSummary(
        raw,
        owner,
        id: widget.subscriptionId,
      );
      if (widget.action == 'enroll' &&
          summary['subscription']['plan_id'] != saved['plan_id'])
        throw const FormatException('Wrong enrollment receipt.');
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
    appBar: AppBar(title: Text(t(widget.action))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (widget.plan != null && !blocked) ...[
          Text('${widget.plan!['name']}'),
          Text(
            '${t('price')}: ${nativeFinancialAmount(c, widget.plan!, 'price_minor')}',
          ),
        ],
        if (widget.subscriptionId != null) Text('#${widget.subscriptionId}'),
        if (operation?.pending != null) ...[
          Text(t('pendingIntent')),
          Text('${operation!.pending!['body']}'),
        ],
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (done) Text(t('done')),
        if (busy) const Center(child: CircularProgressIndicator()),
        if (!done) ...[
          CheckboxListTile(
            value: consent,
            onChanged: busy || operation == null || blocked
                ? null
                : (v) => setState(() => consent = v!),
            title: Text(t('consent')),
          ),
          FilledButton(
            onPressed: busy || !consent || operation == null || blocked
                ? null
                : submit,
            child: Text(t('confirm')),
          ),
        ],
      ],
    ),
  );
}

class SubscriptionDetailView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int id;
  const SubscriptionDetailView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.id,
  });
  @override
  State<SubscriptionDetailView> createState() => _SubscriptionDetailState();
}

class _SubscriptionDetailState extends State<SubscriptionDetailView> {
  Map<String, dynamic>? summary;
  ApiPage<Map<String, dynamic>>? events;
  int generation = 0, page = 1;
  bool busy = false;
  Object? error;
  String t(String k) => subscriptionText(context, k);
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
    setState(() {
      busy = true;
      summary = null;
      events = null;
      error = null;
      page = number;
    });
    try {
      final me = await widget.api.request('me');
      final data = subscriptionSummary(
        await widget.api.request('commercial/subscriptions/${widget.id}'),
        me['id'] as int,
        id: widget.id,
      );
      final history = ApiPage.fromJson(
        await widget.api.request(
          'commercial/subscriptions/${widget.id}/events',
          query: {'page': '$number'},
        ),
        (r) => r,
      );
      if (!mounted || epoch != generation) return;
      for (final i in data['invoices'] as List) {
        nativeFinancialAmount(
          context,
          Map<String, dynamic>.from(i as Map),
          'total_minor',
        );
      }
      summary = data;
      events = history;
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

  Future<void> push(Widget view) async {
    await Navigator.of(
      context,
    ).push(MaterialPageRoute<void>(builder: (_) => view));
    if (mounted) load(number: page);
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text('${t('title')} #${widget.id}')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        OutlinedButton(
          onPressed: busy ? null : () => load(number: page),
          child: Text(t('refresh')),
        ),
        if (busy) const Center(child: CircularProgressIndicator()),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (summary != null) ...[
          Text(
            '${summary!['plan']['name']} · ${t('${summary!['subscription']['status']}')}',
          ),
          Text(
            t(
              summary!['entitlements_active'] == true
                  ? 'benefits'
                  : 'noBenefits',
            ),
          ),
          if (summary!['entitlements_active'] == true)
            Text('${summary!['entitlements']}'),
          Text('${t('end')}: ${summary!['subscription']['period_end'] ?? '—'}'),
          if (summary!['subscription']['next_plan_id'] != null)
            Text(
              '${t('scheduled')}: ${summary!['subscription']['next_plan_id']}',
            ),
          if (summary!['subscription']['cancel_at_period_end'] == true)
            Text(t('cancelling')),
          if (subscriptionActions(
            Map<String, dynamic>.from(summary!['subscription'] as Map),
          ).contains('change_plan')) ...[
            OutlinedButton(
              onPressed: () => push(
                SubscriptionsView(
                  api: widget.api,
                  onExpired: widget.onExpired,
                  changeSubscription: widget.id,
                ),
              ),
              child: Text(t('change_plan')),
            ),
          ],
          for (final action in subscriptionActions(
            Map<String, dynamic>.from(summary!['subscription'] as Map),
          ).where((a) => a != 'change_plan'))
            OutlinedButton(
              onPressed: () => push(
                SubscriptionActionView(
                  api: widget.api,
                  onExpired: widget.onExpired,
                  action: action,
                  subscriptionId: widget.id,
                ),
              ),
              child: Text(t(action)),
            ),
          OutlinedButton(
            onPressed: () => push(
              RecurringPaymentsView(
                api: widget.api,
                onExpired: widget.onExpired,
              ),
            ),
            child: Text(t('payments')),
          ),
          OutlinedButton(
            onPressed: () => push(
              PaymentRecoveryView(api: widget.api, onExpired: widget.onExpired),
            ),
            child: Text(t('recovery')),
          ),
          OutlinedButton(
            onPressed: () => push(
              PaymentRecoveryView(
                api: widget.api,
                onExpired: widget.onExpired,
                subscriptionNotices: true,
              ),
            ),
            child: Text(t('notices')),
          ),
          Text(t('invoices'), style: Theme.of(c).textTheme.titleLarge),
          for (final raw in summary!['invoices'] as List)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '#${raw['id']} · ${nativeFinancialAmount(c, Map<String, dynamic>.from(raw as Map), 'total_minor')}',
                    ),
                    Text(t(raw['paid'] == true ? 'paid' : 'unpaid')),
                    Text('${raw['period_start']} — ${raw['period_end']}'),
                    OutlinedButton(
                      onPressed: () => push(
                        FinancialDocumentsView(
                          api: widget.api,
                          onExpired: widget.onExpired,
                          sourceKind: 'subscription_invoice',
                          sourceId: raw['id'] as int,
                        ),
                      ),
                      child: Text(t('documents')),
                    ),
                  ],
                ),
              ),
            ),
          Text(t('history'), style: Theme.of(c).textTheme.titleLarge),
          for (final e in events!.items)
            ListTile(
              title: Text(t('${e['kind']}')),
              subtitle: Text('${e['created_at']}'),
            ),
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton(
                onPressed: page <= 1 ? null : () => load(number: page - 1),
                child: Text(t('previous')),
              ),
              Text('$page / ${events!.last}'),
              OutlinedButton(
                onPressed: page >= events!.last
                    ? null
                    : () => load(number: page + 1),
                child: Text(t('next')),
              ),
            ],
          ),
        ],
      ],
    ),
  );
}
