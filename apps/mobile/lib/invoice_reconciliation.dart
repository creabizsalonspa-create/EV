import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';
import 'vendor_earnings_csv.dart';

const _max = 9007199254740991;
int _exact(dynamic value, {bool signed = false, bool positive = false}) {
  if (value is! int || value < (positive ? 1 : signed ? -_max : 0) ||
      value > _max) {
    throw const FormatException('Invalid reconciliation integer.');
  }
  return value;
}

const auditMoneyFields = [
  'invoiced_minor', 'paid_minor', 'refunded_minor', 'unpaid_credited_minor',
  'net_collected_minor', 'amount_due_minor',
];
const _issueLabels = <String, List<String>>{
  'invoice_component_mismatch': ['Invoice component mismatch', 'عدم تطابق مكونات الفاتورة'],
  'invoice_journal_mismatch': ['Invoice journal mismatch', 'عدم تطابق قيد الفاتورة'],
  'payment_journal_mismatch': ['Payment journal mismatch', 'عدم تطابق قيد الدفع'],
  'refund_journal_mismatch': ['Refund journal mismatch', 'عدم تطابق قيد الاسترداد'],
  'refund_component_mismatch': ['Refund component mismatch', 'عدم تطابق مكونات الاسترداد'],
  'refund_allocation_exceeded': ['Refund allocation exceeded', 'تجاوز تخصيص الاسترداد'],
  'unpaid_credit_journal_mismatch': ['Noncash credit journal mismatch', 'عدم تطابق قيد التسوية غير النقدية'],
  'unpaid_credit_component_mismatch': ['Noncash credit component mismatch', 'عدم تطابق مكونات التسوية غير النقدية'],
  'invalid_journal_entry': ['Invalid journal entry', 'قيد غير صالح'],
  'journal_accounts_mismatch': ['Journal accounts mismatch', 'عدم تطابق حسابات القيد'],
  'journal_unbalanced': ['Journal unbalanced', 'قيد غير متوازن'],
  'journal_currency_mismatch': ['Journal currency mismatch', 'عدم تطابق عملة القيد'],
  'unexpected_journal_kind': ['Unexpected journal type', 'نوع قيد غير متوقع'],
  'unlinked_financial_journal': ['Unlinked financial journal', 'قيد مالي غير مرتبط'],
  'collection_out_of_bounds': ['Collection outside bounds', 'تحصيل خارج الحدود'],
};

class InvoiceAuditRow {
  final int id, precision, journals;
  final String number, currency, status;
  final Map<String, int> amounts;
  final List<String> issues;
  InvoiceAuditRow._(this.id, this.precision, this.journals, this.number,
      this.currency, this.status, this.amounts, this.issues);

  factory InvoiceAuditRow.parse(dynamic raw, String currency) {
    if (raw is! Map || raw['currency'] != currency ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
        raw['evidence'] != 'SIMULATED_CANONICAL_LEDGER' ||
        raw['external_psp_or_bank_verified'] != false ||
        raw['invoice_number'] is! String ||
        (raw['invoice_number'] as String).isEmpty ||
        (raw['invoice_number'] as String).length > 255 ||
        !['reconciled', 'attention_required'].contains(raw['status']) ||
        raw['issues'] is! List) {
      throw const FormatException('Invalid invoice audit source.');
    }
    final precision = _exact(raw['minor_units']);
    final journals = _exact(raw['journal_count']);
    if (precision > 3 || journals > 500) {
      throw const FormatException('Invalid invoice audit limits.');
    }
    final issues = <String>[];
    for (final code in raw['issues'] as List) {
      if (code is! String || !_issueLabels.containsKey(code) || issues.contains(code)) {
        throw const FormatException('Unrecognized audit finding.');
      }
      issues.add(code);
    }
    if ((raw['status'] == 'reconciled') != issues.isEmpty) {
      throw const FormatException('Audit status disagrees with findings.');
    }
    final amounts = <String, int>{
      for (final field in auditMoneyFields)
        field: _exact(raw[field], signed: field != 'amount_due_minor'),
    };
    final invoiced = amounts['invoiced_minor']!, paid = amounts['paid_minor']!,
        refunded = amounts['refunded_minor']!, credited = amounts['unpaid_credited_minor']!;
    final due = invoiced - paid - credited;
    if (amounts['net_collected_minor'] != _exact(paid - refunded, signed: true) ||
        amounts['amount_due_minor'] != _exact(due < 0 ? 0 : due) ||
        (issues.isEmpty && (invoiced < 0 || paid < 0 || refunded < 0 ||
          credited < 0 || paid + credited > invoiced || refunded > paid || journals < 1))) {
      throw const FormatException('Audit money does not reconcile.');
    }
    return InvoiceAuditRow._(_exact(raw['invoice_id'], positive: true), precision,
      journals, raw['invoice_number'], currency, raw['status'],
      Map.unmodifiable(amounts), List.unmodifiable(issues));
  }

