import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'financial_operation_store.dart';

const refundEn = {
  'title': 'Refund requests',
  'new': 'Request a refund',
  'amount': 'Requested amount',
  'reason': 'Reason',
  'submit': 'Submit request',
  'resume': 'Resume the same request',
  'notice':
      'A request is not a refund. Only canonical confirmed records prove refunded funds. Provider-backed approval may remain unavailable until supported provider execution is configured.',
  'uncertain':
      'Outcome unconfirmed. Refresh history before explicitly resending the same saved request. Its amount, reason and identity remain locked across app restarts.',
  'saved':
      'A saved request is awaiting confirmation. Review history before explicitly resending it.',
  'invalid': 'Enter an exact positive amount and a reason (1–500 characters).',
  'empty': 'No refund requests.',
  'refresh': 'Refresh',
  'next': 'Next',
  'previous': 'Previous',
  'pending': 'Pending review',
  'approved': 'Approved',
  'rejected': 'Rejected',
  'unknown': 'Unknown state',
  'review': 'Review request',
  'approve': 'Approve',
  'reject': 'Reject',
  'note': 'Decision note',
  'confirm': 'Confirm',
  'cancel': 'Cancel',
  'confirmed': 'Request confirmed. This does not prove funds were returned.',
};
const refundAr = {
  'title': 'طلبات الاسترداد',
  'new': 'طلب استرداد',
  'amount': 'المبلغ المطلوب',
  'reason': 'السبب',
  'submit': 'إرسال الطلب',
  'resume': 'استئناف الطلب نفسه',
  'notice':
      'الطلب ليس استرداداً. السجلات الأصلية المؤكدة وحدها تثبت إعادة الأموال. قد لا تتاح الموافقة على عمليات المزود حتى تهيئة التنفيذ المدعوم.',
  'uncertain':
      'النتيجة غير مؤكدة. حدّث السجل قبل إعادة إرسال الطلب المحفوظ نفسه صراحةً. يظل المبلغ والسبب والمعرف ثابتاً بعد إعادة تشغيل التطبيق.',
  'saved':
      'يوجد طلب محفوظ بانتظار التأكيد. راجع السجل قبل إعادة إرسال الطلب نفسه صراحةً.',
  'invalid': 'أدخل مبلغاً موجباً دقيقاً وسبباً من ١ إلى ٥٠٠ حرف.',
  'empty': 'لا توجد طلبات استرداد.',
  'refresh': 'تحديث',
  'next': 'التالي',
  'previous': 'السابق',
  'pending': 'بانتظار المراجعة',
  'approved': 'تمت الموافقة',
  'rejected': 'مرفوض',
  'unknown': 'حالة غير معروفة',
  'review': 'مراجعة الطلب',
  'approve': 'موافقة',
  'reject': 'رفض',
  'note': 'ملاحظة القرار',
  'confirm': 'تأكيد',
  'cancel': 'إلغاء',
  'confirmed': 'تم تأكيد الطلب. لا يثبت ذلك إعادة الأموال.',
};
String refundText(BuildContext c, String k) =>
    (Localizations.localeOf(c).languageCode == 'ar' ? refundAr : refundEn)[k] ??
    k;

class RefundRequestsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String role;
  const RefundRequestsView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.role,
  });
  @override
  State<RefundRequestsView> createState() => _RefundListState();
}

