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
  bool current(int n, bool Function() session) =>
      mounted && foreground && authorized() && session() && generation == n;

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
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void didUpdateWidget(covariant InvoicePage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.invoiceId != widget.invoiceId ||
        oldWidget.sessionId != widget.sessionId) {
      clearPrivate();
      authorized = widget.api.sessionFence();
      // An uncertain session-invoice POST must never be replayed automatically.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (mounted && !foreground) setState(clearPrivate);
  }

  @override
  void dispose() {
    generation++;
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
      if (result is! Map ||
          invoice is! Map ||
          invoice['evidence'] != 'SIMULATED' ||
          invoice['id'] is! int ||
          (invoice['id'] as int) <= 0 ||
          (widget.invoiceId != null && invoice['id'] != widget.invoiceId) ||
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
          (result['payments'] != null && result['payments'] is! List) ||
          (result['refunds'] != null && result['refunds'] is! List) ||
          (result['unpaid_credit_notes'] != null &&
              result['unpaid_credit_notes'] is! List)) {
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
      if (current(n, session)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), invoice = data?['invoice'];
    if (!authorized()) return Scaffold(
      appBar: AppBar(title: Text(s.t('invoice'))),
      body: Center(child: Text(s.t('expired'))),
    );
    return Scaffold(
      appBar: AppBar(title: Text(s.t('invoice'))),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(s.t('invoiceDisclaimer')),
          if (busy) const LinearProgressIndicator(),
          if (error != null) Text(s.t(errorKey(error!))),
          TextButton(
            onPressed: busy ? null : load,
            child: Text(s.t('refresh')),
          ),
          if (invoice != null) ...[
            Text(
              '${invoice['number']}',
              style: Theme.of(context).textTheme.headlineMedium,
            ),
            OutlinedButton(
              onPressed: busy
                  ? null
                  : () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => FinancialDocumentsView(
                          api: widget.api,
                          onExpired: widget.onExpired,
                          sourceKind: 'charging_invoice',
                          sourceId: invoice['id'] as int,
                        ),
                      ),
                    ),
              child: Text(financialText(context, 'title')),
            ),
            if (invoice['minor_units'] is int &&
                invoice['currency'] is String &&
                invoice['user_id'] is int &&
                data!['state'] != 'credited')
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => RefundRequestView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            invoiceId: invoice['id'] as int,
                            digits: invoice['minor_units'] as int,
                            currency: invoice['currency'] as String,
                          ),
                        ),
                      ),
                child: Text(refundText(context, 'new')),
              ),
            if (data!['state'] != 'credited')
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => CorporateBillingView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            invoiceId: invoice['id'] as int,
                          ),
                        ),
                      ),
                child: Text(corporateText(context, 'title')),
              ),
            if (data!['state'] != 'credited')
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => CreatePaymentCaseView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            invoiceId: invoice['id'] as int,
                          ),
                        ),
                      ),
                child: Text(caseText(context, 'new')),
              ),
            if (data!['state'] != 'credited')
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => HostedCheckoutView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            scope: 'charging',
                            invoiceId: invoice['id'] as int,
                            sourceOwnerId: invoice['user_id'] as int?,
                          ),
                        ),
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
                child: Text(
                  s.t('invoiceLine', {
                    'label': s.t(entry.key),
                    'amount': entry.value,
                    'currency': invoice['currency'],
                  }),
                ),
              ),
            OutlinedButton(
              onPressed: busy
                  ? null
                  : () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => ChargingProvenanceView(
                          api: widget.api,
                          invoiceId: invoice['id'] as int,
                          onExpired: widget.onExpired,
                        ),
                      ),
                    ),
              child: Text(
                Localizations.localeOf(context).languageCode == 'ar'
                    ? 'أدلة مصدر الشحن'
                    : 'Charging source evidence',
              ),
            ),
            if (data!['can_review_unpaid_credit'] == true)
              OutlinedButton(
                onPressed: busy
                    ? null
                    : () async {
                        final current = widget.api.sessionFence();
                        await Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => UnpaidCreditView(
                              api: widget.api,
                              invoiceId: invoice['id'] as int,
                              onExpired: widget.onExpired,
                            ),
                          ),
                        );
                        if (mounted && current()) await load();
                      },
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
                onPressed: busy
                    ? null
                    : () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => FinancialDocumentsView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            sourceKind: 'charging_unpaid_credit',
                            sourceId: note['id'] as int,
                          ),
                        ),
                      ),
                child: Text('${note['credit_note_number']}'),
              ),
            for (final kind in ['payments', 'refunds']) ...[
              Text(s.t(kind), style: Theme.of(context).textTheme.titleMedium),
              if ((data![kind] as List? ?? []).isEmpty)
                Text(s.t('noTransactions')),
              for (final row in (data![kind] as List? ?? [])) ...[
                Text(
                  s.t('invoiceTransaction', {
                    'amount': row['amount_minor'],
                    'currency': invoice['currency'],
                    'time': '${row['created_at']}',
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