  String money(String field) {
    final amount = amounts[field]!;
    return '$currency ${amount < 0 ? '-' : ''}${minorDecimal(amount.abs(), precision)}';
  }
}

class InvoiceAuditPage {
  final List<InvoiceAuditRow> rows;
  final int page, last, total;
  InvoiceAuditPage._(this.rows, this.page, this.last, this.total);
  factory InvoiceAuditPage.parse(dynamic raw, String currency, int requested, {
    int? vendorId, String? from, String? to,
  }) {
    if (raw is! Map || raw['evidence'] != 'SIMULATED_INTERNAL_RECONCILIATION' ||
        raw['external_psp_or_bank_verified'] != false || raw['data'] is! List) {
      throw const FormatException('Invalid audit authority.');
    }
    final scope = raw['scope'];
    if (scope is! Map ||
        !['vendor_id', 'currency', 'from', 'to'].every(scope.containsKey) ||
        scope['vendor_id'] != vendorId ||
        (vendorId != null && scope['vendor_id'] is! int) ||
        scope['currency'] != currency || scope['from'] != from || scope['to'] != to) {
      throw const FormatException('Invalid private audit scope.');
    }
    final page = _exact(raw['current_page'], positive: true);
    final last = _exact(raw['last_page'], positive: true);
    final total = _exact(raw['total']);
    final size = _exact(raw['per_page'], positive: true);
    final remaining = total - (page - 1) * size;
    if (page != requested || page > 10000 || size != 20 ||
        last != (total == 0 ? 1 : ((total - 1) ~/ size) + 1) ||
        (raw['data'] as List).length != (remaining < 0 ? 0 : remaining > size ? size : remaining)) {
      throw const FormatException('Invalid audit pagination.');
    }
    final rows = <InvoiceAuditRow>[];
    for (final value in raw['data'] as List) {
      final row = InvoiceAuditRow.parse(value, currency);
      if (rows.isNotEmpty && row.id >= rows.last.id) {
        throw const FormatException('Duplicate or unordered audit source.');
      }
      rows.add(row);
    }
    return InvoiceAuditPage._(List.unmodifiable(rows), page, last, total);
  }
}

/// Verify every CSV cell against the already accepted private page.
class InvoiceAuditCsv {
  static const headers = [
    'invoice_id', 'invoice_number', 'currency', 'minor_units', ...auditMoneyFields,
    'journal_count', 'status', 'issues', 'evidence', 'external_psp_or_bank_verified',
  ];

  // PHP's literalCsv guards Unicode separators, controls and format
  // characters. Match their exact prefix policy without changing the source.
  static String literal(String value) =>
      RegExp(r'^[\p{Z}\p{Cc}\p{Cf}]*[=+\-@]', unicode: true).hasMatch(value)
          ? "'$value" : value;

  static String verify(PrivateDocument document, InvoiceAuditPage source) {
    if (document.mime != 'text/csv' || document.bytes.isEmpty ||
        document.bytes.length > 65536) {
      throw const FormatException('Invalid private audit CSV.');
    }
    final text = utf8.decode(document.bytes, allowMalformed: false);
    final data = VendorEarningsCsv.parseBounded(text);
    bool same(List<String> a, List<String> b) {
      if (a.length != b.length) return false;
      for (var i = 0; i < a.length; i++) {
        if (a[i] != b[i]) return false;
      }
      return true;
    }
    if (data.length != source.rows.length + 1 || !same(data.first, headers)) {
      throw const FormatException('Audit export differs from private page.');
    }
    for (var i = 0; i < source.rows.length; i++) {
      final row = source.rows[i];
      final expected = [
        '${row.id}', literal(row.number), row.currency, '${row.precision}',
        for (final field in auditMoneyFields) '${row.amounts[field]}',
        '${row.journals}', row.status, row.issues.join(';'),
        'SIMULATED_CANONICAL_LEDGER', 'false',
      ];
      if (!same(data[i + 1], expected)) {
        throw const FormatException('Audit CSV does not match canonical source.');
      }
    }
    return text;
  }
}

