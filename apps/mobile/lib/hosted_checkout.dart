import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';
import 'api.dart';
import 'strings.dart';
import 'invoice.dart';
import 'subscriptions.dart';
import 'corporate_billing.dart';
import 'financial_operation_store.dart';
import 'vendor_finance.dart' show nativeFinancialAmount;

const hostedEn = {
  'title': 'Hosted checkout',
  'corporateTitle': 'Corporate hosted collection',
  'corporateHistory': 'Corporate payment history',
  'corporateNotice':
      'TEST company collection only. The original statement, payer and line snapshot remain authoritative. Returning from a browser never proves payment or a bank transfer.',
  'corporateOwnerOnly':
      'Only the original statement payer who still owns the company may authorize collection.',
  'corporateConsent':
      'I reviewed this original company statement and authorize this TEST checkout. The backend controls its exact amount and taxes.',
  'statementSource': 'Source statement',
  'statementDetails': 'Review statement and private documents',
  'history': 'Hosted payment history',
  'notice':
      'TEST checkout only. Card details stay with the provider. Returning to this app never confirms payment. Only the authenticated backend/provider state below is authoritative.',
  'empty': 'No hosted checkouts.',
  'refresh': 'Refresh backend status',
  'inspect': 'Reconcile provider status',
  'retryPreparation': 'Retry original provider session preparation',
  'create': 'Create or recover checkout',
  'consent':
      'I reviewed this invoice and authorize this TEST checkout. The amount and taxes come only from the server.',
  'openProvider': 'Continue with TEST provider',
  'capture': 'Confirm capture',
  'cancel': 'Cancel existing checkout',
  'confirm': 'Confirm',
  'dismiss': 'Back',
  'captureNotice':
      'Capture this exact authorized TEST payment? An uncertain outcome must be inspected, never replaced.',
  'cancelNotice':
      'Cancel this existing checkout? The provider must confirm cancellation; returning or closing the browser alone does not cancel payment.',
  'pending':
      'The original request identity is saved privately. Explicit retry uses that same identity and payload; no automatic replacement or payment replay.',
  'resume':
      'Back from provider: refresh or reconcile the existing checkout. No callback or link proves success.',
  'ownerOnly':
      'Associated invoice checkout: read only. Only its customer can authorize operations.',
  'source': 'Source invoice',
  'sourceDetails': 'Review invoice, receipts and financial history',
  'amount': 'Server amount',
  'expires': 'Provider session expiry',
  'canonical': 'Canonical payment state',
  'next': 'Next',
  'previous': 'Previous',
  'unavailable':
      'No approved hosted link is currently available. Inspect the existing outcome; do not create a replacement.',
  'mock':
      'Synthetic provider test controls. These actions simulate a provider result; no card authentication or real money is verified.',
  'mockAuthorize': 'Simulate authorization',
  'mockDecline': 'Simulate decline',
  'mockAbandon': 'Simulate abandonment',
  'terminal':
      'This checkout is closed. Review the authoritative invoice and recovery history before any further action.',
  'preparing': 'Preparing',
  'open': 'Awaiting provider authentication',
  'uncertain': 'Outcome uncertain',
  'authorized': 'Authorized, awaiting capture',
  'captured': 'TEST capture recorded',
  'expired': 'Expired',
  'cancelled': 'Cancelled',
  'needs_review': 'Investigation required',
  'failed': 'Failed',
  'invalid':
      'Unsupported or inconsistent payment source. Refresh and contact support; do not start another payment.',
  'hidden': 'Private checkout details were cleared while the app was inactive.',
  'retry': 'Start a new attempt after confirmed closure',
  'retryNotice':
      'The backend reports this original attempt closed. Create a distinct attempt only after checking the authoritative invoice? Never replace an uncertain attempt.',
};
const hostedAr = {
  'title': 'الدفع عبر موقع المزود',
  'corporateTitle': 'التحصيل المستضاف للشركة',
  'corporateHistory': 'سجل مدفوعات الشركة',
  'corporateNotice':
      'تحصيل اختباري للشركة فقط. يظل الكشف الأصلي والدافع ولقطة البنود هي المرجع. لا تثبت العودة من المتصفح نجاح الدفع أو التحويل المصرفي.',
  'corporateOwnerOnly':
      'الدافع الأصلي للكشف الذي لا يزال مالك المؤسسة وحده يستطيع تفويض التحصيل.',
  'corporateConsent':
      'راجعت كشف الشركة الأصلي وأفوض عملية الدفع الاختبارية هذه. يتحكم الخادم في المبلغ والضرائب الدقيقة.',
  'statementSource': 'الكشف الأصلي',
  'statementDetails': 'مراجعة الكشف والمستندات الخاصة',
  'history': 'سجل الدفع عبر المزود',
  'notice':
      'دفع اختباري فقط. تبقى بيانات البطاقة لدى المزود. لا تؤكد العودة للتطبيق نجاح الدفع؛ الحالة المؤكدة لدى الخادم والمزود أدناه هي المرجع.',
  'empty': 'لا توجد عمليات دفع عبر المزود.',
  'refresh': 'تحديث حالة الخادم',
  'inspect': 'مطابقة حالة المزود',
  'retryPreparation': 'استئناف إعداد جلسة المزود الأصلية',
  'create': 'إنشاء أو استعادة الدفع',
  'consent':
      'راجعت الفاتورة وأفوض عملية الدفع الاختبارية هذه. يحدد الخادم المبلغ والضرائب حصراً.',
  'openProvider': 'المتابعة مع مزود الاختبار',
  'capture': 'تأكيد التحصيل',
  'cancel': 'إلغاء عملية الدفع الحالية',
  'confirm': 'تأكيد',
  'dismiss': 'رجوع',
  'captureNotice':
      'تحصيل هذه الدفعة الاختبارية المفوضة نفسها؟ يجب فحص النتيجة غير المؤكدة ولا يجوز استبدالها.',
  'cancelNotice':
      'إلغاء عملية الدفع الحالية؟ يجب أن يؤكد المزود الإلغاء؛ العودة أو إغلاق المتصفح لا يلغي الدفع تلقائياً.',
  'pending':
      'حُفظ معرف الطلب الأصلي بشكل خاص. تستخدم إعادة المحاولة الصريحة المعرف والبيانات نفسيهما، دون استبدال أو إعادة دفع تلقائية.',
  'resume':
      'عدت من المزود: حدّث أو طابق العملية الحالية. لا تثبت الروابط أو إشعارات العودة نجاح الدفع.',
  'ownerOnly':
      'عملية لفاتورة مرتبطة: للقراءة فقط. عميل الفاتورة وحده يستطيع تفويض الإجراءات.',
  'source': 'الفاتورة الأصلية',
  'sourceDetails': 'مراجعة الفاتورة والإيصالات والسجل المالي',
  'amount': 'المبلغ المحدد من الخادم',
  'expires': 'انتهاء جلسة المزود',
  'canonical': 'حالة الدفع الأصلية',
  'next': 'التالي',
  'previous': 'السابق',
  'unavailable':
      'لا يتوفر رابط معتمد الآن. افحص النتيجة الحالية ولا تنشئ عملية بديلة.',
  'mock':
      'أدوات مزود اصطناعي للاختبار. تحاكي الإجراءات نتيجة المزود، ولا تثبت توثيق بطاقة أو حركة أموال حقيقية.',
  'mockAuthorize': 'محاكاة التفويض',
  'mockDecline': 'محاكاة الرفض',
  'mockAbandon': 'محاكاة مغادرة الدفع',
  'terminal':
      'أُغلقت هذه العملية. راجع الفاتورة الأصلية وسجل المطابقة قبل اتخاذ إجراء آخر.',
  'preparing': 'جارٍ الإعداد',
  'open': 'بانتظار توثيق المزود',
  'uncertain': 'النتيجة غير مؤكدة',
  'authorized': 'مفوّض وبانتظار التحصيل',
  'captured': 'تحصيل اختباري مسجل',
  'expired': 'منتهٍ',
  'cancelled': 'ملغى',
  'needs_review': 'يتطلب تحقيقاً',
  'failed': 'فشل',
  'invalid':
      'مصدر دفع غير مدعوم أو غير متسق. حدّث واتصل بالدعم ولا تبدأ دفعة بديلة.',
  'hidden': 'مُسحت تفاصيل الدفع الخاصة عند مغادرة التطبيق.',
  'retry': 'بدء محاولة جديدة بعد تأكيد الإغلاق',
  'retryNotice':
      'يؤكد الخادم إغلاق المحاولة الأصلية. إنشاء محاولة مستقلة بعد مراجعة الفاتورة الأصلية؟ لا تستبدل محاولة غير مؤكدة.',
};
String hostedText(BuildContext c, String k) =>
    (Localizations.localeOf(c).languageCode == 'ar' ? hostedAr : hostedEn)[k] ??
    k;

