import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'api.dart';
import 'financial_operation_store.dart';
import 'strings.dart';
import 'noncash_capabilities.dart';

String couponRequestBinding(String code, int owner, String key) {
  String digest(String value) => sha256.convert(utf8.encode(value)).toString();
  return digest('${digest(code)}:${digest('coupon-claim:$owner:$key')}');
}

bool validCouponCode(String code) =>
    RegExp(r'^[A-Z0-9-]{12,40}$').hasMatch(code);

class CouponClaimRecord {
  final int id;
  final NoncashReceiptEvidence provenance;
  final String name, arabicName, status, claimedAt, requestBinding;
  const CouponClaimRecord._(
    this.id,
    this.name,
    this.arabicName,
    this.status,
    this.claimedAt,
    this.requestBinding,
    this.provenance,
  );
  factory CouponClaimRecord.parse(dynamic value, {String? expectedBinding}) {
    bool identifier(dynamic v) => v is int && v > 0 && v <= 9007199254740991;
    bool name(dynamic v) => v is String && v.isNotEmpty && v.length <= 300;
    if (value is! Map ||
        ![
          'id',
          'coupon_id',
          'redemption_id',
        ].every((key) => identifier(value[key])) ||
        !name(value['reward_name']) ||
        !name(value['reward_name_ar']) ||
        !['pending', 'fulfilled', 'rejected'].contains(value['status']) ||
        value['points_cost'] is! int ||
        value['points_cost'] != 0 ||
        value['cash_value'] != false ||
        value['fulfillment'] != 'MANUAL_NON_MONETARY' ||
        value['source'] != 'COUPON' ||
        value['delivery_verified'] != false ||
        value['claimed_at'] is! String ||
        !RegExp(
          r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$',
        ).hasMatch(value['claimed_at']) ||
        DateTime.tryParse(value['claimed_at']) == null ||
        value['request_binding'] is! String ||
        !RegExp(r'^[a-f0-9]{64}$').hasMatch(value['request_binding']) ||
        expectedBinding != null && value['request_binding'] != expectedBinding)
      throw const FormatException('Unsupported coupon receipt.');
    return CouponClaimRecord._(
      value['id'],
      value['reward_name'],
      value['reward_name_ar'],
      value['status'],
      value['claimed_at'],
      value['request_binding'],
      NoncashReceiptEvidence.parse(value),
    );
  }
}

/// API consumer only. Canonical eligibility, stock and fulfillment stay on server.
class CustomerCouponsView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final FinancialOperationStore? store;
  const CustomerCouponsView({
    super.key,
    required this.api,
    required this.onExpired,
    this.store,
  });
  @override
  State<CustomerCouponsView> createState() => _CouponsState();
}

