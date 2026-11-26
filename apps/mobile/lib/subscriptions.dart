import 'dart:async';
import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'subscription_preview.dart';
import 'hosted_checkout.dart';
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
  'renewConsent': 'Check this billing cycle with the server? If due, the server may issue its renewal invoice or finalize a scheduled cancellation. This does not collect payment.',
  'renewConfirm': 'Check this cycle',
  'renewDismiss': 'Not now',
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
  'privateCleared': 'Private subscription source cleared. Refresh after signing in again.',
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
  'renewConsent': 'هل تريد فحص دورة الفوترة هذه لدى الخادم؟ عند الاستحقاق قد يصدر فاتورة التجديد أو ينفذ الإلغاء المجدول. لا يتم تحصيل دفعة.',
  'renewConfirm': 'فحص هذه الدورة',
  'renewDismiss': 'ليس الآن',
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
  'privateCleared': 'مُسحت بيانات الاشتراك الخاصة. حدّث بعد تسجيل الدخول مجدداً.',
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

/// Freeze validated invoice, entitlement and owner evidence before a client
/// retains it. A mutable fixture/transport map must never rewrite accepted
/// financial source metadata after the account authorization check.
dynamic _immutableSubscriptionValue(dynamic value) {
  if (value is Map) {
    return Map<String, dynamic>.unmodifiable(value.map(
      (key, item) => MapEntry(
        key as String, _immutableSubscriptionValue(item),
      ),
    ));
  }
  if (value is List) {
    return List<dynamic>.unmodifiable(
      value.map(_immutableSubscriptionValue),
    );
  }
  return value;
}

/// Server summary validation is shared by reads and mutation acknowledgements.
/// A response for another subscription/owner never clears a pending operation.
Map<String, dynamic> subscriptionSummary(dynamic value, int owner, {int? id}) {
  final data = Map<String, dynamic>.from(value as Map);
  final sub = data['subscription'];
  // Native/browser JSON must never round a persisted financial identity.
  bool sourceId(dynamic id) =>
      id is int && id >= 1 && id <= 9007199254740991;
  if (sub is! Map ||
      !sourceId(owner) || !sourceId(sub['id']) ||
      !sourceId(sub['plan_id']) ||
      sub['user_id'] != owner ||
      (id != null && sub['id'] != id) ||
      data['plan'] is! Map ||
      !sourceId((data['plan'] as Map)['id']) ||
      (data['plan'] as Map)['id'] != sub['plan_id'] ||
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
        !sourceId(raw['id']) ||
        raw['subscription_id'] != sub['id'] ||
        raw['paid'] is! bool) {
      throw const FormatException('Unsupported invoice evidence.');
    }
  }
  return _immutableSubscriptionValue(data) as Map<String, dynamic>;
}

