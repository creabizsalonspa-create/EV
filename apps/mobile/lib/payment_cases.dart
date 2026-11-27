import 'dart:async';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:crypto/crypto.dart';
import 'api.dart';
import 'strings.dart';
import 'reservation_times.dart';
import 'money_input.dart';
import 'financial_operation_store.dart';
import 'vendor_company.dart';

const caseEn = {
  'title': 'Payment disputes',
  'notice':
      'Internal invoice disputes and private review evidence. These records do not create a card-network chargeback, submit evidence to a payment provider or prove a refund. Only authorized reviewers resolve cases.',
  'new': 'Open invoice dispute',
  'amount': 'Disputed amount',
  'amountMinor': 'Disputed amount (minor units)',
  'reason': 'Reason',
  'confirm': 'Confirm saved request',
  'consent':
      'I authorize sharing this evidence with the invoice customer, vendor and authorized platform reviewers. It is not automatically sent to a payment provider.',
  'submitConsent':
      'I reviewed this invoice dispute and authorize submitting this exact request. A refund requires a separate confirmed decision.',
  'refresh': 'Refresh',
  'next': 'Next',
  'previous': 'Previous',
  'empty': 'No records.',
  'evidence': 'Private evidence',
  'choose': 'Choose PDF, PNG or JPEG up to4 MiB',
  'upload': 'Upload private evidence',
  'view': 'View verified private file',
  'service_record': 'Service record',
  'receipt': 'Receipt',
  'correspondence': 'Correspondence',
  'other': 'Other evidence',
  'kind': 'Evidence category',
  'pending':
      'An unconfirmed request is saved. Refresh source history before explicitly resending the original intent. No automatic replay or replacement key.',
  'reselect':
      'After reopening, select the same original file. Different bytes, size, type or category cannot replace an unconfirmed upload.',
  'done':
      'Server confirmed. Refresh authoritative history. No provider submission or real refund is claimed.',
  'open': 'Open',
  'approved': 'Approved',
  'rejected': 'Rejected',
  'unknown': 'Unknown state',
  'invalid': 'Complete the exact required fields.',
  'noFunds': 'No captured funds remain available for a new invoice dispute.',
  'private': 'Private review only; not submitted to provider',
  'invoice': 'Source invoice',
  'resolution': 'Resolution',
};
const caseAr = {
  'title': 'نزاعات الدفع',
  'notice':
      'نزاعات داخلية للفواتير وأدلة مراجعة خاصة. لا تنشئ هذه السجلات اعتراضاً لدى شبكة بطاقات ولا ترسل الأدلة لمزود الدفع ولا تثبت استرداداً. المراجعون المصرح لهم فقط يبتون في النزاعات.',
  'new': 'فتح نزاع على الفاتورة',
  'amount': 'المبلغ المتنازع عليه',
  'amountMinor': 'المبلغ المتنازع عليه (وحدات نقدية صغرى)',
  'reason': 'السبب',
  'confirm': 'تأكيد الطلب المحفوظ',
  'consent':
      'أفوض مشاركة هذا الدليل مع عميل الفاتورة والمورد ومراجعي المنصة المصرح لهم. لا يُرسل تلقائياً إلى مزود الدفع.',
  'submitConsent':
      'راجعت نزاع الفاتورة وأفوض إرسال هذا الطلب نفسه. يتطلب الاسترداد قراراً مؤكداً مستقلاً.',
  'refresh': 'تحديث',
  'next': 'التالي',
  'previous': 'السابق',
  'empty': 'لا توجد سجلات.',
  'evidence': 'أدلة خاصة',
  'choose': 'اختيار PDF أو PNG أو JPEG حتى4 MiB',
  'upload': 'رفع دليل خاص',
  'view': 'عرض الملف الخاص المتحقق منه',
  'service_record': 'سجل الخدمة',
  'receipt': 'إيصال',
  'correspondence': 'مراسلات',
  'other': 'دليل آخر',
  'kind': 'فئة الدليل',
  'pending':
      'طلب غير مؤكد محفوظ. حدّث السجل قبل إعادة إرسال النية الأصلية صراحةً. لا إعادة تلقائية أو معرف بديل.',
  'reselect':
      'بعد إعادة الفتح اختر الملف الأصلي نفسه. لا تستبدل بايتات أو حجماً أو نوعاً أو فئة مختلفة لرفع غير مؤكد.',
  'done':
      'أكد الخادم. حدّث السجل الأصلي. لا يُدعى إرسال للمزود أو استرداد فعلي.',
  'open': 'مفتوح',
  'approved': 'معتمد',
  'rejected': 'مرفوض',
  'unknown': 'حالة غير معروفة',
  'invalid': 'أكمل الحقول الدقيقة المطلوبة.',
  'noFunds': 'لا توجد أموال محصلة متبقية لإنشاء نزاع جديد على الفاتورة.',
  'private': 'مراجعة خاصة فقط؛ لم يُرسل للمزود',
  'invoice': 'الفاتورة الأصلية',
  'resolution': 'القرار',
};
String caseText(BuildContext c, String key) =>
    (Localizations.localeOf(c).languageCode == 'ar' ? caseAr : caseEn)[key] ??
    caseEn['unknown']!;

