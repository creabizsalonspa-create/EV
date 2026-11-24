import 'dart:async';
import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';

String financeReviewText(BuildContext context, String en, String ar) =>
    Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

const _maxExact = 9007199254740991;
const _kinds = ['refund', 'settlement', 'adjustment'];
int _exact(dynamic value, {bool positive = false, bool signed = false}) {
  if (value is! int ||
      value < (positive ? 1 : signed ? -_maxExact : 0) ||
      value > _maxExact) {
    throw const FormatException('Invalid private review source.');
  }
  return value;
}

class FinanceReviewRow {
  final String kind, currency, status, createdAt;
  final int id, vendor, amount;
  final int? invoice, precision;
  FinanceReviewRow._(this.kind, this.currency, this.status, this.createdAt,
      this.id, this.vendor, this.amount, this.invoice, this.precision);

  factory FinanceReviewRow.parse(dynamic raw) {
    if (raw is! Map ||
        !_kinds.contains(raw['kind']) ||
        raw['currency'] is! String ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(raw['currency']) ||
        raw['status'] != (raw['kind'] == 'adjustment' ? 'awaiting_approval' : 'pending') ||
        raw['created_at'] is! String ||
        DateTime.tryParse(raw['created_at']) == null ||
        raw.keys.any((key) => !const [
          'kind', 'record_id', 'vendor_id', 'invoice_id', 'currency',
          'minor_units', 'amount_minor', 'status', 'created_at',
        ].contains(key))) {
      throw const FormatException('Invalid private review row.');
    }
    final id = _exact(raw['record_id'], positive: true);
    final vendor = _exact(raw['vendor_id'], positive: true);
    final amount = _exact(raw['amount_minor'], signed: raw['kind'] == 'adjustment');
    final invoice = raw['invoice_id'] == null ? null : _exact(raw['invoice_id'], positive: true);
    final precision = raw['minor_units'] == null ? null : _exact(raw['minor_units']);
    if ((raw['kind'] == 'refund') != (invoice != null) ||
        (precision != null && precision > 3)) {
      throw const FormatException('Invalid private review lineage.');
    }
    return FinanceReviewRow._(raw['kind'], raw['currency'], raw['status'],
        raw['created_at'], id, vendor, amount, invoice, precision);
  }

  String get amountLabel {
    final digits = precision;
    if (digits == null) return '$currency $amount';
    final sign = amount < 0 ? '-' : '';
    return '$currency $sign${minorDecimal(amount.abs(), digits)}';
  }
}

class FinanceReviewGroup {
  final String kind, currency;
  final int precision, count, amount;
  const FinanceReviewGroup._(this.kind, this.currency, this.precision, this.count, this.amount);

  String get key => '$kind:$currency:$precision';
  String get amountLabel =>
      '$currency ${amount < 0 ? '-' : ''}${minorDecimal(amount.abs(), precision)}';

  factory FinanceReviewGroup.parse(dynamic raw, Map<String, String> query) {
    if (raw is! Map ||
        raw['basis'] != 'PENDING_RECORDS_NOT_FINANCIAL_BALANCE' ||
        !_kinds.contains(raw['kind']) ||
        raw['currency'] is! String ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(raw['currency']) ||
        (query['kind'] != 'all' && raw['kind'] != query['kind']) ||
        (query['currency'] != null && raw['currency'] != query['currency'])) {
      throw const FormatException('Invalid pending review group.');
    }
    final precision = _exact(raw['minor_units']);
    final count = _exact(raw['review_count'], positive: true);
    final amount = _exact(raw['signed_amount_minor'], signed: raw['kind'] == 'adjustment');
    if (precision > 3) throw const FormatException('Invalid review group precision.');
    return FinanceReviewGroup._(raw['kind'], raw['currency'], precision, count, amount);
  }
}

class FinanceReviewPage {
  final List<FinanceReviewRow> rows;
  final List<FinanceReviewGroup> groups;
  final int page, last, total;
  FinanceReviewPage._(this.rows, this.groups, this.page, this.last, this.total);