/// The current summary is not proof of a particular lifecycle mutation.
/// The immutable event receipt binds the actor, target, action and saved key.
bool subscriptionOperationAcknowledged(
  Map<String, dynamic> summary,
  Map<String, dynamic> saved, {
  required int owner,
  required String action,
}) {
  final sub = summary['subscription'];
  final receipt = summary['operation_receipt'];
  final key = saved['idempotency_key'];
  final plan = saved['plan_id'];
  bool sourceId(dynamic v) => v is int && v >= 1 && v <= 9007199254740991;
  if (sub is! Map || receipt is! Map || key is! String || key.isEmpty ||
      !sourceId(owner) || !sourceId(sub['id']) || sub['user_id'] != owner ||
      !const ['enroll', 'change_plan', 'cancel_at_period_end', 'resume',
        'cancel_now'].contains(action) ||
      (action == 'enroll' ? saved.containsKey('action') : saved['action'] != action) ||
      (['enroll', 'change_plan'].contains(action) ? !sourceId(plan) : plan != null) ||
      !sourceId(receipt['event_id']) || !sourceId(receipt['actor_id']) ||
      !sourceId(receipt['subscription_id']) || receipt['actor_id'] != owner ||
      receipt['subscription_id'] != sub['id'] || receipt['action'] != action ||
      !receipt.containsKey('plan_id') ||
      (plan == null ? receipt['plan_id'] != null :
        !sourceId(receipt['plan_id']) || receipt['plan_id'] != plan) ||
      receipt.containsKey('request_key') || receipt.containsKey('fingerprint') ||
      receipt.containsKey('idempotency_key')) {
    return false;
  }
  final binding = sha256.convert(utf8.encode(
    'subscription-operation-v1:$owner:${sub['id']}:${receipt['event_id']}:$action:${plan ?? 0}:$key',
  )).toString();
  return receipt['request_receipt'] == binding;
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

/// A renewal check is a cycle-scoped, non-payment operation. The server
/// determines whether a new invoice or scheduled cancellation is due.
bool subscriptionCanCheckRenewal(dynamic sub) {
  if (sub is! Map || !['active', 'past_due'].contains(sub['status'])) {
    return false;
  }
  final cycle = sub['cycle_number'], end = sub['period_end'];
  return cycle is int && cycle >= 1 && cycle <= 9007199254740991 &&
      end is String && DateTime.tryParse(end) != null;
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

class _SubscriptionsState extends State<SubscriptionsView>
    with WidgetsBindingObserver {
  bool plans = true, busy = false, foreground = true, hidden = false;
  bool Function()? currentSession;
  StreamSubscription<int>? sessionEvents;
  int generation = 0, number = 1;

  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted) return;
      setState(() {
        generation++;
        page = null;
        error = null;
        currentSession = null;
        busy = false;
        hidden = true;
      });
    });
  }
  bool get current => foreground && (currentSession?.call() ?? false);
  Object? error;
  ApiPage<Map<String, dynamic>>? page;
  String t(String k) => subscriptionText(context, k);
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    observe(widget.api);
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    hidden = !foreground;
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant SubscriptionsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.changeSubscription != widget.changeSubscription) {
      if (oldWidget.api != widget.api) observe(widget.api);
      generation++;
      page = null;
      error = null;
      currentSession = null;
      busy = false;
      hidden = true;
      if (foreground) {
        final api = widget.api, target = widget.changeSubscription;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground && identical(api, widget.api) &&
              target == widget.changeSubscription) load();
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
        page = null;
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
    page = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int target = 1}) async {
    if (!foreground || target < 1 || target > 100000) return;
    final epoch = ++generation;
    final session = widget.api.sessionFence();
    currentSession = session;
    bool active() => mounted && foreground &&
        epoch == generation && session();
    setState(() {
      busy = true;
      error = null;
      page = null;
      number = target;
      hidden = false;
    });
    try {
      final me = await widget.api.request('me');
      if (!active()) return;
      if (me is! Map || !['customer', 'vendor'].contains(me['role']) ||
          me['id'] is! int || me['id'] < 1 ||
          me['id'] > 9007199254740991)
        throw const ApiException(403, 'Role required.');
      final raw = await widget.api.request(
        'commercial/${plans ? 'plans' : 'subscriptions'}',
        query: {'page': '$target'},
      );
      if (!active()) return;
      final parsed = ApiPage<Map<String, dynamic>>.fromJson(
        raw,
        (r) => _immutableSubscriptionValue(r) as Map<String, dynamic>,
      );
      // Paginated private source must not claim a different page,
      // an impossible last page or a rounded JSON-wide count.
      if (parsed.current != target || parsed.last < target ||
          parsed.last > 100000 || parsed.total < 0 ||
          parsed.total > 9007199254740991 || parsed.items.length > 25 ||
          (parsed.total == 0 && parsed.items.isNotEmpty))
        throw const FormatException('Invalid catalog page.');
      for (final r in parsed.items) {
        if (r['id'] is! int || r['id'] < 1 ||
            r['id'] > 9007199254740991)
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
      if (active()) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) {
        // The native API may already have erased its current credentials
        // before raising 401. Expire only that original request, never a
        // stale failure from a later account or background lifecycle.
        widget.onExpired();
      }
    } finally {
      if (mounted && foreground && epoch == generation) {
        // Session rotation may invalidate the old fence without scheduling a
        // repaint. Stop that old spinner and erase private source instead
        // of waiting forever for a response belonging to a former account.
        setState(() {
          busy = false;
          if (!session()) {
            page = null;
            error = null;
            hidden = true;
          }
        });
      }
    }
  }

  Future<void> open(Map<String, dynamic> row) async {
    if (!current || busy) return;
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
        if (error != null && current)
          Text(EvStrings.of(c).t(errorKey(error!))),
        if (hidden || !current) Text(t('privateCleared')),
        if (page != null && current) ...[
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

class _SubscriptionActionState extends State<SubscriptionActionView>
    with WidgetsBindingObserver {
  bool busy = true, consent = false, done = false, blocked = false;
  bool foreground = true, hidden = false;
  bool Function()? currentSession;
  StreamSubscription<int>? sessionEvents;
  Map<String, dynamic>? sourcePlan;

  void freezePlan() {
    // A retained transport Map must not change the plan/price after
    // displaying consent or while an encrypted replay intent is pending.
    sourcePlan = widget.plan == null
        ? null
        : _immutableSubscriptionValue(widget.plan) as Map<String, dynamic>;
  }

  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted) return;
      setState(() {
        clearPrivate();
        currentSession = null;
      });
    });
  }

  Object? error;
  int owner = 0, generation = 0;
  FinancialOperation? operation;
  bool get current =>
      foreground && !hidden && (currentSession?.call() ?? false);
  bool active(int epoch, bool Function() fence) =>
      mounted && foreground && epoch == generation && fence();

  void clearPrivate() {
    generation++;
    owner = 0;
    operation = null;
    sourcePlan = null;
    error = null;
    consent = false;
    done = false;
    blocked = false;
    busy = false;
    hidden = true;
  }
  String t(String k) => subscriptionText(context, k);
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    observe(widget.api);
    freezePlan();
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    hidden = !foreground;
    if (foreground) initialize();
  }

  @override
  void didUpdateWidget(covariant SubscriptionActionView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.action != widget.action ||
        oldWidget.subscriptionId != widget.subscriptionId ||
        oldWidget.plan?['id'] != widget.plan?['id']) {
      if (oldWidget.api != widget.api) observe(widget.api);
      clearPrivate();
      currentSession = null;
      freezePlan();
      if (foreground) {
        final api = widget.api, action = widget.action;
        final id = widget.subscriptionId, plan = sourcePlan?['id'];
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground && identical(api, widget.api) &&
              action == widget.action && id == widget.subscriptionId &&
              plan == sourcePlan?['id']) initialize();
        });
      }
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
    clearPrivate();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> initialize() async {
    if (!foreground) return;
    final epoch = ++generation, fence = widget.api.sessionFence();
    currentSession = fence;
    setState(() {
      busy = true;
      error = null;
      blocked = false;
      consent = false;
      done = false;
      operation = null;
      hidden = false;
    });
    try {
      if (![
            'enroll',
            'change_plan',
            'cancel_at_period_end',
            'cancel_now',
            'resume',
          ].contains(widget.action) ||
          (widget.action != 'enroll' &&
              (widget.subscriptionId == null || widget.subscriptionId! < 1 ||
                  widget.subscriptionId! > 9007199254740991)) ||
          (['enroll', 'change_plan'].contains(widget.action) &&
              (sourcePlan?['id'] is! int || sourcePlan!['id'] < 1 ||
                  sourcePlan!['id'] > 9007199254740991)))
        throw const FormatException('Invalid operation.');
      final me = await widget.api.request('me');
      if (!active(epoch, fence)) return;
      if (me is! Map ||
          !['customer', 'vendor'].contains(me['role']) ||
          me['id'] is! int || me['id'] < 1 ||
          me['id'] > 9007199254740991)
        throw const ApiException(403, 'Role required.');
      owner = me['id'] as int;
      if (widget.subscriptionId != null) {
        subscriptionSummary(
          await widget.api.request(
            'commercial/subscriptions/${widget.subscriptionId}',
          ),
          owner,
          id: widget.subscriptionId,
        );
      }
      if (!active(epoch, fence)) return;
      operation = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}|$owner|subscription|${widget.subscriptionId ?? 0}|${widget.action}',
        // A key persisted for this screen must not be removed by an old
        // acknowledgement after the app backgrounds or this form becomes
        // another owner/plan/action, even if the same API login survives.
        isCurrent: () => active(epoch, fence),
      );
      await operation!.restore();
      if (!active(epoch, fence)) return;
      final original = operation!.pending?['body'];
      if (original is Map &&
          original['plan_id'] != null &&
          original['plan_id'] != sourcePlan?['id']) {
        blocked = true;
        throw const ApiException(
          409,
          'Resolve the original plan intent first.',
        );
      }
    } catch (e) {
      if (active(epoch, fence)) {
        fail(e);
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
      }
    } finally {
      if (mounted && foreground && epoch == generation) {
        setState(() {
          if (!fence()) {
            // A revoked request must not leave its old spinner or saved
            // nonce visible; the original durable storage entry survives.
            clearPrivate();
          } else {
            busy = false;
          }
        });
      }
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
    if (busy || !consent || operation == null || blocked || !current) return;
    final epoch = generation, fence = currentSession!;
    final intent = operation!;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = intent.pending == null
          ? <String, dynamic>{
              if (widget.action == 'enroll' || widget.action == 'change_plan')
                'plan_id': sourcePlan!['id'],
              if (widget.action != 'enroll') 'action': widget.action,
            }
          : Map<String, dynamic>.from(intent.pending!['body'] as Map);
      final saved = await intent.prepare(body);
      if (!active(epoch, fence)) return;
      final raw = await widget.api.request(
        widget.action == 'enroll'
            ? 'commercial/subscriptions'
            : 'commercial/subscriptions/${widget.subscriptionId}/change',
        method: 'POST',
        body: saved,
      );
      if (!active(epoch, fence)) return;
      final summary = subscriptionSummary(
        raw,
        owner,
        id: widget.subscriptionId,
      );
      if (!subscriptionOperationAcknowledged(
          summary, saved, owner: owner, action: widget.action)) {
        throw const FormatException('Wrong subscription operation receipt.');
      }
      await intent.confirmed();
      if (active(epoch, fence)) {
        setState(() {
          done = true;
          consent = false;
        });
      }
    } catch (e) {
      if (active(epoch, fence)) {
        fail(e);
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
      }
    } finally {
      if (mounted && foreground && epoch == generation) {
        setState(() {
          if (!fence()) {
            // A revoked request must not leave its old spinner or saved
            // nonce visible; the original durable storage entry survives.
            clearPrivate();
          } else {
            busy = false;
          }
        });
      }
    }
  }

  @override
  Widget build(BuildContext c) => !current
    ? Scaffold(
        appBar: AppBar(title: Text(t(widget.action))),
        body: Center(child: Text(t('privateCleared'))),
      )
    : Scaffold(
    appBar: AppBar(title: Text(t(widget.action))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (sourcePlan != null && operation != null && !blocked) ...[
          Text('${sourcePlan!['name']}'),
          Text(
            '${t('price')}: ${nativeFinancialAmount(c, sourcePlan!, 'price_minor')}',
          ),
        ],
        if (widget.subscriptionId != null) Text('#${widget.subscriptionId}'),
        if (widget.action == 'change_plan' && !busy && !blocked && !done &&
            operation != null && widget.subscriptionId != null &&
            sourcePlan?['id'] is int)
          SubscriptionPreviewPanel(
            key: ValueKey('subscription-preview-${widget.subscriptionId}-${sourcePlan!['id']}'),
            api: widget.api,
            subscriptionId: widget.subscriptionId!,
            planId: sourcePlan!['id'] as int,
            onExpired: widget.onExpired,
          ),
        if (operation?.pending != null) ...[
          Text(t('pendingIntent')),
          // Saved replay keys remain only inside encrypted operation state.
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

class _SubscriptionDetailState extends State<SubscriptionDetailView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? summary;
  ApiPage<Map<String, dynamic>>? events;
  bool Function()? currentSession;
  StreamSubscription<int>? sessionEvents;
  int generation = 0, page = 1;

  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted) return;
      setState(() {
        generation++;
        summary = null;
        events = null;
        error = null;
        currentSession = null;
        busy = false;
        renewalConsentOpen = false;
        hidden = true;
      });
    });
  }
  bool busy = false, foreground = true, hidden = false;
  bool renewalConsentOpen = false;
  bool get current => foreground && (currentSession?.call() ?? false);
  Object? error;
  String t(String k) => subscriptionText(context, k);
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    observe(widget.api);
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    hidden = !foreground;
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant SubscriptionDetailView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.id != widget.id) {
      if (oldWidget.api != widget.api) observe(widget.api);
      generation++;
      summary = null;
      events = null;
      error = null;
      currentSession = null;
      hidden = true;
      busy = false;
      renewalConsentOpen = false;
      if (foreground) {
        final api = widget.api, id = widget.id;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground && identical(api, widget.api) &&
              id == widget.id) load();
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
        summary = null;
        events = null;
        error = null;
        busy = false;
        renewalConsentOpen = false;
        hidden = true;
      }
    });
  }

  @override
  void dispose() {
    sessionEvents?.cancel();
    generation++;
    summary = null;
    events = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int number = 1}) async {
    if (!foreground || number < 1 || number > 100000) return;
    final epoch = ++generation;
    final session = widget.api.sessionFence();
    currentSession = session;
    bool active() => mounted && foreground &&
        epoch == generation && session();
    setState(() {
      busy = true;
      renewalConsentOpen = false;
      summary = null;
      events = null;
      error = null;
      page = number;
      hidden = false;
    });
    try {
      if (widget.id < 1 || widget.id > 9007199254740991)
        throw const FormatException('Invalid subscription identity.');
      final me = await widget.api.request('me');
      if (!active()) return;
      if (me is! Map || !['customer', 'vendor'].contains(me['role']) ||
          me['id'] is! int || me['id'] < 1 ||
          me['id'] > 9007199254740991) {
        throw const FormatException('Untrusted subscription account.');
      }
      final data = subscriptionSummary(
        await widget.api.request('commercial/subscriptions/${widget.id}'),
        me['id'] as int,
        id: widget.id,
      );
      if (!active()) return;
      final history = ApiPage<Map<String, dynamic>>.fromJson(
        await widget.api.request(
          'commercial/subscriptions/${widget.id}/events',
          query: {'page': '$number'},
        ),
        (r) => _immutableSubscriptionValue(r) as Map<String, dynamic>,
      );
      if (!active()) return;
      // History is a private authenticated read; a fabricated page or
      // source event ID must never be rendered with a genuine invoice.
      if (history.current != number || history.last < number ||
          history.last > 100000 || history.total < 0 ||
          history.total > 9007199254740991 || history.items.length > 25 ||
          (history.total == 0 && history.items.isNotEmpty))
        throw const FormatException('Invalid subscription history page.');
      for (final row in history.items) {
        if (row['id'] is! int || row['id'] < 1 ||
            row['id'] > 9007199254740991 ||
            row['kind'] is! String ||
            (row['kind'] as String).isEmpty ||
            (row['kind'] as String).length > 100 ||
            row['created_at'] is! String)
          throw const FormatException('Invalid subscription history event.');
      }
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
      if (active()) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) {
        // The native API may already have erased its current credentials
        // before raising 401. Expire only that original request, never a
        // stale failure from a later account or background lifecycle.
        widget.onExpired();
      }
    } finally {
      if (mounted && foreground && epoch == generation) {
        // Session rotation may invalidate the old fence without scheduling a
        // repaint. Stop that old spinner and erase private source instead
        // of waiting forever for a response belonging to a former account.
        setState(() {
          busy = false;
          if (!session()) {
            summary = null;
            events = null;
            error = null;
            hidden = true;
          }
        });
      }
    }
  }

  Future<void> push(Widget view) async {
    if (!current || busy) return;
    final session = currentSession!, epoch = generation;
    await Navigator.of(
      context,
    ).push(MaterialPageRoute<void>(builder: (_) => view));
    if (mounted && foreground && epoch == generation && session()) {
      await load(number: page);
    }
  }

  Future<void> checkRenewal() async {
    final source = summary;
    if (busy || !current || hidden || source == null ||
        !subscriptionCanCheckRenewal(source['subscription'])) return;
    final sub = source['subscription'] as Map;
    final id = widget.id, owner = sub['user_id'];
    if (owner is! int) return;
    final cycle = sub['cycle_number'] as int;
    final api = widget.api, session = currentSession!, epoch = generation;
    bool active() => mounted && foreground && !hidden &&
        epoch == generation && session() &&
        identical(api, widget.api) && id == widget.id;
    setState(() {
      busy = true;
      renewalConsentOpen = true;
      error = null;
    });
    try {
      final agreed = await showDialog<bool>(
        context: context,
        builder: (dialog) => AlertDialog(
          title: Text(subscriptionText(dialog, 'renew')),
          content: Text(subscriptionText(dialog, 'renewConsent')),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialog).pop(false),
              child: Text(subscriptionText(dialog, 'renewDismiss')),
            ),
            FilledButton(
              key: const Key('subscription-renew-confirm'),
              onPressed: () => Navigator.of(dialog).pop(true),
              child: Text(subscriptionText(dialog, 'renewConfirm')),
            ),
          ],
        ),
      );
      if (!active() || agreed != true) return;
      setState(() => renewalConsentOpen = false);
      final account = await api.request('me');
      if (!active()) return;
      if (account is! Map || account['id'] is! int ||
          account['id'] != owner ||
          !['customer', 'vendor'].contains(account['role'])) {
        throw const FormatException('Renewal account changed.');
      }
      final result = await api.request(
        'commercial/subscriptions/$id/renew',
        method: 'POST',
        body: {'expected_cycle_number': cycle},
      );
      if (!active()) return;
      final accepted = subscriptionSummary(result, owner, id: id);
      if (accepted['subscription']['cycle_number'] is! int ||
          accepted['subscription']['cycle_number'] != cycle) {
        throw const FormatException('Unexpected renewal cycle.');
      }
      // The authoritative result may be unchanged. Refresh the current
      // summary/history; never imply that an invoice or payment occurred.
      await load();
    } catch (e) {
      if (active()) {
        setState(() {
          summary = null;
          events = null;
          error = e;
        });
        if (e is ApiException && e.endsCurrentSession) widget.onExpired();
      } else if (mounted && foreground && epoch == generation &&
          e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
      }
    } finally {
      if (active()) {
        setState(() {
          busy = false;
          renewalConsentOpen = false;
        });
      }
    }
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
        // Consent is not an in-flight network operation. Do not animate a
        // progress spinner behind the modal while waiting for the user.
        if (busy && !renewalConsentOpen)
          const Center(child: CircularProgressIndicator()),
        if (error != null && current)
          Text(EvStrings.of(c).t(errorKey(error!))),
        if (hidden || !current) Text(t('privateCleared')),
        if (summary != null && current) ...[
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
          if (subscriptionCanCheckRenewal(summary!['subscription']))
            OutlinedButton(
              key: const Key('subscription-renew-check'),
              onPressed: busy ? null : checkRenewal,
              child: Text(t('renew')),
            ),
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
                    OutlinedButton(
                      onPressed: () => push(
                        HostedCheckoutView(
                          api: widget.api,
                          onExpired: widget.onExpired,
                          scope: 'subscription',
                          invoiceId: raw['id'] as int,
                          subscriptionId: widget.id,
                          sourceOwnerId:
                              summary!['subscription']['user_id'] as int?,
                        ),
                      ),
                      child: Text(hostedText(c, 'title')),
                    ),
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
