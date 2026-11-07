import 'package:flutter/material.dart';
import 'api.dart';
import 'ocpi_test_invoice.dart';
import 'invoice.dart';

/// Private opt-in sandbox authority, separate from public partner discovery.
/// A command acknowledgment cannot prove a charging transaction or invoice.
class SandboxRoamingView extends StatefulWidget {
  final EvApi api;
  final int? locationId, providerId;
  final VoidCallback onExpired;
  const SandboxRoamingView({
    super.key,
    required this.api,
    required this.onExpired,
    this.locationId,
    this.providerId,
  }) : assert(locationId == null || providerId == null);
  @override
  State<SandboxRoamingView> createState() => _SandboxRoamingState();
}

class _SandboxRoamingState extends State<SandboxRoamingView>
    with WidgetsBindingObserver {
  Map<String, dynamic>? data;
  Map<String, dynamic>? pendingBody;
  String? pendingPath, pendingMethod;
  bool busy = false, history = false, background = false;
  int generation = 0, page = 1;
  String? error;
  final Set<int> consents = {};
  String tr(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
  bool get operator => widget.providerId != null;
  bool get offers => !history && widget.locationId != null && !operator;
  String get path => offers
      ? 'roaming/locations/${widget.locationId}/sandbox-offers'
      : operator
      ? 'ocpi/providers/${widget.providerId}/sandbox-orders'
      : 'roaming/sandbox-orders';
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
  void didUpdateWidget(covariant SandboxRoamingView old) {
    super.didUpdateWidget(old);
    if (old.api != widget.api ||
        old.locationId != widget.locationId ||
        old.providerId != widget.providerId) {
      generation++;
      data = null;
      pendingPath = pendingMethod = null;
      pendingBody = null;
      history = false;
      page = 1;
      consents.clear();
      load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      generation++;
      setState(() {
        background = true;
        data = null;
        consents.clear();
        busy = false;
      });
    } else {
      setState(() => background = false);
      // Reads only: pending writes are never retried on resume.
      load();
    }
  }

  void contract(Map<String, dynamic> row, {bool order = false}) {
    if (row['evidence'] != 'SANDBOX_PARTNER_UNVERIFIED' ||
        row['billing_eligible'] != false ||
        row['live_commands_enabled'] != false ||
        (order &&
            ((row['local_invoice_created'] != false &&
                    !(row['local_invoice_created'] == true &&
                        row['test_invoice_id'] is int &&
                        row['test_invoice_id'] > 0 &&
                        row['test_invoice_evidence'] ==
                            'SIMULATED_CANONICAL_LEDGER' &&
                        row['real_payment_processed'] == false)) ||
                (row['local_invoice_created'] == false &&
                    row['test_invoice_id'] != null) ||
                row['physical_charging_verified'] != false ||
                row['can_stop'] is! bool ||
                row['can_revoke'] is! bool))) {
      throw const FormatException('Unexpected sandbox authority');
    }
  }

  void failed(Object e) {
    error = tr(
      'Sandbox activity unavailable. Review history before another action.',
      'النشاط التجريبي غير متاح. راجع السجل قبل أي إجراء آخر.',
    );
    if (e is ApiException && [401, 403, 404, 419].contains(e.status)) {
      data = null;
      consents.clear();
      if ([401, 419].contains(e.status)) {
        pendingPath = pendingMethod = null;
        pendingBody = null;
        widget.onExpired();
      }
    }
  }

  Future<void> load() async {
    if (background) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
      consents.clear();
    });
    try {
      final result = await widget.api.request(
        path,
        query: offers ? const {} : {'page': '$page'},
      );
      if (!mounted || background || epoch != generation) return;
      if (offers) {
        contract(result);
        if (result['offers'] is! List)
          throw const FormatException('Invalid offers');
      } else {
        if (result['data'] is! List ||
            result['current_page'] is! int ||
            result['last_page'] is! int) {
          throw const FormatException('Invalid sandbox history');
        }
        for (final row in result['data'] as List) {
          contract(Map<String, dynamic>.from(row as Map), order: true);
        }
      }
      setState(() => data = result);
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<bool> confirm(String en, String ar) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(tr('Confirm sandbox request', 'تأكيد الطلب التجريبي')),
          content: Text(tr(en, ar)),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: Text(tr('Cancel', 'إلغاء')),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context, true),
              child: Text(tr('Confirm', 'تأكيد')),
            ),
          ],
        ),
      ) ??
      false;

  Future<void> mutate(
    String target,
    String method,
    Map<String, dynamic> body,
  ) async {
    if (busy || background || pendingPath != null) return;
    pendingPath = target;
    pendingMethod = method;
    pendingBody = Map<String, dynamic>.unmodifiable(body);
    await retry();
  }

  Future<void> retry() async {
    if (busy || background || pendingPath == null || operator) return;
    final epoch = ++generation;
    final target = pendingPath!, method = pendingMethod!, body = pendingBody!;
    setState(() {
      busy = true;
      error = null;
      data = null;
      consents.clear();
    });
    try {
      final row = await widget.api.request(target, method: method, body: body);
      if (!mounted || background || epoch != generation) return;
      contract(row, order: true);
      pendingPath = pendingMethod = null;
      pendingBody = null;
      history = true;
      page = 1;
      await load();
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> reconcile(int id) async {
    if (busy || background) return;
    final epoch = ++generation;
    setState(() {
      busy = true;
      data = null;
      error = null;
    });
    try {
      final row = await widget.api.request(
        'roaming/sandbox-orders/$id/reconcile',
        method: 'POST',
        body: {},
      );
      if (!mounted || background || epoch != generation) return;
      contract(row, order: true);
      await load();
    } catch (e) {
      if (mounted && epoch == generation) setState(() => failed(e));
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final result = data;
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('Sandbox roaming activity', 'نشاط التجوال التجريبي')),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            tr(
              'Sandbox partner evidence is unverified. Acceptance does not prove charging started or stopped. Commands do not create invoices or collect money. A separate approved test-billing consent is required for a simulated invoice.',
              'بيانات الشريك التجريبي غير متحقق منها. قبول الأمر لا يثبت بدء الشحن أو إيقافه. الأوامر لا تُنشئ فواتير ولا تُحصّل مالاً. تتطلب الفاتورة التجريبية موافقة فوترة تجريبية معتمدة ومنفصلة.',
            ),
          ),
          if (background)
            Text(tr('Private view paused.', 'العرض الخاص متوقف مؤقتاً.')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(error!),
          if (pendingPath != null) ...[
            Text(
              tr(
                'Response uncertain. Review history or retry the same request only. Do not create a replacement attempt.',
                'الاستجابة غير مؤكدة. راجع السجل أو أعد نفس الطلب فقط. لا تبدأ محاولة بديلة.',
              ),
            ),
            TextButton(
              onPressed: busy || background ? null : retry,
              child: Text(
                tr('Retry same sandbox request', 'إعادة نفس الطلب التجريبي'),
              ),
            ),
          ],
          TextButton(
            onPressed: busy || background
                ? null
                : () {
                    history = true;
                    page = 1;
                    load();
                  },
            child: Text(
              tr('Review sandbox history', 'مراجعة سجل التجوال التجريبي'),
            ),
          ),
          TextButton(
            onPressed: busy || background ? null : load,
            child: Text(tr('Refresh', 'تحديث')),
          ),
          if (offers && result != null) ...[
            SelectableText(
              '${result['provider_name']} · ${result['location_name']}',
            ),
            if ((result['offers'] as List).isEmpty)
              Text(
                tr(
                  'No fresh approved sandbox offers are available.',
                  'لا توجد عروض تجريبية حديثة ومعتمدة.',
                ),
              ),
            if (result['truncated'] == true)
              Text(
                tr(
                  'The offer limit was reached. Other offers may be omitted.',
                  'عُرض الحد الأقصى للعروض. قد توجد عروض غير معروضة.',
                ),
              ),
            for (var i = 0; i < (result['offers'] as List).length; i++)
              offerCard(
                Map<String, dynamic>.from(result['offers'][i] as Map),
                i,
              ),
          ],
          if (!offers && result != null) ...[
            if ((result['data'] as List).isEmpty)
              Text(tr('No sandbox activity', 'لا يوجد نشاط تجوال تجريبي')),
            for (final row in result['data'] as List)
              orderCard(Map<String, dynamic>.from(row as Map)),
            Text('${result['current_page']} / ${result['last_page']}'),
            Wrap(
              children: [
                TextButton(
                  onPressed: busy || background || page <= 1
                      ? null
                      : () {
                          page--;
                          load();
                        },
                  child: Text(tr('Previous', 'السابق')),
                ),
                TextButton(
                  onPressed:
                      busy || background || page >= (result['last_page'] as int)
                      ? null
                      : () {
                          page++;
                          load();
                        },
                  child: Text(tr('Next', 'التالي')),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  String tariffUnit(String type) => switch (type) {
    'ENERGY' => tr('per kWh', 'لكل كيلوواط ساعة'),
    'TIME' => tr('per charging hour', 'لكل ساعة شحن'),
    'PARKING_TIME' => tr('per parking hour', 'لكل ساعة وقوف'),
    'FLAT' => tr('per session', 'لكل جلسة'),
    _ => tr('Unit unavailable', 'الوحدة غير متاحة'),
  };

  Widget offerCard(Map<String, dynamic> offer, int index) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SelectableText(
            '${offer['evse_uid']} / ${offer['connector_id']} · ${offer['tariff_id']}',
          ),
          // The complete server tariff is disclosed without client economic calculation.
          for (final element in (offer['tariff']['elements'] ?? []) as List)
            for (final price in element['price_components'] as List) ...[
              SelectableText(
                '${tr('Reported tariff', 'التعرفة المبلّغ عنها')}: ${price['price']} ${offer['tariff']['currency']} · ${tariffUnit(price['type'] as String)}',
              ),
              Text(
                '${tr('Billing step', 'خطوة احتساب التعرفة')}: ${price['step_size']} ${price['type'] == 'ENERGY'
                    ? 'Wh'
                    : price['type'] == 'FLAT'
                    ? tr('session', 'جلسة')
                    : tr('seconds', 'ثوانٍ')}',
              ),
              Text(
                '${tr('VAT percent', 'نسبة ضريبة القيمة المضافة')}: ${price['vat'] ?? tr('Not reported', 'غير مبلّغ عنه')}',
              ),
            ],
          CheckboxListTile(
            value: consents.contains(index),
            onChanged: busy || background || pendingPath != null
                ? null
                : (v) => setState(() {
                    if (v == true) {
                      consents.add(index);
                    } else {
                      consents.remove(index);
                    }
                  }),
            title: Text(
              tr(
                'I consent to this location, connector and tariff for this sandbox attempt only. No payment or invoice; physical charging is unverified.',
                'أوافق على هذا الموقع والموصل والتعرفة لهذه التجربة فقط. لا توجد دفعة أو فاتورة، ولا يُستنتج شحن فعلي.',
              ),
            ),
          ),
          FilledButton(
            onPressed:
                busy ||
                    background ||
                    pendingPath != null ||
                    !consents.contains(index)
                ? null
                : () => mutate(
                    'roaming/locations/${widget.locationId}/sandbox-orders',
                    'POST',
                    {
                      'request_key': operationKey(),
                      'evse_uid': offer['evse_uid'],
                      'connector_id': offer['connector_id'],
                      'tariff_id': offer['tariff_id'],
                      'offer_hash': offer['offer_hash'],
                      'terms_version': offer['terms_version'],
                      'consent': true,
                    },
                  ),
            child: Text(tr('Start sandbox roaming', 'بدء التجوال التجريبي')),
          ),
        ],
      ),
    ),
  );

  Widget orderCard(Map<String, dynamic> row) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${row['location_name']} · ${row['evse_uid']} / ${row['connector_id']}',
          ),
          Text('${tr('Order state', 'حالة الطلب')}: ${row['state']}'),
          Text(
            '${tr('Token state', 'حالة رمز التفويض')}: ${row['token_state']}',
          ),
          Text(
            '${tr('Source status', 'حالة المصدر')}: ${row['source_status'] ?? '—'}',
          ),
          Text(
            '${tr('Reported energy Wh', 'الطاقة المبلّغ عنها بالواط ساعة')}: ${row['energy_wh'] ?? '—'}',
          ),
          Text(
            '${tr('Source observed', 'وقت رصد المصدر')}: ${row['source_observed_at'] ?? '—'}',
          ),
          Text(
            '${tr('Reconciliation', 'مطابقة البيانات')}: ${row['reconciliation_state'] ?? '—'}',
          ),
          if (row['reported_cost'] != null)
            SelectableText(
              '${tr('Partner-reported cost — not an invoice', 'تكلفة الشريك المبلّغ عنها — ليست فاتورة')}: ${row['reported_cost']}',
            ),
          for (final command in row['commands'] as List)
            Text(
              '${command['action']} · ${command['state']} · ${command['transport_result'] ?? '—'} · ${command['callback_result'] ?? '—'}',
            ),

          if ((!operator && row['can_preview_test_invoice'] == true) ||
              row['test_invoice_id'] != null)
            TextButton(
              onPressed: busy || background
                  ? null
                  : () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => operator
                            ? InvoicePage(
                                api: widget.api,
                                invoiceId: row['test_invoice_id'] as int,
                                onExpired: widget.onExpired,
                              )
                            : OcpiTestInvoiceView(
                                api: widget.api,
                                orderId: row['id'] as int,
                                onExpired: widget.onExpired,
                              ),
                      ),
                    ),
              child: Text(
                tr(
                  'Review simulated roaming invoice',
                  'مراجعة فاتورة التجوال التجريبية',
                ),
              ),
            ),
          if (!operator) ...[
            TextButton(
              onPressed: busy || background
                  ? null
                  : () => reconcile(row['id'] as int),
              child: Text(
                tr('Refresh sandbox source', 'تحديث بيانات المصدر التجريبي'),
              ),
            ),
            if (row['can_stop'] == true)
              TextButton(
                onPressed: busy || background || pendingPath != null
                    ? null
                    : () async {
                        final agreed = await confirm(
                          'Request a stop? Command acceptance does not prove charging stopped. Wait for source evidence.',
                          'طلب إيقاف؟ قبول الأمر لا يثبت توقف الشحن. انتظر حالة المصدر.',
                        );
                        if (!mounted || !agreed || background) return;
                        await mutate(
                          'roaming/sandbox-orders/${row['id']}/stop',
                          'POST',
                          {'request_key': operationKey()},
                        );
                      },
                child: Text(tr('Request sandbox stop', 'طلب إيقاف تجريبي')),
              ),
            if (row['can_revoke'] == true)
              TextButton(
                onPressed: busy || background || pendingPath != null
                    ? null
                    : () async {
                        final agreed = await confirm(
                          'Revoke authority for new requests? This does not stop active charging.',
                          'إلغاء التفويض لطلبات جديدة؟ هذا لا يوقف الشحن النشط.',
                        );
                        if (!mounted || !agreed || background) return;
                        await mutate(
                          'roaming/sandbox-orders/${row['id']}/authority',
                          'DELETE',
                          {},
                        );
                      },
                child: Text(
                  tr('Revoke sandbox authority', 'إلغاء التفويض التجريبي'),
                ),
              ),
          ],
        ],
      ),
    ),
  );
}
