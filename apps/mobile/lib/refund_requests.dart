import 'dart:async';
import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'api.dart';
import 'source_credit_picker.dart';
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

bool _refundId(dynamic value) =>
    value is int && value > 0 && value <= 9007199254740991;

/// The POST acknowledgement binds the persisted request identity without
/// returning a replay key. History and review responses carry no binding.
bool refundRequestAcknowledged(
  dynamic receipt,
  Map<String, dynamic> body, {
  required int owner,
  required int invoice,
}) {
  if (receipt is! Map ||
      !_refundId(receipt['id']) ||
      !_refundId(receipt['user_id']) ||
      !_refundId(receipt['invoice_id']) ||
      receipt['user_id'] != owner ||
      receipt['invoice_id'] != invoice ||
      receipt['amount_minor'] is! int ||
      receipt['amount_minor'] != body['amount_minor'] ||
      receipt['reason'] != body['reason'] ||
      (receipt['ocpi_credit_record_id'] != null &&
          !_refundId(receipt['ocpi_credit_record_id'])) ||
      receipt['ocpi_credit_record_id'] != body['ocpi_credit_record_id'] ||
      !['pending', 'approved', 'rejected'].contains(receipt['status']) ||
      receipt.containsKey('request_key') ||
      receipt.containsKey('fingerprint') ||
      body['idempotency_key'] is! String) {
    return false;
  }
  final binding = sha256.convert(utf8.encode(
    'refund-request-v1:$owner:$invoice:${receipt['id']}:${body['idempotency_key']}',
  )).toString();
  return receipt['request_receipt'] == binding;
}

List<Widget> _sourceCreditEvidence(BuildContext context, Map row) {
  final v = row['source_credit_evidence'];
  if (v is! Map ||
      v['schema_version'] != 1 ||
      v['invoice_id'] != row['invoice_id'] ||
      v['credit_record_id'] != row['ocpi_credit_record_id'] ||
      v['financial_authority'] != false ||
      v['automatic_refund'] != false)
    return [];
  final ar = Localizations.localeOf(context).languageCode == 'ar';
  final matched = v['status'] == 'source_reference_matched';
  return [
    Text(
      matched
          ? (ar
                ? 'تطابق مرجع المصدر؛ لا يعني ذلك الموافقة على استرداد.'
                : 'Source reference matched; this does not approve a refund.')
          : (ar
                ? 'تتطلب أدلة مصدر الإشعار الدائن المراجعة.'
                : 'Source credit evidence requires review.'),
    ),
    if (matched &&
        v['currency'] is String &&
        RegExp(r'^[A-Z]{3}$').hasMatch(v['currency'] as String) &&
        v['reported_cost'] is Map)
      for (final entry in (v['reported_cost'] as Map).entries)
        if (['excl_vat', 'incl_vat'].contains(entry.key) &&
            entry.value is String &&
            RegExp(
              r'^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,6})?$',
            ).hasMatch(entry.value as String))
          Text(
            '${ar ? 'مبلغ مبلّغ عنه' : 'Reported amount'} (${entry.key == 'incl_vat' ? (ar ? 'شامل الضريبة' : 'gross') : (ar ? 'قبل الضريبة' : 'net')}): ${entry.value} ${v['currency']}',
          ),
  ];
}

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

