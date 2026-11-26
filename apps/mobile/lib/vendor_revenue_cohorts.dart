import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';

/// Read-only issuance-cohort projection from the existing canonical invoice ledger.
/// This is NOT recognized revenue, cashflow, settlement eligibility or bank proof.
class VendorRevenueCohortSnapshot {
  static const int maxExact = 9007199254740991;
  static const fields = [
    'invoice_count',
    'invoiced_minor',
    'vendor_allocated_minor',
    'commission_allocated_minor',
    'paid_minor',
    'refunded_minor',
    'vendor_refunded_minor',
    'unpaid_credited_minor',
    'vendor_credited_minor',
  ];

  final Map<String, dynamic> source;
  final List<Map<String, dynamic>> days;
  final Map<String, dynamic> totals;

  VendorRevenueCohortSnapshot._(this.source, this.days, this.totals);

  static int dateDay(String value) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(value);
    if (match == null) throw const FormatException('Invalid UTC date.');
    final y = int.parse(match[1]!);
    final m = int.parse(match[2]!);
    final d = int.parse(match[3]!);
    final date = DateTime.utc(y, m, d);
    if (date.year != y || date.month != m || date.day != d) {
      throw const FormatException('Invalid UTC date.');
    }
    return date.millisecondsSinceEpoch ~/ Duration.millisecondsPerDay;
  }

  static int exact(dynamic value, {bool signed = false}) {
    if (value is! int ||
        value > maxExact ||
        value < (signed ? -maxExact : 0)) {
      throw const FormatException('Inexact canonical financial amount.');
    }
    return value;
  }

  static VendorRevenueCohortSnapshot parse(
    dynamic raw, {
    required int vendorId,
    required String currency,
    required String from,
    required String to,
  }) {
    if (raw is! Map) throw const FormatException('Invalid report.');
    final map = Map<String, dynamic>.from(raw);
    if (vendorId < 1 ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
        map['vendor_id'] != vendorId ||
        map['currency'] != currency ||
        map['from'] != from ||
        map['to'] != to ||
        map['minor_units'] is! int ||
        (map['minor_units'] as int) < 0 ||
        (map['minor_units'] as int) > 3 ||
        map['evidence'] != 'SIMULATED_CANONICAL_FINANCE' ||
        map['basis'] != 'INVOICE_ISSUANCE_DATE_UTC_COHORT' ||
        map['payment_and_refund_totals'] !=
            'LIFETIME_OF_SELECTED_INVOICE_COHORT_AS_OF_QUERY' ||
        map['not_cashflow_or_payout_eligibility'] != true ||
        map['external_psp_or_bank_verified'] != false ||
        map['real_money_movement'] != false ||
        map['data'] is! List ||
        map['totals'] is! Map) {
      throw const FormatException('Untrusted invoice cohort source.');
    }

    final first = dateDay(from);
    final last = dateDay(to);
    final count = last - first + 1;
    if (count < 1 || count > 93 || (map['data'] as List).length != count) {
      throw const FormatException('Unexpected invoice cohort period.');
    }

    final aggregate = {for (final field in fields) field: 0};
    final days = <Map<String, dynamic>>[];
    for (var i = 0; i < count; i++) {
      final item = (map['data'] as List)[i];
      if (item is! Map) throw const FormatException('Invalid cohort row.');
      final day = Map<String, dynamic>.from(item);
      if (day['date_utc'] is! String ||
          dateDay(day['date_utc'] as String) != first + i) {
        throw const FormatException('Unordered cohort dates.');
      }
      for (final field in fields) {
        final amount = exact(day[field]);
        if (amount > maxExact - aggregate[field]!) {
          throw const FormatException('Cohort sum exceeds exact bounds.');
        }
        aggregate[field] = aggregate[field]! + amount;
      }
      if (exact(day['net_collected_minor'], signed: true) !=
              day['paid_minor'] - day['refunded_minor'] ||
          exact(day['net_vendor_allocated_minor'], signed: true) !=
              day['vendor_allocated_minor'] -
                  day['vendor_refunded_minor'] -
                  day['vendor_credited_minor']) {
        throw const FormatException('Inconsistent cohort economics.');
      }
      days.add(Map<String, dynamic>.unmodifiable(day));
    }

    final total = Map<String, dynamic>.from(map['totals'] as Map);
    for (final field in fields) {
      if (exact(total[field]) != aggregate[field]) {
        throw const FormatException('Unreconciled invoice cohort.');
      }
    }
    if (exact(total['net_collected_minor'], signed: true) !=
            aggregate['paid_minor']! - aggregate['refunded_minor']! ||
        exact(total['net_vendor_allocated_minor'], signed: true) !=
            aggregate['vendor_allocated_minor']! -
                aggregate['vendor_refunded_minor']! -
                aggregate['vendor_credited_minor']!) {
      throw const FormatException('Unreconciled invoice cohort totals.');
    }

    final immutableDays = List<Map<String, dynamic>>.unmodifiable(days);
    final immutableTotals = Map<String, dynamic>.unmodifiable(total);
    final immutableSource = Map<String, dynamic>.unmodifiable({
      ...map,
      'data': immutableDays,
      'totals': immutableTotals,
    });
    return VendorRevenueCohortSnapshot._(
      immutableSource,
      immutableDays,
      immutableTotals,
    );
  }

  String money(String field, {Map<String, dynamic>? row}) {
    final amount = exact(
      (row ?? totals)[field],
      signed: field.startsWith('net_'),
    );
    final sign = amount < 0 ? '-' : '';
    return '${source['currency']} $sign${minorDecimal(amount.abs(), source['minor_units'] as int)}';
  }
}

