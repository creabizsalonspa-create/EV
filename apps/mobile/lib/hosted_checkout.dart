import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';
import 'api.dart';
import 'strings.dart';
import 'invoice.dart';
import 'subscriptions.dart';
import 'financial_operation_store.dart';
import 'vendor_finance.dart' show nativeFinancialAmount;

const hostedEn = {
  'title': 'Hosted checkout',
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
  HostedCheckoutRecord(dynamic raw)
    : source = Map<String, dynamic>.from(raw as Map) {
    for (final k in ['id', 'user_id', 'invoice_id', 'canonical_checkout_id']) {
      if (source[k] is! int || source[k] < 1)
        throw const FormatException('Invalid checkout identity.');
    }
    if (!['charging', 'subscription'].contains(source['scope']) ||
        source['scope'] == 'subscription' &&
            (source['subscription_id'] is! int ||
                source['subscription_id'] < 1) ||
        source['scope'] == 'charging' && source['subscription_id'] != null)
      throw const FormatException('Invalid billable scope.');
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
  final int? invoiceId, subscriptionId, checkoutId, sourceOwnerId;
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
    this.store,
    this.openProvider,
  }) : assert((scope == null) == (invoiceId == null));
  @override
  State<HostedCheckoutView> createState() => _HostedState();
}

class _HostedState extends State<HostedCheckoutView>
    with WidgetsBindingObserver {
  int owner = 0, generation = 0, page = 1, last = 1;
  int? selected;
  List<HostedCheckoutRecord>? records;
  HostedCheckoutRecord? record;
  FinancialOperation? creation;
  final operations = <String, FinancialOperation>{};
  bool busy = true, consent = false, hidden = false, returned = false;
  Object? error;
  bool Function()? actor;
  FinancialOperationStore get store =>
      widget.store ?? DeviceFinancialOperationStore();
  String key(String suffix) =>
      '${widget.api.base.origin}:$owner:hosted:$suffix';
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
      if (widget.invoiceId != null &&
          (widget.sourceOwnerId == null || widget.sourceOwnerId == owner)) {
        creation = FinancialOperation(
          store,
          key('create:${widget.scope}:${widget.invoiceId}'),
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
    });
    try {
      if (selected != null) {
        final r = HostedCheckoutRecord(
          (await widget.api.request(
            'commercial/hosted-checkouts/$selected',
          ))['checkout'],
        );
        if (r.id != selected ||
            (widget.invoiceId != null &&
                (r.source['scope'] != widget.scope ||
                    r.source['invoice_id'] != widget.invoiceId ||
                    r.source['subscription_id'] != widget.subscriptionId)))
          throw const FormatException('Wrong checkout identity.');
        if (!current || epoch != generation) return;
        record = r;
      } else {
        final raw = await widget.api.request(
          'commercial/hosted-checkouts',
          query: {
            'page': '$page',
            if (widget.scope != null) 'scope': widget.scope!,
            if (widget.invoiceId != null) 'invoice_id': '${widget.invoiceId}',
          },
        );
        final p = ApiPage.fromJson(raw, (r) => HostedCheckoutRecord(r));
        if (widget.invoiceId != null &&
            p.items.any(
              (r) =>
                  r.source['scope'] != widget.scope ||
                  r.source['invoice_id'] != widget.invoiceId ||
                  r.source['subscription_id'] != widget.subscriptionId,
            ))
          throw const FormatException('Checkout filter mismatch.');
        if (!current || epoch != generation) return;
        records = p.items;
        last = p.last;
        if (widget.invoiceId != null && p.items.isNotEmpty) {
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
        'scope': widget.scope,
        'invoice_id': widget.invoiceId,
        if (widget.scope == 'subscription')
          'subscription_id': widget.subscriptionId,
        'consent': true,
      });
      if (!current || epoch != generation) return;
      final r = HostedCheckoutRecord(
        (await widget.api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: body,
        ))['checkout'],
      );
      if (r.source['user_id'] != owner ||
          r.source['scope'] != widget.scope ||
          r.source['invoice_id'] != widget.invoiceId ||
          r.source['subscription_id'] != widget.subscriptionId)
        throw const FormatException('Wrong checkout acknowledgement.');
      if (!current || epoch != generation) return;
      selected = r.id;
      record = r;
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
        before.source['user_id'] != owner ||
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
      final fresh = HostedCheckoutRecord(
        (await widget.api.request(
          'commercial/hosted-checkouts/${before.id}',
        ))['checkout'],
      );
      if (!current || epoch != generation) return;
      if (fresh.id != before.id ||
          fresh.source['user_id'] != owner ||
          !fresh.permits('retry'))
        throw const ApiException(
          409,
          'Original checkout is not safely closed.',
        );
      await creation!.confirmed();
      if (!current || epoch != generation) return;
      setState(() {
        selected = null;
        record = null;
        records = [];
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
    if (busy ||
        before == null ||
        before.source['user_id'] != owner ||
        !before.permits(name))
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
      final r = HostedCheckoutRecord(
        (await widget.api.request(
          'commercial/hosted-checkouts/${before.id}/${name == 'retry_preparation' ? 'retry-preparation' : name}',
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
      if (!current || epoch != generation) return;
      record = r;
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
        before.source['user_id'] != owner ||
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
    String t(String k) => hostedText(c, k);
    final r = record;
    final own = r?.source['user_id'] == owner;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          t(widget.invoiceId == null && selected == null ? 'history' : 'title'),
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
          if (widget.sourceOwnerId != null && widget.sourceOwnerId != owner)
            Text(t('ownerOnly')),
          if (creation != null &&
              widget.invoiceId != null &&
              r == null &&
              records != null &&
              records!.isEmpty &&
              error == null) ...[
            Text('${t('source')}: #${widget.invoiceId}'),
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
                      '${t('source')}: ${r.source['scope']} #${r.source['invoice_id']}',
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
                                  builder: (_) =>
                                      r.source['scope'] == 'charging'
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
          if (r == null && records != null && widget.invoiceId == null) ...[
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
