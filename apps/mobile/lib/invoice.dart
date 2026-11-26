import 'dart:async';
import 'charging_provenance.dart';
import 'package:flutter/material.dart';
import 'api.dart';
import 'unpaid_credit.dart';
import 'money_input.dart';
import 'strings.dart';
import 'financial_documents.dart';
import 'refund_requests.dart';
import 'corporate_billing.dart';
import 'payment_cases.dart';
import 'hosted_checkout.dart';
import 'reservation_times.dart';

/// Canonical invoice details with explicit server-authoritative TEST checkout.
class InvoicePage extends StatefulWidget {
  final EvApi api;
  final int? sessionId;
  final int? invoiceId;
  final VoidCallback onExpired;
  const InvoicePage({
    super.key,
    required this.api,
    this.sessionId,
    this.invoiceId,
    required this.onExpired,
  }) : assert((sessionId == null) != (invoiceId == null));
  @override
  State<InvoicePage> createState() => _InvoicePageState();
}

class _InvoicePageState extends State<InvoicePage>
    with WidgetsBindingObserver {
  Map<String, dynamic>? data;
  Object? error;
  bool busy = false, foreground = true;
  late bool Function() authorized;
  int generation = 0;
  StreamSubscription<int>? sessionSubscription;
  bool current(int n, bool Function() session) =>
      mounted && foreground && authorized() && session() && generation == n;

  void observeSession() {
    final api = widget.api;
    sessionSubscription = api.sessionEvents.listen((_) {
      if (mounted && identical(api, widget.api) && !authorized()) {
        setState(clearPrivate);
      }
    });
  }

  void clearPrivate() {
    generation++;
    data = null;
    error = null;
    busy = false;
  }

  @override
  void initState() {
    super.initState();
    authorized = widget.api.sessionFence();
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    observeSession();
    if (foreground) load();
  }

  @override
  void didUpdateWidget(covariant InvoicePage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.invoiceId != widget.invoiceId ||
        oldWidget.sessionId != widget.sessionId) {
      clearPrivate();
      if (!identical(oldWidget.api, widget.api)) {
        unawaited(sessionSubscription?.cancel());
        authorized = widget.api.sessionFence();
        observeSession();
      }
      // A retained route must not acquire a successor account's credentials.
      // An uncertain session-invoice POST must never be replayed automatically.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (foreground == resumed) return;
    foreground = resumed;
    if (!mounted) return;
    if (!foreground) {
      setState(clearPrivate);
    } else {
      setState(() {});
    }
  }

  @override
  void dispose() {
    generation++;
    unawaited(sessionSubscription?.cancel());
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> load() async {
    if (!foreground || !authorized() || busy) return;
    final session = widget.api.sessionFence();
    final n = ++generation;
    setState(() {
      busy = true;
      error = null;
      data = null;
    });
    try {
      // Issuance is server-idempotent by session, including already issued documents.
      final result = await widget.api.request(
        widget.invoiceId != null
            ? 'finance/invoices/${widget.invoiceId}'
            : 'finance/sessions/${widget.sessionId}/invoice',
        method: widget.invoiceId != null ? 'GET' : 'POST',
      );
      if (!current(n, session)) return;
      // Never create financial action routes from an unverified or partial
      // invoice projection. Real-payment authority cannot come from this
      // simulator-only endpoint.
      final invoice = result is Map ? result['invoice'] : null;
      bool validMinor(Object? value) => value is int && value >= 0;
      bool consistentTotals(Map invoice, Map result) {
        final subtotal = invoice['subtotal_minor'] as int;
        final tax = invoice['tax_minor'] as int;
        final total = invoice['total_minor'] as int;
        final paid = result['paid_minor'] as int;
        final refunded = result['refunded_minor'] as int;
        final credit = result['unpaid_credited_minor'] as int;
        final due = result['amount_due_minor'] as int;
        if (subtotal > total || tax != total - subtotal ||
            paid > total || refunded > paid || credit > total - paid ||
            due != total - paid - credit) return false;
        final state = credit > 0 ? 'credited' : refunded > 0
            ? (refunded == total ? 'refunded' : 'partially_refunded')
            : due == 0 ? 'paid' : paid > 0 ? 'partially_paid' : 'unpaid';
        return state == result['state'];
      }
      bool validTransactions(Object? rows) =>
          rows == null ||
          (rows is List &&
              rows.every((row) =>
                  row is Map &&
                  validMinor(row['amount_minor']) &&
                  row['created_at'] is String &&
                  (row['reason'] == null || row['reason'] is String)));
      bool validCreditNotes(Object? notes) =>
          notes == null ||
          (notes is List &&
              notes.every((note) =>
                  note is Map &&
                  note['id'] is int &&
                  (note['id'] as int) > 0 &&
                  note['credit_note_number'] is String));
      if (result is! Map ||
          invoice is! Map ||
          invoice['evidence'] != 'SIMULATED' ||
          invoice['id'] is! int ||
          (invoice['id'] as int) <= 0 ||
          (widget.invoiceId != null && invoice['id'] != widget.invoiceId) ||
          invoice['charging_session_id'] is! int ||
          (invoice['charging_session_id'] as int) <= 0 ||
          (widget.sessionId != null &&
              invoice['charging_session_id'] != widget.sessionId) ||
          invoice['user_id'] is! int ||
          (invoice['user_id'] as int) <= 0 ||
          invoice['number'] is! String ||
          (invoice['number'] as String).isEmpty ||
          (invoice['number'] as String).length > 100 ||
          invoice['currency'] is! String ||
          !RegExp(r'^[A-Z]{3}$').hasMatch(invoice['currency'] as String) ||
          invoice['minor_units'] is! int ||
          (invoice['minor_units'] as int) < 0 ||
          (invoice['minor_units'] as int) > 3 ||
          !validMinor(invoice['subtotal_minor']) ||
          !validMinor(invoice['tax_minor']) ||
          !validMinor(invoice['total_minor']) ||
          !validMinor(result['paid_minor']) ||
          !validMinor(result['refunded_minor']) ||
          !validMinor(result['amount_due_minor']) ||
          result['ledger_balanced'] is! bool ||
          !const {
            'unpaid',
            'partially_paid',
            'paid',
            'refunded',
            'partially_refunded',
            'credited',
          }.contains(result['state']) ||
          result['payment_provider'] != 'SIMULATOR_ONLY' ||
          result['real_payment_processed'] != false ||
          !validMinor(result['unpaid_credited_minor']) ||
          !validTransactions(result['payments']) ||
          !validTransactions(result['refunds']) ||
          !validCreditNotes(result['unpaid_credit_notes']) ||
          !consistentTotals(invoice, result)) {
        throw const ApiException(409, 'Unsupported finance evidence.');
      }
      if (current(n, session))
        setState(() => data = Map<String, dynamic>.from(result));
    } catch (e) {
      if (!current(n, session)) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        setState(clearPrivate);
        widget.onExpired();
        return;
      }
      setState(() {
        error = e;
        data = null;
      });
    } finally {
      if (current(n, session)) {
        setState(() => busy = false);
      } else if (mounted && n == generation && !authorized()) {
        setState(clearPrivate);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), invoice = data?['invoice'];
    if (!foreground || !authorized()) return Scaffold(
      appBar: AppBar(title: Text(s.t('invoice'))),
      body: Center(child: Text(s.t(foreground ? 'expired' : 'privacyScreenHidden'))),
    );
    final api = widget.api;
    final expired = widget.onExpired;
    final epoch = generation;
    final fence = authorized;
    bool active() => current(epoch, fence) &&
        identical(api, widget.api) && identical(fence, authorized);
    VoidCallback? open(Widget Function() page, {bool refreshOnReturn = false}) {
      if (busy) return null;
      return () async {
        if (!active() || busy) return;
        await Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => active() ? page() : const SizedBox.shrink(),
        ));
        if (refreshOnReturn && active()) await load();
      };
    }
    return Scaffold(
      appBar: AppBar(title: Text(s.t('invoice'))),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(s.t('invoiceDisclaimer')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(s.t(errorKey(error!))),
          TextButton(
            onPressed: busy ? null : () { if (active()) load(); },
            child: Text(s.t('refresh')),
          ),
          if (invoice != null) ...[
            Text(
              '${invoice['number']}',
              style: Theme.of(context).textTheme.headlineMedium,
            ),
            OutlinedButton(
              onPressed: open(() => FinancialDocumentsView(
                          api: api,
                          onExpired: expired,
                          sourceKind: 'charging_invoice',
                          sourceId: invoice['id'] as int),
                    ),
              child: Text(financialText(context, 'title')),
            ),
            if (invoice['minor_units'] is int &&
                invoice['currency'] is String &&
                invoice['user_id'] is int &&
                data!['state'] != 'credited')
              OutlinedButton(
                onPressed: open(() => RefundRequestView(
                            api: api,
                            onExpired: expired,
                            invoiceId: invoice['id'] as int,
                            digits: invoice['minor_units'] as int,
                            currency: invoice['currency'] as String),
                      ),
                child: Text(refundText(context, 'new')),
              ),
            if (data!['state'] != 'credited')
              OutlinedButton(
                onPressed: open(() => CorporateBillingView(
                            api: api,
                            onExpired: expired,
                            invoiceId: invoice['id'] as int),
                      ),
                child: Text(corporateText(context, 'title')),
              ),
            if (data!['state'] != 'credited')
              OutlinedButton(
                onPressed: open(() => CreatePaymentCaseView(
                            api: api,
                            onExpired: expired,
                            invoiceId: invoice['id'] as int),
                      ),
                child: Text(caseText(context, 'new')),
              ),
            if (data!['state'] != 'credited')
              OutlinedButton(
                onPressed: open(() => HostedCheckoutView(
                            api: api,
                            onExpired: expired,
                            scope: 'charging',
                            invoiceId: invoice['id'] as int,
                            sourceOwnerId: invoice['user_id'] as int?),
                      ),
                child: Text(hostedText(context, 'title')),
              ),
            for (final entry in {
              'subtotal': invoice['subtotal_minor'],
              'tax': invoice['tax_minor'],
              'total': invoice['total_minor'],
              'paid': data!['paid_minor'],
              'refunded': data!['refunded_minor'],
              'due': data!['amount_due_minor'],
            }.entries)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${s.t(entry.key)}: \u2068${minorDecimal(entry.value as int, invoice['minor_units'] as int)} ${invoice['currency']}\u2069',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    // Retain exact recorded minor units for source review.
                    Text(
                      s.t('invoiceLine', {
                        'label': s.t(entry.key),
                        'amount': entry.value,
                        'currency': invoice['currency'],
                      }),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            OutlinedButton(
              onPressed: open(() => ChargingProvenanceView(
                          api: api,
                          invoiceId: invoice['id'] as int,
                          onExpired: expired),
                    ),
              child: Text(
                Localizations.localeOf(context).languageCode == 'ar'
                    ? 'أدلة مصدر الشحن'
                    : 'Charging source evidence',
              ),
            ),
            if (data!['can_review_unpaid_credit'] == true)
              OutlinedButton(
                onPressed: open(() => UnpaidCreditView(
                  api: api,
                  invoiceId: invoice['id'] as int,
                  onExpired: expired,
                ), refreshOnReturn: true),
                child: Text(
                  Localizations.localeOf(context).languageCode == 'ar'
                      ? 'تسوية الفاتورة غير المدفوعة والتحقق'
                      : 'Unpaid invoice credit & recovery',
                ),
              ),
            if ((data!['unpaid_credited_minor'] ?? 0) > 0)
              Text(
                '${Localizations.localeOf(context).languageCode == 'ar' ? 'تسوية غير نقدية' : 'Noncash credit'}: ${minorDecimal(data!['unpaid_credited_minor'] as int, invoice['minor_units'] as int)} ${invoice['currency']}',
              ),
            for (final note in data!['unpaid_credit_notes'] ?? [])
              OutlinedButton(
                onPressed: open(() => FinancialDocumentsView(
                            api: api,
                            onExpired: expired,
                            sourceKind: 'charging_unpaid_credit',
                            sourceId: note['id'] as int),
                      ),
                child: Text('${note['credit_note_number']}'),
              ),
            for (final kind in ['payments', 'refunds']) ...[
              Text(s.t(kind), style: Theme.of(context).textTheme.titleMedium),
              if ((data![kind] as List? ?? []).isEmpty)
                Text(s.t('noTransactions')),
              for (final row in (data![kind] as List? ?? [])) ...[
                Text(
                  '\u2068${minorDecimal(row['amount_minor'] as int, invoice['minor_units'] as int)} ${invoice['currency']}\u2069',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                Text(
                  s.t('invoiceTransaction', {
                    'amount': row['amount_minor'],
                    'currency': invoice['currency'],
                    'time': invoiceUtcTime(context, row['created_at']),
                  }),
                ),
                if (kind == 'refunds') Text('${row['reason'] ?? ''}'),
              ],
            ],
            Chip(
              label: Text(
                s.t(
                  data!['ledger_balanced'] == true
                      ? 'ledgerBalanced'
                      : 'ledgerUnbalanced',
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
