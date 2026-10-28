import 'package:flutter/material.dart';
import 'package:crypto/crypto.dart';
import 'api.dart';
import 'strings.dart';
import 'money_input.dart';
import 'vendor_company.dart' show VendorDocumentPreview;

const financialEn = {
  'title': 'Private financial documents',
  'notice':
      'Test documents, not legally approved invoices or proof of bank payment. Private files stay in application memory and close when the app leaves the foreground.',
  'empty': 'No financial documents.',
  'pdf': 'View private PDF',
  'refresh': 'Refresh',
  'next': 'Next',
  'previous': 'Previous',
  'generate': 'Prepare document',
  'source': 'Source',
  'id': 'Source record ID',
  'language': 'Document language',
  'consent':
      'Prepare a historical document from the authorized canonical record. This does not initiate payment.',
  'charging_invoice': 'Charging invoice',
  'charging_credit': 'Charging credit',
  'subscription_invoice': 'Subscription invoice',
  'subscription_credit': 'Subscription credit',
  'vendor_settlement': 'Vendor settlement',
  'corporate_statement': 'Corporate statement',
  'invalid': 'Enter a positive record ID.',
  'uncertain':
      'Document preparation was not confirmed. Refresh the list. Any explicit resubmission uses the same source and language; it cannot create a payment.',
  'snapshot': 'State at generation',
  'current': 'Current source state',
  'en': 'English',
  'ar': 'Arabic',
};
const financialAr = {
  'title': 'المستندات المالية الخاصة',
  'notice':
      'مستندات اختبار غير معتمدة قانونياً وليست إثبات دفع مصرفي. تبقى الملفات في ذاكرة التطبيق وتغلق عند مغادرة الواجهة.',
  'empty': 'لا توجد مستندات مالية.',
  'pdf': 'عرض PDF الخاص',
  'refresh': 'تحديث',
  'next': 'التالي',
  'previous': 'السابق',
  'generate': 'إعداد المستند',
  'source': 'المصدر',
  'id': 'معرف السجل المصدر',
  'language': 'لغة المستند',
  'consent':
      'إعداد مستند تاريخي من السجل الأصلي المصرح به. لا يبدأ ذلك عملية دفع.',
  'charging_invoice': 'فاتورة شحن',
  'charging_credit': 'إشعار دائن للشحن',
  'subscription_invoice': 'فاتورة اشتراك',
  'subscription_credit': 'إشعار دائن للاشتراك',
  'vendor_settlement': 'كشف تسوية المورد',
  'corporate_statement': 'كشف الشركة',
  'invalid': 'أدخل معرف سجل موجباً.',
  'uncertain':
      'لم يتم تأكيد إعداد المستند. حدّث القائمة. تستخدم إعادة الإرسال الصريحة المصدر واللغة نفسيهما ولا تنشئ دفعة.',
  'snapshot': 'الحالة عند الإنشاء',
  'current': 'حالة المصدر الحالية',
  'en': 'الإنجليزية',
  'ar': 'العربية',
};
String financialText(BuildContext c, String key) =>
    (Localizations.localeOf(c).languageCode == 'ar'
        ? financialAr
        : financialEn)[key] ??
    key;
const financialKinds = [
  'charging_invoice',
  'charging_credit',
  'subscription_invoice',
  'subscription_credit',
  'vendor_settlement',
  'corporate_statement',
];

class FinancialDocumentRecord {
  final int id, sourceId;
  final String number, kind, locale, pdfHash;
  final String? current;
  FinancialDocumentRecord.fromJson(Map<String, dynamic> j)
    : id = j['id'] as int,
      sourceId = j['source_id'] as int,
      number = j['number'] as String,
      kind = j['source_kind'] as String,
      locale = j['locale'] as String,
      pdfHash = j['pdf_sha256'] as String,
      current = j['current_source_state'] as String? {
    if (id < 1 ||
        sourceId < 1 ||
        !financialKinds.contains(kind) ||
        !['en', 'ar'].contains(locale) ||
        !RegExp(r'^[a-f0-9]{64}$').hasMatch(pdfHash) ||
        j['legal_status'] != 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED' ||
        j['external_provider_verified'] != false)
      throw const FormatException('Unsupported financial document evidence.');
  }
  void verify(PrivateDocument document) {
    if (document.mime != 'application/pdf' ||
        document.bytes.length < 5 ||
        String.fromCharCodes(document.bytes.take(5)) != '%PDF-' ||
        sha256.convert(document.bytes).toString() != pdfHash)
      throw const ApiException(
        502,
        'Private financial document integrity failure.',
      );
  }
}

class FinancialDocumentsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String? sourceKind;
  final int? sourceId;
  const FinancialDocumentsView({
    super.key,
    required this.api,
    required this.onExpired,
    this.sourceKind,
    this.sourceId,
  });
  @override
  State<FinancialDocumentsView> createState() => _DocumentsState();
}

class _DocumentsState extends State<FinancialDocumentsView> {
  ApiPage<FinancialDocumentRecord>? page;
  Object? error;
  bool busy = false, uncertain = false;
  int generation = 0, target = 1;
  String kind = 'charging_invoice', language = 'en';
  final source = TextEditingController();
  final form = GlobalKey<FormState>();
  bool consent = false;
  String t(String k) => financialText(context, k);
  @override
  void initState() {
    super.initState();
    kind = widget.sourceKind ?? kind;
    source.text = widget.sourceId?.toString() ?? '';
    load();
  }

  @override
  void dispose() {
    generation++;
    source.dispose();
    super.dispose();
  }

  void failed(Object e) {
    error = e;
    page = null;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> load({int number = 1}) async {
    final epoch = ++generation;
    target = number;
    setState(() {
      busy = true;
      error = null;
      page = null;
    });
    try {
      final result = ApiPage.fromJson(
        await widget.api.request(
          'financial/documents',
          query: {
            'page': '$number',
            if (widget.sourceKind != null) 'source_kind': widget.sourceKind!,
            if (widget.sourceId != null) 'source_id': '${widget.sourceId}',
          },
        ),
        FinancialDocumentRecord.fromJson,
      );
      if (mounted && epoch == generation) setState(() => page = result);
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> generate() async {
    if (!consent || !form.currentState!.validate()) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'financial/documents',
        method: 'POST',
        body: {
          'source_kind': kind,
          'source_id': decimalMinor(source.text, 0, maximum: 2000000000)!,
          'locale': language,
        },
      );
      FinancialDocumentRecord.fromJson(
        Map<String, dynamic>.from(result as Map),
      );
      if (!mounted || epoch != generation) return;
      uncertain = false;
      await load();
    } catch (e) {
      if (mounted && epoch == generation)
        setState(() {
          uncertain = true;
          failed(e);
        });
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> open(FinancialDocumentRecord row) async {
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final bytes = await widget.api.downloadDocument(
        'financial/documents/${row.id}/download/pdf',
      );
      row.verify(bytes);
      if (!mounted || epoch != generation) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => VendorDocumentPreview(
            document: bytes,
            title: t('title'),
            notice: t('notice'),
          ),
        ),
      );
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    appBar: AppBar(title: Text(t('title'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(t('notice')),
        ExpansionTile(
          title: Text(t('generate')),
          children: [
            Form(
              key: form,
              child: Column(
                children: [
                  DropdownButtonFormField<String>(
                    initialValue: kind,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: t('source')),
                    items: [
                      for (final k in financialKinds)
                        DropdownMenuItem(value: k, child: Text(t(k))),
                    ],
                    onChanged: busy || uncertain || widget.sourceKind != null
                        ? null
                        : (v) => setState(() => kind = v!),
                  ),
                  TextFormField(
                    controller: source,
                    readOnly: busy || uncertain || widget.sourceId != null,
                    decoration: InputDecoration(labelText: t('id')),
                    keyboardType: TextInputType.number,
                    validator: (v) =>
                        (decimalMinor(v ?? '', 0, maximum: 2000000000) ?? 0) > 0
                        ? null
                        : t('invalid'),
                  ),
                  DropdownButtonFormField<String>(
                    initialValue: language,
                    items: [
                      for (final l in ['en', 'ar'])
                        DropdownMenuItem(value: l, child: Text(t(l))),
                    ],
                    onChanged: busy || uncertain
                        ? null
                        : (v) => setState(() => language = v!),
                  ),
                  CheckboxListTile(
                    value: consent,
                    onChanged: busy
                        ? null
                        : (v) => setState(() => consent = v!),
                    title: Text(t('consent')),
                  ),
                  FilledButton(
                    onPressed: busy || !consent ? null : generate,
                    child: Text(t('generate')),
                  ),
                ],
              ),
            ),
          ],
        ),
        if (uncertain) Text(t('uncertain')),
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
                    Text(row.number, style: Theme.of(c).textTheme.titleMedium),
                    Text('${t(row.kind)} · ${t(row.locale)}'),
                    if (row.current != null)
                      Text('${t('current')}: ${row.current}'),
                    OutlinedButton(
                      onPressed: busy ? null : () => open(row),
                      child: Text(t('pdf')),
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