class _RefundListState extends State<RefundRequestsView> {
  ApiPage<Map<String, dynamic>>? page;
  Object? error;
  bool busy = false;
  int generation = 0, target = 1;
  String t(String k) => refundText(context, k);
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
      page = null;
      error = null;
    });
    try {
      final result = ApiPage.fromJson(
        await widget.api.request(
          'finance/refund-requests',
          query: {'page': '$number'},
        ),
        (r) => r,
      );
      if (mounted && generation == epoch) setState(() => page = result);
    } catch (e) {
      if (mounted && generation == epoch) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('title'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        OutlinedButton(
          onPressed: busy ? null : () => load(number: target),
          child: Text(t('refresh')),
        ),
        if (busy) const Center(child: CircularProgressIndicator()),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (page != null && page!.items.isEmpty) Text(t('empty')),
        if (page != null) ...[
          for (final row in page!.items)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '#${row['id']} · ${t(['pending', 'approved', 'rejected'].contains(row['status']) ? row['status'] : 'unknown')}',
                    ),
                    Text('${row['reason'] ?? ''}'),
                    Text(
                      '${row['amount_minor']} · ${Localizations.localeOf(c).languageCode == 'ar' ? 'وحدات نقدية صغرى' : 'minor units'}',
                    ),
                    if (row['decision_note'] != null)
                      Text('${row['decision_note']}'),
                    if (widget.role == 'vendor' && row['status'] == 'pending')
                      OutlinedButton(
                        onPressed: busy
                            ? null
                            : () async {
                                await Navigator.of(c).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => RefundDecisionView(
                                      api: widget.api,
                                      onExpired: widget.onExpired,
                                      requestId: row['id'] as int,
                                    ),
                                  ),
                                );
                                if (mounted) load(number: target);
                              },
                        child: Text(t('review')),
                      ),
                  ],
                ),
              ),
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
              Text('${page!.current} / ${page!.last}'),
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

class RefundRequestView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int invoiceId;
  final int digits;
  final String currency;
  final FinancialOperationStore? store;
  const RefundRequestView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.invoiceId,
    required this.digits,
    required this.currency,
    this.store,
  });
  @override
  State<RefundRequestView> createState() => _RefundRequestState();
}

class _RefundRequestState extends State<RefundRequestView> {
  final amount = TextEditingController(), reason = TextEditingController();
  FinancialOperation? operation;
  Object? error;
  bool busy = true, uncertain = false, confirmed = false;
  int generation = 0;
  String t(String k) => refundText(context, k);
  @override
  void initState() {
    super.initState();
    restore();
  }

  @override
  void dispose() {
    generation++;
    amount.dispose();
    reason.dispose();
    super.dispose();
  }

  Future<void> restore() async {
    final epoch = ++generation;
    try {
      if (widget.digits < 0 ||
          widget.digits > 3 ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(widget.currency))
        throw const FormatException('Unsupported precision.');
      final me = await widget.api.request('me');
      final owner = me['id'];
      if (owner is! int || owner < 1 || me['role'] != 'customer')
        throw const ApiException(403, 'Customer invoice ownership required.');
      final pending = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:$owner:refund:${widget.invoiceId}',
        isCurrent: widget.api.sessionFence(),
      );
      await pending.restore();
      if (!mounted || generation != epoch) return;
      operation = pending;
      if (pending.pending != null) {
        final body = pending.pending!['body'] as Map;
        amount.text = minorDecimal(body['amount_minor'] as int, widget.digits);
        reason.text = body['reason'] as String;
        uncertain = true;
      }
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

  Future<void> submit() async {
    final minor = decimalMinor(amount.text, widget.digits, maximum: 2000000000);
    if (minor == null ||
        minor < 1 ||
        reason.text.trim().isEmpty ||
        reason.text.length > 500) {
      setState(
        () => error = const ApiException(422, 'Invalid refund request.'),
      );
      return;
    }
    final consent = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t('new')),
        content: Text(t('notice')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: Text(t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: Text(t('confirm')),
          ),
        ],
      ),
    );
    if (consent != true || !mounted) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await operation!.prepare({
        'amount_minor': minor,
        'reason': reason.text.trim(),
      });
      if (!mounted || generation != epoch) return;
      final result = await widget.api.request(
        'finance/invoices/${widget.invoiceId}/refund-requests',
        method: 'POST',
        body: body,
      );
      if (!mounted || generation != epoch) return;
      if (result is! Map ||
          result['invoice_id'] != widget.invoiceId ||
          result['amount_minor'] != minor ||
          result['request_key'] != body['idempotency_key'] ||
          result['reason'] != body['reason'] ||
          !['pending', 'approved', 'rejected'].contains(result['status']))
        throw const ApiException(502, 'Unconfirmed request receipt.');
      confirmed = true;
      await operation!.confirmed();
      uncertain = false;
    } catch (e) {
      if (mounted && generation == epoch) {
        uncertain = operation?.pending != null;
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
    appBar: AppBar(title: Text(t('new'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (uncertain) Text(t('uncertain')),
        if (confirmed) Text(t('confirmed')),
        if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
        if (busy) const LinearProgressIndicator(),
        TextField(
          controller: amount,
          readOnly: busy || uncertain || confirmed,
          decoration: InputDecoration(
            labelText: '${t('amount')} (${widget.currency})',
          ),
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
        ),
        TextField(
          controller: reason,
          readOnly: busy || uncertain || confirmed,
          maxLength: 500,
          minLines: 2,
          maxLines: 5,
          decoration: InputDecoration(labelText: t('reason')),
        ),
        FilledButton(
          onPressed: busy || confirmed || operation == null ? null : submit,
          child: Text(t(uncertain ? 'resume' : 'submit')),
        ),
        OutlinedButton(
          onPressed: busy
              ? null
              : () => Navigator.of(c).push(
                  MaterialPageRoute<void>(
                    builder: (_) => RefundRequestsView(
                      api: widget.api,
                      onExpired: widget.onExpired,
                      role: 'customer',
                    ),
                  ),
                ),
          child: Text(t('title')),
        ),
      ],
    ),
  );
}

class RefundDecisionView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int requestId;
  final FinancialOperationStore? store;
  const RefundDecisionView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.requestId,
    this.store,
  });
  @override
  State<RefundDecisionView> createState() => _RefundDecisionState();
}