  factory FinanceReviewPage.parse(dynamic raw, Map<String, String> query) {
    if (raw is! Map ||
        raw['admin_only'] != true || raw['read_only'] != true ||
        raw['mutations_performed'] != false || raw['real_money_moved'] != false ||
        raw['not_a_payment_or_payout_authority'] != true ||
        raw['evidence'] != 'SIMULATED_FINANCIAL_APPROVAL_REVIEW' ||
        raw['data'] is! List) {
      throw const FormatException('Untrusted review authority.');
    }
    final page = _exact(raw['current_page'], positive: true);
    final last = _exact(raw['last_page'], positive: true);
    final total = _exact(raw['total']);
    final size = _exact(raw['per_page'], positive: true);
    final expected = int.parse(query['page']!);
    final wantedSize = int.parse(query['per_page']!);
    if (page != expected || page > 10000 || size != wantedSize ||
        size > 25 || last != (total == 0 ? 1 : ((total - 1) ~/ size) + 1)) {
      throw const FormatException('Invalid private review pagination.');
    }
    final data = raw['data'] as List;
    final offset = (page - 1) * size;
    final remaining = total > offset ? total - offset : 0;
    final expectedCount = remaining < size ? remaining : size;
    if (data.length != expectedCount) {
      throw const FormatException('Invalid private review page size.');
    }
    final rows = data.map(FinanceReviewRow.parse).toList(growable: false);
    final seen = <String>{};
    for (final row in rows) {
      if (!seen.add('${row.kind}:${row.id}') ||
          (query['kind'] != 'all' && row.kind != query['kind']) ||
          (query['currency'] != null && row.currency != query['currency']) ||
          (query['vendor_id'] != null && '${row.vendor}' != query['vendor_id'])) {
        throw const FormatException('Private review filters changed.');
      }
    }
    final summary = raw['summary'];
    final groups = <FinanceReviewGroup>[];
    if (summary != null) {
      if (summary is! Map ||
          summary['scope'] != 'ALL_CURRENT_FILTERED_PENDING_RECORDS_NOT_PAGE' ||
          summary['currency_and_precision_isolated'] != true ||
          summary['not_a_payment_or_payout_authority'] != true ||
          summary['groups'] is! List || (summary['groups'] as List).length > 200) {
        throw const FormatException('Invalid pending review summary.');
      }
      final byKey = <String, FinanceReviewGroup>{};
      int count = 0;
      for (final value in summary['groups'] as List) {
        final group = FinanceReviewGroup.parse(value, query);
        if (byKey.containsKey(group.key)) {
          throw const FormatException('Duplicate pending review scope.');
        }
        byKey[group.key] = group;
        groups.add(group);
        count = _exact(count + group.count);
      }
      if (count != total) {
        throw const FormatException('Pending review summary count changed.');
      }
      final pageCounts = <String, int>{};
      for (final row in rows) {
        if (row.precision == null) continue;
        final key = '${row.kind}:${row.currency}:${row.precision}';
        final group = byKey[key];
        final n = (pageCounts[key] ?? 0) + 1;
        if (group == null || n > group.count) {
          throw const FormatException('Pending review row outside summary scope.');
        }
        pageCounts[key] = n;
      }
      // A zero-precision group might contain a legacy null-precision
      // source on another page. Never format those amounts as money.
      groups.removeWhere((group) => group.precision == 0);
      if (rows.any((row) => row.precision == null)) groups.clear();
    }
    return FinanceReviewPage._(List.unmodifiable(rows), List.unmodifiable(groups), page, last, total);
  }
}

/// Read only: never a source of authority to approve or move money.
class FinanceApprovalQueueView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const FinanceApprovalQueueView({super.key, required this.api, required this.onExpired});
  @override
  State<FinanceApprovalQueueView> createState() => _FinanceApprovalQueueState();
}

