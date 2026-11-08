import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

/// Select source evidence only. This screen never creates or approves a refund.
class SourceCreditPickerView extends StatefulWidget {
  final EvApi api;
  final int invoiceId;
  final bool requireFullReversal;
  final VoidCallback onExpired;
  const SourceCreditPickerView({
    super.key,
    required this.api,
    required this.invoiceId,
    required this.onExpired,
    this.requireFullReversal = false,
  });
  @override
  State<SourceCreditPickerView> createState() => _SourceCreditPickerState();
}

class _SourceCreditPickerState extends State<SourceCreditPickerView>
    with WidgetsBindingObserver {
  late bool Function() current;
  ApiPage<Map<String, dynamic>>? page;
  bool busy = true, hidden = false;
  int generation = 0;
  Object? error;
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
  void dispose() {
    generation++;
    page = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      generation++;
      setState(() {
        page = null;
        error = null;
        busy = false;
        hidden = true;
      });
    }
  }

  Future<void> load({int number = 1}) async {
    if (!current()) {
      widget.onExpired();
      return;
    }
    final epoch = ++generation;
    setState(() {
      page = null;
      busy = true;
      hidden = false;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'finance/invoices/${widget.invoiceId}/source-credits',
        query: {
          'page': '$number',
          if (widget.requireFullReversal) 'full_reversal': '1',
        },
      );
      if (!mounted || epoch != generation || !current()) return;
      if (result['schema_version'] != 1 ||
          result['invoice_id'] != widget.invoiceId ||
          result['financial_authority'] != false ||
          result['automatic_refund'] != false) {
        throw const ApiException(502, 'Unverified credit source list.');
      }
      setState(() => page = ApiPage.fromJson(result, (row) => row));
    } catch (e) {
      if (mounted && epoch == generation && current()) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  bool selectable(Map<String, dynamic> row) {
    final evidence = row['evidence'];
    return current() &&
        row['id'] is int &&
        (row['id'] as int) > 0 &&
        row['available_for_review'] == true &&
        (!widget.requireFullReversal ||
            row['full_reversal_source_match'] == true) &&
        evidence is Map &&
        evidence['schema_version'] == 1 &&
        evidence['invoice_id'] == widget.invoiceId &&
        evidence['credit_record_id'] == row['id'] &&
        evidence['status'] == 'source_reference_matched' &&
        evidence['financial_authority'] == false &&
        evidence['automatic_refund'] == false;
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(
      title: Text(
        t('Partner credit references', 'مراجع الإشعارات الدائنة للشريك'),
      ),
    ),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          t(
            'These references match this invoice’s original partner CDR. Reported costs are evidence only. A refund still requires captured funds and manual review.',
            'تطابق هذه المراجع سجل الشحن الأصلي للشريك في هذه الفاتورة. التكاليف المبلغة أدلة فقط. يظل الاسترداد مشروطاً بأموال محصلة ومراجعة يدوية.',
          ),
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (hidden)
          Text(
            t(
              'References hidden. Refresh to reload.',
              'تم إخفاء المراجع. حدّث لإعادة تحميلها.',
            ),
          ),
        TextButton(
          onPressed: busy ? null : () => load(),
          child: Text(t('Refresh', 'تحديث')),
        ),
        TextButton(
          onPressed: busy || !current() ? null : () => Navigator.pop(c, 0),
          child: Text(
            t(
              'Continue without source credit',
              'المتابعة دون إشعار دائن من المصدر',
            ),
          ),
        ),
        if (current()) ...[
          if (page != null && page!.items.isEmpty)
            Text(
              t(
                'No matching source credits',
                'لا توجد إشعارات دائنة مطابقة من المصدر',
              ),
            ),
          for (final row in page?.items ?? <Map<String, dynamic>>[])
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${row['external_id']}'),
                    if (row['existing_refund_state'] != null)
                      Text(
                        t(
                          'Already linked to a refund review',
                          'مرتبط مسبقاً بمراجعة استرداد',
                        ),
                      ),
                    if (row['evidence'] is Map)
                      Text(
                        '${row['evidence']['currency'] ?? ''} · ${row['evidence']['reported_cost']?['excl_vat'] ?? '—'} ${t('reported excluding VAT', 'مبلغ مبلغ عنه دون ضريبة')}',
                      ),
                    OutlinedButton(
                      onPressed: busy || !selectable(row)
                          ? null
                          : () => Navigator.pop(c, row['id'] as int),
                      child: Text(
                        t(
                          'Select credit reference',
                          'اختيار مرجع الإشعار الدائن',
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          if ((page?.current ?? 1) > 1)
            TextButton(
              onPressed: busy ? null : () => load(number: page!.current - 1),
              child: Text(t('Previous', 'السابق')),
            ),
          if ((page?.current ?? 1) < (page?.last ?? 1))
            TextButton(
              onPressed: busy ? null : () => load(number: page!.current + 1),
              child: Text(t('Next', 'التالي')),
            ),
        ],
      ],
    ),
  );
}
