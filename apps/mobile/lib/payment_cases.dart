import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:crypto/crypto.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'financial_operation_store.dart';
import 'vendor_company.dart';

const caseEn = {
  'title': 'Payment disputes',
  'notice':
      'Internal invoice disputes and private review evidence. These records do not create a card-network chargeback, submit evidence to a payment provider or prove a refund. Only authorized reviewers resolve cases.',
  'new': 'Open invoice dispute',
  'amount': 'Disputed amount',
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

class _CasesState extends State<PaymentCasesView> {
  ApiPage<Map<String, dynamic>>? page;
  Object? error;
  bool busy = false;
  int generation = 0, target = 1;
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
            r['invoice_id'] is! int ||
            r['real_money_moved'] != false ||
            !['MOCK_PROVIDER', 'STRIPE_TEST_CONTRACT'].contains(r['evidence']))
          throw const FormatException('Unsupported case evidence.');
      }
      if (mounted && epoch == generation) page = raw;
    } catch (e) {
      if (mounted && epoch == generation) {
        error = e;
        if (e is ApiException && [401, 419].contains(e.status))
          widget.onExpired();
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => caseText(c, k);
    return Scaffold(
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
          if (page != null) ...[
            if (page!.items.isEmpty) Text(t('empty')),
            for (final r in page!.items)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('#${r['id']} · ${t('${r['status']}')}'),
                      Text('${t('invoice')}: #${r['invoice_id']}'),
                      Text('${r['reason']}'),
                      if (r['resolution'] != null)
                        Text('${t('resolution')}: ${r['resolution']}'),
                      OutlinedButton(
                        onPressed: busy
                            ? null
                            : () async {
                                await Navigator.of(c).push(
                                  MaterialPageRoute<void>(
                                    builder: (_) => CaseEvidenceView(
                                      api: widget.api,
                                      onExpired: widget.onExpired,
                                      caseId: r['id'] as int,
                                    ),
                                  ),
                                );
                                if (mounted) load(number: target);
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
                  onPressed: busy || target <= 1
                      ? null
                      : () => load(number: target - 1),
                  child: Text(t('previous')),
                ),
                Text('$target / ${page!.last}'),
                OutlinedButton(
                  onPressed: busy || target >= page!.last
                      ? null
                      : () => load(number: target + 1),
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

class _CreateCaseState extends State<CreatePaymentCaseView> {
  final amount = TextEditingController(), reason = TextEditingController();
  FinancialOperation? operation;
  int owner = 0, digits = 0, maximum = 0;
  String currency = '';
  bool busy = true, consent = false, done = false, noFunds = false;
  Object? error;
  @override
  void initState() {
    super.initState();
    initialize();
  }

  @override
  void dispose() {
    amount.dispose();
    reason.dispose();
    super.dispose();
  }

  void fail(Object e) {
    error = e;
    if (e is ApiException && [401, 419].contains(e.status)) {
      operation = null;
      widget.onExpired();
    }
  }

  Future<void> initialize() async {
    try {
      final me = await widget.api.request('me');
      final result = await widget.api.request(
        'finance/invoices/${widget.invoiceId}',
      );
      final invoice = result['invoice'];
      if (invoice is! Map ||
          invoice['id'] != widget.invoiceId ||
          invoice['user_id'] != me['id'] ||
          invoice['minor_units'] is! int ||
          invoice['minor_units'] < 0 ||
          invoice['minor_units'] > 3 ||
          invoice['currency'] is! String ||
          result['real_payment_processed'] != false)
        throw const ApiException(403, 'Own invoice required.');
      owner = me['id'] as int;
      digits = invoice['minor_units'] as int;
      currency = invoice['currency'] as String;
      if (!RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
          result['net_collected_minor'] is! int ||
          result['net_collected_minor'] < 0)
        throw const FormatException('Unsupported captured balance.');
      maximum = result['net_collected_minor'] as int;
      if (!mounted) return;
      operation = FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:$owner:case:${widget.invoiceId}',
        isCurrent: widget.api.sessionFence(),
      );
      await operation!.restore();
      if (!mounted) return;
      if (operation!.pending == null && maximum == 0) {
        noFunds = true;
        operation = null;
        return;
      }
      if (operation!.pending != null) {
        final body = operation!.pending!['body'] as Map;
        amount.text = minorDecimal(body['amount_minor'] as int, digits);
        reason.text = body['reason'] as String;
      }
    } catch (e) {
      if (mounted) fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> submit() async {
    if (busy || operation == null || !consent) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final parsed = decimalMinor(amount.text, digits, maximum: 2000000000);
      if (parsed == null ||
          parsed < 1 ||
          (operation!.pending == null && parsed > maximum) ||
          reason.text.trim().isEmpty ||
          reason.text.trim().length > 500)
        throw const FormatException('Invalid dispute fields.');
      final body = await operation!.prepare({
        'invoice_id': widget.invoiceId,
        'kind': 'dispute',
        'amount_minor': parsed,
        'reason': reason.text.trim(),
      });
      if (!mounted) return;
      final row = await widget.api.request(
        'commercial/cases',
        method: 'POST',
        body: body,
      );
      if (row is! Map ||
          row['id'] is! int ||
          row['user_id'] != owner ||
          row['invoice_id'] != widget.invoiceId ||
          row['amount_minor'] != parsed ||
          row['reason'] != body['reason'] ||
          row['kind'] != 'dispute' ||
          row['real_money_moved'] != false ||
          !['MOCK_PROVIDER', 'STRIPE_TEST_CONTRACT'].contains(row['evidence']))
        throw const FormatException('Unsupported case receipt.');
      await operation!.confirmed();
      if (mounted) setState(() => done = true);
    } catch (e) {
      if (mounted) fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => caseText(c, k);
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
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: '${t('amount')} ($currency)',
            ),
          ),
          TextField(
            controller: reason,
            readOnly: locked,
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
                  : (v) => setState(() => consent = v!),
              title: Text(t('submitConsent')),
            ),
            FilledButton(
              onPressed: busy || !consent || operation == null ? null : submit,
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

class _EvidenceState extends State<CaseEvidenceView> {
  FinancialOperation? operation;
  ApiPage<Map<String, dynamic>>? page;
  VendorEvidence? file;
  String kind = 'service_record';
  bool busy = false, consent = false, done = false, canUpload = false;
  Object? error;
  int generation = 0, target = 1;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    file = null;
    super.dispose();
  }

  void validate(Map<String, dynamic> r) {
    if (r['id'] is! int ||
        r['case_id'] != widget.caseId ||
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
    final epoch = ++generation;
    setState(() {
      busy = true;
      page = null;
      error = null;
      target = number;
    });
    try {
      final me = await widget.api.request('me');
      if (me['id'] is! int || me['id'] < 1)
        throw const ApiException(403, 'Authenticated owner required.');
      final raw = await widget.api.request(
        'commercial/cases/${widget.caseId}/evidence',
        query: {'page': '$number'},
      );
      if (raw['can_upload'] is! bool)
        throw const FormatException('Unsupported case permission.');
      final parsed = ApiPage.fromJson(raw, (r) => r);
      for (final r in parsed.items) validate(r);
      if (!mounted || epoch != generation) return;
      final op =
          operation ??
          FinancialOperation(
            widget.store ?? DeviceFinancialOperationStore(),
            '${widget.api.base.origin}:${me['id']}:case-evidence:${widget.caseId}',
            isCurrent: widget.api.sessionFence(),
          );
      if (operation == null) await op.restore();
      if (!mounted || epoch != generation) return;
      operation = op;
      if (op.pending != null)
        kind = (op.pending!['body'] as Map)['kind'] as String;
      canUpload = raw['can_upload'] as bool;
      page = parsed;
    } catch (e) {
      if (mounted && epoch == generation) fail(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> pick() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final picked = await (widget.picker ?? pickVendorEvidence)();
      if (!mounted || picked == null) return;
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
      file = picked;
      done = false;
    } catch (e) {
      if (mounted) fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> upload() async {
    if (busy || !consent || file == null || operation == null) return;
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
      final saved = await operation!.prepare(metadata);
      if (!mounted) return;
      final result = Map<String, dynamic>.from(
        await widget.api.uploadCaseEvidence(
              widget.caseId,
              kind: kind,
              bytes: selected.bytes,
              mime: selected.mime,
              key: saved['idempotency_key'] as String,
            )
            as Map,
      );
      validate(result);
      for (final k in metadata.keys) {
        if (result[k] != metadata[k])
          throw const FormatException('Wrong evidence acknowledgement.');
      }
      await operation!.confirmed();
      if (!mounted) return;
      file = null;
      consent = false;
      done = true;
      await load(number: target);
    } catch (e) {
      if (mounted) fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> preview(Map<String, dynamic> row) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final doc = await widget.api.downloadDocument(
        'commercial/cases/${widget.caseId}/evidence/${row['id']}',
      );
      if (doc.mime != row['mime_type'] ||
          doc.bytes.length != row['size_bytes'] ||
          sha256.convert(doc.bytes).toString() != row['sha256'] ||
          vendorEvidenceMime(doc.bytes) != doc.mime)
        throw const FormatException('Private evidence integrity failed.');
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => VendorDocumentPreview(
            document: doc,
            title: caseText(context, 'evidence'),
            notice: caseText(context, 'private'),
          ),
        ),
      );
    } catch (e) {
      if (mounted) fail(e);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => caseText(c, k);
    return Scaffold(
      appBar: AppBar(title: Text('${t('evidence')} #${widget.caseId}')),
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
          if (operation != null &&
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
                  : (v) => setState(() {
                      kind = v!;
                      file = null;
                      consent = false;
                    }),
            ),
            if (operation?.pending != null) ...[
              Text(t('pending')),
              Text(t('reselect')),
            ],
            OutlinedButton(
              onPressed: busy ? null : pick,
              child: Text(t('choose')),
            ),
            if (file != null) Text('${file!.mime} · ${file!.bytes.length} B'),
            CheckboxListTile(
              value: consent,
              onChanged: busy ? null : (v) => setState(() => consent = v!),
              title: Text(t('consent')),
            ),
            FilledButton(
              onPressed: busy || !consent || file == null ? null : upload,
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
                      Text('${r['created_at']}'),
                      OutlinedButton(
                        onPressed: busy ? null : () => preview(r),
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
                      : () => load(number: target - 1),
                  child: Text(t('previous')),
                ),
                Text('$target / ${page!.last}'),
                OutlinedButton(
                  onPressed: busy || target >= page!.last
                      ? null
                      : () => load(number: target + 1),
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
