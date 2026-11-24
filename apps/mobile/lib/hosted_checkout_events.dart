import 'package:flutter/material.dart';

import 'api.dart';

class HostedCheckoutEventsView extends StatefulWidget {
  const HostedCheckoutEventsView({
    super.key,
    required this.api,
    required this.checkoutId,
    required this.corporate,
    required this.onExpired,
  });

  final EvApi api;
  final int checkoutId;
  final bool corporate;
  final VoidCallback onExpired;

  @override
  State<HostedCheckoutEventsView> createState() => _HostedCheckoutEventsState();
}

class _HostedCheckoutEventsState extends State<HostedCheckoutEventsView>
    with WidgetsBindingObserver {
  static const labels = <String, (String, String)>{
    'prepared': ('Checkout prepared', 'تم تجهيز عملية الدفع'),
    'provider_creation_dispatched': (
      'Provider setup requested',
      'تم طلب الإعداد لدى المزود',
    ),
    'provider_session_observed': (
      'Provider session observed',
      'تم رصد جلسة المزود',
    ),
    'provider_creation_uncertain': (
      'Provider setup outcome uncertain',
      'نتيجة الإعداد لدى المزود غير مؤكدة',
    ),
    'provider_no_session_observed': (
      'Provider session unavailable',
      'جلسة المزود غير متاحة',
    ),
    'provider_cancellation_observed': (
      'Provider cancellation observed',
      'تم رصد الإلغاء لدى المزود',
    ),
    'provider_inspected': ('Provider status inspected', 'تم فحص حالة المزود'),
    'capture_observed': ('Test capture recorded', 'تم تسجيل تحصيل اختباري'),
    'cancellation_observed': ('Cancellation recorded', 'تم تسجيل الإلغاء'),
    'mock_opened': ('Test checkout opened', 'تم فتح عملية دفع اختبارية'),
    'mock_authorized': (
      'Test authorization recorded',
      'تم تسجيل تفويض اختباري',
    ),
    'mock_declined': (
      'Test authorization declined',
      'تم رفض التفويض الاختباري',
    ),
    'mock_abandoned': (
      'Test checkout abandoned',
      'تم ترك عملية الدفع الاختبارية',
    ),
    'mock_cancelled': (
      'Test checkout cancelled',
      'تم إلغاء عملية الدفع الاختبارية',
    ),
    'canonical_allocation_observed': (
      'Test allocation recorded',
      'تم تسجيل تخصيص اختباري',
    ),
    'reconciliation_requires_review': (
      'Reconciliation requires review',
      'تتطلب المطابقة المراجعة',
    ),
    'provider_case_requires_review': (
      'Provider case requires review',
      'تتطلب حالة المزود المراجعة',
    ),
  };

  late final bool Function() ownsSession;
  late bool foreground;
  int generation = 0;
  int target = 1;
  bool busy = false;
  bool failed = false;
  ApiPage<(String, DateTime)>? events;

  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    foreground =
        WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (foreground) load(1);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final next = state == AppLifecycleState.resumed;
    if (foreground == next) return;
    setState(() {
      foreground = next;
      generation++;
      events = null;
      busy = false;
      failed = false;
    });
  }

  bool current(int epoch) =>
      mounted && foreground && generation == epoch && ownsSession();

  (String, DateTime) parse(Map<String, dynamic> row) {
    final identity = widget.corporate ? 'checkout_id' : 'hosted_checkout_id';
    final wire = row['created_at'];
    if (row[identity] != widget.checkoutId ||
        row['id'] is! int ||
        (row['id'] as int) < 1 ||
        row['kind'] is! String ||
        wire is! String ||
        !RegExp(
          r'^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$',
        ).hasMatch(wire)) {
      throw const FormatException('Invalid checkout event');
    }
    final instant = DateTime.parse(wire.endsWith('Z') ? wire : '${wire}Z');
    return (row['kind'] as String, instant);
  }

  Future<void> load(int page) async {
    if (!foreground || !ownsSession() || busy) return;
    final epoch = ++generation;
    setState(() {
      target = page;
      busy = true;
      events = null;
      failed = false;
    });
    try {
      final prefix = widget.corporate
          ? 'commercial/corporate/hosted-checkouts'
          : 'commercial/hosted-checkouts';
      final response = await widget.api.request(
        '$prefix/${widget.checkoutId}/events',
        query: {'page': '$page'},
      );
      if (!current(epoch)) return;
      final parsed = ApiPage.fromJson(response, parse);
      if (parsed.current != page || parsed.last < 1) {
        throw const FormatException('Invalid checkout event page');
      }
      setState(() => events = parsed);
    } catch (error) {
      if (!mounted || generation != epoch || !foreground) return;
      if (error is ApiException && error.endsCurrentSession) {
        widget.onExpired();
      } else if (ownsSession()) {
        setState(() => failed = true);
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Widget event((String, DateTime) row) {
    final message =
        labels[row.$1] ??
        ('Checkout event recorded', 'تم تسجيل حدث لعملية الدفع');
    final localizations = MaterialLocalizations.of(context);
    final date = localizations.formatCompactDate(row.$2);
    final time = localizations.formatTimeOfDay(
      TimeOfDay.fromDateTime(row.$2),
      alwaysUse24HourFormat: MediaQuery.alwaysUse24HourFormatOf(context),
    );
    return ListTile(
      title: Text(t(message.$1, message.$2)),
      subtitle: Text(t('$date · $time UTC', '$date · $time بالتوقيت العالمي')),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (!foreground || !ownsSession()) return const Scaffold();
    final page = events;
    return Scaffold(
      appBar: AppBar(title: Text(t('Checkout activity', 'نشاط عملية الدفع'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Recorded test checkout events are shown in UTC.',
              'تُعرض أحداث عملية الدفع الاختبارية المسجلة بالتوقيت العالمي.',
            ),
          ),
          TextButton(
            onPressed: busy ? null : () => load(target),
            child: Text(t('Refresh activity', 'تحديث النشاط')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (failed)
            Semantics(
              liveRegion: true,
              child: Text(
                t(
                  'Could not load checkout activity.',
                  'تعذر تحميل نشاط عملية الدفع.',
                ),
              ),
            ),
          if (page != null) ...[
            if (page.items.isEmpty)
              Text(
                t(
                  'No checkout activity recorded.',
                  'لا يوجد نشاط مسجل لعملية الدفع.',
                ),
              ),
            for (final row in page.items) event(row),
            Wrap(
              children: [
                if (page.current > 1)
                  TextButton(
                    onPressed: () => load(page.current - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                if (page.current < page.last)
                  TextButton(
                    onPressed: () => load(page.current + 1),
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
