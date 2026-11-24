import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';

String cycleText(BuildContext c, String en, String ar) =>
    Localizations.localeOf(c).languageCode == 'ar' ? ar : en;

const _states = [
  'agreement_inactive', 'before_billing_day', 'selection_required',
  'no_approved_claims', 'source_hold', 'credit_limit', 'ready_for_review',
];

int _exact(dynamic value, {bool positive = false}) {
  if (value is! int || value < (positive ? 1 : 0) ||
      value > 9007199254740991) {
    throw const FormatException('Invalid corporate cycle integer.');
  }
  return value;
}

class CycleClaim {
  final int id, invoice, amount;
  final bool held;
  const CycleClaim(this.id, this.invoice, this.amount, this.held);
}

class CorporateCycleReview {
  final int agreement, day, count, remaining, blocked, precision;
  final int? total;
  final String currency, period, reviewAt, state;
  final bool lowerBound;
  final List<CycleClaim> claims;
  CorporateCycleReview._(this.agreement, this.day, this.count, this.remaining,
      this.blocked, this.precision, this.total, this.currency, this.period,
      this.reviewAt, this.state, this.lowerBound, this.claims);

  factory CorporateCycleReview.parse(dynamic raw, {
    required int agreement, required String currency, required int precision,
  }) {
    if (raw is! Map || raw['read_only'] != true ||
        raw['statement_issued'] != false || raw['real_payment_processed'] != false ||
        _exact(raw['agreement_id'], positive: true) != agreement ||
        raw['currency'] != currency || !RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
        _exact(raw['minor_units']) != precision || precision > 3 ||
        !_states.contains(raw['state']) ||
        raw['period'] is! String ||
        !RegExp(r'^\d{4}-(0[1-9]|1[0-2])$').hasMatch(raw['period']) ||
        raw['review_at'] is! String || DateTime.tryParse(raw['review_at']) == null ||
        raw['claims'] is! List || raw['count_is_lower_bound'] is! bool) {
      throw const FormatException('Invalid corporate review authority.');
    }
    final day = _exact(raw['billing_day'], positive: true);
    final count = _exact(raw['claim_count']);
    final remaining = _exact(raw['remaining_credit_minor']);
    final blocked = _exact(raw['blocked_count']);
    final lower = raw['count_is_lower_bound'] as bool;
    final rows = raw['claims'] as List;
    final total = raw['total_minor'] == null ? null : _exact(raw['total_minor']);
    if (day > 28 || rows.length > 100 ||
        (lower ? count != 101 || rows.isNotEmpty || total != null
            : count != rows.length || total == null)) {
      throw const FormatException('Incomplete corporate review batch.');
    }
    final ids = <int>{}, invoices = <int>{};
    final claims = <CycleClaim>[];
    int sum = 0, held = 0;
    for (final row in rows) {
      if (row is! Map || row['held'] is! bool) {
        throw const FormatException('Invalid corporate claim.');
      }
      final id = _exact(row['claim_id'], positive: true);
      final invoice = _exact(row['invoice_id'], positive: true);
      final amount = _exact(row['amount_minor']);
      if (!ids.add(id) || !invoices.add(invoice)) {
        throw const FormatException('Duplicate corporate claim source.');
      }
      sum = _exact(sum + amount);
      final hold = row['held'] as bool;
      if (hold) held++;
      claims.add(CycleClaim(id, invoice, amount, hold));
    }
    final state = raw['state'] as String;
    if ((!lower && sum != total) || held != blocked ||
        (state == 'ready_for_review' &&
          (lower || held != 0 || sum < 1 || sum > remaining)) ||
        (state == 'selection_required' && !lower) ||
        (state == 'no_approved_claims' && (lower || count != 0)) ||
        (state == 'source_hold' && held == 0)) {
      throw const FormatException('Invalid corporate cycle total or state.');
    }
    return CorporateCycleReview._(agreement, day, count, remaining, blocked,
      precision, total, currency, raw['period'], raw['review_at'], state,
      lower, List.unmodifiable(claims));
  }

  String money(int amount) => '$currency ${minorDecimal(amount, precision)}';
}

class CorporateCycleReviewView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int agreementId, fleetId, precision;
  final String currency;
  const CorporateCycleReviewView({super.key, required this.api,
    required this.onExpired, required this.agreementId, required this.fleetId,
    required this.currency, required this.precision});
  @override
  State<CorporateCycleReviewView> createState() => _CycleState();
}

