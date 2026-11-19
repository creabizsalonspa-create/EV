import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'vendor_finance.dart' show nativeFinancialAmount;

/// Display authoritative server terms; never calculate tax, credits or charges.
class SubscriptionChangePreview {
  final Map<String, dynamic> values;
  SubscriptionChangePreview._(this.values);

  factory SubscriptionChangePreview.parse(
    dynamic raw, {
    required int subscriptionId,
    required int planId,
  }) {
    if (raw is! Map || raw.keys.any((key) => key is! String)) {
      throw const FormatException('Invalid subscription preview.');
    }
    final value = Map<String, dynamic>.from(raw);
    bool integer(dynamic number, {bool signed = false}) =>
        number is int &&
        number >= (signed ? -9007199254740991 : 0) &&
        number <= 9007199254740991;
    final effective = value['effective_at_utc'];
    final instant = effective is String ? DateTime.tryParse(effective) : null;
    if (!integer(value['subscription_id']) ||
        !integer(value['proposed_plan_id']) ||
        value['subscription_id'] != subscriptionId ||
        subscriptionId < 1 ||
        value['proposed_plan_id'] != planId ||
        planId < 1 ||
        !integer(value['current_plan_id']) ||
        value['current_plan_id'] < 1 ||
        !integer(value['proposed_plan_version']) ||
        value['proposed_plan_version'] < 1 ||
        value['currency'] is! String ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(value['currency']) ||
        value['minor_units'] is! int ||
        value['minor_units'] < 0 ||
        value['minor_units'] > 3 ||
        !['month', 'year'].contains(value['interval']) ||
        !['month', 'year'].contains(value['current_plan_interval']) ||
        effective is! String ||
        !RegExp(
          r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$',
        ).hasMatch(effective) ||
        instant == null ||
        !instant.isUtc ||
        instant.toIso8601String().substring(0, 19) !=
            effective.substring(0, 19) ||
        value['evidence'] != 'SIMULATED_NEXT_CYCLE_PREVIEW' ||
        value['amount_due_now_minor'] is! int ||
        value['amount_due_now_minor'] != 0 ||
        [
          'proration_supported',
          'next_cycle_invoice_issued',
          'quote_is_binding',
          'real_payment_processed',
          'comparison_is_proration',
        ].any((key) => value[key] != false) ||
        [
          'next_cycle_subtotal_minor',
          'next_cycle_tax_minor',
          'next_cycle_total_minor',
          'current_plan_list_total_minor',
        ].any((key) => !integer(value[key])) ||
        value['cycles_comparable'] is! bool ||
        value['cycles_comparable'] !=
            (value['interval'] == value['current_plan_interval']) ||
        (value['cycles_comparable'] == true
            ? !integer(
                value['nominal_plan_list_difference_minor'],
                signed: true,
              )
            : value['nominal_plan_list_difference_minor'] != null)) {
      throw const FormatException(
        'Unverified nonbinding subscription preview.',
      );
    }
    const keys = [
      'subscription_id',
      'current_plan_id',
      'proposed_plan_id',
      'proposed_plan_version',
      'currency',
      'minor_units',
      'interval',
      'current_plan_interval',
      'effective_at_utc',
      'next_cycle_subtotal_minor',
      'next_cycle_tax_minor',
      'next_cycle_total_minor',
      'current_plan_list_total_minor',
      'cycles_comparable',
      'nominal_plan_list_difference_minor',
      'amount_due_now_minor',
    ];
    return SubscriptionChangePreview._(
      Map.unmodifiable({for (final key in keys) key: value[key]}),
    );
  }
}

/// Optional GET-only preview alongside the separate saved-intent confirmation.
class SubscriptionPreviewPanel extends StatefulWidget {
  final EvApi api;
  final int subscriptionId, planId;
  final VoidCallback onExpired;
  const SubscriptionPreviewPanel({
    super.key,
    required this.api,
    required this.subscriptionId,
    required this.planId,
    required this.onExpired,
  });
  @override
  State<SubscriptionPreviewPanel> createState() => _SubscriptionPreviewState();
}

