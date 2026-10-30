import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'financial_operation_store.dart';
import 'financial_documents.dart';
import 'vendor_finance.dart' show nativeFinancialAmount;

const corporateEn = {
  'title': 'Corporate billing',
  'notice':
      'Authorized company records only. Statements are not bank receipts or legally approved invoices. Live company collection is not enabled.',
  'organizations': 'My organizations',
  'agreements': 'Billing agreements',
  'claims': 'Invoice claims',
  'statements': 'Company statements',
  'empty': 'No records.',
  'refresh': 'Refresh',
  'previous': 'Previous',
  'next': 'Next',
  'back': 'Back',
  'accept': 'Accept published agreement',
  'claim': 'Allocate my invoice',
  'review': 'Review claim',
  'issue': 'Issue selected statement',
  'confirm': 'Confirm',
  'cancel': 'Cancel',
  'consent':
      'I reviewed the displayed immutable terms and explicitly authorize this operation. It does not initiate live payment.',
  'invoice': 'My charging invoice',
  'center': 'Cost center',
  'period': 'Billing month (YYYY-MM, UTC)',
  'reason': 'Decision reason',
  'approved': 'Approved',
  'rejected': 'Rejected',
  'pending': 'Pending',
  'accepted': 'Accepted',
  'unaccepted': 'Awaiting owner consent',
  'terms': 'Published terms',
  'limit': 'Published credit limit',
  'unknown': 'Unknown state',
  'statement': 'Private statement document',
  'detail': 'Statement details',
  'saved':
      'Unconfirmed intent is saved. Refresh source history before explicitly resending the same exact request. No automatic retry or replacement key.',
  'done':
      'Server confirmed. Refresh authoritative history. No real company payment is claimed.',
  'invalid': 'Complete the required exact fields.',
  'selection': 'Selected claim IDs',
  'ownInvoice': 'No own charging invoice on this page.',
  'noConsent':
      'Only the current authorized owner may accept a new agreement. Historical payer consent does not transfer to another owner.',
  'issued': 'Issued',
  'overdue': 'Overdue',
  'simulated_paid': 'Simulated receipt posted',
  'collection_attention': 'Collection needs review',
  'total': 'Statement total',
  'due': 'Due date (UTC)',
};
const corporateAr = {
  'title': 'فوترة الشركات',
  'notice':
      'سجلات الشركات المصرح بها فقط. الكشوف ليست إيصالات مصرفية أو فواتير معتمدة قانونياً. التحصيل الفعلي للشركات غير مفعل.',
  'organizations': 'مؤسساتي',
  'agreements': 'اتفاقيات الفوترة',
  'claims': 'مطالبات الفواتير',
  'statements': 'كشوف الشركة',
  'empty': 'لا توجد سجلات.',
  'refresh': 'تحديث',
  'previous': 'السابق',
  'next': 'التالي',
  'back': 'رجوع',
  'accept': 'قبول الاتفاقية المنشورة',
  'claim': 'تخصيص فاتورتي',
  'review': 'مراجعة المطالبة',
  'issue': 'إصدار الكشف المحدد',
  'confirm': 'تأكيد',
  'cancel': 'إلغاء',
  'consent':
      'راجعت الشروط الثابتة المعروضة وأفوض هذه العملية صراحةً. لا تبدأ دفعة فعلية.',
  'invoice': 'فاتورة الشحن الخاصة بي',
  'center': 'مركز التكلفة',
  'period': 'شهر الفوترة (YYYY-MM، UTC)',
  'reason': 'سبب القرار',
  'approved': 'معتمدة',
  'rejected': 'مرفوضة',
  'pending': 'معلقة',
  'accepted': 'مقبولة',
  'unaccepted': 'بانتظار موافقة المالك',
  'terms': 'الشروط المنشورة',
  'limit': 'حد الائتمان المنشور',
  'unknown': 'حالة غير معروفة',
  'statement': 'مستند الكشف الخاص',
  'detail': 'تفاصيل الكشف',
  'saved':
      'النية غير المؤكدة محفوظة. حدّث السجل قبل إعادة إرسال الطلب نفسه صراحةً. لا إعادة تلقائية أو معرف بديل.',
  'done': 'أكد الخادم العملية. حدّث السجل الأصلي. لا يُدعى سداد فعلي للشركة.',
  'invalid': 'أكمل الحقول الدقيقة المطلوبة.',
  'selection': 'معرفات المطالبات المحددة',
  'ownInvoice': 'لا توجد فاتورة شحن تخصك في هذه الصفحة.',
  'noConsent':
      'المالك الحالي المصرح له وحده يقبل اتفاقية جديدة. لا تنتقل موافقة الدافع السابق إلى مالك آخر.',
  'issued': 'صادر',
  'overdue': 'متأخر',
  'simulated_paid': 'تم تسجيل إيصال محاكى',
  'collection_attention': 'التحصيل يحتاج مراجعة',
  'total': 'إجمالي الكشف',
  'due': 'تاريخ الاستحقاق (UTC)',
};
String corporateText(BuildContext c, String k) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? corporateAr
        : corporateEn)[k] ??
    corporateEn['unknown']!;

