import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';

/// Read-only simulated accounting; no checkout, card collection or payout UI.
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