class _RefundListState extends State<RefundRequestsView>
    with WidgetsBindingObserver {
  ApiPage<Map<String, dynamic>>? page;
  Object? error;
  bool busy = false;
  int generation = 0, target = 1;
  bool foreground = true, revoked = false;
  late final bool Function() ownsSession;
  StreamSubscription<int>? session;
  bool get visible => foreground && !revoked && ownsSession();
  bool current(int epoch) => mounted && visible && epoch == generation;
  String t(String k) => refundText(context, k);
  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() {
        revoked = true;
        clearPrivate();
      });
    });
    load();
  }

  void clearPrivate() {
    generation++;
    page = null;
    error = null;
    busy = false;
    target = 1;
  }

  @override
  void didUpdateWidget(covariant RefundRequestsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) || oldWidget.role != widget.role) {
      revoked = true;
      clearPrivate();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) clearPrivate();
    });
  }

  @override
  void dispose() {
    generation++;
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load({int number = 1}) async {
    if (!visible || number < 1 || number > 100000) return;
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
      if (current(epoch)) setState(() => page = result);
    } catch (e) {
      if (current(epoch)) {
        setState(() => error = e);
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('title'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (visible) ...[
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
                    if (row['ocpi_credit_record_id'] != null)
                      Text(
                        '${Localizations.localeOf(c).languageCode == 'ar' ? 'مرجع مصدر الإشعار الدائن' : 'Source credit record'}: ${row['ocpi_credit_record_id']}',
                      ),
                    Text(
                      '${row['amount_minor']} · ${Localizations.localeOf(c).languageCode == 'ar' ? 'وحدات نقدية صغرى' : 'minor units'}',
                    ),
                    ..._sourceCreditEvidence(c, row),
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
                                if (mounted && visible) load(number: target);
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

class _RefundRequestState extends State<RefundRequestView>
    with WidgetsBindingObserver {
  final amount = TextEditingController(),
      reason = TextEditingController(),
      credit = TextEditingController();
  FinancialOperation? operation;
  Object? error;
  bool busy = true, uncertain = false, confirmed = false;
  int generation = 0, privateEpoch = 0;
  int? ownerId;
  bool foreground = true, revoked = false;
  late final bool Function() ownsSession;
  StreamSubscription<int>? session;
  bool get visible => foreground && !revoked && ownsSession();
  bool current(int epoch) => mounted && visible && epoch == generation;
  String t(String k) => refundText(context, k);
  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() { revoked = true; clearPrivate(); });
    });
    restore();
  }

  void clearPrivate() {
    generation++;
    privateEpoch++;
    operation = null;
    ownerId = null;
    error = null;
    busy = false;
    confirmed = false;
    uncertain = false;
    amount.clear();
    reason.clear();
    credit.clear();
  }

  @override
  void didUpdateWidget(covariant RefundRequestView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.invoiceId != widget.invoiceId ||
        oldWidget.currency != widget.currency || oldWidget.digits != widget.digits) {
      revoked = true;
      clearPrivate();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) clearPrivate();
    });
    if (visible && operation == null) restore();
  }

  @override
  void dispose() {
    generation++;
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    amount.dispose();
    reason.dispose();
    credit.dispose();
    super.dispose();
  }

  Future<void> restore() async {
    if (!visible) return;
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      if (!_refundId(widget.invoiceId) || widget.digits < 0 ||
          widget.digits > 3 ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(widget.currency))
        throw const FormatException('Unsupported precision.');
      final me = await widget.api.request('me');
      if (!current(epoch)) return;
      final owner = me['id'];
      if (!_refundId(owner) || me['role'] != 'customer')
        throw const ApiException(403, 'Customer invoice ownership required.');
      final sourceEpoch = privateEpoch;
      final pending = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:$owner:refund:${widget.invoiceId}',
        isCurrent: () => mounted && visible && sourceEpoch == privateEpoch,
      );
      await pending.restore();
      if (!current(epoch)) return;
      ownerId = owner as int;
      operation = pending;
      if (pending.pending != null) {
        final body = pending.pending!['body'] as Map;
        if (body['amount_minor'] is! int ||
            body['amount_minor'] < 1 || body['amount_minor'] > 2000000000 ||
            body['reason'] is! String || (body['reason'] as String).trim().isEmpty ||
            (body['reason'] as String).length > 500 ||
            (body['ocpi_credit_record_id'] != null && !_refundId(body['ocpi_credit_record_id']))) {
          operation = null;
          throw const FormatException('Invalid saved refund request.');
        }
        amount.text = minorDecimal(body['amount_minor'] as int, widget.digits);
        reason.text = body['reason'] as String;
        credit.text = '${body['ocpi_credit_record_id'] ?? ''}';
        uncertain = true;
      }
    } catch (e) {
      if (current(epoch)) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (!visible || busy || operation == null || ownerId == null) return;
    final formGeneration = generation;
    final op = operation!;
    final owner = ownerId!;
    final creditId = int.tryParse(credit.text.trim());
    final originalReason = reason.text.trim();
    final minor = decimalMinor(amount.text, widget.digits, maximum: 2000000000);
    if ((credit.text.trim().isNotEmpty &&
            !_refundId(creditId)) ||
        minor == null ||
        minor < 1 ||
        originalReason.isEmpty ||
        originalReason.length > 500) {
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
    if (consent != true || !current(formGeneration) ||
        !identical(op, operation) || busy) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await op.prepare({
        'amount_minor': minor,
        'reason': originalReason,
        if (creditId != null) 'ocpi_credit_record_id': creditId,
      });
      if (!current(epoch)) return;
      final result = await widget.api.request(
        'finance/invoices/${widget.invoiceId}/refund-requests',
        method: 'POST',
        body: body,
      );
      if (!current(epoch)) return;
      if (!refundRequestAcknowledged(result, body, owner: owner, invoice: widget.invoiceId)) {
        throw const ApiException(502, 'Unconfirmed request receipt.');
      }
      await op.confirmed();
      if (!current(epoch)) return;
      confirmed = true;
      uncertain = false;
    } catch (e) {
      if (current(epoch)) {
        uncertain = operation?.pending != null;
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('new'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        if (!visible)
          Text(Localizations.localeOf(c).languageCode == 'ar'
              ? 'مُسح الطلب الخاص. افتح الطلب مجدداً.'
              : 'Private request cleared. Reopen the request.'),
        if (visible) ...[
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
        OutlinedButton(
          onPressed: busy || uncertain || confirmed
              ? null
              : () async {
                  final current = widget.api.sessionFence();
                  final selected = await Navigator.of(c).push<int>(
                    MaterialPageRoute(
                      builder: (_) => SourceCreditPickerView(
                        api: widget.api,
                        invoiceId: widget.invoiceId,
                        onExpired: widget.onExpired,
                      ),
                    ),
                  );
                  if (!mounted || !visible ||
                      !current() ||
                      selected == null ||
                      busy ||
                      uncertain ||
                      confirmed)
                    return;
                  setState(
                    () => credit.text = selected == 0 ? '' : '$selected',
                  );
                },
          child: Text(
            Localizations.localeOf(c).languageCode == 'ar'
                ? 'اختيار مرجع إشعار دائن اختياري'
                : 'Choose optional partner credit',
          ),
        ),
        if (credit.text.isNotEmpty)
          TextField(
            controller: credit,
            readOnly: true,
            decoration: InputDecoration(
              labelText: Localizations.localeOf(c).languageCode == 'ar'
                  ? 'مرجع المصدر المحدد'
                  : 'Selected source credit record',
            ),
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

class _RefundDecisionState extends State<RefundDecisionView>
    with WidgetsBindingObserver {
  final note = TextEditingController();
  String decision = 'reject';
  bool busy = true, uncertain = false, confirmed = false;
  Object? error;
  int generation = 0, privateEpoch = 0;
  bool foreground = true, revoked = false;
  late final bool Function() ownsSession;
  StreamSubscription<int>? session;
  bool get visible => foreground && !revoked && ownsSession();
  bool current(int epoch) => mounted && visible && epoch == generation;
  FinancialOperation? operation;
  @override
  void initState() {
    super.initState();
    ownsSession = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() {
        revoked = true;
        clearPrivate();
      });
    });
    restore();
  }

  void clearPrivate() {
    generation++;
    privateEpoch++;
    operation = null;
    error = null;
    busy = false;
    uncertain = false;
    confirmed = false;
    decision = 'reject';
    note.clear();
  }

  @override
  void didUpdateWidget(covariant RefundDecisionView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.requestId != widget.requestId) {
      revoked = true;
      clearPrivate();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) clearPrivate();
    });
    if (visible && operation == null) restore();
  }

  @override
  void dispose() {
    generation++;
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    note.dispose();
    super.dispose();
  }

  Future<void> restore() async {
    if (!visible) return;
    final epoch = ++generation;
    setState(() => busy = true);
    try {
      if (!_refundId(widget.requestId)) {
        throw const FormatException('Invalid refund review source.');
      }
      final me = await widget.api.request('me');
      if (!current(epoch)) return;
      if (!_refundId(me['id']) || me['role'] != 'vendor')
        throw const ApiException(403, 'Vendor ownership required.');
      final sourceEpoch = privateEpoch;
      final op = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:${me['id']}:refund-review:${widget.requestId}',
        isCurrent: () => mounted && visible && sourceEpoch == privateEpoch,
      );
      await op.restore();
      if (!current(epoch)) return;
      operation = op;
      if (op.pending != null) {
        final body = op.pending!['body'] as Map;
        if (!['approve', 'reject'].contains(body['decision']) ||
            body['note'] is! String || (body['note'] as String).trim().isEmpty ||
            (body['note'] as String).length > 500) {
          operation = null;
          throw const FormatException('Invalid saved refund decision.');
        }
        decision = body['decision'] as String;
        note.text = body['note'] as String;
        uncertain = true;
      }
    } catch (e) {
      if (current(epoch)) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (!visible || busy || operation == null) return;
    final formGeneration = generation;
    final op = operation!;
    final selectedDecision = decision;
    final selectedNote = note.text.trim();
    if (selectedNote.isEmpty || selectedNote.length > 500 ||
        !['approve', 'reject'].contains(selectedDecision)) return;
    final t = (String k) => refundText(context, k);
    final yes = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t(selectedDecision)),
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
    if (yes != true || !current(formGeneration) ||
        !identical(op, operation) || busy) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await op.prepare({
        'decision': selectedDecision,
        'note': selectedNote,
      });
      if (!current(epoch)) return;
      final result = await widget.api.request(
        'finance/refund-requests/${widget.requestId}/review',
        method: 'POST',
        body: body,
      );
      if (!current(epoch)) return;
      if (result is! Map ||
          !_refundId(result['id']) ||
          result['id'] != widget.requestId ||
          result.containsKey('request_key') ||
          result.containsKey('fingerprint') ||
          result.containsKey('request_receipt') ||
          result['decision_note'] != body['note'] ||
          result['status'] != (selectedDecision == 'approve' ? 'approved' : 'rejected'))
        throw const ApiException(502, 'Unconfirmed decision receipt.');
      await op.confirmed();
      if (!current(epoch)) return;
      confirmed = true;
      uncertain = false;
    } catch (e) {
      if (current(epoch)) {
        error = e;
        uncertain = operation?.pending != null;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) setState(() => busy = false);
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
          if (visible) ...[
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
        ],
      ),
    );
  }
}
