import 'dart:async';
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
  'charging_unpaid_credit': 'Unpaid charging credit (no cash)',
  'subscription_invoice': 'Subscription invoice',
  'subscription_credit': 'Subscription credit',
  'vendor_settlement': 'Vendor settlement',
  'corporate_statement': 'Corporate statement',
  'invalid': 'Enter a positive record ID.',
  'uncertain':
      'Document preparation was not confirmed. Refresh the list. Any explicit resubmission uses the same source and language; it cannot create a payment.',
  'snapshot': 'State at generation',
  'current': 'Current source state',
  'privateCleared': 'Private document source cleared. Refresh with the currently authorized account.',
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
  'charging_unpaid_credit': 'تسوية شحن غير مدفوعة دون حركة نقدية',
  'subscription_invoice': 'فاتورة اشتراك',
  'subscription_credit': 'إشعار دائن للاشتراك',
  'vendor_settlement': 'كشف تسوية المورد',
  'corporate_statement': 'كشف الشركة',
  'invalid': 'أدخل معرف سجل موجباً.',
  'uncertain':
      'لم يتم تأكيد إعداد المستند. حدّث القائمة. تستخدم إعادة الإرسال الصريحة المصدر واللغة نفسيهما ولا تنشئ دفعة.',
  'snapshot': 'الحالة عند الإنشاء',
  'current': 'حالة المصدر الحالية',
  'privateCleared': 'مُسحت المستندات المالية الخاصة. حدّث باستخدام الحساب المصرح به حالياً.',
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
  'charging_unpaid_credit',
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
    if (id < 1 || id > 9007199254740991 ||
        sourceId < 1 || sourceId > 9007199254740991 ||
        !RegExp(r'^CB-TDOC-[A-Z]{2}-(P|V[1-9][0-9]*)-[0-9]{4}-[0-9]{9}$')
            .hasMatch(number) ||
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

class _DocumentsState extends State<FinancialDocumentsView>
    with WidgetsBindingObserver {
  ApiPage<FinancialDocumentRecord>? page;
  Object? error;
  bool busy = false, uncertain = false, foreground = true, hidden = false;
  bool mutationInFlight = false;
  bool Function()? currentSession;
  StreamSubscription<int>? sessionEvents;
  int generation = 0, target = 1;

  void observe(EvApi api) {
    sessionEvents?.cancel();
    sessionEvents = api.sessionEvents.listen((_) {
      if (!mounted) return;
      setState(() {
        // A revoked account must not silently "resolve" an in-flight
        // immutable document creation or invite an automatic resubmission.
        final possibleWrite = mutationInFlight || uncertain;
        erasePrivate();
        currentSession = null;
        uncertain = possibleWrite;
      });
    });
  }

  bool get current => foreground && (currentSession?.call() ?? false);
  bool active(int epoch, bool Function() fence) =>
      mounted && foreground && generation == epoch && fence();

  void erasePrivate() {
    generation++;
    page = null;
    error = null;
    busy = false;
    mutationInFlight = false;
    hidden = true;
    consent = false;
  }
  String kind = 'charging_invoice', language = 'en';
  final source = TextEditingController();
  final form = GlobalKey<FormState>();
  bool consent = false;
  String t(String k) => financialText(context, k);
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    observe(widget.api);
    kind = widget.sourceKind ?? kind;
    source.text = widget.sourceId?.toString() ?? '';
    foreground = WidgetsBinding.instance.lifecycleState == null ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed;
    hidden = !foreground;
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant FinancialDocumentsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.sourceKind != widget.sourceKind ||
        oldWidget.sourceId != widget.sourceId) {
      if (oldWidget.api != widget.api) observe(widget.api);
      erasePrivate();
      currentSession = null;
      uncertain = false;
      kind = widget.sourceKind ?? 'charging_invoice';
      source.text = widget.sourceId?.toString() ?? '';
      language = 'en';
      target = 1;
      if (foreground) {
        final api = widget.api, expectedKind = widget.sourceKind;
        final id = widget.sourceId;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted && foreground && identical(api, widget.api) &&
              expectedKind == widget.sourceKind && id == widget.sourceId) {
            load();
          }
        });
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!mounted) return;
    setState(() {
      foreground = state == AppLifecycleState.resumed;
      if (!foreground) {
        // Unknown POST outcome is preserved for this same screen. Never
        // allow an unknown response to become a new document or auto-retry.
        if (mutationInFlight) uncertain = true;
        erasePrivate();
      }
    });
  }

  @override
  void dispose() {
    sessionEvents?.cancel();
    erasePrivate();
    WidgetsBinding.instance.removeObserver(this);
    source.dispose();
    super.dispose();
  }

  void failed(Object e) {
    error = e;
    page = null;
  }

  bool handleExpiry(Object e, EvApi requestApi) {
    // A real expiry clears the old session fence before the error arrives.
    // Do not sign out a replacement API or newer credential generation.
    if (mounted && identical(requestApi, widget.api) &&
        e is ApiException && e.endsCurrentSession) {
      widget.onExpired();
      return true;
    }
    return false;
  }

  Future<void> load({int number = 1}) async {
    if (!foreground || number < 1 || number > 100000) return;
    final api = widget.api;
    final epoch = ++generation, fence = api.sessionFence();
    currentSession = fence;
    target = number;
    setState(() {
      busy = true;
      mutationInFlight = false;
      error = null;
      page = null;
      hidden = false;
    });
    try {
      final raw = await api.request(
        'financial/documents',
        query: {
          'page': '$number',
          if (widget.sourceKind != null) 'source_kind': widget.sourceKind!,
          if (widget.sourceId != null) 'source_id': '${widget.sourceId}',
        },
      );
      if (!active(epoch, fence)) return;
      final result = ApiPage.fromJson(
        raw,
        FinancialDocumentRecord.fromJson,
      );
      // The requested immutable source scope is independent of server
      // access authorization; verify the native view cannot be pointed at
      // another source by an old or malformed response.
      if (widget.sourceKind != null &&
          result.items.any((r) => r.kind != widget.sourceKind)) {
        throw const FormatException('Foreign document kind.');
      }
      if (widget.sourceId != null &&
          result.items.any((r) => r.sourceId != widget.sourceId)) {
        throw const FormatException('Foreign document record.');
      }
      if (active(epoch, fence)) setState(() => page = result);
    } catch (e) {
      if (handleExpiry(e, api)) return;
      if (active(epoch, fence)) setState(() => failed(e));
    } finally {
      if (mounted && foreground && epoch == generation) {
        setState(() {
          busy = false;
          if (!fence()) {
            page = null;
            error = null;
            hidden = true;
          }
        });
      }
    }
  }

  Future<void> generate() async {
    if (!current || busy || uncertain || !consent ||
        !form.currentState!.validate()) return;
    final api = widget.api;
    final epoch = ++generation, fence = currentSession!;
    final selectedKind = kind, selectedLanguage = language;
    final selectedSource = decimalMinor(source.text, 0, maximum: 2000000000)!;
    setState(() {
      busy = true;
      mutationInFlight = true;
      error = null;
    });
    try {
      final result = await api.request(
        'financial/documents',
        method: 'POST',
        body: {
          'source_kind': selectedKind,
          'source_id': selectedSource,
          'locale': selectedLanguage,
        },
      );
      if (!active(epoch, fence)) return;
      final record = FinancialDocumentRecord.fromJson(
        Map<String, dynamic>.from(result as Map),
      );
      if (record.kind != selectedKind ||
          record.sourceId != selectedSource ||
          record.locale != selectedLanguage) {
        throw const FormatException('Foreign document response.');
      }
      if (!active(epoch, fence)) return;
      uncertain = false;
      mutationInFlight = false;
      await load();
    } catch (e) {
      if (handleExpiry(e, api)) return;
      if (active(epoch, fence)) {
        setState(() {
          mutationInFlight = false;
          // After a possible provider/database commit, never silently
          // regenerate a numbered artifact. Require explicit list review.
          uncertain = true;
          failed(e);
        });
      }
    } finally {
      if (mounted && foreground && generation == epoch) {
        setState(() {
          busy = false;
          mutationInFlight = false;
          if (!fence()) {
            erasePrivate();
            uncertain = true;
          }
        });
      }
    }
  }

  Future<void> open(FinancialDocumentRecord row) async {
    if (!current || busy || page == null ||
        !page!.items.any((record) => record.id == row.id &&
            record.kind == row.kind && record.sourceId == row.sourceId &&
            record.pdfHash == row.pdfHash)) return;
    final api = widget.api;
    final epoch = ++generation, fence = currentSession!;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final bytes = await api.downloadDocument(
        'financial/documents/${row.id}/download/pdf',
      );
      if (!active(epoch, fence)) return;
      // Server encrypted-file SHA and the originally displayed document
      // metadata must agree before any bytes are handed to a PDF viewer.
      row.verify(bytes);
      if (!active(epoch, fence)) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => VendorDocumentPreview(
            document: bytes,
            title: t('title'),
            notice: t('notice'),
            revokedSessionEvents: widget.api.sessionEvents,
          ),
        ),
      );
    } catch (e) {
      if (handleExpiry(e, api)) return;
      if (active(epoch, fence)) setState(() => failed(e));
    } finally {
      if (mounted && foreground && generation == epoch) {
        setState(() {
          busy = false;
          if (!fence()) {
            erasePrivate();
          }
        });
      }
    }
  }

  @override
  Widget build(BuildContext c) => !current
      ? Scaffold(
          appBar: AppBar(title: Text(t('title'))),
          body: Center(child: Text(t('privateCleared'))),
        )
      : Scaffold(
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
                    onChanged: busy || uncertain || !foreground ||
                        widget.sourceKind != null
                        ? null
                        : (v) => setState(() => kind = v!),
                  ),
                  TextFormField(
                    controller: source,
                    readOnly: busy || uncertain || !foreground ||
                        widget.sourceId != null,
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
                    onChanged: busy || uncertain || !foreground
                        ? null
                        : (v) => setState(() => language = v!),
                  ),
                  CheckboxListTile(
                    value: consent,
                    onChanged: busy || !foreground || uncertain
                        ? null
                        : (v) => setState(() => consent = v!),
                    title: Text(t('consent')),
                  ),
                  FilledButton(
                    onPressed: busy || !consent || uncertain || !foreground
                        ? null : generate,
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
        if (error != null && current)
          Text(EvStrings.of(c).t(errorKey(error!))),
        if (hidden) Text(t('privateCleared')),
        if (page != null && current && page!.items.isEmpty)
          Text(t('empty')),
        if (page != null && current) ...[
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