class CorporateBillingView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int? invoiceId;
  const CorporateBillingView({
    super.key,
    required this.api,
    required this.onExpired,
    this.invoiceId,
  });
  @override
  State<CorporateBillingView> createState() => _CorporateRootState();
}

class _CorporateRootState extends State<CorporateBillingView> {
  ApiPage<Map<String, dynamic>>? fleets;
  List<Map<String, dynamic>>? agreements;
  Map<String, dynamic>? selected;
  bool canManage = false, busy = false;
  int generation = 0, target = 1;
  Object? error;
  String t(String k) => corporateText(context, k);
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    final epoch = ++generation;
    target = page;
    setState(() {
      busy = true;
      error = null;
      fleets = null;
      agreements = null;
      canManage = false;
    });
    try {
      if (selected == null) {
        final data = ApiPage.fromJson(
          await widget.api.request('fleets', query: {'page': '$page'}),
          (r) => r,
        );
        if (mounted && generation == epoch) fleets = data;
      } else {
        final result = await widget.api.request(
          'commercial/corporate',
          query: {'fleet_id': '${selected!['id']}'},
        );
        if (result['real_payment_enabled'] != false ||
            result['can_manage'] is! bool ||
            result['agreements'] is! List)
          throw const FormatException('Unsupported corporate evidence.');
        if (!mounted || generation != epoch) return;
        final rows = (result['agreements'] as List)
            .map((r) => Map<String, dynamic>.from(r as Map))
            .toList();
        for (final row in rows) {
          if (row['fleet_id'] != selected!['id'])
            throw const FormatException('Wrong corporate source.');
          nativeFinancialAmount(context, row, 'credit_limit_minor');
        }
        agreements = rows;
        canManage = result['can_manage'] as bool;
      }
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        agreements = null;
        fleets = null;
        canManage = false;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  Future<void> action(Map<String, dynamic> row, String kind) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CorporateActionView(
          api: widget.api,
          onExpired: widget.onExpired,
          action: kind,
          agreementId: row['id'] as int,
          fleetId: selected!['id'] as int,
          currency: row['currency'] as String,
          invoiceId: widget.invoiceId,
        ),
      ),
    );
    if (mounted) await load();
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('title'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (selected != null)
          Text('${selected!['name']}', style: Theme.of(c).textTheme.titleLarge),
        Wrap(
          spacing: 12,
          children: [
            OutlinedButton(
              onPressed: busy ? null : () => load(page: target),
              child: Text(t('refresh')),
            ),
            if (selected != null)
              TextButton(
                onPressed: busy
                    ? null
                    : () {
                        selected = null;
                        load();
                      },
                child: Text(t('organizations')),
              ),
          ],
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (fleets != null) ...[
          if (fleets!.items.isEmpty) Text(t('empty')),
          for (final row in fleets!.items)
            Card(
              child: ListTile(
                title: Text('${row['name']}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: busy
                    ? null
                    : () {
                        selected = row;
                        load();
                      },
              ),
            ),
          Wrap(
            spacing: 12,
            children: [
              OutlinedButton(
                onPressed: busy || fleets!.current <= 1
                    ? null
                    : () => load(page: fleets!.current - 1),
                child: Text(t('previous')),
              ),
              Text('${fleets!.current}/${fleets!.last}'),
              OutlinedButton(
                onPressed: busy || fleets!.current >= fleets!.last
                    ? null
                    : () => load(page: fleets!.current + 1),
                child: Text(t('next')),
              ),
            ],
          ),
        ],
        if (agreements != null) ...[
          if (agreements!.isEmpty) Text(t('empty')),
          for (final row in agreements!)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${row['legal_name']}',
                      style: Theme.of(c).textTheme.titleLarge,
                    ),
                    Text('${t('terms')}: ${row['terms']}'),
                    Text(
                      '${t('limit')}: ${nativeFinancialAmount(c, row, 'credit_limit_minor')}',
                    ),
                    Text(
                      t(row['acceptance'] == null ? 'unaccepted' : 'accepted'),
                    ),
                    Wrap(
                      spacing: 12,
                      runSpacing: 8,
                      children: [
                        if (canManage && row['acceptance'] == null)
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () => action(row, 'accept'),
                            child: Text(t('accept')),
                          ),
                        if (row['acceptance'] != null)
                          OutlinedButton(
                            onPressed: busy ? null : () => action(row, 'claim'),
                            child: Text(t('claim')),
                          ),
                        OutlinedButton(
                          onPressed: busy
                              ? null
                              : () => Navigator.of(c).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => CorporateRecordsView(
                                      api: widget.api,
                                      onExpired: widget.onExpired,
                                      agreement: row,
                                      fleetId: selected!['id'] as int,
                                      canManage: canManage,
                                    ),
                                  ),
                                ),
                          child: Text(t('claims')),
                        ),
                      ],
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

class CorporateRecordsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final Map<String, dynamic> agreement;
  final int fleetId;
  final bool canManage;
  const CorporateRecordsView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.agreement,
    required this.fleetId,
    required this.canManage,
  });
  @override
  State<CorporateRecordsView> createState() => _CorporateRecordsState();
}

