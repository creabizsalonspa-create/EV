import 'package:flutter/material.dart';
import 'api.dart';
import 'invoice.dart';
import 'money_input.dart';

/// Read-only partner lineage; financial actions stay in the existing invoice workflow.
class PartnerBillingPage extends StatefulWidget {
  final EvApi api;
  final int providerId;
  final VoidCallback onExpired;
  const PartnerBillingPage({
    super.key,
    required this.api,
    required this.providerId,
    required this.onExpired,
  });
  @override
  State<PartnerBillingPage> createState() => _PartnerBillingState();
}

class _PartnerBillingState extends State<PartnerBillingPage>
    with WidgetsBindingObserver {
  late bool Function() current;
  final currency = TextEditingController(),
      country = TextEditingController(),
      party = TextEditingController();
  Map<String, dynamic>? report;
  String kind = 'all', message = '';
  Map<String, String> appliedFilters = {'kind': 'all'};
  bool busy = false;
  int generation = 0;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  @override
  void initState() {
    super.initState();
    current = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didUpdateWidget(covariant PartnerBillingPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.providerId != widget.providerId) {
      generation++;
      currency.clear();
      country.clear();
      party.clear();
      report = null;
      appliedFilters = {'kind': 'all'};
      kind = 'all';
      current = widget.api.sessionFence();
      load();
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    currency.dispose();
    country.dispose();
    party.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      generation++;
      currency.clear();
      country.clear();
      party.clear();
      setState(() {
        report = null;
        appliedFilters = {'kind': 'all'};
        busy = false;
        message = '';
        kind = 'all';
      });
    }
  }

  Future<void> load({int page = 1, bool applyFilters = false}) async {
    if (!current()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      report = null;
      message = '';
    });
    try {
      if (applyFilters) {
        appliedFilters = {
          'kind': kind,
          if (currency.text.trim().isNotEmpty)
            'currency': currency.text.trim().toUpperCase(),
          if (country.text.trim().isNotEmpty)
            'country_code': country.text.trim().toUpperCase(),
          if (party.text.trim().isNotEmpty)
            'party_id': party.text.trim().toUpperCase(),
        };
      }
      final result = await widget.api.request(
        'ocpi/providers/${widget.providerId}/billing-reconciliation',
        query: {...appliedFilters, 'page': '$page'},
      );
      if (!mounted || !current() || epoch != generation) return;
      if (result is! Map<String, dynamic> ||
          result['provider_id'] != widget.providerId ||
          result['schema_version'] != 1 ||
          result['read_only'] != true ||
          result['billing_authority'] != false ||
          result['payment_execution'] != false ||
          result['data'] is! List)
        throw const FormatException('Unverified report');
      setState(() => report = result);
    } catch (error) {
      if (!mounted || !current() || epoch != generation) return;
      setState(
        () => message = t(
          'Report unavailable. Refresh current source state.',
          'التقرير غير متاح. حدّث حالة المصدر الحالية.',
        ),
      );
      if (error is ApiException && [401, 419].contains(error.status))
        widget.onExpired();
    } finally {
      if (mounted && epoch == generation) {
        setState(() {
          busy = false;
          if (!current()) {
            report = null;
            message = '';
          }
        });
      }
    }
  }

  String stateLabel(dynamic value) => switch (value) {
    'test_invoice_linked' => t(
      'Canonical TEST invoice linked',
      'تم ربط فاتورة الاختبار الأصلية',
    ),
    'not_invoiced' => t(
      'No canonical invoice; no collection inferred',
      'لا توجد فاتورة أصلية؛ لا يفترض تحصيل',
    ),
    'credited' => t(
      'Credited without cash collection',
      'تمت التسوية دون تحصيل نقدي',
    ),
    'paid' => t('Paid in TEST ledger', 'مدفوعة في سجل الاختبار'),
    'unpaid' => t('Unpaid', 'غير مدفوعة'),
    'partially_paid' => t('Partially paid', 'مدفوعة جزئياً'),
    'refunded' => t('Refunded in TEST ledger', 'مستردة في سجل الاختبار'),
    'partially_refunded' => t('Partially refunded', 'مستردة جزئياً'),
    _ => t('Requires review', 'تتطلب مراجعة'),
  };
  List<Widget> record(Map row) {
    final billing = row['billing'] is Map
        ? row['billing'] as Map
        : <String, dynamic>{};
    final invoice = billing['invoice'] is Map
        ? billing['invoice'] as Map
        : <String, dynamic>{};
    final units = invoice['minor_units'], code = invoice['currency'];
    final validInvoice =
        billing['status'] == 'test_invoice_linked' &&
        invoice['id'] is int &&
        units is int &&
        units >= 0 &&
        units <= 3 &&
        code is String &&
        RegExp(r'^[A-Z]{3}$').hasMatch(code);
    return [
      Text(
        '${row['country_code']} · ${row['party_id']} · ${row['external_id']}',
      ),
      Text(
        row['credit'] == true
            ? t('Source credit', 'إشعار دائن من المصدر')
            : t('Source charge', 'شحنة من المصدر'),
      ),
      Text(
        (row['source'] as Map?)?['status'] == 'source_consistent'
            ? t(
                'Partner session and CDR agree',
                'تتطابق جلسة الشريك وسجل الشحن',
              )
            : t('Partner source requires review', 'يتطلب مصدر الشريك مراجعة'),
      ),
      Text(stateLabel(billing['status'])),
      if (validInvoice) ...[
        Text('${invoice['number']}'),
        Text(stateLabel(billing['state'])),
        if (billing['ledger_balanced'] != true)
          Text(
            t('Ledger requires reconciliation', 'السجل المالي يتطلب مطابقة'),
          ),
        for (final field in [
          'total_minor',
          'paid_minor',
          'refunded_minor',
          'unpaid_credited_minor',
          'due_minor',
        ])
          if ((field == 'total_minor' ? invoice[field] : billing[field])
                  is int &&
              (field == 'total_minor' ? invoice[field] : billing[field]) >= 0)
            Text(
              '${switch (field) {
                'total_minor' => t('Original total', 'الإجمالي الأصلي'),
                'paid_minor' => t('Collected in TEST ledger', 'المحصّل في سجل الاختبار'),
                'refunded_minor' => t('Refunded in TEST ledger', 'المسترد في سجل الاختبار'),
                'unpaid_credited_minor' => t('Noncash credit', 'تسوية غير نقدية'),
                _ => t('Amount due', 'المبلغ المستحق'),
              }}: $code ${minorDecimal(field == 'total_minor' ? invoice[field] : billing[field], units)}',
            ),
        Text(
          '${t('Refund reviews (not refunds)', 'مراجعات الاسترداد وليست استردادات')}: ${billing['refund_review_count']}',
        ),
        TextButton(
          onPressed: () {
            if (current())
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => Scaffold(
                    appBar: AppBar(title: Text(t('Invoice', 'الفاتورة'))),
                    body: InvoicePage(
                      api: widget.api,
                      invoiceId: invoice['id'],
                      onExpired: widget.onExpired,
                    ),
                  ),
                ),
              );
          },
          child: Text(t('Open canonical invoice', 'فتح الفاتورة الأصلية')),
        ),
      ],
    ];
  }

  @override
  Widget build(BuildContext context) => !current()
      ? Scaffold(
          body: Center(child: Text(t('Sign in again.', 'سجّل الدخول مجددًا.'))),
        )
      : Scaffold(
          appBar: AppBar(
            title: Text(
              t('Partner billing reconciliation', 'مطابقة فوترة الشريك'),
            ),
          ),
          body: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(
                t(
                  'Read-only partner reports and canonical TEST ledger. This does not settle a partner, authorize billing or prove a bank payment.',
                  'تقارير الشريك وسجل الاختبار الأصلي للعرض فقط. لا تُسوى مستحقات الشريك ولا تُصرح فوترة ولا يُثبت دفع مصرفي.',
                ),
              ),
              TextField(
                controller: currency,
                maxLength: 3,
                decoration: InputDecoration(labelText: t('Currency', 'العملة')),
              ),
              TextField(
                controller: country,
                maxLength: 2,
                decoration: InputDecoration(
                  labelText: t('Country code', 'رمز الدولة'),
                ),
              ),
              TextField(
                controller: party,
                maxLength: 3,
                decoration: InputDecoration(
                  labelText: t('Party ID', 'رمز الطرف'),
                ),
              ),
              DropdownButton<String>(
                value: kind,
                items: [
                  for (final entry in {
                    'all': t('All records', 'كل السجلات'),
                    'charges': t('Charges', 'الشحنات'),
                    'credits': t('Credits', 'الإشعارات الدائنة'),
                  }.entries)
                    DropdownMenuItem(
                      value: entry.key,
                      child: Text(entry.value),
                    ),
                ],
                onChanged: busy
                    ? null
                    : (value) => setState(() => kind = value!),
              ),
              TextButton(
                onPressed: busy ? null : () => load(applyFilters: true),
                child: Text(t('Refresh', 'تحديث')),
              ),
              if (busy) const LinearProgressIndicator(),
              if (message.isNotEmpty) Text(message),
              if (report != null) ...[
                for (final row in report!['data'] as List)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: record(row as Map),
                      ),
                    ),
                  ),
                if ((report!['data'] as List).isEmpty)
                  Text(
                    t(
                      'No matching source records',
                      'لا توجد سجلات مصدر مطابقة',
                    ),
                  ),
                if (report!['current_page'] > 1)
                  TextButton(
                    onPressed: () => load(page: report!['current_page'] - 1),
                    child: Text(t('Previous', 'السابق')),
                  ),
                if (report!['current_page'] < report!['last_page'])
                  TextButton(
                    onPressed: () => load(page: report!['current_page'] + 1),
                    child: Text(t('Next', 'التالي')),
                  ),
              ],
            ],
          ),
        );
}
