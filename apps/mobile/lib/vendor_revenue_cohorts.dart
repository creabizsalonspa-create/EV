import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';
import 'vendor_earnings_csv.dart';

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

  static void verifyEconomics(Map<String, dynamic> row) {
    final issued = exact(row['invoiced_minor']);
    final paid = exact(row['paid_minor']);
    final refunded = exact(row['refunded_minor']);
    final credited = exact(row['unpaid_credited_minor']);
    final vendor = exact(row['vendor_allocated_minor']);
    final commission = exact(row['commission_allocated_minor']);
    final vendorRefund = exact(row['vendor_refunded_minor']);
    final vendorCredit = exact(row['vendor_credited_minor']);
    // Subtraction comparisons avoid overflowing the safe browser integer
    // domain when a forged response contains individually valid operands.
    if (paid > issued || credited > issued - paid ||
        refunded > paid || vendor > issued || commission > issued - vendor ||
        vendorRefund > refunded || vendorCredit > credited ||
        vendorRefund > vendor || vendorCredit > vendor - vendorRefund ||
        (exact(row['invoice_count']) == 0 &&
            fields.any((key) => exact(row[key]) != 0))) {
      throw const FormatException('Impossible invoice cohort economics.');
    }
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
        exact(map['vendor_id']) != vendorId ||
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

    // The companion canonical as-of source adds this optional metadata.
    // Older sources remain supported without inventing a snapshot timestamp.
    if (map.containsKey('as_of')) {
      final cutoff = map['as_of'];
      final instant = cutoff is String ? DateTime.tryParse(cutoff) : null;
      if (cutoff is! String || instant == null || !instant.isUtc ||
          !RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$').hasMatch(cutoff) ||
          instant.toIso8601String().substring(0, 19) != cutoff.substring(0, 19)) {
        throw const FormatException('Invalid UTC cohort cutoff.');
      }
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
      verifyEconomics(day);
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
    verifyEconomics(total);
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

/// Compare every cell to the previously accepted immutable source.
class VendorRevenueCohortCsv {
  static const headers = [
    'date_utc',
    ...VendorRevenueCohortSnapshot.fields,
    'net_collected_minor',
    'net_vendor_allocated_minor',
  ];

  static String verifiedText(PrivateDocument document, VendorRevenueCohortSnapshot snapshot) {
    if (document.mime != 'text/csv' || document.bytes.isEmpty ||
        document.bytes.length > 65536) {
      throw const FormatException('Invalid private revenue export.');
    }
    final text = utf8.decode(document.bytes, allowMalformed: false);
    final rows = VendorEarningsCsv.parseBounded(text, maxRows: 94);
    if (rows.length != snapshot.days.length + 1 || rows.first.length != headers.length) {
      throw const FormatException('Revenue CSV page differs from source.');
    }
    for (var j = 0; j < headers.length; j++) {
      if (rows.first[j] != headers[j]) {
        throw const FormatException('Invalid revenue CSV header.');
      }
    }
    for (var i = 0; i < snapshot.days.length; i++) {
      final cells = rows[i + 1];
      if (cells.length != headers.length) {
        throw const FormatException('Incomplete revenue CSV row.');
      }
      for (var j = 0; j < headers.length; j++) {
        if (cells[j] != '${snapshot.days[i][headers[j]]}') {
          throw const FormatException('Revenue CSV differs from accepted source.');
        }
      }
    }
    return text;
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
  String? csvPreview;
  bool exporting = false;
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
    csvPreview = null;
    exporting = false;
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
    final api = widget.api;
    final currency = widget.currency;
    final session = api.sessionFence();
    currentAuthority = session;
    setState(() {
      report = null;
      csvPreview = null;
      exporting = false;
      error = null;
      busy = true;
      cleared = false;
    });
    bool active() => mounted && foreground &&
        identical(api, widget.api) && currency == widget.currency &&
        generation == attempt && session();

    try {
      final start = from.text.trim(), end = to.text.trim();
      final days = VendorRevenueCohortSnapshot.dateDay(end) -
          VendorRevenueCohortSnapshot.dateDay(start) + 1;
      if (days < 1 || days > 93 ||
          end.compareTo(DateTime.now().toUtc()
              .toIso8601String().substring(0, 10)) > 0 ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(currency)) {
        throw const FormatException('Invalid cohort filters.');
      }

      final account = await api.request('me');
      if (!active()) return;
      if (account is! Map ||
          account['role'] != 'vendor' ||
          account['id'] is! int ||
          account['id'] < 1) {
        throw const ApiException(403, 'Vendor role required.');
      }

      final raw = await api.request(
        'commercial/vendors/${account['id']}/revenue-cohorts',
        query: {'currency': currency, 'from': start, 'to': end},
      );
      if (!active()) return;
      final parsed = VendorRevenueCohortSnapshot.parse(
        raw,
        vendorId: account['id'] as int,
        currency: currency,
        from: start,
        to: end,
      );
      setState(() => report = parsed);
    } catch (e) {
      if (mounted && identical(api, widget.api) &&
          e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
        return;
      }
      if (!active()) return;
      setState(() => error = e);
    } finally {
      if (active()) setState(() => busy = false);
    }
  }

  Future<void> exportCsv() async {
    final accepted = report;
    final session = currentAuthority;
    if (accepted == null || session == null || !session() ||
        !foreground || busy || exporting) return;
    final api = widget.api;
    final attempt = generation;
    bool active() => mounted && foreground && identical(api, widget.api) &&
        generation == attempt && identical(report, accepted) && session();
    setState(() {
      csvPreview = null;
      error = null;
      exporting = true;
    });
    try {
      final account = await api.request('me');
      if (!active()) return;
      if (account is! Map || account['role'] != 'vendor' ||
          account['id'] != accepted.source['vendor_id']) {
        throw const FormatException('Revenue export account changed.');
      }
      final document = await api.downloadVendorRevenueCohort(
        accepted.source['vendor_id'] as int,
        currency: accepted.source['currency'] as String,
        from: accepted.source['from'] as String,
        to: accepted.source['to'] as String,
      );
      if (!active()) return;
      final verified = VendorRevenueCohortCsv.verifiedText(document, accepted);
      setState(() => csvPreview = verified);
    } catch (e) {
      if (mounted && identical(api, widget.api) &&
          e is ApiException && e.endsCurrentSession) {
        widget.onExpired();
        return;
      }
      if (active()) setState(() {
        // A conflicting export proves the displayed snapshot cannot be
        // revalidated. Require a fresh authorized JSON read before retry.
        csvPreview = null;
        report = null;
        exporting = false;
        error = e;
      });
    } finally {
      if (active()) setState(() => exporting = false);
    }
  }

  /// All amounts come from the validated, immutable snapshot. The
  /// compact cards retain their summaries; expanded rows expose every
  /// canonical metric without a second read or any financial action.
  List<Widget> breakdown(
    VendorRevenueCohortSnapshot data, {
    Map<String, dynamic>? row,
  }) {
    final scope = row == null ? 'total' : row['date_utc'];
    final values = row ?? data.totals;
    final labels = <String, String>{
      'invoiced_minor': t('Issued', 'صادرة'),
      'vendor_allocated_minor': t('Gross vendor allocation', 'إجمالي مخصص المورد'),
      'commission_allocated_minor': t('Commission allocation', 'مخصص العمولة'),
      'paid_minor': t('Captured', 'مقبوض'),
      'refunded_minor': t('Refunded', 'مسترد'),
      'vendor_refunded_minor': t('Vendor allocation refunded', 'مخصص المورد المسترد'),
      'unpaid_credited_minor': t('Unpaid invoice credits', 'إشعارات دائنة للفواتير غير المسددة'),
      'vendor_credited_minor': t('Vendor allocation credited', 'إشعارات دائنة لمخصص المورد'),
      'net_collected_minor': t('Net collected', 'صافي التحصيل'),
      'net_vendor_allocated_minor': t('Net vendor allocated', 'صافي مخصص المورد'),
    };
    return [
      ListTile(
        dense: true,
        key: ValueKey('revenue-breakdown-$scope-invoice_count'),
        title: Text(t('Invoice count', 'عدد الفواتير')),
        subtitle: Text('${values['invoice_count']}'),
      ),
      for (final entry in labels.entries)
        ListTile(
          dense: true,
          key: ValueKey('revenue-breakdown-$scope-${entry.key}'),
          title: Text(entry.value),
          subtitle: Text(data.money(entry.key, row: row)),
        ),
    ];
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
            OutlinedButton(
              key: const Key('revenue-cohort-export'),
              onPressed: exporting ? null : exportCsv,
              child: Text(t('View verified private CSV', 'عرض CSV الخاص المتحقق منه')),
            ),
            if (exporting) const LinearProgressIndicator(),
            if (csvPreview != null)
              SelectableText(csvPreview!, key: const Key('revenue-cohort-csv')),
            if (data.source['as_of'] != null)
              Text(
                '${t('Report cutoff (UTC)', 'وقت التقرير (UTC)')}: ${data.source['as_of']}',
                key: const Key('revenue-cohort-as-of'),
              ),
            Card(
              child: ExpansionTile(
                key: const Key('revenue-cohort-total-breakdown'),
                title: Text(t('Complete financial breakdown', 'التفصيل المالي الكامل')),
                children: breakdown(data),
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
                      Text('${t('Invoice count', 'عدد الفواتير')}: ${day['invoice_count']}'),
                      Text('${t('Issued', 'صادرة')}: ${data.money('invoiced_minor', row: day)}'),
                      Text('${t('Net collected', 'صافي التحصيل')}: ${data.money('net_collected_minor', row: day)}'),
                      Text('${t('Net vendor allocated', 'صافي مخصص المورد')}: ${data.money('net_vendor_allocated_minor', row: day)}'),
                      ExpansionTile(
                        key: ValueKey('revenue-cohort-breakdown-${day['date_utc']}'),
                        title: Text(t('Daily financial breakdown', 'التفصيل المالي اليومي')),
                        children: breakdown(data, row: day),
                      ),
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
