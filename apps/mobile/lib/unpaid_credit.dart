import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'financial_operation_store.dart';
import 'source_credit_picker.dart';
import 'financial_documents.dart';

/// Full TEST receivable correction, never a payment or captured-fund refund.
class UnpaidCreditView extends StatefulWidget {
  final EvApi api;
  final int invoiceId;
  final VoidCallback onExpired;
  final FinancialOperationStore? store;
  const UnpaidCreditView({
    super.key,
    required this.api,
    required this.invoiceId,
    required this.onExpired,
    this.store,
  });
  @override
  State<UnpaidCreditView> createState() => _UnpaidCreditState();
}

class _UnpaidCreditState extends State<UnpaidCreditView>
    with WidgetsBindingObserver {
  late bool Function() current;
  final reason = TextEditingController();
  FinancialOperation? operation;
  Map<String, dynamic>? summary, note;
  int? actor, credit;
  int generation = 0;
  bool busy = true, consent = false, paused = false;
  Object? error;
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get path => 'finance/invoices/${widget.invoiceId}';
  bool get pending => operation?.pending != null;
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
    WidgetsBinding.instance.removeObserver(this);
    reason.dispose();
    operation = null;
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      generation++;
      reason.clear();
      setState(() {
        summary = null;
        note = null;
        operation = null;
        actor = null;
        credit = null;
        consent = false;
        busy = false;
        error = null;
        paused = true;
      });
    }
  }

  void fail(Object value) {
    if (!mounted || !current()) return;
    setState(() => error = value);
    if (value is ApiException && [401, 419].contains(value.status))
      widget.onExpired();
  }

  Future<void> load() async {
    if (!current()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
      paused = false;
    });
    try {
      final me = await widget.api.request('me');
      final data = await widget.api.request(path);
      if (!mounted || epoch != generation || !current()) return;
      if (me['id'] is! int ||
          !['vendor', 'admin'].contains(me['role']) ||
          data['invoice']?['id'] != widget.invoiceId)
        throw const ApiException(403, 'Credit operator context required.');
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:${me['id']}:unpaid-credit:${widget.invoiceId}',
        isCurrent: current,
      );
      await op.restore();
      if (!mounted || epoch != generation || !current()) return;
      final saved = op.pending?['body'];
      if (saved != null &&
          (saved['expected_actor_id'] != me['id'] ||
              saved['expected_invoice_number'] != data['invoice']['number']))
        throw const ApiException(409, 'Saved credit context differs.');
      setState(() {
        actor = me['id'];
        summary = Map<String, dynamic>.from(data);
        operation = op;
        if (saved != null) {
          reason.text = saved['reason'];
          credit = saved['credit_record_id'];
          consent = saved['consent'] == true;
        }
      });
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  bool matches(dynamic value, String key, {Map<String, dynamic>? body}) {
    final invoice = summary!['invoice'];
    return value is Map &&
        value['invoice_id'] == widget.invoiceId &&
        value['actor_id'] == actor &&
        value['request_key'] == key &&
        value['amount_minor'] == invoice['total_minor'] &&
        value['currency'] == invoice['currency'] &&
        value['minor_units'] == invoice['minor_units'] &&
        value['evidence'] == 'SIMULATED_NONCASH' &&
        (body == null ||
            (value['credit_record_id'] == body['credit_record_id'] &&
                value['reason'] == body['reason']));
  }

  Future<void> submit() async {
    if (!current() || operation == null || summary == null || busy || paused)
      return;
    if (!pending &&
        (credit == null || !consent || reason.text.trim().length < 10)) {
      fail(
        const ApiException(422, 'Full source and explicit consent required.'),
      );
      return;
    }
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final invoice = summary!['invoice'];
      final original = operation!.pending?['body'];
      final body = await operation!.prepare(
        original == null
            ? {
                'expected_actor_id': actor,
                'expected_invoice_number': invoice['number'],
                'credit_record_id': credit,
                'reason': reason.text.trim(),
                'consent': true,
                'expected_total_minor': invoice['total_minor'],
                'expected_currency': invoice['currency'],
                'expected_minor_units': invoice['minor_units'],
              }
            : Map<String, dynamic>.from(original),
      );
      if (!mounted || epoch != generation || !current()) return;
      final result = await widget.api.request(
        '$path/unpaid-credit',
        method: 'POST',
        body: body,
      );
      if (!mounted || epoch != generation || !current()) return;
      if (result['invoice_id'] != widget.invoiceId ||
          result['cash_moved'] != false ||
          result['refund_created'] != false ||
          !matches(result['credit_note'], body['idempotency_key'], body: body))
        throw const ApiException(502, 'Unconfirmed noncash credit.');
      await operation!.confirmed();
      if (!mounted || epoch != generation || !current()) return;
      setState(() => note = Map<String, dynamic>.from(result['credit_note']));
      await load();
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> resolve() async {
    if (!current() || !pending || summary == null || busy || paused) return;
    final epoch = ++generation, key = operation!.pending!['key'];
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        '$path/unpaid-credit-resolve',
        method: 'POST',
        body: {
          'expected_actor_id': actor,
          'expected_invoice_number': summary!['invoice']['number'],
          'idempotency_key': key,
          'cancel_unissued': true,
        },
      );
      if (!mounted || epoch != generation || !current()) return;
      if (result['invoice_id'] != widget.invoiceId ||
          result['cash_moved'] != false ||
          !['issued', 'cancelled_unissued'].contains(result['state']) ||
          (result['state'] == 'issued' && !matches(result['credit_note'], key)))
        throw const ApiException(502, 'Unconfirmed credit resolution.');
      await operation!.confirmed();
      if (!mounted || epoch != generation || !current()) return;
      if (result['state'] == 'issued')
        note = Map<String, dynamic>.from(result['credit_note']);
      credit = null;
      consent = false;
      reason.clear();
      await load();
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    final invoice = summary?['invoice'];
    final frozen =
        busy ||
        pending ||
        note != null ||
        summary?['can_issue_unpaid_credit'] != true;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t('Unpaid TEST invoice credit', 'تسوية فاتورة اختبار غير مدفوعة'),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            t(
              'Full source reversal for an untouched unpaid TEST invoice only. No cash moves and no refund is created.',
              'عكس كامل للمصدر لفاتورة اختبار غير مدفوعة لم تبدأ عليها أي عملية مالية. لا توجد حركة نقدية أو عملية استرداد.',
            ),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (paused)
            Text(
              t(
                'Private credit data hidden. Refresh to resume.',
                'تم إخفاء بيانات التسوية الخاصة. حدّث للاستئناف.',
              ),
            ),
          TextButton(
            onPressed: busy ? null : load,
            child: Text(t('Refresh', 'تحديث')),
          ),
          if (current() && invoice != null) ...[
            Text('${invoice['number']}'),
            Text(
              '${minorDecimal(invoice['total_minor'] as int, invoice['minor_units'] as int)} ${invoice['currency']}',
            ),
            if (pending)
              Text(
                t(
                  'Resolve the existing request before creating another.',
                  'تحقق من الطلب الموجود قبل إنشاء طلب آخر.',
                ),
              ),
            OutlinedButton(
              onPressed: frozen
                  ? null
                  : () async {
                      final selected = await Navigator.of(c).push<int>(
                        MaterialPageRoute(
                          builder: (_) => SourceCreditPickerView(
                            api: widget.api,
                            invoiceId: widget.invoiceId,
                            onExpired: widget.onExpired,
                            requireFullReversal: true,
                          ),
                        ),
                      );
                      if (mounted &&
                          current() &&
                          !pending &&
                          !busy &&
                          selected != null)
                        setState(() => credit = selected > 0 ? selected : null);
                    },
              child: Text(
                t(
                  'Choose full partner credit',
                  'اختيار إشعار دائن كامل من الشريك',
                ),
              ),
            ),
            if (credit != null)
              Text(t('Source credit selected', 'تم اختيار مرجع من المصدر')),
            TextField(
              controller: reason,
              readOnly: frozen,
              maxLength: 500,
              decoration: InputDecoration(
                labelText: t('Correction reason', 'سبب التسوية'),
              ),
            ),
            CheckboxListTile(
              value: consent,
              onChanged: frozen
                  ? null
                  : (v) => setState(() => consent = v ?? false),
              title: Text(
                t(
                  'I confirm this full TEST noncash correction.',
                  'أؤكد هذه التسوية الاختبارية الكاملة غير النقدية.',
                ),
              ),
            ),
            if (pending || summary?['can_issue_unpaid_credit'] == true)
              FilledButton(
                onPressed: busy ? null : submit,
                child: Text(
                  pending
                      ? t(
                          'Retry saved credit request',
                          'إعادة طلب التسوية المحفوظ',
                        )
                      : t(
                          'Issue full noncash TEST credit',
                          'إصدار تسوية اختبارية كاملة غير نقدية',
                        ),
                ),
              ),
            if (pending)
              OutlinedButton(
                onPressed: busy ? null : resolve,
                child: Text(
                  t(
                    'Resolve or cancel unissued request',
                    'التحقق من الطلب غير المنفذ أو إلغاؤه',
                  ),
                ),
              ),
            for (final n in summary?['unpaid_credit_notes'] ?? []) ...[
              Text('${n['credit_note_number']}'),
              Text(
                t(
                  'Noncash credit issued; no payment or refund.',
                  'صدرت تسوية غير نقدية دون دفع أو استرداد.',
                ),
              ),
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () => Navigator.of(c).push(
                        MaterialPageRoute<void>(
                          builder: (_) => FinancialDocumentsView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            sourceKind: 'charging_unpaid_credit',
                            sourceId: n['id'] as int,
                          ),
                        ),
                      ),
                child: Text(
                  t('Private credit document', 'مستند التسوية الخاص'),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }
}