class _CouponsState extends State<CustomerCouponsView>
    with WidgetsBindingObserver {
  final code = TextEditingController();
  late FinancialOperationStore store;
  late bool Function() ownerFence;
  FinancialOperation? operation;
  NoncashCapabilities? capabilities;
  List<CouponClaimRecord> records = [];
  int actor = 0, generation = 0, page = 1, last = 1;
  bool busy = false, foreground = true, needsRestore = false;
  String? message;
  String tr(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  @override
  void initState() {
    super.initState();
    store = widget.store ?? DeviceFinancialOperationStore();
    ownerFence = widget.api.sessionFence();
    WidgetsBinding.instance.addObserver(this);
    initialize();
  }

  @override
  void didUpdateWidget(covariant CustomerCouponsView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api) ||
        !identical(oldWidget.store, widget.store)) {
      generation++;
      clearPrivate();
      ownerFence = widget.api.sessionFence();
      store = widget.store ?? DeviceFinancialOperationStore();
    }
  }

  @override
  void dispose() {
    generation++;
    code.clear();
    code.dispose();
    records = [];
    operation = null;
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void clearPrivate() {
    records = [];
    code.clear();
    operation = null;
    capabilities = null;
    actor = 0;
    busy = false;
    message = null;
    needsRestore = true;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    generation++;
    if (mounted)
      setState(() {
        foreground = state == AppLifecycleState.resumed;
        clearPrivate();
      });
  }

  bool current(int run) =>
      mounted && foreground && run == generation && ownerFence();
  bool validSaved(FinancialOperation op) {
    final saved = op.pending;
    if (saved == null) return true;
    final body = saved['body'];
    return saved['key'] is String &&
        RegExp(
          r'^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$',
        ).hasMatch(saved['key']) &&
        body is Map &&
        body.length == 1 &&
        body['code'] is String &&
        validCouponCode(body['code']);
  }

  Future<ApiPage<CouponClaimRecord>> readPage(int number) async {
    final raw = await widget.api.request(
      'commercial/coupons/claims',
      query: {'page': '$number'},
    );
    final result = ApiPage.fromJson(raw, (r) => CouponClaimRecord.parse(r));
    if (result.current != number ||
        result.current < 1 ||
        result.current > 100000 ||
        result.last < 1 ||
        result.last > 100000 ||
        result.total < 0 ||
        result.total > 2500000 ||
        result.items.length > 25 ||
        result.items.length > result.total ||
        result.items.map((r) => r.id).toSet().length != result.items.length)
      throw const FormatException('Unsupported coupon history.');
    return result;
  }

  void failure(Object e, int run) {
    if (!mounted || !foreground || run != generation) return;
    if (e is ApiException &&
        (e.endsCurrentSession ||
            ownerFence() && [401, 419].contains(e.status))) {
      setState(clearPrivate);
      widget.onExpired();
      return;
    }
    if (!ownerFence()) return;
    setState(() {
      records = [];
      message = e is ApiException && e.status == 429
          ? 'limited'
          : 'unconfirmed';
      needsRestore = true;
    });
  }

  Future<void> initialize() async {
    if (busy || !foreground || !ownerFence()) return;
    final run = ++generation, api = widget.api;
    setState(() {
      busy = true;
      message = null;
      records = [];
      code.clear();
      actor = 0;
      operation = null;
      capabilities = null;
    });
    try {
      final me = await api.request('me');
      if (!current(run)) return;
      if (me is! Map ||
          me['id'] is! int ||
          me['id'] < 1 ||
          me['id'] > 9007199254740991 ||
          me['role'] != 'customer')
        throw const ApiException(403, 'Customer account required.');
      actor = me['id'];
      final boundActor = actor, fence = ownerFence;
      final op = FinancialOperation(
        store,
        '${api.base.origin}:$actor:coupon-claim:v1',
        isCurrent: () =>
            mounted &&
            foreground &&
            identical(api, widget.api) &&
            fence() &&
            actor == boundActor,
      );
      await op.restore();
      if (!current(run)) return;
      if (!validSaved(op))
        throw const FormatException('Unrecognized saved coupon request.');
      operation = op;
      if (op.pending != null) code.text = op.pending!['body']['code'];
      final capability = NoncashCapabilities.parse(
        await api.request('commercial/noncash/status'),
      );
      if (!current(run)) return;
      capabilities = capability;
      final history = await readPage(1);
      if (!current(run)) return;
      setState(() {
        records = history.items;
        page = history.current;
        last = history.last;
        needsRestore = false;
      });
    } catch (e) {
      failure(e, run);
    } finally {
      if (mounted && foreground && run == generation)
        setState(() => busy = false);
    }
  }

  Future<void> history(int number) async {
    if (busy ||
        !foreground ||
        !ownerFence() ||
        actor == 0 ||
        number < 1 ||
        number > 100000)
      return;
    final run = ++generation;
    setState(() {
      busy = true;
      records = [];
      message = null;
    });
    try {
      final result = await readPage(number);
      if (!current(run)) return;
      setState(() {
        records = result.items;
        page = result.current;
        last = result.last;
      });
    } catch (e) {
      failure(e, run);
    } finally {
      if (mounted && foreground && run == generation)
        setState(() => busy = false);
    }
  }

  Future<void> claim({bool inspect = false}) async {
    final op = operation;
    if (busy ||
        !foreground ||
        !ownerFence() ||
        actor == 0 ||
        op == null ||
        needsRestore)
      return;
    if (!inspect && capabilities?.enabled != true) {
      setState(() => message = 'disabled');
      return;
    }
    final entered = code.text.trim().toUpperCase();
    if (!inspect && op.pending == null && !validCouponCode(entered)) {
      setState(() => message = 'invalid');
      return;
    }
    if (inspect && op.pending == null) return;
    final run = ++generation;
    setState(() {
      busy = true;
      records = [];
      message = null;
    });
    try {
      Map<String, dynamic> request;
      if (inspect) {
        request = {'idempotency_key': op.pending!['key']};
      } else {
        final original = op.pending == null
            ? entered
            : op.pending!['body']['code'] as String;
        request = await op.prepare({'code': original});
      }
      if (!current(run)) return;
      final pending = op.pending!;
      final expected = couponRequestBinding(
        pending['body']['code'] as String,
        actor,
        pending['key'] as String,
      );
      final result = await widget.api.request(
        'commercial/coupons/${inspect ? 'claim-receipt' : 'claim'}',
        method: 'POST',
        body: request,
      );
      if (!current(run)) return;
      if (result is! Map || !result.containsKey('claim'))
        throw const FormatException('Missing coupon confirmation.');
      if (result['claim'] == null) {
        setState(() => message = 'unknown');
        return;
      }
      final receipt = CouponClaimRecord.parse(
        result['claim'],
        expectedBinding: expected,
      );
      await op.confirmed();
      if (!current(run)) return;
      setState(() {
        records = [receipt];
        code.clear();
        page = 1;
        last = 1;
        message = 'confirmed';
      });
    } catch (e) {
      failure(e, run);
    } finally {
      if (mounted && foreground && run == generation)
        setState(() => busy = false);
    }
  }

  Future<void> retire() async {
    final op = operation, runBeforeDialog = generation;
    if (busy ||
        needsRestore ||
        op?.pending == null ||
        !foreground ||
        !ownerFence())
      return;
    final confirmed =
        await showDialog<bool>(
          context: context,
          builder: (c) => AlertDialog(
            title: Text(
              tr('Resolve saved coupon request', 'حل طلب القسيمة المحفوظ'),
            ),
            content: Text(
              tr(
                'Ask the server to permanently cancel this exact unaccepted request. If it was already accepted, its existing reward receipt is recovered instead. Closing this dialog does nothing.',
                'اطلب من الخادم إلغاء هذا الطلب غير المقبول نفسه نهائياً. إذا قُبل بالفعل، يُستعاد إيصال المكافأة الموجود بدلاً من إلغائه. إغلاق النافذة لا ينفذ شيئاً.',
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(c, false),
                child: Text(tr('Keep request', 'الاحتفاظ بالطلب')),
              ),
              FilledButton(
                key: const Key('coupon-retire-confirm'),
                onPressed: () => Navigator.pop(c, true),
                child: Text(
                  tr('Resolve this exact request', 'حل هذا الطلب نفسه'),
                ),
              ),
            ],
          ),
        ) ??
        false;
    if (!confirmed || !current(runBeforeDialog) || !identical(operation, op))
      return;
    final pending = op!.pending!, run = ++generation;
    final expected = couponRequestBinding(
      pending['body']['code'] as String,
      actor,
      pending['key'] as String,
    );
    setState(() {
      busy = true;
      message = null;
      records = [];
    });
    try {
      final result = await widget.api.request(
        'commercial/coupons/claim-intents/cancel',
        method: 'POST',
        body: {
          'code': pending['body']['code'],
          'idempotency_key': pending['key'],
        },
      );
      if (!current(run)) return;
      if (result is! Map ||
          !['accepted', 'cancelled'].contains(result['state']) ||
          result['request_binding'] != expected ||
          !result.containsKey('claim'))
        throw const FormatException('Unconfirmed coupon cancellation.');
      CouponClaimRecord? receipt;
      if (result['state'] == 'accepted') {
        receipt = CouponClaimRecord.parse(
          result['claim'],
          expectedBinding: expected,
        );
      } else if (result['claim'] != null) {
        throw const FormatException('Conflicting coupon cancellation.');
      }
      await op.confirmed();
      if (!current(run)) return;
      setState(() {
        records = receipt == null ? [] : [receipt];
        code.clear();
        page = 1;
        last = 1;
        message = receipt == null ? 'cancelled' : 'confirmed';
      });
    } catch (e) {
      failure(e, run);
    } finally {
      if (mounted && foreground && run == generation)
        setState(() => busy = false);
    }
  }

  String messageText(String value) => switch (value) {
    'invalid' => tr(
      'Enter the12–40character coupon code you received.',
      'أدخل رمز القسيمة الذي تلقيته من١٢ إلى٤٠حرفاً.',
    ),
    'limited' => tr(
      'Too many requests. Wait before explicitly refreshing.',
      'طلبات كثيرة. انتظر قبل التحديث الصريح.',
    ),
    'confirmed' => tr(
      'Claim recorded. Fulfillment requires separate manual review; no delivery is verified.',
      'سُجلت المطالبة. يتطلب التسليم مراجعة يدوية منفصلة؛ لم يُتحقق من التسليم.',
    ),
    'disabled' => tr(
      'New coupon claims are disabled. Existing receipts and saved-request cancellation remain available.',
      'المطالبات الجديدة بالقسائم معطلة. تبقى الإيصالات الموجودة وإلغاء الطلب المحفوظ متاحين.',
    ),
    'cancelled' => tr(
      'Server permanently retired the unaccepted request. A new coupon draft may now be entered.',
      'أنهى الخادم الطلب غير المقبول نهائياً. يمكن الآن إدخال مسودة قسيمة جديدة.',
    ),
    'unknown' => tr(
      'No receipt is recorded yet. Keep this exact saved request; checking does not cancel an in-flight claim.',
      'لم يُسجل إيصال بعد. احتفظ بهذا الطلب المحفوظ نفسه؛ لا يلغي التحقق مطالبة قيد التنفيذ.',
    ),
    _ => tr(
      'Outcome unconfirmed or coupons unavailable. Explicitly restore and check the saved request before resending it. Its code and identity remain locked.',
      'النتيجة غير مؤكدة أو القسائم غير متاحة. استعد الطلب المحفوظ وتحقق منه صراحةً قبل إعادة إرساله. يظل الرمز والمعرف ثابتين.',
    ),
  };
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(tr('Reward coupons', 'قسائم المكافآت'))),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          tr(
            'Noncash reward coupons. Each receipt identifies sandbox or reviewed-manual evidence. No cash value, transfer, charging discount or verified delivery. Only the server confirms eligibility, stock and manual fulfillment.',
            'قسائم مكافآت غير نقدية. يحدد كل إيصال دليلاً تجريبياً أو يدوياً مراجعاً. لا قيمة نقدية ولا تحويل ولا خصم شحن ولا تسليم متحقق. الخادم وحده يؤكد الأهلية والمخزون والتسليم اليدوي.',
          ),
        ),
        if (!foreground || !ownerFence())
          Text(
            tr(
              'Private coupons hidden. Return to your account to continue.',
              'القسائم الخاصة مخفية. ارجع إلى حسابك للمتابعة.',
            ),
          )
        else ...[
          if (busy) const LinearProgressIndicator(),
          if (capabilities?.enabled == false) Text(messageText('disabled')),
          if (message != null)
            Semantics(liveRegion: true, child: Text(messageText(message!))),
          OutlinedButton(
            key: const Key('coupon-restore'),
            onPressed: busy ? null : initialize,
            child: Text(
              tr('Restore and refresh coupons', 'استعادة القسائم وتحديثها'),
            ),
          ),
          if (actor != 0 && operation != null) ...[
            TextField(
              key: const Key('coupon-code'),
              controller: code,
              enabled: !busy && !needsRestore && operation!.pending == null,
              maxLength: 40,
              autocorrect: false,
              enableSuggestions: false,
              enableIMEPersonalizedLearning: false,
              textDirection: TextDirection.ltr,
              decoration: InputDecoration(
                labelText: tr('Coupon code', 'رمز القسيمة'),
              ),
            ),
            if (operation!.pending != null)
              Text(
                tr(
                  'A saved request awaits confirmation. It cannot be replaced or discarded while its outcome is unknown.',
                  'طلب محفوظ ينتظر التأكيد. لا يمكن استبداله أو تجاهله ما دامت نتيجته مجهولة.',
                ),
              ),
            FilledButton(
              key: const Key('coupon-claim'),
              onPressed: busy || needsRestore || capabilities?.enabled != true
                  ? null
                  : () => claim(),
              child: Text(
                operation!.pending == null
                    ? tr('Claim coupon', 'المطالبة بالقسيمة')
                    : tr('Resume the same claim', 'استئناف المطالبة نفسها'),
              ),
            ),
            if (operation!.pending != null)
              OutlinedButton(
                key: const Key('coupon-inspect'),
                onPressed: busy || needsRestore
                    ? null
                    : () => claim(inspect: true),
                child: Text(
                  tr(
                    'Check saved claim receipt',
                    'التحقق من إيصال المطالبة المحفوظة',
                  ),
                ),
              ),
            if (operation!.pending != null)
              TextButton(
                key: const Key('coupon-retire'),
                onPressed: busy || needsRestore ? null : retire,
                child: Text(tr('Resolve saved request', 'حل الطلب المحفوظ')),
              ),
            for (final row in records)
              ListTile(
                title: Text(
                  EvStrings.of(context).arabic ? row.arabicName : row.name,
                ),
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      row.provenance.mode == 'reviewed_manual'
                          ? tr(
                              'Reviewed manual noncash record',
                              'سجل غير نقدي يدوي مراجع',
                            )
                          : tr('Sandbox test record', 'سجل تجريبي'),
                    ),
                    Text(
                      '#${row.id} · ${row.claimedAt}',
                      textDirection: TextDirection.ltr,
                    ),
                  ],
                ),
                trailing: Text(switch (row.status) {
                  'fulfilled' => tr('Fulfilled record', 'سجل تسليم'),
                  'rejected' => tr('Rejected', 'مرفوض'),
                  _ => tr(
                    'Pending manual fulfillment',
                    'بانتظار التسليم اليدوي',
                  ),
                }),
              ),
            if (page > 1)
              TextButton(
                key: const Key('coupon-previous'),
                onPressed: busy ? null : () => history(page - 1),
                child: Text(tr('Previous', 'السابق')),
              ),
            if (page < last)
              TextButton(
                key: const Key('coupon-next'),
                onPressed: busy ? null : () => history(page + 1),
                child: Text(tr('Next', 'التالي')),
              ),
          ],
        ],
      ],
    ),
  );
}