class _FinanceApprovalQueueState extends State<FinanceApprovalQueueView>
    with WidgetsBindingObserver {
  final currency = TextEditingController(), vendor = TextEditingController();
  final scroll = ScrollController();
  String kind = 'all';
  FinanceReviewPage? page;
  Map<String, String>? applied;
  bool busy = false, foreground = true, revoked = false, failed = false;
  int generation = 0;
  late final bool Function() authorized;
  StreamSubscription<int>? session;
  String t(String en, String ar) => financeReviewText(context, en, ar);

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() {
        revoked = true;
        clear();
        currency.clear();
        vendor.clear();
      });
    });
    if (foreground) load();
  }

  void clear() {
    generation++;
    page = null;
    applied = null;
    busy = false;
    failed = false;
  }

  @override
  void didUpdateWidget(covariant FinanceApprovalQueueView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api)) {
      revoked = true;
      clear();
      currency.clear();
      vendor.clear();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) {
        clear();
        currency.clear();
        vendor.clear();
        kind = 'all';
      }
    });
  }

  @override
  void dispose() {
    generation++;
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    currency.dispose();
    vendor.dispose();
    scroll.dispose();
    super.dispose();
  }

  bool get visible => foreground && !revoked && authorized();
  Map<String, String> filters(int number) {
    final cur = currency.text.trim().toUpperCase();
    final id = vendor.text.trim();
    if ((cur.isNotEmpty && !RegExp(r'^[A-Z]{3}$').hasMatch(cur)) ||
        (id.isNotEmpty && (!RegExp(r'^[1-9][0-9]{0,15}$').hasMatch(id) ||
          (int.tryParse(id) ?? _maxExact + 1) > _maxExact))) {
      throw const FormatException('Invalid review filters.');
    }
    return {'kind': kind, 'page': '$number', 'per_page': '20',
      if (cur.isNotEmpty) 'currency': cur, if (id.isNotEmpty) 'vendor_id': id};
  }

  Future<void> load({Map<String, String>? previous, int number = 1}) async {
    if (!visible) return;
    final api = widget.api;
    final epoch = ++generation;
    bool active() => mounted && visible && identical(api, widget.api) && epoch == generation;
    // Page navigation starts from the filters rather than retaining an old
    // offset below the end of a much shorter replacement page.
    if (scroll.hasClients) scroll.jumpTo(0);
    setState(() { busy = true; failed = false; page = null; applied = null; });
    try {
      final query = Map<String, String>.unmodifiable(
        previous == null ? filters(number) : {...previous, 'page': '$number'},
      );
      final me = await api.request('me');
      if (!active()) return;
      if (me is! Map || me['role'] != 'admin') {
        throw const ApiException(403, 'Role required.');
      }
      _exact(me['id'], positive: true);
      final raw = await api.request('finance/approval-queue', query: query);
      if (!active()) return;
      final result = FinanceReviewPage.parse(raw, query);
      setState(() { page = result; applied = query; });
    } catch (error) {
      if (mounted && identical(api, widget.api) &&
          error is ApiException && error.endsCurrentSession) {
        widget.onExpired();
        return;
      }
      if (!active()) return;
      setState(() { page = null; applied = null; failed = true; });
    } finally {
      if (active()) setState(() => busy = false);
    }
  }

  String kindLabel(String value) => switch (value) {
    'refund' => t('Refund', 'استرداد'),
    'settlement' => t('Settlement', 'تسوية'),
    'adjustment' => t('Adjustment', 'تعديل'),
    _ => t('All review types', 'جميع أنواع المراجعة'),
  };

  @override
  Widget build(BuildContext context) {
    final data = visible ? page : null;
    return Scaffold(
      appBar: AppBar(title: Text(t('Pending finance reviews', 'المراجعات المالية المعلقة'))),
      body: ListView(controller: scroll, padding: const EdgeInsets.all(16), children: [
        Text(t('Read-only TEST review queue. These are pending source records, not balances or approval, payment or payout authority.',
          'قائمة مراجعة اختبارية للقراءة فقط. هذه سجلات معلقة وليست أرصدة أو صلاحية اعتماد أو دفع أو تحويل.')),
        if (!visible)
          Text(t('Private review cleared. Return to the account menu to reopen.',
            'مُسحت المراجعة الخاصة. ارجع إلى قائمة الحساب لإعادة فتحها.')),
        if (visible) ...[
          DropdownButtonFormField<String>(
            key: const Key('finance-review-kind'),
            initialValue: kind,
            isExpanded: true,
            items: ['all', ..._kinds].map((v) => DropdownMenuItem(value: v, child: Text(kindLabel(v)))).toList(),
            onChanged: busy ? null : (v) => setState(() { clear(); kind = v!; }),
          ),
          TextField(key: const Key('finance-review-currency'), controller: currency,
            enabled: !busy, maxLength: 3,
            onChanged: (_) => setState(clear),
            decoration: InputDecoration(labelText: t('Currency (optional)', 'العملة (اختياري)'))),
          TextField(key: const Key('finance-review-vendor'), controller: vendor,
            enabled: !busy, maxLength: 16, keyboardType: TextInputType.number,
            onChanged: (_) => setState(clear),
            decoration: InputDecoration(labelText: t('Vendor ID (optional)', 'معرف المورد (اختياري)'))),
          FilledButton(key: const Key('finance-review-refresh'), onPressed: busy ? null : () => load(),
            child: Text(t('Apply and refresh', 'تطبيق وتحديث'))),
          if (busy) const Center(child: CircularProgressIndicator()),
          if (failed) Text(t('Private review unavailable. Check filters and access, then refresh.',
            'المراجعة الخاصة غير متاحة. تحقق من المرشحات والصلاحية ثم حدّث.')),
          if (data != null) ...[
            Text('${t('Pending records', 'السجلات المعلقة')}: ${data.total}'),
            if (data.groups.isNotEmpty) ExpansionTile(
              title: Text(t('Filtered pending records by scope', 'السجلات المعلقة المصفاة حسب النطاق')),
              subtitle: Text(t('Not a balance or payout amount', 'ليس رصيداً أو مبلغ تحويل')),
              children: [
                Text(t('Amounts cover all filtered pages for the shown scopes. Uncertain precision is omitted.',
                  'تشمل المبالغ جميع الصفحات المصفاة للنطاقات المعروضة. تستبعد الدقة غير المؤكدة.')),
                for (final group in data.groups) ListTile(
                  title: Text('${kindLabel(group.kind)} · ${group.currency} · ${t('Precision', 'الدقة')} ${group.precision}'),
                  subtitle: Text('${t('Records', 'السجلات')}: ${group.count}'),
                  trailing: Text(group.amountLabel, textDirection: TextDirection.ltr),
                ),
              ],
            ),
            Text('${t('Page', 'صفحة')} ${data.page} / ${data.last}'),
            if (data.rows.isEmpty) Text(t('No pending records on this page.', 'لا توجد سجلات معلقة في هذه الصفحة.')),
            for (final row in data.rows)
              Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(
                crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${kindLabel(row.kind)} #${row.id}'),
                  Text('${t('Vendor', 'المورد')} #${row.vendor}'),
                  if (row.invoice != null) Text('${t('Invoice', 'الفاتورة')} #${row.invoice}'),
                  Text(row.amountLabel, textDirection: TextDirection.ltr),
                  if (row.precision == null) Text(t('Raw minor units; precision unavailable.', 'وحدات صغرى خام؛ الدقة غير متاحة.')),
                  Text(row.status == 'awaiting_approval' ? t('Awaiting approval', 'بانتظار الاعتماد') : t('Pending', 'معلق')),
                  Text(row.createdAt, textDirection: TextDirection.ltr),
                ],
              ))),
            Wrap(spacing: 12, children: [
              OutlinedButton(key: const Key('finance-review-previous'),
                onPressed: data.page > 1 ? () => load(previous: applied, number: data.page - 1) : null,
                child: Text(t('Previous', 'السابق'))),
              OutlinedButton(key: const Key('finance-review-next'),
                onPressed: data.page < data.last && data.page < 10000
                    ? () => load(previous: applied, number: data.page + 1) : null,
                child: Text(t('Next', 'التالي'))),
            ]),
          ],
        ],
      ]),
    );
  }
}