class _SubscriptionPreviewState extends State<SubscriptionPreviewPanel>
    with WidgetsBindingObserver {
  SubscriptionChangePreview? preview;
  Object? error;
  bool busy = false, foreground = true;
  int generation = 0;
  late bool Function() session;
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  void clear() {
    generation++;
    preview = null;
    error = null;
    busy = false;
  }

  @override
  void initState() {
    super.initState();
    session = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didUpdateWidget(covariant SubscriptionPreviewPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.subscriptionId != widget.subscriptionId ||
        oldWidget.planId != widget.planId) {
      clear();
      session = widget.api.sessionFence();
    } else if (!session()) {
      clear(); // Do not silently rebind an old subscription to a new account.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted)
      setState(() {
        if (!foreground) clear();
      });
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> read() async {
    if (busy || !foreground || !session()) return;
    final request = ++generation,
        api = widget.api,
        subscription = widget.subscriptionId,
        plan = widget.planId;
    final current = api.sessionFence();
    bool active() =>
        mounted &&
        foreground &&
        session() &&
        current() &&
        request == generation &&
        identical(api, widget.api);
    setState(() {
      busy = true;
      preview = null;
      error = null;
    });
    try {
      final data = await api.request(
        'commercial/subscriptions/$subscription/change-preview',
        query: {'plan_id': '$plan'},
      );
      if (!active()) return;
      final validated = SubscriptionChangePreview.parse(
        data,
        subscriptionId: subscription,
        planId: plan,
      );
      setState(() => preview = validated);
    } catch (e) {
      if (!active()) return;
      setState(() => error = e);
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
    } finally {
      if (active()) {
        setState(() => busy = false);
      } else if (mounted &&
          request == generation &&
          identical(api, widget.api) &&
          !session()) {
        setState(clear);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!foreground)
      return Text(
        t(
          'Preview hidden while the app is inactive.',
          'المعاينة مخفية أثناء عدم نشاط التطبيق.',
        ),
      );
    if (!session())
      return Text(
        t(
          'Account changed. Reopen the subscription.',
          'تغير الحساب. أعد فتح الاشتراك.',
        ),
      );
    final data = preview?.values;
    final buildGeneration = generation, buildSession = session;
    Widget amount(String key, String en, String ar) =>
        Text('${t(en, ar)}: ${nativeFinancialAmount(context, data!, key)}');
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              t('Preview next billing cycle', 'معاينة دورة الفوترة التالية'),
              style: Theme.of(context).textTheme.titleMedium,
            ),
            Text(
              t(
                'Test-provider, nonbinding list terms. Scheduling uses the separate confirmation below.',
                'شروط سعر مزود اختبار غير ملزمة. تستخدم الجدولة التأكيد المنفصل أدناه.',
              ),
            ),
            OutlinedButton.icon(
              key: const Key('subscription-preview-read'),
              onPressed: busy
                  ? null
                  : () {
                      if (generation == buildGeneration &&
                          identical(session, buildSession) &&
                          session() &&
                          foreground)
                        read();
                    },
              icon: const Icon(Icons.preview),
              label: Text(t('Read server preview', 'قراءة معاينة الخادم')),
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null)
              Semantics(
                liveRegion: true,
                child: Text(EvStrings.of(context).t(errorKey(error!))),
              ),
            if (data != null) ...[
              Text(
                '${t('Effective next cycle (UTC)', 'سريان الدورة التالية (UTC)')}: ${data['effective_at_utc']}',
              ),
              amount(
                'amount_due_now_minor',
                'Amount due now',
                'المبلغ المستحق الآن',
              ),
              amount(
                'next_cycle_subtotal_minor',
                'Next-cycle published subtotal',
                'السعر الأساسي المعلن للدورة التالية',
              ),
              amount(
                'next_cycle_tax_minor',
                'Next-cycle tax',
                'ضريبة الدورة التالية',
              ),
              amount(
                'next_cycle_total_minor',
                'Next-cycle published total',
                'الإجمالي المعلن للدورة التالية',
              ),
              amount(
                'current_plan_list_total_minor',
                'Current plan published total',
                'الإجمالي المعلن للخطة الحالية',
              ),
              if (data['cycles_comparable'] == true)
                amount(
                  'nominal_plan_list_difference_minor',
                  'Nominal published-price difference',
                  'الفرق الاسمي في السعر المعلن',
                )
              else
                Text(
                  t(
                    'Billing cadences differ; no nominal difference is comparable.',
                    'تختلف دورات الفوترة؛ لا تتوفر مقارنة للفرق الاسمي.',
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