class _RefundDecisionState extends State<RefundDecisionView> {
  final note = TextEditingController();
  String decision = 'reject';
  bool busy = true, uncertain = false, confirmed = false;
  Object? error;
  int generation = 0;
  FinancialOperation? operation;
  @override
  void initState() {
    super.initState();
    restore();
  }

  @override
  void dispose() {
    generation++;
    note.dispose();
    super.dispose();
  }

  Future<void> restore() async {
    final epoch = ++generation;
    try {
      final me = await widget.api.request('me');
      if (me['id'] is! int || me['role'] != 'vendor')
        throw const ApiException(403, 'Vendor ownership required.');
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:${me['id']}:refund-review:${widget.requestId}',
        isCurrent: widget.api.sessionFence(),
      );
      await op.restore();
      if (!mounted || epoch != generation) return;
      operation = op;
      if (op.pending != null) {
        final body = op.pending!['body'] as Map;
        decision = body['decision'] as String;
        note.text = body['note'] as String;
        uncertain = true;
      }
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

  Future<void> submit() async {
    if (note.text.trim().isEmpty || note.text.length > 500) return;
    final t = (String k) => refundText(context, k);
    final yes = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t(decision)),
        content: Text(t('notice')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: Text(t('cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: Text(t('confirm')),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await operation!.prepare({
        'decision': decision,
        'note': note.text.trim(),
      });
      if (!mounted || generation != epoch) return;
      final result = await widget.api.request(
        'finance/refund-requests/${widget.requestId}/review',
        method: 'POST',
        body: body,
      );
      if (!mounted || generation != epoch) return;
      if (result is! Map ||
          result['id'] != widget.requestId ||
          result['decision_note'] != body['note'] ||
          result['status'] != (decision == 'approve' ? 'approved' : 'rejected'))
        throw const ApiException(502, 'Unconfirmed decision receipt.');
      confirmed = true;
      await operation!.confirmed();
      uncertain = false;
    } catch (e) {
      if (mounted && generation == epoch) {
        error = e;
        uncertain = operation?.pending != null;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && generation == epoch) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    final t = (String k) => refundText(c, k);
    return Scaffold(
      appBar: AppBar(title: Text(t('review'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (uncertain) Text(t('uncertain')),
          if (confirmed) Text(t('confirmed')),
          DropdownButtonFormField<String>(
            key: ValueKey(decision),
            initialValue: decision,
            items: [
              for (final d in ['approve', 'reject'])
                DropdownMenuItem(value: d, child: Text(t(d))),
            ],
            onChanged: busy || uncertain || confirmed
                ? null
                : (v) => setState(() => decision = v!),
          ),
          TextField(
            controller: note,
            readOnly: busy || uncertain || confirmed,
            maxLength: 500,
            decoration: InputDecoration(labelText: t('note')),
          ),
          FilledButton(
            onPressed: busy || confirmed || operation == null ? null : submit,
            child: Text(t(uncertain ? 'resume' : 'confirm')),
          ),
        ],
      ),
    );
  }
}
