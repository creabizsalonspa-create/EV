import 'package:flutter/material.dart';
import 'api.dart';
import 'vendor_finance.dart' show nativeFinancialAmount;

String providerDisputeText(BuildContext context, String en, String ar) =>
    Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

/// Provider receipts remain distinct from internal complaints and ledger entries.
Map<String, dynamic> providerDispute(dynamic value, {int? expectedId}) {
  final row = Map<String, dynamic>.from(value as Map);
  if (row['id'] is! int ||
      row['invoice_id'] is! int ||
      (expectedId != null && row['id'] != expectedId) ||
      row['evidence'] != 'STRIPE_TEST_CONTRACT' ||
      row['real_money_moved'] != false ||
      row['settlement_hold'] is! bool ||
      row['deadline_passed'] is! bool ||
      row['requires_financial_resolution'] is! bool ||
      row['status'] is! String ||
      row.containsKey('provider_reference') ||
      row.containsKey('charge_reference')) {
    throw const FormatException('Unsupported provider dispute receipt.');
  }
  return row;
}

class ProviderDisputesView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int? initialDisputeId;
  const ProviderDisputesView({
    super.key,
    required this.api,
    required this.onExpired,
    this.initialDisputeId,
  });
  @override
  State<ProviderDisputesView> createState() => _ProviderDisputesState();
}

