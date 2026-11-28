import 'dart:async';

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
  bool retired = false;
  StreamSubscription<int>? sessionSubscription;
  int generation = 0;
  bool get active => mounted && foreground && !retired && ownsSession();
  void redact() {
    events = null;
    busy = false;
    failed = false;
    target = 1;
  }
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
    sessionSubscription = widget.api.sessionEvents.listen((_) {
      if (mounted && !ownsSession()) {
        setState(() { retired = true; redact(); });
      }
    });
    if (active) load(1);
  }

  @override
  void didUpdateWidget(covariant HostedCheckoutEventsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.checkoutId != widget.checkoutId ||
        oldWidget.corporate != widget.corporate) {
      // A reused private route never adopts the successor's authority.
      generation++;
      retired = true;
      redact();
    }
  }

  @override
  void dispose() {
    generation++;
    unawaited(sessionSubscription?.cancel());
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final next = state == AppLifecycleState.resumed;
    if (!mounted || foreground == next) return;
    setState(() {
      foreground = next;
      generation++;
      redact();
    });
  }

  bool current(int epoch) => active && generation == epoch;

  DateTime? utcInstant(dynamic value) {
    if (value is! String) return null;
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?Z?$').firstMatch(value);
    if (match == null) return null;
    final fields = [for (var i=1; i<=6; i++) int.parse(match.group(i)!)];
    final utc = DateTime.utc(fields[0], fields[1], fields[2], fields[3], fields[4], fields[5]);
    if (fields[0] < 1 || utc.year != fields[0] || utc.month != fields[1] ||
        utc.day != fields[2] || utc.hour != fields[3] ||
        utc.minute != fields[4] || utc.second != fields[5]) return null;
    return DateTime.tryParse(value.endsWith('Z') ? value : '${value}Z')?.toUtc();
  }

  (String, DateTime) parse(Map<String, dynamic> row) {
    final identity = widget.corporate ? 'checkout_id' : 'hosted_checkout_id';
    final instant = utcInstant(row['created_at']);
    if (row[identity] is! int || row[identity] != widget.checkoutId ||
        row['id'] is! int ||
        (row['id'] as int) < 1 ||
        row['kind'] is! String ||
        instant == null) {
      throw const FormatException('Invalid checkout event');
    }
    return (row['kind'] as String, instant);
  }

  Future<void> load(int page) async {
    if (!active || busy || page < 1 || widget.checkoutId < 1) return;
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
      int? previousId;
      final parsed = ApiPage.fromJson(response, (row) {
        final event = parse(row);
        final id = row['id'] as int;
        if (previousId != null && id >= previousId!) {
          throw const FormatException('Unordered checkout event');
        }
        previousId = id;
        return event;
      });
      if (response['per_page'] is! int ||
          response['per_page'] != 25 ||
          parsed.current != page ||
          parsed.last < 1 ||
          parsed.total < 0 ||
          parsed.last != (parsed.total == 0 ? 1 : (parsed.total - 1) ~/ 25 + 1)) {
        throw const FormatException('Invalid checkout event page');
      }
      final remaining = page > parsed.last
          ? 0
          : parsed.total - (page - 1) * 25;
      final expected = remaining > 25 ? 25 : remaining;
      if (parsed.items.length != expected) {
        throw const FormatException('Incomplete checkout event page');
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
    if (!active) return const Scaffold();
    final rendered = generation;
    final page = events;
    void read(int number) {
      if (current(rendered) && !busy && (ModalRoute.of(context)?.isCurrent ?? true)) load(number);
    }
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
            onPressed: busy ? null : () => read(target),
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
                    onPressed: () => read(page.current - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                if (page.current < page.last)
                  TextButton(
                    onPressed: () => read(page.current + 1),
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