/// Return links are untrusted wake-up signals. They carry no financial identity,
/// status, secret or authority; only the current authenticated record is read.
class HostedPaymentReturnEvents extends ChangeNotifier {
  void signal() => notifyListeners();
}

class HostedPaymentReturnBridge {
  static const channel = MethodChannel('com.creabiz.ev/payment_return');
  static final changes = HostedPaymentReturnEvents();
  static bool started = false;
  static Future<void> start() async {
    if (!started) {
      started = true;
      channel.setMethodCallHandler((call) async {
        if (call.method == 'hostedReturn') await consume();
      });
    }
    await consume();
  }

  static Future<void> consume() async {
    try {
      if (await channel.invokeMethod<bool>('consumeReturn') == true)
        changes.signal();
    } on PlatformException {
      // A platform wake-up failure cannot authorize or mutate any payment.
    } on MissingPluginException {
      /* Lifecycle GET recovery also works without this optional native signal. */
    }
  }
}

bool approvedHostedPaymentUrl(Uri u) =>
    u.scheme == 'https' &&
    u.host == 'checkout.stripe.com' &&
    u.userInfo.isEmpty &&
    (!u.hasPort || u.port == 443) &&
    !u.hasQuery &&
    RegExp(r'^/c/pay/cs_test_[A-Za-z0-9]+$').hasMatch(u.path);