class _CorporateRecordsState extends State<CorporateRecordsView> {
  String section = 'claims';
  ApiPage<Map<String, dynamic>>? page;
  bool busy = false;
  Object? error;
  int generation = 0, target = 1;
  final selected = <int>{};
  String t(String k) => corporateText(context, k);
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> load({int number = 1}) async {
    final epoch = ++generation;
    target = number;
    setState(() {
      busy = true;
      error = null;
      page = null;
      selected.clear();
    });
    try {
      final result = ApiPage.fromJson(
        await widget.api.request(
          'commercial/corporate/agreements/${widget.agreement['id']}/$section',
          query: {'page': '$number'},
        ),
        (r) => r,
      );
      if (!mounted || generation != epoch) return;
      for (final row in result.items) {
        if (row['agreement_id'] != widget.agreement['id'])
          throw const FormatException('Wrong agreement source.');
        nativeFinancialAmount(
          context,
          row,
          section == 'claims' ? 'amount_minor' : 'total_minor',
        );
      }
      page = result;
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        page = null;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  Future<void> action(String action, {int? claim}) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CorporateActionView(
          api: widget.api,
          onExpired: widget.onExpired,
          action: action,
          agreementId: widget.agreement['id'] as int,
          fleetId: widget.fleetId,
          currency: widget.agreement['currency'] as String,
          claimId: claim,
          claimIds: selected.toList()..sort(),
        ),
      ),
    );
    if (mounted) await load(number: target);
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t(section))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (widget.canManage)
          DropdownButtonFormField<String>(
            initialValue: section,
            items: [
              for (final k in ['claims', 'statements'])
                DropdownMenuItem(value: k, child: Text(t(k))),
            ],
            onChanged: busy
                ? null
                : (v) {
                    section = v!;
                    load();
                  },
          ),
        OutlinedButton(
          onPressed: busy ? null : () => load(number: target),
          child: Text(t('refresh')),
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (page != null) ...[
          if (page!.items.isEmpty) Text(t('empty')),
          for (final row in page!.items)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      section == 'claims'
                          ? '#${row['id']}'
                          : '${row['number']}',
                    ),
                    Text(
                      nativeFinancialAmount(
                        c,
                        row,
                        section == 'claims' ? 'amount_minor' : 'total_minor',
                      ),
                    ),
                    if (section == 'claims') ...[
                      Text('${row['cost_center_name']}'),
                      Text(t(row['decision']?['decision'] ?? 'pending')),
                      if (widget.canManage && row['decision'] == null)
                        OutlinedButton(
                          onPressed: busy
                              ? null
                              : () => action('review', claim: row['id'] as int),
                          child: Text(t('review')),
                        ),
                      if (widget.canManage &&
                          row['decision']?['decision'] == 'approved' &&
                          row['statement_id'] == null)
                        CheckboxListTile(
                          value: selected.contains(row['id']),
                          onChanged: busy
                              ? null
                              : (v) => setState(() {
                                  if (v!)
                                    selected.add(row['id'] as int);
                                  else
                                    selected.remove(row['id']);
                                }),
                          title: Text(t('selection')),
                        ),
                    ] else ...[
                      Text('${row['period']} · ${t('due')}: ${row['due_at']}'),
                      OutlinedButton(
                        onPressed: busy
                            ? null
                            : () => Navigator.of(c).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => CorporateStatementView(
                                    api: widget.api,
                                    onExpired: widget.onExpired,
                                    id: row['id'] as int,
                                  ),
                                ),
                              ),
                        child: Text(t('detail')),
                      ),
                      OutlinedButton(
                        onPressed: busy
                            ? null
                            : () => Navigator.of(c).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => FinancialDocumentsView(
                                    api: widget.api,
                                    onExpired: widget.onExpired,
                                    sourceKind: 'corporate_statement',
                                    sourceId: row['id'] as int,
                                  ),
                                ),
                              ),
                        child: Text(t('statement')),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          if (widget.canManage && section == 'claims')
            FilledButton(
              onPressed: busy || selected.isEmpty
                  ? null
                  : () => action('issue'),
              child: Text(t('issue')),
            ),
          Wrap(
            spacing: 12,
            children: [
              OutlinedButton(
                onPressed: busy || page!.current <= 1
                    ? null
                    : () => load(number: page!.current - 1),
                child: Text(t('previous')),
              ),
              Text('${page!.current}/${page!.last}'),
              OutlinedButton(
                onPressed: busy || page!.current >= page!.last
                    ? null
                    : () => load(number: page!.current + 1),
                child: Text(t('next')),
              ),
            ],
          ),
        ],
      ],
    ),
  );
}