class PaymentCasesView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const PaymentCasesView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<PaymentCasesView> createState() => _CasesState();
}

class _CasesState extends State<PaymentCasesView>
    with WidgetsBindingObserver {
  ApiPage<Map<String, dynamic>>? page;
  Object? error;
  bool busy = false, foreground = true;
  late final bool Function() authorized;
  StreamSubscription<int>? session;
  bool revoked = false;
  bool get visible => foreground && !revoked && authorized();
  bool current(int view) => mounted && visible && view == generation;
  int generation = 0, target = 1;
  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (mounted) setState(() {
        revoked = true;
        generation++;
        page = null;
        error = null;
        busy = false;
        target = 1;
      });
    });
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant PaymentCasesView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api) {
      generation++;
      revoked = true;
      page = null;
      error = null;
      busy = false;
      target = 1;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (foreground == resumed) return;
    foreground = resumed;
    if (!resumed) {
      generation++;
      setState(() {
        page = null;
        error = null;
        busy = false;
      });
    } else {
      // Explicit refresh is required after returning to private case history.
      setState(() {});
    }
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
    setState(() {
      busy = true;
      error = null;
      page = null;
      target = number;
    });
    try {
      final raw = ApiPage.fromJson(
        await widget.api.request(
          'commercial/cases',
          query: {'page': '$number'},
        ),
        (r) => r,
      );
      for (final r in raw.items) {
        if (r['id'] is! int ||
            r['id'] < 1 || r['id'] > 9007199254740991 ||
            r['invoice_id'] is! int ||
            r['invoice_id'] < 1 || r['invoice_id'] > 9007199254740991 ||
            r['amount_minor'] is! int ||
            r['amount_minor'] < 1 ||
            r['amount_minor'] > 2000000000 ||
            r['reason'] is! String ||
            (r['reason'] as String).trim().isEmpty ||
            (r['reason'] as String).length > 500 ||
            r['status'] is! String ||
            !['open', 'approved', 'rejected'].contains(r['status']) ||
            r['real_money_moved'] != false ||
            !['MOCK_PROVIDER', 'STRIPE_TEST_CONTRACT'].contains(r['evidence']))
          throw const FormatException('Unsupported case evidence.');
      }
      if (current(epoch)) {
        page = raw;
      }
    } catch (e) {
      if (current(epoch)) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (current(epoch)) {
        setState(() => busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => caseText(c, k);
    final view = generation;
    final visible = this.visible;
    final currentPage = visible ? page : null;
    final currentError = visible ? error : null;
    final boundApi = widget.api;
    final boundFence = boundApi.sessionFence();
    return Scaffold(
      appBar: AppBar(title: Text(t('title'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          OutlinedButton(
            onPressed: busy || !visible
                ? null
                : () {
                    if (current(view) && identical(boundApi, widget.api) && boundFence()) {
                      load(number: target);
                    }
                  },
            child: Text(t('refresh')),
          ),
          if (busy && visible) const Center(child: CircularProgressIndicator()),
          if (currentError != null)
            Text(EvStrings.of(c).t(errorKey(currentError))),
          if (currentPage != null) ...[
            if (currentPage.items.isEmpty) Text(t('empty')),
            for (final r in currentPage.items)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('#${r['id']} · ${t('${r['status']}')}'),
                      Text('${t('invoice')}: #${r['invoice_id']}'),
                      Text('${t('amountMinor')}: ${r['amount_minor']}'),
                      Text('${r['reason']}'),
                      if (r['resolution'] != null)
                        Text('${t('resolution')}: ${r['resolution']}'),
                      OutlinedButton(
                        onPressed: busy || !visible
                            ? null
                            : () async {
                                if (!current(view) ||
                                    !boundFence() ||
                                    !identical(boundApi, widget.api)) return;
                                await Navigator.of(c).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => CaseEvidenceView(
                                      api: boundApi,
                                      onExpired: widget.onExpired,
                                      caseId: r['id'] as int,
                                    ),
                                  ),
                                );
                                if (current(view) &&
                                    boundFence() &&
                                    identical(boundApi, widget.api)) {
                                  load(number: target);
                                }
                              },
                        child: Text(t('evidence')),
                      ),
                    ],
                  ),
                ),
              ),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: busy || !visible || target <= 1
                      ? null
                      : () { if (current(view)) load(number: target - 1); },
                  child: Text(t('previous')),
                ),
                Text('$target / ${currentPage.last}'),
                OutlinedButton(
                  onPressed: busy || !visible || target >= currentPage.last
                      ? null
                      : () { if (current(view)) load(number: target + 1); },
                  child: Text(t('next')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class CreatePaymentCaseView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int invoiceId;
  final FinancialOperationStore? store;
  const CreatePaymentCaseView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.invoiceId,
    this.store,
  });
  @override
  State<CreatePaymentCaseView> createState() => _CreateCaseState();
}

class _CreateCaseState extends State<CreatePaymentCaseView>
    with WidgetsBindingObserver {
  final amount = TextEditingController(), reason = TextEditingController();
  FinancialOperation? operation;
  int owner = 0, digits = 0, maximum = 0;
  String currency = '';
  bool busy = true, consent = false, done = false, noFunds = false;
  Object? error;
  int generation = 0, targetGeneration = 0;
  late final bool Function() sessionFence;
  StreamSubscription<int>? session;
  bool revoked = false, foreground = true;
  bool authorized() => foreground && !revoked && sessionFence();

  @override
  void initState() {
    super.initState();
    sessionFence = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    session = widget.api.sessionEvents.listen((_) {
      if (!mounted) return;
      revoked = true;
      generation++;
      targetGeneration++;
      redactFormerIdentity();
    });
    if (foreground) initialize();
  }

  @override
  void didUpdateWidget(covariant CreatePaymentCaseView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.invoiceId != widget.invoiceId ||
        !identical(oldWidget.store, widget.store)) {
      generation++;
      targetGeneration++;
      if (!identical(oldWidget.api, widget.api)) revoked = true;
      amount.clear();
      reason.clear();
      operation = null;
      owner = 0;
      digits = 0;
      maximum = 0;
      currency = '';
      busy = true;
      consent = false;
      done = false;
      noFunds = false;
      error = null;
      if (authorized()) initialize();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (!mounted || foreground == resumed) return;
    foreground = resumed;
    generation++;
    targetGeneration++;
    if (!resumed) {
      redactFormerIdentity();
    } else if (authorized()) {
      setState(() => busy = true);
      initialize();
    } else {
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    amount.dispose();
    reason.dispose();
    super.dispose();
  }

  void redactFormerIdentity() {
    // Do not preserve the prior owner's invoice information on this route.
    amount.clear();
    reason.clear();
    operation = null;
    owner = 0;
    maximum = 0;
    digits = 0;
    currency = '';
    consent = false;
    noFunds = false;
    done = false;
    error = null;
    setState(() => busy = false);
  }

  void fail(Object e) {
    error = e;
    if (e is ApiException && [401, 419].contains(e.status)) {
      operation = null;
      widget.onExpired();
    }
  }

  Future<void> initialize() async {
    final epoch = ++generation;
    final api = widget.api;
    final current = authorized;
    if (!current()) return;
    try {
      final me = await api.request('me');
      if (!mounted || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      if (me is! Map || me['id'] is! int || me['id'] < 1 ||
          me['id'] > 9007199254740991 ||
          me['role'] != 'customer') {
        throw const ApiException(403, 'Customer invoice ownership required.');
      }
      final actorId = me['id'] as int;
      final result = await api.request(
        'finance/invoices/${widget.invoiceId}',
      );
      if (!mounted || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      if (result is! Map) {
        throw const FormatException('Unsupported invoice record.');
      }
      final invoice = result['invoice'];
      if (invoice is! Map ||
          invoice['id'] is! int || invoice['id'] != widget.invoiceId ||
          invoice['id'] < 1 || invoice['id'] > 9007199254740991 ||
          invoice['user_id'] is! int || invoice['user_id'] != actorId ||
          invoice['minor_units'] is! int ||
          invoice['minor_units'] < 0 ||
          invoice['minor_units'] > 3 ||
          invoice['currency'] is! String ||
          result['real_payment_processed'] != false) {
        throw const ApiException(403, 'Own invoice required.');
      }
      final parsedCurrency = invoice['currency'] as String;
      final captured = result['net_collected_minor'];
      if (!RegExp(r'^[A-Z]{3}$').hasMatch(parsedCurrency) ||
          captured is! int || captured < 0 || captured > 2000000000) {
        throw const FormatException('Unsupported captured balance.');
      }
      final target = targetGeneration;
      final pending = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${api.base.origin}:$actorId:case:${widget.invoiceId}',
        isCurrent: () => mounted && current() &&
            identical(api, widget.api) && target == targetGeneration,
      );
      await pending.restore();
      if (!mounted || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      owner = actorId;
      digits = invoice['minor_units'] as int;
      currency = parsedCurrency;
      maximum = captured;
      operation = pending;
      if (pending.pending == null && maximum == 0) {
        noFunds = true;
        operation = null;
      } else if (pending.pending != null) {
        final body = pending.pending!['body'];
        if (body is! Map || body['invoice_id'] is! int ||
            body['invoice_id'] != widget.invoiceId || body['kind'] != 'dispute' ||
            body['amount_minor'] is! int || body['amount_minor'] < 1 ||
            body['amount_minor'] > 2000000000 || body['reason'] is! String ||
            (body['reason'] as String).trim().isEmpty ||
            (body['reason'] as String).length > 500) {
          operation = null;
          throw const FormatException('Invalid saved dispute intent.');
        }
        amount.text = minorDecimal(body['amount_minor'] as int, digits);
        reason.text = body['reason'] as String;
      }
    } catch (e) {
      if (mounted && epoch == generation && current() &&
          identical(api, widget.api)) fail(e);
    } finally {
      if (mounted && epoch == generation &&
          identical(api, widget.api)) {
        if (!current()) {
          redactFormerIdentity();
        } else {
          setState(() => busy = false);
        }
      }
    }
  }

  Future<void> submit() async {
    final api = widget.api;
    final current = authorized;
    final pending = operation;
    if (!mounted || busy || done || pending == null || !consent || !current()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final parsed = decimalMinor(amount.text, digits, maximum: 2000000000);
      if (parsed == null ||
          parsed < 1 ||
          (pending.pending == null && parsed > maximum) ||
          reason.text.trim().isEmpty ||
          reason.text.trim().length > 500) {
        throw const FormatException('Invalid dispute fields.');
      }
      final body = await pending.prepare({
        'invoice_id': widget.invoiceId,
        'kind': 'dispute',
        'amount_minor': parsed,
        'reason': reason.text.trim(),
      });
      if (!mounted || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      final row = await api.request(
        'commercial/cases',
        method: 'POST',
        body: body,
      );
      if (!mounted || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      if (row is! Map ||
          row['id'] is! int ||
          row['id'] < 1 || row['id'] > 9007199254740991 ||
          row['user_id'] is! int || row['user_id'] != owner ||
          row['invoice_id'] is! int || row['invoice_id'] != widget.invoiceId ||
          row['amount_minor'] is! int || row['amount_minor'] != parsed ||
          row['reason'] != body['reason'] ||
          row['kind'] != 'dispute' ||
          row['status'] != 'open' ||
          row['real_money_moved'] != false ||
          !['MOCK_PROVIDER', 'STRIPE_TEST_CONTRACT'].contains(row['evidence'])) {
        throw const FormatException('Unsupported case receipt.');
      }
      await pending.confirmed();
      if (mounted && epoch == generation && current() &&
          identical(api, widget.api)) {
        setState(() => done = true);
      }
    } catch (e) {
      if (mounted && epoch == generation && current() &&
          identical(api, widget.api)) fail(e);
    } finally {
      if (mounted && epoch == generation &&
          identical(api, widget.api)) {
        if (!current()) {
          redactFormerIdentity();
        } else {
          setState(() => busy = false);
        }
      }
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => caseText(c, k);
    if (!authorized()) {
      return Scaffold(
        appBar: AppBar(title: Text(t('new'))),
        body: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(t('notice')),
        ),
      );
    }
    final view = generation;
    final locked = busy || operation?.pending != null || done;
    return Scaffold(
      appBar: AppBar(title: Text(t('new'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          Text('${t('invoice')}: #${widget.invoiceId}'),
          if (noFunds) Text(t('noFunds')),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (busy) const Center(child: CircularProgressIndicator()),
          TextField(
            controller: amount,
            readOnly: locked,
            onChanged: (_) { if (authorized() && consent) setState(() => consent = false); },
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: '${t('amount')} ($currency)',
            ),
          ),
          TextField(
            controller: reason,
            readOnly: locked,
            onChanged: (_) { if (authorized() && consent) setState(() => consent = false); },
            maxLength: 500,
            decoration: InputDecoration(labelText: t('reason')),
          ),
          if (operation?.pending != null) Text(t('pending')),
          if (done) Text(t('done')),
          if (!done) ...[
            CheckboxListTile(
              value: consent,
              onChanged: busy || operation == null
                  ? null
                  : (v) {
                      if (mounted && authorized() && view == generation) {
                        setState(() => consent = v!);
                      }
                    },
              title: Text(t('submitConsent')),
            ),
            FilledButton(
              onPressed: busy || !consent || operation == null ? null : () {
                if (mounted && authorized() && view == generation) submit();
              },
              child: Text(t('confirm')),
            ),
          ],
        ],
      ),
    );
  }
}

class CaseEvidenceView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final int caseId;
  final FinancialOperationStore? store;
  final Future<VendorEvidence?> Function()? picker;
  const CaseEvidenceView({
    super.key,
    required this.api,
    required this.onExpired,
    required this.caseId,
    this.store,
    this.picker,
  });
  @override
  State<CaseEvidenceView> createState() => _EvidenceState();
}

class _EvidenceState extends State<CaseEvidenceView>
    with WidgetsBindingObserver {
  FinancialOperation? operation;
  ApiPage<Map<String, dynamic>>? page;
  VendorEvidence? file;
  String kind = 'service_record';
  bool busy = false, consent = false, done = false, canUpload = false;
  bool choosingFile = false;
  Object? error;
  int generation = 0, target = 1;
  bool foreground = true;
  late final bool Function() sessionFence;
  StreamSubscription<int>? session;
  bool revoked = false;
  int targetGeneration = 0;
  // Asynchronous delivery avoids rebuilding a detached route during the
  // underlying parent's didUpdateWidget/dispose framework phase.
  final previewEvents = StreamController<int>.broadcast();
  void retirePreview() {
    if (!previewEvents.isClosed) previewEvents.add(generation);
  }
  bool authorized() => !revoked && sessionFence();
  bool currentView(int view) => mounted && foreground && authorized() &&
      view == generation;

  @override
  void initState() {
    super.initState();
    sessionFence = widget.api.sessionFence();
    session = widget.api.sessionEvents.listen((_) {
      if (!mounted) return;
      revoked = true;
      generation++;
      targetGeneration++;
      retirePreview();
      redactFormerIdentity();
    });
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant CaseEvidenceView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        oldWidget.caseId != widget.caseId ||
        !identical(oldWidget.store, widget.store)) {
      generation++;
      targetGeneration++;
      retirePreview();
      if (!identical(oldWidget.api, widget.api)) revoked = true;
      operation = null;
      page = null;
      file = null;
      kind = 'service_record';
      busy = false;
      consent = false;
      done = false;
      canUpload = false;
      choosingFile = false;
      error = null;
      target = 1;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    // A system picker may briefly report inactive while retaining the current
    // authenticated document selection. Hidden/paused/detached is a full
    // background transition, even when inactive already changed foreground.
    if (!resumed &&
        state != AppLifecycleState.inactive &&
        choosingFile) {
      generation++;
      targetGeneration++;
      retirePreview();
      choosingFile = false;
      foreground = false;
      setState(() {
        operation = null;
        page = null;
        file = null;
        consent = false;
        canUpload = false;
        busy = false;
        done = false;
        error = null;
      });
      return;
    }
    if (foreground == resumed) return;
    foreground = resumed;
    if (!resumed) {
      if (choosingFile) {
        // System document pickers temporarily inactivate the app. The
        // foreground guard hides the page without invalidating the picker.
        // The original account fence still gates the returned file.
        setState(() {});
        return;
      }
      generation++;
      targetGeneration++;
      retirePreview();
      setState(() {
        operation = null;
        page = null;
        file = null;
        consent = false;
        canUpload = false;
        busy = false;
        done = false;
        error = null;
      });
    } else {
      // Private file lists and upload identity require deliberate refresh.
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    retirePreview();
    unawaited(previewEvents.close());
    session?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    file = null;
    super.dispose();
  }

  void validate(Map<String, dynamic> r) {
    if (r['id'] is! int ||
        r['id'] < 1 || r['id'] > 9007199254740991 ||
        r['case_id'] is! int || r['case_id'] != widget.caseId ||
        !['service_record', 'receipt', 'correspondence', 'other'].contains(r['kind']) ||
        r['evidence_status'] != 'PRIVATE_REVIEW_ONLY' ||
        r['provider_submitted'] != false ||
        r['sha256'] is! String ||
        !RegExp(r'^[a-f0-9]{64}$').hasMatch(r['sha256']) ||
        r['size_bytes'] is! int ||
        r['size_bytes'] < 1 ||
        r['size_bytes'] > 4 * 1024 * 1024 ||
        ![
          'application/pdf',
          'image/png',
          'image/jpeg',
        ].contains(r['mime_type']))
      throw const FormatException('Unsupported private evidence.');
  }

  void redactFormerIdentity() {
    setState(() {
      page = null;
      file = null;
      operation = null;
      consent = false;
      canUpload = false;
      choosingFile = false;
      done = false;
      error = null;
      busy = false;
    });
  }

  void fail(Object e) {
    error = e;
    if (e is ApiException && [401, 419].contains(e.status)) {
      page = null;
      file = null;
      operation = null;
      widget.onExpired();
    }
  }

  Future<void> load({int number = 1}) async {
    if (!foreground || !authorized() || number < 1 || number > 100000) return;
    final epoch = ++generation;
    final api = widget.api;
    final current = authorized;
    setState(() {
      busy = true;
      page = null;
      error = null;
      target = number;
      canUpload = false;
    });
    try {
      final me = await api.request('me');
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      if (me is! Map || me['id'] is! int || me['id'] < 1 || me['id'] > 9007199254740991)
        throw const ApiException(403, 'Authenticated owner required.');
      final raw = await api.request(
        'commercial/cases/${widget.caseId}/evidence',
        query: {'page': '$number'},
      );
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      if (raw is! Map || raw['can_upload'] is! bool)
        throw const FormatException('Unsupported case permission.');
      final parsed = ApiPage.fromJson(raw, (r) => r);
      for (final r in parsed.items) validate(r);
      final scopeGeneration = targetGeneration;
      final op =
          operation ??
          FinancialOperation(
            widget.store ?? DeviceFinancialOperationStore(),
            '${api.base.origin}:${me['id']}:case-evidence:${widget.caseId}',
            isCurrent: () => mounted && foreground && current() &&
                identical(api, widget.api) && scopeGeneration == targetGeneration,
          );
      if (operation == null) await op.restore();
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      operation = op;
      if (op.pending != null) {
        final saved = op.pending!['body'];
        if (saved is! Map ||
            !['service_record', 'receipt', 'correspondence', 'other'].contains(saved['kind']) ||
            !['application/pdf', 'image/png', 'image/jpeg'].contains(saved['mime_type']) ||
            saved['size_bytes'] is! int || saved['size_bytes'] < 1 ||
            saved['size_bytes'] > 4 * 1024 * 1024 ||
            saved['sha256'] is! String ||
            !RegExp(r'^[a-f0-9]{64}$').hasMatch(saved['sha256'])) {
          operation = null;
          throw const FormatException('Invalid saved evidence intent.');
        }
        kind = saved['kind'] as String;
      }
      canUpload = raw['can_upload'] as bool;
      page = parsed;
    } catch (e) {
      if (mounted && foreground && epoch == generation && current() &&
          identical(api, widget.api)) fail(e);
    } finally {
      if (mounted && epoch == generation &&
          identical(api, widget.api)) {
        if (!current()) {
          redactFormerIdentity();
        } else if (foreground) {
          setState(() => busy = false);
        }
      }
    }
  }

  Future<void> pick() async {
    if (busy || !foreground || !authorized() || page == null ||
        operation == null || (!canUpload && operation!.pending == null)) return;
    final epoch = ++generation;
    final api = widget.api;
    final current = authorized;
    setState(() {
      busy = true;
      choosingFile = true;
      error = null;
    });
    try {
      final choose = widget.picker ?? pickVendorEvidence;
      final picked = await choose();
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api) || picked == null) return;
      if (picked.bytes.isEmpty ||
          picked.bytes.length > 4 * 1024 * 1024 ||
          vendorEvidenceMime(picked.bytes) != picked.mime)
        throw const FormatException('Unsupported evidence.');
      final metadata = {
        'kind': kind,
        'mime_type': picked.mime,
        'size_bytes': picked.bytes.length,
        'sha256': sha256.convert(picked.bytes).toString(),
      };
      if (operation?.pending != null) {
        final old = operation!.pending!['body'] as Map;
        for (final k in metadata.keys) {
          if (old[k] != metadata[k])
            throw const FormatException('Original evidence required.');
        }
      }
      file = VendorEvidence(Uint8List.fromList(picked.bytes), picked.mime);
      consent = false;
      done = false;
    } catch (e) {
      if (mounted && foreground && epoch == generation && current() &&
          identical(api, widget.api)) fail(e);
    } finally {
      if (mounted && epoch == generation &&
          identical(api, widget.api)) {
        if (!current()) {
          redactFormerIdentity();
        } else if (foreground) {
          setState(() {
            choosingFile = false;
            busy = false;
          });
        } else {
          // A picker that completes before the app resumes cannot safely
          // retain former foreground authority or its private selection.
          generation++;
          targetGeneration++;
      retirePreview();
          setState(() {
            choosingFile = false;
            page = null;
            file = null;
            operation = null;
            consent = false;
            canUpload = false;
            busy = false;
          });
        }
      }
    }
  }

  Future<void> upload() async {
    final api = widget.api;
    final current = authorized;
    final pending = operation;
    if (busy || !foreground || !current() || !consent ||
        file == null || pending == null || page == null ||
        (!canUpload && pending.pending == null)) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final selected = VendorEvidence(
        Uint8List.fromList(file!.bytes),
        file!.mime,
      );
      final metadata = {
        'kind': kind,
        'mime_type': selected.mime,
        'size_bytes': selected.bytes.length,
        'sha256': sha256.convert(selected.bytes).toString(),
      };
      final saved = await pending.prepare(metadata);
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      final result = Map<String, dynamic>.from(
        await api.uploadCaseEvidence(
          widget.caseId,
          kind: kind,
          bytes: selected.bytes,
          mime: selected.mime,
          key: saved['idempotency_key'] as String,
        ) as Map,
      );
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      validate(result);
      for (final k in metadata.keys) {
        if (result[k] != metadata[k])
          throw const FormatException('Wrong evidence acknowledgement.');
      }
      await pending.confirmed();
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      file = null;
      consent = false;
      done = true;
      await load(number: target);
    } catch (e) {
      if (mounted && foreground && epoch == generation && current() &&
          identical(api, widget.api)) fail(e);
    } finally {
      if (mounted && epoch == generation &&
          identical(api, widget.api)) {
        if (!current()) {
          redactFormerIdentity();
        } else if (foreground) {
          setState(() => busy = false);
        }
      }
    }
  }

  Future<void> preview(Map<String, dynamic> row) async {
    if (busy || !foreground || !authorized() ||
        !(page?.items.any((item) => identical(item, row)) ?? false)) return;
    final epoch = ++generation;
    final api = widget.api;
    final current = authorized;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final doc = await api.downloadDocument(
        'commercial/cases/${widget.caseId}/evidence/${row['id']}',
      );
      if (!mounted || !foreground || epoch != generation || !current() ||
          !identical(api, widget.api)) return;
      if (doc.mime != row['mime_type'] ||
          doc.bytes.length != row['size_bytes'] ||
          sha256.convert(doc.bytes).toString() != row['sha256'] ||
          vendorEvidenceMime(doc.bytes) != doc.mime)
        throw const FormatException('Private evidence integrity failed.');
      final title = caseText(context, 'evidence');
      final notice = caseText(context, 'private');
      final privateCopy = PrivateDocument(Uint8List.fromList(doc.bytes), doc.mime);
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (routeContext) => StreamBuilder<int>(
            stream: previewEvents.stream,
            builder: (context, _) => currentView(epoch)
                ? VendorDocumentPreview(
                    document: privateCopy,
                    title: title,
                    notice: notice,
                  )
                : Scaffold(
                    appBar: AppBar(title: Text(title)),
                    body: Center(child: Text(notice)),
                  ),
          ),
        ),
      );
    } catch (e) {
      if (mounted && foreground && epoch == generation && current() &&
          identical(api, widget.api)) fail(e);
    } finally {
      if (mounted && epoch == generation &&
          identical(api, widget.api)) {
        if (!current()) {
          redactFormerIdentity();
        } else if (foreground) {
          setState(() => busy = false);
        }
      }
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => caseText(c, k);
    if (!foreground || !authorized()) {
      return Scaffold(
        appBar: AppBar(title: Text(t('evidence'))),
        body: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(t('notice')),
        ),
      );
    }
    final view = generation;
    return Scaffold(
      appBar: AppBar(title: Text('${t('evidence')} #${widget.caseId}')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          OutlinedButton(
            onPressed: busy ? null : () { if (currentView(view)) load(number: target); },
            child: Text(t('refresh')),
          ),
          if (busy) const Center(child: CircularProgressIndicator()),
          if (error != null) Text(EvStrings.of(c).t(errorKey(error!))),
          if (page != null && operation != null &&
              (canUpload || operation!.pending != null)) ...[
            DropdownButtonFormField<String>(
              initialValue: kind,
              isExpanded: true,
              decoration: InputDecoration(labelText: t('kind')),
              items: [
                for (final k in [
                  'service_record',
                  'receipt',
                  'correspondence',
                  'other',
                ])
                  DropdownMenuItem(value: k, child: Text(t(k))),
              ],
              onChanged: busy || operation?.pending != null
                  ? null
                  : (v) {
                      if (!currentView(view)) return;
                      setState(() {
                        kind = v!;
                        file = null;
                        consent = false;
                      });
                    },
            ),
            if (operation?.pending != null) ...[
              Text(t('pending')),
              Text(t('reselect')),
            ],
            OutlinedButton(
              onPressed: busy ? null : () { if (currentView(view)) pick(); },
              child: Text(t('choose')),
            ),
            if (file != null) Text('${file!.mime} · ${file!.bytes.length} B'),
            CheckboxListTile(
              value: consent,
              onChanged: busy ? null : (v) {
                if (currentView(view)) setState(() => consent = v!);
              },
              title: Text(t('consent')),
            ),
            FilledButton(
              onPressed: busy || !consent || file == null ? null : () {
                if (currentView(view)) upload();
              },
              child: Text(t('upload')),
            ),
          ],
          if (done) Text(t('done')),
          if (page != null) ...[
            if (page!.items.isEmpty) Text(t('empty')),
            for (final r in page!.items)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('#${r['id']} · ${t('${r['kind']}')}'),
                      Text(t('private')),
                      Text(serverEventTime(c, r['created_at'])),
                      OutlinedButton(
                        onPressed: busy ? null : () { if (currentView(view)) preview(r); },
                        child: Text(t('view')),
                      ),
                    ],
                  ),
                ),
              ),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: busy || target <= 1
                      ? null
                      : () { if (currentView(view)) load(number: target - 1); },
                  child: Text(t('previous')),
                ),
                Text('$target / ${page!.last}'),
                OutlinedButton(
                  onPressed: busy || target >= page!.last
                      ? null
                      : () { if (currentView(view)) load(number: target + 1); },
                  child: Text(t('next')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