class HostedCheckoutRecord {
  final Map<String, dynamic> source;
  HostedCheckoutRecord(dynamic raw, {bool corporate = false})
    : source = Map<String, dynamic>.from(raw as Map) {
    for (final k in [
      'id',
      'user_id',
      if (!corporate) 'invoice_id',
      'canonical_checkout_id',
    ]) {
      if (source[k] is! int || source[k] < 1)
        throw const FormatException('Invalid checkout identity.');
    }
    if (corporate) {
      if (source['scope'] != 'corporate' ||
          source['statement_id'] is! int ||
          source['statement_id'] < 1 ||
          source['payer_id'] != source['user_id'] ||
          source['invoice_id'] != null ||
          source['subscription_id'] != null ||
          source['canonical_checkout_id'] != source['id'] ||
          source['line_fingerprint'] is! String ||
          !RegExp(r'^[a-f0-9]{64}$').hasMatch(source['line_fingerprint']) ||
          source['owner'] is! bool)
        throw const FormatException('Invalid corporate collection identity.');
    } else {
      if (!['charging', 'subscription'].contains(source['scope']) ||
          source['scope'] == 'subscription' &&
              (source['subscription_id'] is! int ||
                  source['subscription_id'] < 1) ||
          source['scope'] == 'charging' && source['subscription_id'] != null)
        throw const FormatException('Invalid billable scope.');
    }
    if (![
          'preparing',
          'open',
          'uncertain',
          'authorized',
          'captured',
          'expired',
          'cancelled',
          'needs_review',
          'failed',
        ].contains(source['status']) ||
        source['canonical_status'] is! String ||
        !['STRIPE_TEST_CONTRACT', 'MOCK_HOSTED'].contains(source['evidence']) ||
        source['real_money_moved'] != false ||
        source['external_provider_verified'] != false)
      throw const FormatException('Unsupported checkout evidence.');
    if ((source['status'] == 'captured' &&
            source['canonical_status'] != 'captured') ||
        (source['status'] == 'authorized' &&
            source['canonical_status'] != 'authorized'))
      throw const FormatException('Contradictory canonical payment state.');
    if (source['amount_minor'] is! int ||
        source['amount_minor'] < 1 ||
        source['amount_minor'] > 9007199254740991 ||
        source['minor_units'] is! int ||
        source['minor_units'] < 0 ||
        source['minor_units'] > 3 ||
        source['currency'] is! String ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(source['currency']))
      throw const FormatException('Invalid server amount.');
    if (source['expires_at'] != null &&
        (source['expires_at'] is! String ||
            DateTime.tryParse(source['expires_at']) == null))
      throw const FormatException('Invalid checkout expiry.');
    if (source['allowed_actions'] is! List ||
        (source['allowed_actions'] as List).any(
          (a) => ![
            'inspect',
            'capture',
            'cancel',
            'simulate',
            'retry',
            'retry_preparation',
          ].contains(a),
        ))
      throw const FormatException('Unsupported payment action.');
    final url = source['hosted_url'];
    if (url != null &&
        (url is! String ||
            source['evidence'] != 'STRIPE_TEST_CONTRACT' ||
            !approvedHostedPaymentUrl(Uri.parse(url))))
      throw const FormatException('Unapproved hosted payment URL.');
  }
  int get id => source['id'];
  String get status => source['status'];
  bool permits(String action) => action == 'simulate'
      ? source['can_simulate'] == true &&
            source['evidence'] == 'MOCK_HOSTED' &&
            status == 'open'
      : action == 'retry'
      ? source['can_retry'] == true && ['expired', 'cancelled'].contains(status)
      : (source['allowed_actions'] as List).contains(action);
  Uri? get hosted =>
      source['hosted_url'] == null ? null : Uri.parse(source['hosted_url']);
}