class CorporateActionView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String action, currency;
  final int agreementId, fleetId;
  final int? claimId, invoiceId;
  final List<int> claimIds;
  final FinancialOperationStore? store;
  const CorporateActionView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.action,
    required this.agreementId,
    required this.fleetId,
    required this.currency,
    this.claimId,
    this.invoiceId,
    this.claimIds = const [],
    this.store,
  });
  @override
  State<CorporateActionView> createState() => _CorporateActionState();
}

class _CorporateActionState extends State<CorporateActionView> {
  FinancialOperation? operation;
  Object? error;
  bool busy = true, consent = false, confirmed = false;
  int generation = 0, invoicePage = 1, invoiceLast = 1;
  List<Map<String, dynamic>> invoices = [], centers = [];
  int? invoice, center;
  String decision = 'approved';
  final reason = TextEditingController(), period = TextEditingController();
  Map<String, dynamic>? saved;
  String t(String k) => corporateText(context, k);
  @override
  void initState() {
    super.initState();
    restore();
  }

  @override
  void dispose() {
    generation++;
    reason.dispose();
    period.dispose();
    super.dispose();
  }

  Future<void> restore({int number = 1}) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      invoices = [];
      centers = [];
      operation = null;
    });
    try {
      final me = await widget.api.request('me');
      if (me['id'] is! int ||
          !['accept', 'claim', 'review', 'issue'].contains(widget.action))
        throw const FormatException('Unsupported corporate operation.');
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:${me['id']}:corporate:${widget.agreementId}:${widget.action}:${widget.claimId ?? ''}',
        isCurrent: widget.api.sessionFence(),
      );
      await op.restore();
      Map<String, dynamic>? pending = op.pending == null
          ? null
          : Map<String, dynamic>.from(op.pending!['body'] as Map);
      if (widget.action == 'claim' && pending == null) {
        final fleet = await widget.api.request('fleets/${widget.fleetId}');
        centers = (fleet['cost_centers'] as List)
            .map((r) => Map<String, dynamic>.from(r as Map))
            .toList();
        if (widget.invoiceId != null) {
          final result = await widget.api.request(
            'finance/invoices/${widget.invoiceId}',
          );
          final row = Map<String, dynamic>.from(result['invoice'] as Map);
          if (row['user_id'] != me['id'])
            throw const ApiException(404, 'Invoice ownership required.');
          invoices = [row];
          invoice = widget.invoiceId;
        } else {
          final result = ApiPage.fromJson(
            await widget.api.request(
              'finance/invoices',
              query: {'page': '$number', 'currency': widget.currency},
            ),
            (r) => r,
          );
          invoices = result.items
              .where((r) => r['user_id'] == me['id'])
              .toList();
          invoice = null;
          invoicePage = result.current;
          invoiceLast = result.last;
        }
        if (center != null && !centers.any((r) => r['id'] == center))
          center = null;
      }
      if (!mounted || generation != epoch) return;
      saved = pending;
      operation = op;
      if (pending != null) {
        reason.text = '${pending['reason'] ?? ''}';
        period.text = '${pending['period'] ?? ''}';
        decision = '${pending['decision'] ?? 'approved'}';
      }
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        invoices = [];
        centers = [];
        operation = null;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  Map<String, dynamic> payload() {
    if (saved != null) return Map<String, dynamic>.from(saved!);
    switch (widget.action) {
      case 'accept':
        return {'consent': true};
      case 'claim':
        if (invoice == null || center == null)
          throw const ApiException(422, 'Select invoice and center.');
        return {'invoice_id': invoice, 'cost_center_id': center};
      case 'review':
        if (reason.text.trim().isEmpty || reason.text.length > 500)
          throw const ApiException(422, 'Decision reason required.');
        return {'decision': decision, 'reason': reason.text.trim()};
      case 'issue':
        final pieces = period.text.trim().split('-');
        final year = pieces.length == 2
            ? decimalMinor(pieces[0], 0, maximum: 9999)
            : null;
        final month = pieces.length == 2
            ? decimalMinor(pieces[1], 0, maximum: 12)
            : null;
        if (year == null ||
            year < 1 ||
            month == null ||
            month < 1 ||
            widget.claimIds.isEmpty)
          throw const ApiException(422, 'Select period and claims.');
        return {
          'period':
              '${year.toString().padLeft(4, '0')}-${month.toString().padLeft(2, '0')}',
          'claim_ids': List<int>.from(widget.claimIds),
        };
      default:
        throw const FormatException('Unsupported operation.');
    }
  }

  Future<void> submit() async {
    if (!consent) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final data = await operation!.prepare(payload());
      if (!mounted || generation != epoch) return;
      saved = Map<String, dynamic>.from(operation!.pending!['body'] as Map);
      final suffix = widget.action == 'accept'
          ? 'agreements/${widget.agreementId}/accept'
          : widget.action == 'claim'
          ? 'agreements/${widget.agreementId}/claims'
          : widget.action == 'review'
          ? 'claims/${widget.claimId}/review'
          : 'agreements/${widget.agreementId}/statements';
      final result = await widget.api.request(
        'commercial/corporate/$suffix',
        method: 'POST',
        body: data,
      );
      if (!mounted || generation != epoch) return;
      final row =
          result[widget.action == 'accept'
              ? 'agreement'
              : widget.action == 'claim'
              ? 'claim'
              : widget.action == 'review'
              ? 'decision'
              : 'statement'];
      if (row is! Map ||
          row['id'] is! int ||
          (widget.action == 'accept'
              ? row['id'] != widget.agreementId
              : widget.action == 'review'
              ? row['claim_id'] != widget.claimId
              : row['agreement_id'] != widget.agreementId))
        throw const ApiException(502, 'Unconfirmed corporate receipt.');
      if (widget.action == 'claim' &&
          (row['invoice_id'] != data['invoice_id'] ||
              row['cost_center_id'] != data['cost_center_id']))
        throw const ApiException(502, 'Unconfirmed claim source.');
      if (widget.action == 'review' &&
          (row['decision'] != data['decision'] ||
              row['reason'] != data['reason']))
        throw const ApiException(502, 'Unconfirmed decision.');
      confirmed = true;
      await operation!.confirmed();
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t(widget.action))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (busy) const LinearProgressIndicator(),
        if (confirmed) Text(t('done')),
        if (saved != null && !confirmed) Text(t('saved')),
        if (widget.action == 'claim') ...[
          if (saved != null)
            Text(
              '${t('invoice')}: ${saved!['invoice_id']} · ${t('center')}: ${saved!['cost_center_id']}',
            )
          else ...[
            if (invoices.isEmpty && !busy) Text(t('ownInvoice')),
            DropdownButtonFormField<int>(
              key: ValueKey('invoice-$invoicePage'),
              initialValue: invoice,
              isExpanded: true,
              items: [
                for (final row in invoices)
                  DropdownMenuItem(
                    value: row['id'] as int,
                    child: Text('${row['number']}'),
                  ),
              ],
              onChanged: busy || confirmed
                  ? null
                  : (v) => setState(() => invoice = v),
              decoration: InputDecoration(labelText: t('invoice')),
            ),
            DropdownButtonFormField<int>(
              initialValue: center,
              isExpanded: true,
              items: [
                for (final row in centers)
                  DropdownMenuItem(
                    value: row['id'] as int,
                    child: Text('${row['code']} · ${row['name']}'),
                  ),
              ],
              onChanged: busy || confirmed
                  ? null
                  : (v) => setState(() => center = v),
              decoration: InputDecoration(labelText: t('center')),
            ),
            if (widget.invoiceId == null)
              Wrap(
                spacing: 12,
                children: [
                  TextButton(
                    onPressed: busy || invoicePage <= 1
                        ? null
                        : () => restore(number: invoicePage - 1),
                    child: Text(t('previous')),
                  ),
                  Text('$invoicePage/$invoiceLast'),
                  TextButton(
                    onPressed: busy || invoicePage >= invoiceLast
                        ? null
                        : () => restore(number: invoicePage + 1),
                    child: Text(t('next')),
                  ),
                ],
              ),
          ],
        ],
        if (widget.action == 'review') ...[
          DropdownButtonFormField<String>(
            key: ValueKey(decision),
            initialValue: decision,
            items: [
              for (final d in ['approved', 'rejected'])
                DropdownMenuItem(value: d, child: Text(t(d))),
            ],
            onChanged: busy || saved != null || confirmed
                ? null
                : (v) => setState(() => decision = v!),
          ),
          TextField(
            controller: reason,
            readOnly: busy || saved != null || confirmed,
            maxLength: 500,
            decoration: InputDecoration(labelText: t('reason')),
          ),
        ],
        if (widget.action == 'issue') ...[
          Text(
            '${t('selection')}: ${(saved?['claim_ids'] ?? widget.claimIds).join(', ')}',
          ),
          TextField(
            controller: period,
            readOnly: busy || saved != null || confirmed,
            decoration: InputDecoration(labelText: t('period')),
          ),
        ],
        CheckboxListTile(
          value: consent,
          onChanged: busy || confirmed
              ? null
              : (v) => setState(() => consent = v!),
          title: Text(t('consent')),
        ),
        FilledButton(
          onPressed: busy || confirmed || !consent || operation == null
              ? null
              : submit,
          child: Text(t('confirm')),
        ),
        OutlinedButton(
          onPressed: busy ? null : () => Navigator.of(c).pop(),
          child: Text(t('back')),
        ),
      ],
    ),
  );
}