class _CycleState extends State<CorporateCycleReviewView>
    with WidgetsBindingObserver {
  late final bool Function() ownsSession;
  StreamSubscription<int>? session;
  CorporateCycleReview? review;
  bool foreground = true, revoked = false, busy = false, failed = false;
  int generation = 0;
  int? owner;
  bool get visible => foreground && !revoked && ownsSession();
  String t(String en, String ar) => cycleText(context, en, ar);

  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() { revoked = true; clear(); });
    });
    if (foreground) load();
  }

  void clear() {
    generation++;
    review = null;
    busy = false;
    failed = false;
  }

  @override
  void didUpdateWidget(covariant CorporateCycleReviewView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.agreementId != widget.agreementId ||
        oldWidget.fleetId != widget.fleetId ||
        oldWidget.currency != widget.currency ||
        oldWidget.precision != widget.precision) {
      revoked = true;
      clear();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) clear();
    });
  }

  @override
  void dispose() {
    generation++;
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load() async {
    if (!visible || busy) return;
    final api = widget.api;
    final epoch = ++generation;
    bool current() => mounted && visible && generation == epoch && identical(api, widget.api);
    setState(() { review = null; busy = true; failed = false; });
    try {
      _exact(widget.agreementId, positive: true);
      _exact(widget.fleetId, positive: true);
      final me = await api.request('me');
      if (!current()) return;
      if (me is! Map || !['admin', 'customer'].contains(me['role'])) {
        throw const FormatException('Current manager required.');
      }
      final id = _exact(me['id'], positive: true);
      if (owner != null && owner != id) {
        setState(() { revoked = true; clear(); });
        return;
      }
      owner = id;
      final raw = await api.request('commercial/corporate',
        query: {'fleet_id': '${widget.fleetId}'});
      if (!current()) return;
      if (raw is! Map || raw['can_manage'] != true ||
          raw['real_payment_enabled'] != false || raw['agreements'] is! List ||
          !(raw['agreements'] as List).any((r) => r is Map &&
            r['id'] == widget.agreementId && r['fleet_id'] == widget.fleetId &&
            r['currency'] == widget.currency && r['minor_units'] == widget.precision)) {
        throw const FormatException('Current manager authority required.');
      }
      final response = await api.request(
        'commercial/corporate/agreements/${widget.agreementId}/cycle-review');
      if (!current()) return;
      final result = CorporateCycleReview.parse(response, agreement: widget.agreementId,
        currency: widget.currency, precision: widget.precision);
      setState(() => review = result);
    } catch (error) {
      if (!current()) return;
      setState(() { review = null; failed = true; });
      if (error is ApiException && [401, 419].contains(error.status)) {
        widget.onExpired();
      }
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  String status(String state) => switch (state) {
    'agreement_inactive' => t('Agreement inactive', 'الاتفاقية غير سارية'),
    'before_billing_day' => t('Scheduled review day has not arrived', 'لم يحن يوم المراجعة المحدد'),
    'selection_required' => t('More than 100 claims. Use explicit selection.', 'أكثر من ١٠٠ مطالبة. استخدم التحديد الصريح.'),
    'no_approved_claims' => t('No approved unallocated claims', 'لا توجد مطالبات معتمدة غير مخصصة'),
    'source_hold' => t('Original invoices have financial holds', 'توجد قيود مالية على الفواتير الأصلية'),
    'credit_limit' => t('Batch outside remaining credit or zero total', 'المجموعة خارج الائتمان المتبقي أو إجماليها صفر'),
    _ => t('Ready for explicit review, not issuance', 'جاهزة للمراجعة الصريحة، وليس الإصدار'),
  };

  @override
  Widget build(BuildContext context) {
    final data = visible ? review : null;
    return Scaffold(
      appBar: AppBar(title: Text(t('Monthly billing review', 'مراجعة الفوترة الشهرية'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(t('Read-only proposed batch. No statement or payment is issued. Return to invoice claims for explicit selection.',
          'مجموعة مقترحة للقراءة فقط. لا يصدر كشف أو دفع. ارجع إلى مطالبات الفواتير للتحديد الصريح.')),
        if (!visible)
          Text(t('Private review cleared. Reopen from company agreements.',
            'مُسحت المراجعة الخاصة. أعد فتحها من اتفاقيات الشركة.')),
        if (visible) ...[
          OutlinedButton(onPressed: busy ? null : load,
            child: Text(t('Refresh cycle review', 'تحديث مراجعة الدورة'))),
          if (busy) const LinearProgressIndicator(),
          if (failed) Text(t('Private review unavailable.', 'المراجعة الخاصة غير متاحة.')),
          if (data != null) ...[
            Text(data.period),
            Text('${t('Scheduled review', 'المراجعة المحددة')}: ${data.reviewAt}'),
            Text(status(data.state)),
            Text('${t('Claims', 'المطالبات')}: ${data.lowerBound ? '≥' : ''}${data.count}'),
            Text('${t('Remaining credit', 'الائتمان المتبقي')}: ${data.money(data.remaining)}'),
            if (data.total != null)
              Text('${t('Proposed total', 'الإجمالي المقترح')}: ${data.money(data.total!)}'),
            for (final row in data.claims)
              Card(child: ListTile(
                title: Text('#${row.id} / #${row.invoice}'),
                subtitle: Text(data.money(row.amount), textDirection: TextDirection.ltr),
                trailing: row.held ? Text(t('Held', 'مقيدة')) : null,
              )),
          ],
        ],
      ]),
    );
  }
}
