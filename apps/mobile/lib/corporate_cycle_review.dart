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
  final bool lowerBound, canIssue;
  final List<CycleClaim> claims;
  CorporateCycleReview._(this.agreement, this.day, this.count, this.remaining,
      this.blocked, this.precision, this.total, this.currency, this.period,
      this.reviewAt, this.state, this.lowerBound, this.canIssue, this.claims);

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
        raw['claims'] is! List || raw['count_is_lower_bound'] is! bool ||
        raw['can_issue_reviewed_batch'] is! bool) {
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
    final canIssue = raw['can_issue_reviewed_batch'] as bool;
    if ((!lower && sum != total) || held != blocked ||
        (canIssue && state != 'ready_for_review') ||
        (state == 'ready_for_review' &&
          (lower || held != 0 || sum < 1 || sum > remaining)) ||
        (state == 'selection_required' && !lower) ||
        (state == 'no_approved_claims' && (lower || count != 0)) ||
        (state == 'source_hold' && held == 0)) {
      throw const FormatException('Invalid corporate cycle total or state.');
    }
    return CorporateCycleReview._(agreement, day, count, remaining, blocked,
      precision, total, currency, raw['period'], raw['review_at'], state,
      lower, canIssue, List.unmodifiable(claims));
  }

  String money(int amount) => '$currency ${minorDecimal(amount, precision)}';

  Map<String, dynamic> issuePayload() {
    if (!canIssue || state != 'ready_for_review' || total == null) {
      throw const FormatException('Reviewed batch cannot be issued.');
    }
    return Map.unmodifiable({
      'period': period,
      'claim_ids': List<int>.unmodifiable(claims.map((row) => row.id).toList()..sort()),
      'total_minor': total!,
      'consent': true,
    });
  }

  int verifyIssueReceipt(dynamic raw) {
    final ids = issuePayload()['claim_ids'] as List<int>;
    if (raw is! Map || raw['evidence'] != 'TEST_REVIEWED_STATEMENT' ||
        raw['statement_issued'] != true || raw['real_payment_processed'] != false ||
        raw['replayed'] is! bool || _exact(raw['agreement_id'], positive: true) != agreement ||
        raw['period'] != period || raw['currency'] != currency ||
        _exact(raw['minor_units']) != precision || _exact(raw['total_minor']) != total ||
        raw['claim_ids'] is! List || (raw['claim_ids'] as List).length != ids.length) {
      throw const FormatException('Unconfirmed reviewed statement.');
    }
    final returned = raw['claim_ids'] as List;
    for (var i = 0; i < ids.length; i++) {
      if (_exact(returned[i], positive: true) != ids[i]) {
        throw const FormatException('Unconfirmed reviewed claim source.');
      }
    }
    return _exact(raw['statement_id'], positive: true);
  }
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
  int? owner, issuedId;
  bool issueFailed = false;
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
    issueFailed = false;
    issuedId = null;
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
    setState(() {
      review = null; busy = true; failed = false; issueFailed = false; issuedId = null;
    });
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

  Future<void> issue(CorporateCycleReview data) async {
    if (!visible || busy || !identical(review, data) || !data.canIssue) return;
    final epoch = generation;
    final api = widget.api;
    bool current() => mounted && visible && generation == epoch && identical(api, widget.api);
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: Text(t('Issue reviewed TEST statement?', 'إصدار كشف الاختبار المراجع؟')),
        content: Text(t(
          'Issue one TEST statement for ${data.period}, ${data.count} claims, ${data.money(data.total!)}. No payment will be collected.',
          'إصدار كشف اختبار واحد للفترة ${data.period}، ${data.count} مطالبة، ${data.money(data.total!)}. لن يتم تحصيل أي دفعة.',
        )),
        actions: [
          TextButton(onPressed: () => Navigator.of(dialog).pop(false),
            child: Text(t('Cancel', 'إلغاء'))),
          FilledButton(onPressed: () => Navigator.of(dialog).pop(true),
            child: Text(t('Confirm TEST issue', 'تأكيد إصدار الاختبار'))),
        ],
      ),
    );
    if (accepted != true || !current() || busy || !identical(review, data)) return;
    setState(() { busy = true; issueFailed = false; });
    try {
      // The server derives a stable actor/batch identity; an explicit
      // repeat cannot create another statement for these same claims.
      final response = await api.request(
        'commercial/corporate/agreements/${widget.agreementId}/cycle-review/issue',
        method: 'POST', body: data.issuePayload());
      if (!current()) return;
      final id = data.verifyIssueReceipt(response);
      setState(() { issuedId = id; review = null; });
    } catch (error) {
      if (!current()) return;
      setState(() => issueFailed = true);
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
        Text(t('Reviewing is read-only. Only a separate, explicitly confirmed TEST issue can create a statement; no payment is collected.',
          'المراجعة للقراءة فقط. لا يصدر كشف إلا بتأكيد منفصل وصريح للاختبار؛ لا تُحصّل أي دفعة.')),
        if (!visible)
          Text(t('Private review cleared. Reopen from company agreements.',
            'مُسحت المراجعة الخاصة. أعد فتحها من اتفاقيات الشركة.')),
        if (visible) ...[
          OutlinedButton(onPressed: busy ? null : load,
            child: Text(t('Refresh cycle review', 'تحديث مراجعة الدورة'))),
          if (busy) const LinearProgressIndicator(),
          if (failed) Text(t('Private review unavailable.', 'المراجعة الخاصة غير متاحة.')),
          if (issueFailed) Text(t('Statement not confirmed. Check statement history or explicitly retry this same reviewed batch.',
            'لم يتأكد الكشف. راجع سجل الكشوف أو أعد محاولة المجموعة المراجعة نفسها صراحةً.')),
          if (issuedId != null)
            Text(t('TEST statement #$issuedId confirmed. No payment collected.',
              'تم تأكيد كشف الاختبار #$issuedId. لم تُحصّل أي دفعة.')),
          if (data != null) ...[
            Text(data.period),
            Text('${t('Scheduled review', 'المراجعة المحددة')}: ${data.reviewAt}'),
            Text(status(data.state)),
            Text('${t('Claims', 'المطالبات')}: ${data.lowerBound ? '≥' : ''}${data.count}'),
            Text('${t('Remaining credit', 'الائتمان المتبقي')}: ${data.money(data.remaining)}'),
            if (data.total != null)
              Text('${t('Proposed total', 'الإجمالي المقترح')}: ${data.money(data.total!)}'),
            if (data.canIssue)
              OutlinedButton(onPressed: busy ? null : () => issue(data),
                child: Text(t('Issue reviewed TEST statement', 'إصدار كشف الاختبار المراجع'))),
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