class InvoiceReconciliationView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String currency;
  const InvoiceReconciliationView({super.key, required this.api,
    required this.onExpired, required this.currency});
  @override
  State<InvoiceReconciliationView> createState() => _AuditState();
}

class _AuditState extends State<InvoiceReconciliationView> with WidgetsBindingObserver {
  late final bool Function() ownsSession;
  StreamSubscription<int>? session;
  final from = TextEditingController(), to = TextEditingController();
  InvoiceAuditPage? data;
  Map<String, String>? applied;
  String? csvPreview;
  int generation = 0;
  int? actor;
  String? role;
  bool busy = false, failed = false, foreground = true, revoked = false;
  bool get visible => foreground && !revoked && ownsSession();
  bool get ar => Localizations.localeOf(context).languageCode == 'ar';
  String t(String en, String arabic) => ar ? arabic : en;

  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() { revoked = true; clear(); from.clear(); to.clear(); });
    });
    if (foreground) load();
  }
  void clear() {
    generation++;
    data = null;
    applied = null;
    csvPreview = null;
    busy = false;
    failed = false;
  }
  @override
  void didUpdateWidget(covariant InvoiceReconciliationView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) || oldWidget.currency != widget.currency) {
      revoked = true;
      clear();
      from.clear();
      to.clear();
    }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) { clear(); from.clear(); to.clear(); }
    });
  }
  @override
  void dispose() {
    generation++;
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    from.dispose();
    to.dispose();
    super.dispose();
  }

  String? date(String input) {
    final value = input.trim();
    if (value.isEmpty) return null;
    final parsed = DateTime.tryParse(value);
    if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value) ||
        parsed == null || parsed.toIso8601String().substring(0, 10) != value) {
      throw const FormatException('Invalid date filter.');
    }
    return value;
  }

  Future<void> load({int page = 1, Map<String, String>? previous}) async {
    if (!visible || busy) return;
    final api = widget.api;
    final epoch = ++generation;
    bool current() => mounted && visible && identical(api, widget.api) && generation == epoch;
    setState(() { data = null; applied = null; csvPreview = null; busy = true; failed = false; });
    try {
      if (!RegExp(r'^[A-Z]{3}$').hasMatch(widget.currency) || page < 1 || page > 10000) {
        throw const FormatException('Invalid audit filters.');
      }
      final start = date(from.text), end = date(to.text);
      if (start != null && end != null && start.compareTo(end) > 0) {
        throw const FormatException('Reversed audit period.');
      }
      final query = Map<String, String>.unmodifiable(previous == null ? {
        'currency': widget.currency, 'page': '$page', 'per_page': '20',
        if (start != null) 'from': start, if (end != null) 'to': end,
      } : {...previous, 'page': '$page'});
      final me = await api.request('me');
      if (!current()) return;
      if (me is! Map || !['vendor', 'admin'].contains(me['role'])) {
        throw const FormatException('Current finance role required.');
      }
      final id = _exact(me['id'], positive: true);
      if (actor != null && (actor != id || role != me['role'])) {
        setState(() { revoked = true; clear(); });
        return;
      }
      actor = id;
      role = me['role'] as String;
      final raw = await api.request('finance/reconciliation-audit', query: query);
      if (!current()) return;
      final result = InvoiceAuditPage.parse(raw, widget.currency, page,
        vendorId: role == 'vendor' ? id : null, from: query['from'], to: query['to']);
      setState(() { data = result; applied = query; });
    } catch (error) {
      if (mounted && identical(api, widget.api) &&
          error is ApiException && error.endsCurrentSession) {
        widget.onExpired();
        return;
      }
      if (current()) setState(() => failed = true);
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  Future<void> exportPage() async {
    final source = data, query = applied;
    if (!visible || busy || source == null || query == null) return;
    final api = widget.api;
    final epoch = ++generation;
    bool current() => mounted && visible && identical(api, widget.api) && generation == epoch;
    setState(() { busy = true; failed = false; csvPreview = null; });
    try {
      final me = await api.request('me');
      if (!current()) return;
      if (me is! Map || me['id'] != actor || me['role'] != role) {
        setState(() { revoked = true; clear(); });
        return;
      }
      final document = await api.downloadInvoiceAuditPage(query);
      if (!current()) return;
      final verified = InvoiceAuditCsv.verify(document, source);
      if (current()) setState(() => csvPreview = verified);
    } catch (error) {
      if (mounted && identical(api, widget.api) &&
          error is ApiException && error.endsCurrentSession) {
        widget.onExpired();
        return;
      }
      if (current()) setState(() { clear(); failed = true; });
    } finally {
      if (current()) setState(() => busy = false);
    }
  }

  String label(String field) => switch(field) {
    'invoiced_minor' => t('Invoiced', 'قيمة الفاتورة'),
    'paid_minor' => t('Recorded payments', 'المدفوعات المسجلة'),
    'refunded_minor' => t('Recorded refunds', 'الاستردادات المسجلة'),
    'unpaid_credited_minor' => t('Noncash credits', 'التسويات غير النقدية'),
    'net_collected_minor' => t('Net recorded collection', 'صافي التحصيل المسجل'),
    _ => t('Amount due', 'المبلغ المستحق'),
  };

  @override
  Widget build(BuildContext context) {
    final page = visible ? data : null;
    return Scaffold(
      appBar: AppBar(title: Text(t('Internal invoice reconciliation', 'تدقيق تسوية الفواتير الداخلية'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(t('Read-only simulated ledger diagnostic. Not a bank or PSP reconciliation, payout instruction or financial close.',
          'تشخيص محاكى لدفتر الحسابات للقراءة فقط. ليس تسوية مصرفية أو مع مزود الدفع أو أمر تحويل أو إغلاقاً مالياً.')),
        if (!visible) Text(t('Private audit cleared. Reopen from vendor finance.', 'مُسح التدقيق الخاص. أعد فتحه من مالية المورد.')),
        if (visible) ...[
          Text(widget.currency),
          TextField(controller: from, enabled: !busy, onChanged: (_) => setState(clear),
            decoration: InputDecoration(labelText: t('From (YYYY-MM-DD)', 'من (YYYY-MM-DD)'))),
          TextField(controller: to, enabled: !busy, onChanged: (_) => setState(clear),
            decoration: InputDecoration(labelText: t('To (YYYY-MM-DD)', 'إلى (YYYY-MM-DD)'))),
          OutlinedButton(onPressed: busy ? null : () => load(),
            child: Text(t('Refresh audit', 'تحديث التدقيق'))),
          if (busy) const LinearProgressIndicator(),
          if (failed) Text(t('Private audit unavailable. Check dates and access.', 'التدقيق الخاص غير متاح. تحقق من التواريخ والصلاحية.')),
          if (page != null) ...[
            Text('${t('Invoices', 'الفواتير')}: ${page.total}'),
            OutlinedButton(onPressed: busy ? null : exportPage,
              child: Text(t('View verified current-page CSV', 'عرض CSV الموثق للصفحة الحالية'))),
            if (csvPreview != null) ...[
              Text(t('Private CSV preview in memory only. Not a bank statement.',
                'معاينة CSV خاصة في الذاكرة فقط. ليست كشفاً مصرفياً.')),
              OutlinedButton(onPressed: () => setState(() => csvPreview = null),
                child: Text(t('Close CSV preview', 'إغلاق معاينة CSV'))),
              SingleChildScrollView(scrollDirection: Axis.horizontal,
                child: Text(csvPreview!, textDirection: TextDirection.ltr,
                  key: const Key('invoice-audit-csv-preview'))),
            ],
            if (page.rows.isEmpty) Text(t('No invoices in this scope.', 'لا توجد فواتير في هذا النطاق.')),
            for (final row in page.rows) Card(child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('#${row.id} · ${row.number}', textDirection: TextDirection.ltr),
                Text(row.status == 'reconciled' ? t('Internally reconciled', 'متطابقة داخلياً')
                    : t('Attention required', 'تحتاج إلى مراجعة')),
                for (final field in auditMoneyFields) Text('${label(field)}: ${row.money(field)}'),
                Text('${t('Journals', 'القيود')}: ${row.journals}'),
                for (final code in row.issues) Text(_issueLabels[code]![ar ? 1 : 0]),
              ]),
            )),
            Wrap(spacing: 8, children: [
              OutlinedButton(onPressed: page.page > 1 ? () => load(page: page.page - 1, previous: applied) : null,
                child: Text(t('Previous', 'السابق'))),
              Text('${page.page} / ${page.last}'),
              OutlinedButton(onPressed: page.page < page.last && page.page < 10000
                ? () => load(page: page.page + 1, previous: applied) : null,
                child: Text(t('Next', 'التالي'))),
            ]),
          ],
        ],
      ]),
    );
  }
}