class _ProviderDisputesState extends State<ProviderDisputesView>
    with WidgetsBindingObserver {
  ApiPage<Map<String, dynamic>>? page;
  Map<String, dynamic>? detail;
  bool busy = false, attention = false, foreground = true;
  int generation = 0, target = 1;
  String? error;
  late bool Function() authorized;
  String t(String en, String ar) => providerDisputeText(context, en, ar);
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    load(id: widget.initialDisputeId);
  }

  @override
  void didUpdateWidget(covariant ProviderDisputesView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api) {
      generation++;
      page = null;
      detail = null;
      error = null;
      busy = false;
      authorized = widget.api.sessionFence();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (!foreground) {
      generation++;
      setState(() {
        page = null;
        detail = null;
        error = null;
        busy = false;
      });
    } else {
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int number = 1, int? id}) async {
    if (!foreground || !authorized()) return;
    final request = ++generation;
    setState(() {
      busy = true;
      error = null;
      detail = null;
      page = null;
    });
    try {
      final raw = await widget.api.request(
        id == null
            ? 'commercial/external-disputes'
            : 'commercial/external-disputes/$id',
        query: id == null
            ? {'page': '$number', 'attention': attention ? '1' : '0'}
            : const {},
      );
      ApiPage<Map<String, dynamic>>? result;
      Map<String, dynamic>? receipt;
      if (id == null) {
        result = ApiPage.fromJson(raw, (row) => providerDispute(row));
      } else {
        receipt = providerDispute((raw as Map)['dispute'], expectedId: id);
        final events = raw['events'];
        final operations = raw['evidence_submissions'];
        if (events is! List ||
            operations is! List ||
            events.length > 500 ||
            operations.length > 500) {
          throw const FormatException(
            'Provider history requires assisted review.',
          );
        }
        receipt['history'] = events.map((event) {
          if (event is! Map ||
              event['type'] is! String ||
              event['status'] is! String ||
              event['created_at'] is! String) {
            throw const FormatException('Invalid provider history.');
          }
          return '${event['created_at']} UTC — ${event['type']}: ${event['status']}';
        }).toList();
        receipt['submissions'] = operations.map((operation) {
          if (operation is! Map ||
              operation['status'] is! String ||
              !(operation['submit'] is bool ||
                  operation['submit'] == 0 ||
                  operation['submit'] == 1)) {
            throw const FormatException('Invalid evidence receipt.');
          }
          return '${operation['status']} — ${operation['retry_until'] ?? ''}';
        }).toList();
      }
      if (mounted && foreground && authorized() && request == generation) {
        setState(() {
          page = result;
          detail = receipt;
          target = number;
        });
      }
    } catch (e) {
      if (mounted && foreground && authorized() && request == generation) {
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
        } else {
          setState(
            () => error = t(
              'Could not load provider receipts. Refresh to retry.',
              'تعذر تحميل إيصالات المزود. حدّث للمحاولة مجددًا.',
            ),
          );
        }
      }
    } finally {
      if (mounted && request == generation) setState(() => busy = false);
    }
  }

  Widget receipt(Map<String, dynamic> row, {bool expanded = false}) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${t('Invoice', 'الفاتورة')} #${row['invoice_id']}'),
          Text('${t('Provider status', 'حالة المزود')}: ${row['status']}'),
          if (row['amount_minor'] is int && row['minor_units'] is int)
            Text(nativeFinancialAmount(context, row, 'amount_minor')),
          if (expanded) ...[
            Text(t('Provider event history (UTC)', 'سجل أحداث المزود (UTC)')),
            for (final event in (row['history'] as List? ?? const []))
              Text(event as String),
            if ((row['submissions'] as List? ?? const []).isNotEmpty)
              Text(
                t(
                  'Evidence operation receipts; uncertain outcomes require review',
                  'إيصالات عمليات الأدلة؛ النتائج غير المؤكدة تتطلب المراجعة',
                ),
              ),
            for (final operation in (row['submissions'] as List? ?? const []))
              Text(operation as String),
          ],
          if (row['evidence_due_at'] != null)
            Text(
              '${t('Evidence deadline (UTC)', 'الموعد النهائي للأدلة (UTC)')}: ${row['evidence_due_at']}',
            ),
          if (row['deadline_passed'] == true)
            Text(t('Evidence deadline passed', 'انتهى الموعد النهائي للأدلة')),
          if (row['settlement_hold'] == true)
            Text(t('Settlement hold recorded', 'تم تسجيل حجز التسوية')),
          if (row['requires_financial_resolution'] == true)
            Text(
              t(
                'Authorized financial resolution required; no chargeback ledger entry is inferred.',
                'تلزم معالجة مالية معتمدة؛ لا يُفترض إنشاء قيد استرداد مصرفي.',
              ),
            ),
          if (!expanded)
            TextButton(
              onPressed: busy ? null : () => load(id: row['id'] as int),
              child: Text(t('Inspect receipt', 'عرض الإيصال')),
            ),
        ],
      ),
    ),
  );
  @override
  Widget build(BuildContext context) {
    final visible = authorized() && foreground;
    final currentPage = visible ? page : null;
    final currentDetail = visible ? detail : null;
    return Scaffold(
      appBar: AppBar(title: Text(t('Provider disputes', 'نزاعات مزود الدفع'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Test-provider records only. Viewing a receipt does not submit evidence, move funds or post chargeback accounting. Authorized reviewers manage evidence and financial resolution.',
              'سجلات مزود الاختبار فقط. عرض الإيصال لا يرسل أدلة أو يحرك أموالاً أو يسجل استردادًا مصرفيًا. يدير المراجعون المخوّلون الأدلة والمعالجة المالية.',
            ),
          ),
          SwitchListTile(
            title: Text(t('Needs attention', 'تحتاج إلى متابعة')),
            value: attention,
            onChanged: busy || !visible
                ? null
                : (value) {
                    setState(() => attention = value);
                    load();
                  },
          ),
          OutlinedButton(
            onPressed: busy || !visible ? null : () => load(number: target),
            child: Text(t('Refresh list', 'تحديث القائمة')),
          ),
          if (busy) const LinearProgressIndicator(),
          if (visible && error != null) Text(error!),
          if (currentDetail != null) receipt(currentDetail, expanded: true),
          if (currentPage != null) ...[
            if (currentPage.items.isEmpty)
              Text(t('No provider disputes.', 'لا توجد نزاعات لدى المزود.')),
            ...currentPage.items.map((row) => receipt(row)),
            Row(
              children: [
                TextButton(
                  onPressed: !busy && target > 1
                      ? () => load(number: target - 1)
                      : null,
                  child: Text(t('Previous', 'السابق')),
                ),
                TextButton(
                  onPressed: !busy && currentPage.current < currentPage.last
                      ? () => load(number: target + 1)
                      : null,
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