class HostedCheckoutView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final String? scope;
  final int? invoiceId, subscriptionId, checkoutId, sourceOwnerId, statementId;
  final FinancialOperationStore? store;
  final Future<bool> Function(Uri)? openProvider;
  const HostedCheckoutView({
    super.key,
    required this.api,
    required this.onExpired,
    this.scope,
    this.invoiceId,
    this.subscriptionId,
    this.checkoutId,
    this.sourceOwnerId,
    this.statementId,
    this.store,
    this.openProvider,
  }) : assert(
         scope == 'corporate'
             ? statementId != null &&
                   invoiceId == null &&
                   subscriptionId == null
             : statementId == null && ((scope == null) == (invoiceId == null)),
       );
  bool get corporate => scope == 'corporate';
  int? get billableId => corporate ? statementId : invoiceId;
  @override
  State<HostedCheckoutView> createState() => _HostedState();
}

class _HostedState extends State<HostedCheckoutView>
    with WidgetsBindingObserver {
  int owner = 0, generation = 0, page = 1, last = 1;
  int? selected;
  List<HostedCheckoutRecord>? records;
  HostedCheckoutRecord? record;
  Map<String, dynamic>? statementSource;
  FinancialOperation? creation;
  final operations = <String, FinancialOperation>{};
  bool busy = true,
      consent = false,
      hidden = false,
      returned = false,
      retryDraft = false;
  Object? error;
  bool Function()? actor;
  FinancialOperationStore get store =>
      widget.store ?? DeviceFinancialOperationStore();
  String get endpoint => widget.corporate
      ? 'commercial/corporate/hosted-checkouts'
      : 'commercial/hosted-checkouts';
  HostedCheckoutRecord parse(dynamic raw) =>
      HostedCheckoutRecord(raw, corporate: widget.corporate);
  bool contextMatches(HostedCheckoutRecord row) =>
      widget.billableId == null ||
      (widget.corporate
          ? row.source['scope'] == 'corporate' &&
                row.source['statement_id'] == widget.statementId
          : row.source['scope'] == widget.scope &&
                row.source['invoice_id'] == widget.invoiceId &&
                row.source['subscription_id'] == widget.subscriptionId);
  bool ownRecord(HostedCheckoutRecord row) =>
      row.source['user_id'] == owner &&
      (!widget.corporate || row.source['owner'] == true);
  void verifyStatement(HostedCheckoutRecord row) {
    if (!widget.corporate) return;
    final quote = statementSource;
    if (quote == null ||
        row.source['statement_id'] != quote['id'] ||
        row.source['payer_id'] != quote['payer_id'] ||
        row.source['amount_minor'] != quote['total_minor'] ||
        row.source['currency'] != quote['currency'] ||
        row.source['minor_units'] != quote['minor_units'] ||
        row.source['line_fingerprint'] != quote['line_fingerprint'])
      throw const FormatException('Statement checkout source mismatch.');
  }

  Future<Map<String, dynamic>> loadStatement() async {
    final data = await widget.api.request(
      'commercial/corporate/statements/${widget.statementId}',
    );
    final quote = Map<String, dynamic>.from(data['statement'] as Map);
    if (data['real_payment_processed'] != false ||
        quote['id'] != widget.statementId ||
        quote['payer_id'] is! int ||
        quote['payer_id'] < 1 ||
        quote['total_minor'] is! int ||
        quote['total_minor'] < 1 ||
        quote['total_minor'] > 9007199254740991 ||
        quote['currency'] is! String ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(quote['currency']) ||
        quote['minor_units'] is! int ||
        quote['minor_units'] < 0 ||
        quote['minor_units'] > 3 ||
        data['can_collect_hosted'] is! bool ||
        data['line_fingerprint'] is! String ||
        !RegExp(r'^[a-f0-9]{64}$').hasMatch(data['line_fingerprint']) ||
        data['lines'] is! List)
      throw const FormatException('Invalid corporate statement source.');
    return {
      ...quote,
      'line_fingerprint': data['line_fingerprint'],
      'can_collect_hosted': data['can_collect_hosted'],
    };
  }

  String key(String suffix) =>
      '${widget.api.base.origin}:$owner:${widget.corporate ? 'corporate-hosted' : 'hosted'}:$suffix';
  @override
  void initState() {
    super.initState();
    selected = widget.checkoutId;
    WidgetsBinding.instance.addObserver(this);
    HostedPaymentReturnBridge.changes.addListener(returnSignal);
    HostedPaymentReturnBridge.start();
    initialize();
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    HostedPaymentReturnBridge.changes.removeListener(returnSignal);
    super.dispose();
  }

  bool get current => mounted && (actor?.call() ?? true);
  bool get top => ModalRoute.of(context)?.isCurrent ?? true;
  void returnSignal() {
    if (current && top) {
      returned = true;
      if (!busy) load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && mounted) {
      generation++;
      setState(() {
        record = null;
        records = null;
        statementSource = null;
        busy = false;
        hidden = true;
      });
    } else if (current && top) {
      HostedPaymentReturnBridge.consume();
      returned = true;
      if (owner == 0) {
        initialize();
      } else {
        load();
      }
    }
  }

  void fail(Object e) {
    error = e;
    record = null;
    records = null;
    statementSource = null;
    if (e is ApiException && [401, 419].contains(e.status)) widget.onExpired();
  }

  Future<void> initialize() async {
    actor = widget.api.sessionFence();
    final epoch = ++generation;
    try {
      final me = await widget.api.request('me');
      if (!current || epoch != generation) return;
      if (me['id'] is! int || !['customer', 'vendor'].contains(me['role']))
        throw const ApiException(403, 'Unsupported mobile actor.');
      owner = me['id'];
      if (widget.billableId != null &&
          (widget.sourceOwnerId == null || widget.sourceOwnerId == owner)) {
        creation = FinancialOperation(
          store,
          key(
            widget.corporate
                ? 'corporate:create:statement:${widget.statementId}'
                : 'create:${widget.scope}:${widget.invoiceId}',
          ),
          isCurrent: actor,
        );
        await creation!.restore();
      }
      if (current && epoch == generation) await load();
    } catch (e) {
      if (current && epoch == generation) setState(() => fail(e));
    } finally {
      if (current && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> load({int? number}) async {
    if (owner == 0) return;
    final epoch = ++generation;
    if (number != null) page = number;
    setState(() {
      busy = true;
      error = null;
      record = null;
      records = null;
      hidden = false;
      retryDraft = false;
    });
    try {
      if (widget.corporate) {
        final quote = await loadStatement();
        if (!current || epoch != generation) return;
        statementSource = quote;
        if (selected != null) {
          final raw = await widget.api.request(
            endpoint,
            query: {'statement_id': '${widget.statementId}', 'page': '$page'},
          );
          final history = ApiPage.fromJson(raw, (r) => parse(r));
          for (final row in history.items) {
            if (!contextMatches(row))
              throw const FormatException('Statement history mismatch.');
            verifyStatement(row);
          }
          if (!current || epoch != generation) return;
          records = history.items;
          last = history.last;
        }
      }
      if (selected != null) {
        final r = parse(
          (await widget.api.request('$endpoint/$selected'))['checkout'],
        );
        if (r.id != selected || !contextMatches(r))
          throw const FormatException('Wrong checkout identity.');
        verifyStatement(r);
        if (!current || epoch != generation) return;
        record = r;
      } else {
        final raw = await widget.api.request(
          endpoint,
          query: {
            'page': '$page',
            if (!widget.corporate && widget.scope != null)
              'scope': widget.scope!,
            if (widget.corporate) 'statement_id': '${widget.statementId}',
            if (widget.invoiceId != null) 'invoice_id': '${widget.invoiceId}',
          },
        );
        final p = ApiPage.fromJson(raw, (r) => parse(r));
        if (p.items.any((r) => !contextMatches(r)))
          throw const FormatException('Checkout filter mismatch.');
        for (final row in p.items) {
          verifyStatement(row);
        }
        if (!current || epoch != generation) return;
        records = p.items;
        last = p.last;
        if (widget.billableId != null && p.items.isNotEmpty) {
          selected = p.items.first.id;
          record = p.items.first;
        }
      }
    } catch (e) {
      if (current && epoch == generation) fail(e);
    } finally {
      if (current && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> create() async {
    if (busy || !consent || creation == null || record != null) return;
    final epoch = generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final body = await creation!.prepare({
        if (widget.corporate) 'statement_id': widget.statementId,
        if (!widget.corporate) 'scope': widget.scope,
        if (!widget.corporate) 'invoice_id': widget.invoiceId,
        if (widget.scope == 'subscription')
          'subscription_id': widget.subscriptionId,
        'consent': true,
      });
      if (!current || epoch != generation) return;
      final r = parse(
        (await widget.api.request(
          endpoint,
          method: 'POST',
          body: body,
        ))['checkout'],
      );
      if (!ownRecord(r) || !contextMatches(r))
        throw const FormatException('Wrong checkout acknowledgement.');
      verifyStatement(r);
      if (!current || epoch != generation) return;
      selected = r.id;
      record = r;
      if (widget.corporate)
        records = [r, ...?records?.where((old) => old.id != r.id)];
      retryDraft = false;
      consent = false;
      // Retain creation identity while the financial session exists. An uncertain
      // or lost provider/browser outcome can never allocate a replacement key.
    } catch (e) {
      if (current && epoch == generation) fail(e);
    } finally {
      if (current && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> retryClosed() async {
    final before = record;
    if (busy ||
        creation == null ||
        before == null ||
        !ownRecord(before) ||
        !before.permits('retry'))
      return;
    if (!await confirm('retryNotice') || !current || record?.id != before.id)
      return;
    // Re-read the existing source before releasing any saved creation identity.
    // Backend create also rechecks absence of an unresolved predecessor.
    final epoch = generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final fresh = parse(
        (await widget.api.request('$endpoint/${before.id}'))['checkout'],
      );
      if (!current || epoch != generation) return;
      verifyStatement(fresh);
      if (fresh.id != before.id || !ownRecord(fresh) || !fresh.permits('retry'))
        throw const ApiException(
          409,
          'Original checkout is not safely closed.',
        );
      await creation!.confirmed();
      if (!current || epoch != generation) return;
      setState(() {
        selected = null;
        record = null;
        if (!widget.corporate) records = [];
        retryDraft = widget.corporate;
        consent = false;
      });
    } catch (e) {
      if (current && epoch == generation) fail(e);
    } finally {
      if (current && epoch == generation) setState(() => busy = false);
    }
  }

  Future<bool> confirm(String text) async =>
      await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          content: Text(hostedText(c, text)),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: Text(hostedText(c, 'dismiss')),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: Text(hostedText(c, 'confirm')),
            ),
          ],
        ),
      ) ??
      false;
  Future<void> action(String name, {String? outcome}) async {
    final before = record;
    if (busy || before == null || !ownRecord(before) || !before.permits(name))
      return;
    if (['capture', 'cancel'].contains(name) && !await confirm('${name}Notice'))
      return;
    if (name == 'simulate' && !await confirm('mock')) return;
    if (!current || record?.id != before.id) return;
    final epoch = generation;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      Map<String, dynamic> body = {};
      FinancialOperation? intent;
      if (['capture', 'cancel'].contains(name)) {
        intent = operations.putIfAbsent(
          '${before.id}:$name',
          () => FinancialOperation(
            store,
            key('${before.id}:$name'),
            isCurrent: actor,
          ),
        );
        await intent.restore();
        body = await intent.prepare({});
      } else if (name == 'simulate') {
        body = {'outcome': outcome, 'consent': true};
      }
      if (!current || epoch != generation) return;
      final r = parse(
        (await widget.api.request(
          '$endpoint/${before.id}/${name == 'retry_preparation' ? 'retry-preparation' : name}',
          method: 'POST',
          body: body,
        ))['checkout'],
      );
      if (r.id != before.id ||
          r.source['user_id'] != before.source['user_id'] ||
          r.source['scope'] != before.source['scope'] ||
          r.source['invoice_id'] != before.source['invoice_id'] ||
          r.source['amount_minor'] != before.source['amount_minor'] ||
          r.source['currency'] != before.source['currency'] ||
          r.source['minor_units'] != before.source['minor_units'])
        throw const FormatException('Payment acknowledgement mismatch.');
      verifyStatement(r);
      if (!current || epoch != generation) return;
      record = r;
      if (widget.corporate && records != null)
        records = records!.map((old) => old.id == r.id ? r : old).toList();
      if (intent != null &&
          !['uncertain', 'needs_review', 'preparing'].contains(r.status))
        await intent.confirmed();
    } catch (e) {
      if (current && epoch == generation) fail(e);
    } finally {
      if (current && epoch == generation) setState(() => busy = false);
    }
  }

  Future<void> openProvider() async {
    final before = record, u = before?.hosted;
    if (busy ||
        before == null ||
        !ownRecord(before) ||
        u == null ||
        !approvedHostedPaymentUrl(u))
      return;
    final expiry = DateTime.tryParse('${before.source['expires_at']}');
    if (before.status != 'open' ||
        expiry == null ||
        !expiry.isAfter(DateTime.now().toUtc())) {
      setState(
        () =>
            error = const ApiException(409, 'Inspect expired hosted session.'),
      );
      return;
    }
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final ok =
          await (widget.openProvider?.call(u) ??
              launchUrl(u, mode: LaunchMode.externalApplication));
      if (!ok) throw const ApiException(503, 'Provider browser unavailable.');
    } catch (e) {
      if (current) setState(() => error = e);
    } finally {
      if (current) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext c) {
    String t(String k) => hostedText(
      c,
      widget.corporate
          ? (const {
                  'title': 'corporateTitle',
                  'history': 'corporateHistory',
                  'notice': 'corporateNotice',
                  'consent': 'corporateConsent',
                  'source': 'statementSource',
                  'sourceDetails': 'statementDetails',
                  'ownerOnly': 'corporateOwnerOnly',
                }[k] ??
                k)
          : k,
    );
    final r = record;
    final own = r != null && ownRecord(r);
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t(
            widget.billableId == null && selected == null ? 'history' : 'title',
          ),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(t('notice')),
          const SizedBox(height: 16),
          OutlinedButton(
            key: const Key('hosted-refresh'),
            onPressed: busy ? null : () => load(),
            child: Text(t('refresh')),
          ),
          if (busy) const Center(child: CircularProgressIndicator()),
          if (error != null)
            Text(
              error is FormatException
                  ? t('invalid')
                  : EvStrings.of(c).t(errorKey(error!)),
              key: const Key('hosted-error'),
            ),
          if (hidden) Text(t('hidden')),
          if (returned) Text(t('resume')),
          if (creation?.pending != null && r?.status != 'captured')
            Text(t('pending')),
          if (widget.corporate &&
              statementSource != null &&
              statementSource!['can_collect_hosted'] != true &&
              r == null)
            Text(t('ownerOnly')),
          if (widget.sourceOwnerId != null && widget.sourceOwnerId != owner)
            Text(t('ownerOnly')),
          if (creation != null &&
              widget.billableId != null &&
              (!widget.corporate ||
                  statementSource?['can_collect_hosted'] == true) &&
              r == null &&
              records != null &&
              (records!.isEmpty || retryDraft) &&
              error == null) ...[
            Text('${t('source')}: #${widget.billableId}'),
            if (widget.corporate && statementSource != null)
              Text(nativeFinancialAmount(c, statementSource!, 'total_minor')),
            CheckboxListTile(
              key: const Key('hosted-consent'),
              value: consent,
              onChanged: busy ? null : (v) => setState(() => consent = v!),
              title: Text(t('consent')),
            ),
            FilledButton(
              key: const Key('hosted-create'),
              onPressed: busy || !consent ? null : create,
              child: Text(t('create')),
            ),
          ],
          if (r != null)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      '#${r.id} · ${t(r.status)}',
                      style: Theme.of(c).textTheme.headlineSmall,
                    ),
                    Text(
                      '${t('source')}: ${r.source['scope']} #${widget.corporate ? r.source['statement_id'] : r.source['invoice_id']}',
                    ),
                    Text(
                      '${t('amount')}: ${nativeFinancialAmount(c, r.source, 'amount_minor')}',
                      style: Theme.of(c).textTheme.titleLarge,
                    ),
                    Text(
                      '${t('canonical')}: ${t('${r.source['canonical_status']}')}',
                    ),
                    Text('${r.source['evidence']}'),
                    OutlinedButton(
                      key: const Key('hosted-source'),
                      onPressed: busy
                          ? null
                          : () async {
                              await Navigator.of(c).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => widget.corporate
                                      ? CorporateStatementView(
                                          api: widget.api,
                                          onExpired: widget.onExpired,
                                          id: r.source['statement_id'] as int,
                                        )
                                      : r.source['scope'] == 'charging'
                                      ? InvoicePage(
                                          api: widget.api,
                                          invoiceId:
                                              r.source['invoice_id'] as int,
                                          onExpired: widget.onExpired,
                                        )
                                      : SubscriptionDetailView(
                                          api: widget.api,
                                          id:
                                              r.source['subscription_id']
                                                  as int,
                                          onExpired: widget.onExpired,
                                        ),
                                ),
                              );
                              if (current && top) await load();
                            },
                      child: Text(t('sourceDetails')),
                    ),
                    Text('${t('expires')}: ${r.source['expires_at'] ?? '—'}'),
                    if (!own) Text(t('ownerOnly')),
                    if (own) ...[
                      if (creation != null && r.permits('retry'))
                        OutlinedButton(
                          key: const Key('hosted-retry'),
                          onPressed: busy ? null : retryClosed,
                          child: Text(t('retry')),
                        ),
                      if (r.hosted != null && r.status == 'open')
                        FilledButton(
                          key: const Key('hosted-provider'),
                          onPressed: busy ? null : openProvider,
                          child: Text(t('openProvider')),
                        ),
                      if (r.hosted == null &&
                          r.source['evidence'] == 'STRIPE_TEST_CONTRACT' &&
                          r.status == 'open')
                        Text(t('unavailable')),
                      if (r.permits('inspect'))
                        OutlinedButton(
                          key: const Key('hosted-inspect'),
                          onPressed: busy ? null : () => action('inspect'),
                          child: Text(t('inspect')),
                        ),
                      if (r.permits('retry_preparation') &&
                          r.source['can_retry_preparation'] == true)
                        OutlinedButton(
                          key: const Key('hosted-retry-preparation'),
                          onPressed: busy
                              ? null
                              : () => action('retry_preparation'),
                          child: Text(t('retryPreparation')),
                        ),
                      for (final name in ['capture', 'cancel'])
                        if (r.permits(name))
                          OutlinedButton(
                            key: Key('hosted-$name'),
                            onPressed: busy ? null : () => action(name),
                            child: Text(t(name)),
                          ),
                      if (r.source['evidence'] == 'MOCK_HOSTED' &&
                          r.permits('simulate')) ...[
                        Text(t('mock')),
                        for (final pair in [
                          ('authorized', 'mockAuthorize'),
                          ('declined', 'mockDecline'),
                          ('abandoned', 'mockAbandon'),
                        ])
                          OutlinedButton(
                            onPressed: busy
                                ? null
                                : () => action('simulate', outcome: pair.$1),
                            child: Text(t(pair.$2)),
                          ),
                      ],
                    ],
                    if ([
                      'expired',
                      'cancelled',
                      'captured',
                      'failed',
                    ].contains(r.status))
                      Text(t('terminal')),
                  ],
                ),
              ),
            ),
          if (widget.corporate && records != null) ...[
            const SizedBox(height: 16),
            Text(t('history'), style: Theme.of(c).textTheme.titleMedium),
            if (records!.isEmpty) Text(t('empty')),
            for (final item in records!)
              ListTile(
                key: Key('corporate-hosted-history-${item.id}'),
                selected: selected == item.id,
                title: Text('#${item.id} · ${t(item.status)}'),
                subtitle: Text(
                  nativeFinancialAmount(c, item.source, 'amount_minor'),
                ),
                onTap: busy
                    ? null
                    : () {
                        selected = item.id;
                        load();
                      },
              ),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: busy || page <= 1
                      ? null
                      : () => load(number: page - 1),
                  child: Text(t('previous')),
                ),
                Text('$page / $last'),
                OutlinedButton(
                  onPressed: busy || page >= last
                      ? null
                      : () => load(number: page + 1),
                  child: Text(t('next')),
                ),
              ],
            ),
          ],
          if (r == null && records != null && widget.billableId == null) ...[
            if (records!.isEmpty) Text(t('empty')),
            for (final item in records!)
              ListTile(
                title: Text('#${item.id} · ${t(item.status)}'),
                subtitle: Text(
                  nativeFinancialAmount(c, item.source, 'amount_minor'),
                ),
                trailing: const Icon(Icons.chevron_right),
                onTap: busy
                    ? null
                    : () => Navigator.of(c).push(
                        MaterialPageRoute<void>(
                          builder: (_) => HostedCheckoutView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            checkoutId: item.id,
                            scope: widget.corporate ? 'corporate' : null,
                            statementId: widget.statementId,
                            store: widget.store,
                            openProvider: widget.openProvider,
                          ),
                        ),
                      ),
              ),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: busy || page <= 1
                      ? null
                      : () => load(number: page - 1),
                  child: Text(t('previous')),
                ),
                Text('$page / $last'),
                OutlinedButton(
                  onPressed: busy || page >= last
                      ? null
                      : () => load(number: page + 1),
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