class CorporateStatementView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int id;
  const CorporateStatementView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.id,
  });
  @override
  State<CorporateStatementView> createState() => _CorporateStatementState();
}

class _CorporateStatementState extends State<CorporateStatementView> {
  Map<String, dynamic>? data;
  bool busy = false;
  Object? error;
  int generation = 0;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    super.dispose();
  }

  Future<void> load() async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final result = Map<String, dynamic>.from(
        await widget.api.request('commercial/corporate/statements/${widget.id}')
            as Map,
      );
      if (!mounted || generation != epoch) return;
      if (result['real_payment_processed'] != false ||
          result['statement']?['id'] != widget.id ||
          result['lines'] is! List)
        throw const FormatException('Unsupported corporate statement.');
      nativeFinancialAmount(
        context,
        Map<String, dynamic>.from(result['statement'] as Map),
        'total_minor',
      );
      data = result;
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    final t = (String k) => corporateText(c, k);
    return Scaffold(
      appBar: AppBar(title: Text(t('detail'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          OutlinedButton(
            onPressed: busy ? null : load,
            child: Text(t('refresh')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (data != null) ...[
            Text('${data!['statement']['number']}'),
            Text(t('${data!['state']}')),
            Text(
              '${t('total')}: ${nativeFinancialAmount(c, Map<String, dynamic>.from(data!['statement'] as Map), 'total_minor')}',
            ),
            Text('${t('due')}: ${data!['statement']['due_at']}'),
            for (final row in data!['lines'] as List)
              ListTile(
                title: Text('${row['invoice_number']}'),
                subtitle: Text('${row['cost_center_name']}'),
              ),
          ],
        ],
      ),
    );
  }
}
