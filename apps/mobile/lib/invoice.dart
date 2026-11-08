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

class _InvoicePageState extends State<InvoicePage> {
  Map<String, dynamic>? data;
  Object? error;
  bool busy = false;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      // Issuance is server-idempotent by session, including already issued documents.
      final result = await widget.api.request(
        widget.invoiceId != null
            ? 'finance/invoices/${widget.invoiceId}'
            : 'finance/sessions/${widget.sessionId}/invoice',
        method: widget.invoiceId != null ? 'GET' : 'POST',
      );
      if (result['invoice']?['evidence'] != 'SIMULATED' ||
          result['payment_provider'] != 'SIMULATOR_ONLY' ||
          result['real_payment_processed'] != false) {
        throw const ApiException(409, 'Unsupported finance evidence.');
      }
      if (mounted) setState(() => data = Map<String, dynamic>.from(result));
    } catch (e) {
      if (mounted && e is ApiException && [401, 419].contains(e.status))
        widget.onExpired();
      if (mounted)
        setState(() {
          error = e;
          data = null;
        });
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), invoice = data?['invoice'];
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
