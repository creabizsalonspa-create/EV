import 'package:flutter/material.dart';
import 'api.dart';
import 'financial_operation_store.dart';
import 'invoice.dart';
import 'money_input.dart';

/// Explicit optional simulated invoice. Uses the existing durable financial store
/// and canonical invoice UI; no charging command, capture or client billing math.
class OcpiTestInvoiceView extends StatefulWidget {
  final EvApi api;
  final int orderId;
  final VoidCallback onExpired;
  final FinancialOperationStore? store;
  const OcpiTestInvoiceView({
    super.key,
    required this.api,
    required this.orderId,
    required this.onExpired,
    this.store,
  });
  @override
  State<OcpiTestInvoiceView> createState() => _InvoiceConsentState();
}

class _InvoiceConsentState extends State<OcpiTestInvoiceView>
    with WidgetsBindingObserver {
  FinancialOperation? operation;
  Map<String, dynamic>? preview;
  int? invoiceId, owner;
  bool busy = true, consent = false, background = false, privateDenied = false;
  int generation = 0;
  String? error;
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  String get root => 'roaming/sandbox-orders/${widget.orderId}';
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant OcpiTestInvoiceView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api ||
        old.orderId != widget.orderId ||
        old.store != widget.store) {
      generation++;
      operation = null;
      preview = null;
      invoiceId = null;
      owner = null;
      consent = false;
      load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      generation++;
      setState(() {
        background = true;
        preview = null;
        invoiceId = null;
        consent = false;
        busy = false;
      });
    } else {
      setState(() => background = false);
      load();
    }
  }

  void failed(Object e) {
    error = tr(
      'Invoice outcome unconfirmed. Review source history or resolve the saved request before new consent.',
      'نتيجة الفاتورة غير مؤكدة. راجع سجل المصدر أو احسم الطلب المحفوظ قبل موافقة جديدة.',
    );
    preview = null;
    consent = false;
    if (e is ApiException && [401, 403, 404, 419].contains(e.status)) {
      invoiceId = null;
      privateDenied = true;
      operation = null;
      if ([401, 419].contains(e.status)) widget.onExpired();
    }
  }

  void previewContract(Map<String, dynamic> p) {
    if (p['evidence'] != 'SIMULATED' ||
        p['physical_charging_verified'] != false ||
        p['real_payment_processed'] != false ||
        p['automatic_charge'] != false ||
        p['terms_version'] != 'ocpi-simulated-invoice-v1' ||
        p['currency'] is! String ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(p['currency']) ||
        p['minor_units'] is! int ||
        p['minor_units'] < 0 ||
        p['minor_units'] > 3 ||
        p['preview_token'] is! String ||
        (p['preview_token'] as String).length > 2048)
      throw const FormatException('Unexpected invoice preview authority');
    for (final key in [
      'energy_wh',
      'subtotal_minor',
      'tax_minor',
      'total_minor',
      'rate_minor_per_kwh',
      'tax_bps',
    ]) {
      if (p[key] is! int || p[key] < 0 || p[key] > 2000000000)
        throw const FormatException('Invalid exact invoice amount');
    }
    if (p['total_minor'] < 1)
      throw const FormatException('Invalid invoice total');
  }

  Future<void> load() async {
    if (background) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      preview = null;
      invoiceId = null;
      consent = false;
      error = null;
    });
    try {
      final me = await widget.api.request('me');
      if (!mounted || epoch != generation || background) return;
      if (me['id'] is! int ||
          me['id'] < 1 ||
          (owner != null && me['id'] != owner))
        throw const ApiException(401, 'Session changed');
      owner = me['id'] as int;
      operation ??= FinancialOperation(
        widget.store ?? DeviceFinancialOperationStore(),
        '${widget.api.base.origin}:$owner:ocpi-test-invoice:${widget.orderId}',
        isCurrent: widget.api.sessionFence(),
      );
      if (operation!.pending == null) await operation!.restore();
      if (!mounted || epoch != generation || background) return;
      final order = await widget.api.request(root);
      if (!mounted || epoch != generation || background) return;
      if (order['evidence'] != 'SANDBOX_PARTNER_UNVERIFIED' ||
          order['id'] != widget.orderId ||
          order['physical_charging_verified'] != false ||
          order['live_commands_enabled'] != false)
        throw const FormatException('Unexpected order authority');
      final id = order['test_invoice_id'];
      if (id != null) {
        if (id is! int ||
            id < 1 ||
            order['local_invoice_created'] != true ||
            order['test_invoice_evidence'] != 'SIMULATED_CANONICAL_LEDGER' ||
            order['real_payment_processed'] != false)
          throw const FormatException('Unexpected invoice lineage');
        invoiceId = id;
      }
      if (invoiceId == null && operation!.pending == null) {
        final value = await widget.api.request('$root/test-invoice-preview');
        if (!mounted || epoch != generation || background) return;
        previewContract(value);
        preview = Map<String, dynamic>.from(value);
      }
      privateDenied = false;
    } catch (e) {
      if (mounted && epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> send() async {
    if (busy || background || privateDenied || operation == null) return;
    final body = operation!.pending?['body'] as Map<String, dynamic>?;
    final p = preview;
    if (body == null && (!consent || p == null)) return;
    final frozen =
        body ??
        <String, dynamic>{
          'preview_token': p!['preview_token'],
          'terms_version': p['terms_version'],
          'consent': true,
          'expected_total_minor': p['total_minor'],
          'expected_currency': p['currency'],
          'expected_minor_units': p['minor_units'],
          'expected_energy_wh': p['energy_wh'],
        };
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final saved = await operation!.prepare(Map<String, dynamic>.from(frozen));
      if (!mounted || epoch != generation || background) return;
      final request = Map<String, dynamic>.from(saved);
      request['request_key'] = request.remove('idempotency_key');
      final result = await widget.api.request(
        '$root/test-invoice',
        method: 'POST',
        body: request,
      );
      if (!mounted || epoch != generation || background) return;
      if (result['billing_origin'] != 'OCPI_TEST_EXPLICIT_CONSENT' ||
          result['roaming_order_id'] != widget.orderId ||
          result['real_payment_processed'] != false ||
          result['payment_provider'] != 'SIMULATOR_ONLY' ||
          result['invoice']?['evidence'] != 'SIMULATED' ||
          result['invoice']?['id'] is! int ||
          result['invoice']['id'] < 1 ||
          result['invoice']['total_minor'] != frozen['expected_total_minor'] ||
          result['invoice']['currency'] != frozen['expected_currency'])
        throw const FormatException('Unconfirmed financial receipt');
      await operation!.confirmed();
      if (!mounted || epoch != generation || background) return;
      invoiceId = result['invoice']['id'] as int;
      preview = null;
      consent = false;
    } catch (e) {
      if (mounted && epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> resolve() async {
    if (busy || background || privateDenied || operation?.pending == null)
      return;
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(
          tr('Resolve saved invoice request', 'حسم طلب الفاتورة المحفوظ'),
        ),
        content: Text(
          tr(
            'If an invoice exists, open it. Otherwise cancel this saved unissued request. This never deletes an invoice or refunds money.',
            'إذا كانت الفاتورة موجودة فسيتم عرضها. وإلا فسيُلغى هذا الطلب المحفوظ غير المُصدر. لا يحذف هذا فاتورة ولا يُرجع مالاً.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(tr('Cancel', 'إلغاء')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(tr('Resolve', 'حسم')),
          ),
        ],
      ),
    );
    if (!mounted || yes != true || background) return;
    final key = operation!.pending!['key'];
    final epoch = ++generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        '$root/test-invoice-resolve',
        method: 'POST',
        body: {'request_key': key, 'cancel_unissued': true},
      );
      if (!mounted || epoch != generation || background) return;
      if (result['order_id'] != widget.orderId ||
          !['issued', 'cancelled_unissued'].contains(result['state']) ||
          result['financial_records_changed'] != false ||
          result['real_payment_processed'] != false ||
          (result['state'] == 'issued' &&
              (result['invoice_id'] is! int || result['invoice_id'] < 1)))
        throw const FormatException('Unconfirmed resolution');
      await operation!.confirmed();
      if (!mounted || epoch != generation || background) return;
      invoiceId = result['invoice_id'] as int?;
      preview = null;
      consent = false;
    } catch (e) {
      if (mounted && epoch == generation) failed(e);
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = preview;
    final saved = operation?.pending?['body'] as Map?;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          tr(
            'Separate simulated invoice consent',
            'موافقة منفصلة على فاتورة تجريبية',
          ),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            tr(
              'This creates an unpaid simulated invoice for a completed approved partner test session. It does not collect money or verify physical charging.',
              'ينشئ هذا فاتورة تجريبية غير مدفوعة لجلسة اختبار مكتملة لدى شريك معتمد للاختبار. لا يُحصّل المال ولا يثبت شحناً فعلياً.',
            ),
          ),
          if (busy) const LinearProgressIndicator(),
          if (background)
            Text(
              tr(
                'Private invoice view paused.',
                'عُرض الفاتورة الخاص متوقف مؤقتاً.',
              ),
            ),
          if (error != null) Text(error!),
          TextButton(
            onPressed: busy || background ? null : load,
            child: Text(tr('Refresh invoice preview', 'تحديث معاينة الفاتورة')),
          ),
          if (!background && !privateDenied) ...[
            if (saved != null) ...[
              Text(
                tr(
                  'A saved request is awaiting confirmation. Reads never resend it. Resolve it before new consent.',
                  'طلب محفوظ بانتظار التأكيد. لا تعيد القراءة إرساله. احسمه قبل موافقة جديدة.',
                ),
              ),
              if (saved['expected_total_minor'] is int &&
                  saved['expected_minor_units'] is int &&
                  saved['expected_currency'] is String)
                Text(
                  '${tr('Saved invoice total', 'إجمالي الفاتورة المحفوظ')}: ${minorDecimal(saved['expected_total_minor'] as int, saved['expected_minor_units'] as int)} ${saved['expected_currency']}',
                ),
              TextButton(
                onPressed: busy ? null : send,
                child: Text(
                  tr(
                    'Retry same simulated invoice request',
                    'إعادة نفس طلب الفاتورة التجريبية',
                  ),
                ),
              ),
              TextButton(
                onPressed: busy ? null : resolve,
                child: Text(
                  tr(
                    'Resolve saved invoice request',
                    'حسم طلب الفاتورة المحفوظ',
                  ),
                ),
              ),
            ],
            if (invoiceId != null)
              TextButton(
                onPressed: busy
                    ? null
                    : () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => InvoicePage(
                            api: widget.api,
                            invoiceId: invoiceId,
                            onExpired: widget.onExpired,
                          ),
                        ),
                      ),
                child: Text(
                  tr('View simulated invoice', 'عرض الفاتورة التجريبية'),
                ),
              ),
            if (p != null && saved == null) ...[
              Text('${p['provider_name']} · ${p['location_name']}'),
              Text(
                '${tr('Energy (Wh)', 'الطاقة (واط ساعة)')}: ${p['energy_wh']}',
              ),
              for (final entry in [
                ['subtotal_minor', tr('Net amount', 'المبلغ الصافي')],
                ['tax_minor', tr('Tax', 'الضريبة')],
                ['total_minor', tr('Invoice total', 'إجمالي الفاتورة')],
              ])
                Text(
                  '${entry[1]}: ${minorDecimal(p[entry[0]] as int, p['minor_units'] as int)} ${p['currency']}',
                ),
              CheckboxListTile(
                value: consent,
                onChanged: busy
                    ? null
                    : (v) => setState(() => consent = v == true),
                title: Text(
                  tr(
                    'I accept this exact simulated invoice amount under the approved test billing terms. This is separate from charging consent and collects no money.',
                    'أوافق على مبلغ هذه الفاتورة التجريبية بالضبط وفق شروط الفوترة التجريبية المعتمدة. هذه موافقة منفصلة عن الشحن ولا تُحصّل مالاً.',
                  ),
                ),
              ),
              FilledButton(
                onPressed: busy || !consent ? null : send,
                child: Text(
                  tr('Create simulated invoice', 'إنشاء فاتورة تجريبية'),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }
}