class VendorRevenueCohortView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String currency;

  const VendorRevenueCohortView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.currency,
  });

  @override
  State<VendorRevenueCohortView> createState() =>
      _VendorRevenueCohortState();
}

class _VendorRevenueCohortState extends State<VendorRevenueCohortView>
    with WidgetsBindingObserver {
  final from = TextEditingController();
  final to = TextEditingController();
  VendorRevenueCohortSnapshot? report;
  bool Function()? currentAuthority;
  StreamSubscription<int>? sessionEvents;

  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted) return;
      setState(() {
        invalidate();
        currentAuthority = null;
      });
    });
  }

  Object? error;
  bool busy = false;
  bool foreground = true;
  bool cleared = false;
  int generation = 0;

  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    observe(widget.api);
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    final today = DateTime.now().toUtc();
    to.text = today.toIso8601String().substring(0, 10);
    from.text = today.subtract(const Duration(days: 29))
        .toIso8601String().substring(0, 10);
    cleared = !foreground;
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant VendorRevenueCohortView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.currency != widget.currency) {
      if (oldWidget.api != widget.api) observe(widget.api);
      invalidate();
      currentAuthority = null;
      if (foreground) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground) load();
        });
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) invalidate();
    });
  }

  void invalidate() {
    generation++;
    report = null;
    error = null;
    busy = false;
    cleared = true;
  }

  @override
  void dispose() {
    sessionEvents?.cancel();
    invalidate();
    from.dispose();
    to.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void edited(String value) => setState(invalidate);

  Future<void> load() async {
    if (!foreground) return;
    final attempt = ++generation;
    final session = widget.api.sessionFence();
    currentAuthority = session;
    setState(() {
      report = null;
      error = null;
      busy = true;
      cleared = false;
    });
    bool active() => mounted && foreground &&
        generation == attempt && session();

    try {
      final start = from.text.trim(), end = to.text.trim();
      final days = VendorRevenueCohortSnapshot.dateDay(end) -
          VendorRevenueCohortSnapshot.dateDay(start) + 1;
      if (days < 1 || days > 93 ||
          end.compareTo(DateTime.now().toUtc()
              .toIso8601String().substring(0, 10)) > 0 ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(widget.currency)) {
        throw const FormatException('Invalid cohort filters.');
      }

      final account = await widget.api.request('me');
      if (!active()) return;
      if (account is! Map ||
          account['role'] != 'vendor' ||
          account['id'] is! int ||
          account['id'] < 1) {
        throw const ApiException(403, 'Vendor role required.');
      }

      final raw = await widget.api.request(
        'commercial/vendors/${account['id']}/revenue-cohorts',
        query: {'currency': widget.currency, 'from': start, 'to': end},
      );
      if (!active()) return;
      final parsed = VendorRevenueCohortSnapshot.parse(
        raw,
        vendorId: account['id'] as int,
        currency: widget.currency,
        from: start,
        to: end,
      );
      setState(() => report = parsed);
    } catch (e) {
      if (!active()) return;
      setState(() => error = e);
      if (e is ApiException && [401, 419].contains(e.status)) {
        widget.onExpired();
      }
    } finally {
      if (active()) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final authorized = currentAuthority?.call() ?? false;
    final data = foreground && authorized ? report : null;
    final maxDaily = data == null ? 0 : data.days.fold<int>(
      0, (best, row) => row['invoiced_minor'] > best
          ? row['invoiced_minor'] as int : best,
    );

    return Scaffold(
      appBar: AppBar(
        title: Text(t('Revenue by invoice date', 'الإيرادات حسب تاريخ الفاتورة')),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t(
            'Canonical TEST invoice-issuance cohorts only. Payments and refunds are lifetime totals for these invoices, not transaction-date cashflow, recognized revenue or eligible payout. No bank or PSP verification.',
            'دفعات فواتير الاختبار بحسب تاريخ الإصدار فقط. المدفوعات والاستردادات إجماليات عمر الفواتير وليست تدفقاً نقدياً أو إيراداً معترفاً به أو مبلغاً مؤهلاً للصرف. لا يوجد تحقق مصرفي أو من مزود الدفع.',
          )),
          const SizedBox(height: 12),
          TextField(
            key: const Key('revenue-cohort-from'),
            controller: from,
            decoration: InputDecoration(
              labelText: t('From (UTC)', 'من (UTC)'),
            ),
            onChanged: edited,
          ),
          TextField(
            key: const Key('revenue-cohort-to'),
            controller: to,
            decoration: InputDecoration(
              labelText: t('To (UTC)', 'إلى (UTC)'),
            ),
            onChanged: edited,
          ),
          OutlinedButton(
            key: const Key('revenue-cohort-refresh'),
            onPressed: busy || !foreground ? null : load,
            child: Text(t('Apply and refresh', 'تطبيق وتحديث')),
          ),
          if (busy && authorized)
            const Center(child: CircularProgressIndicator()),
          if (error != null && authorized)
            Text(
              t('Source unavailable or invalid. Check filters and retry.',
                'المصدر غير متاح أو غير صالح. راجع المرشحات وأعد المحاولة.'),
              key: const Key('revenue-cohort-error'),
            ),
          if (cleared || !authorized)
            Text(t(
              'Private report cleared. Refresh when your session is active.',
              'تم مسح التقرير الخاص. حدّث عند تنشيط الجلسة.',
            )),
          if (data != null) ...[
            Text('${data.source['from']} — ${data.source['to']} · ${widget.currency}'),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(t('Invoice cohort totals', 'إجماليات دفعات الفواتير'),
                      style: Theme.of(context).textTheme.titleMedium),
                    Text('${t('Issued', 'صادرة')}: ${data.money('invoiced_minor')}',
                      key: const Key('revenue-cohort-total-issued')),
                    Text('${t('Captured', 'مقبوض')}: ${data.money('paid_minor')}'),
                    Text('${t('Refunded', 'مسترد')}: ${data.money('refunded_minor')}'),
                    Text('${t('Net vendor allocated', 'صافي مخصص المورد')}: ${data.money('net_vendor_allocated_minor')}',
                      key: const Key('revenue-cohort-total-vendor')),
                    Text('${t('Invoice count', 'عدد الفواتير')}: ${data.totals['invoice_count']}'),
                  ],
                ),
              ),
            ),
            if (data.totals['invoice_count'] == 0)
              Text(t('No invoices for this period.', 'لا توجد فواتير في هذه الفترة.')),
            for (final day in data.days)
              Card(
                key: ValueKey('revenue-cohort-${day['date_utc']}'),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(day['date_utc'] as String),
                      Semantics(
                        label: t('Daily invoice activity', 'نشاط الفواتير اليومي'),
                        child: LinearProgressIndicator(
                          value: maxDaily == 0 ? 0.0 :
                            (day['invoiced_minor'] as int) / maxDaily,
                        ),
                      ),
                      Text('${t('Issued', 'صادرة')}: ${data.money('invoiced_minor', row: day)}'),
                      Text('${t('Net vendor allocated', 'صافي مخصص المورد')}: ${data.money('net_vendor_allocated_minor', row: day)}'),
                    ],
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }
}
