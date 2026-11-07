import 'package:flutter/material.dart';
import 'api.dart';
import 'invoice.dart';
import 'strings.dart';

class InvoiceHistoryView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  const InvoiceHistoryView({
    super.key,
    required this.api,
    required this.onExpired,
  });
  @override
  State<InvoiceHistoryView> createState() => _InvoiceHistoryViewState();
}

class _InvoiceHistoryViewState extends State<InvoiceHistoryView> {
  final prefix = TextEditingController(), currency = TextEditingController();
  final from = TextEditingController(), to = TextEditingController();
  final form = GlobalKey<FormState>();
  Map<String, String> filters = {};
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false;
  int generation = 0, target = 1;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    generation++;
    prefix.dispose();
    currency.dispose();
    from.dispose();
    to.dispose();
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    final epoch = ++generation;
    target = page;
    setState(() {
      busy = true;
      error = null;
      data = null;
    });
    try {
      final result = ApiPage.fromJson(
        await widget.api.request(
          'finance/invoices',
          query: {...filters, 'page': '$page'},
        ),
        (row) => row,
      );
      if (result.items.any((row) => row['evidence'] != 'SIMULATED'))
        throw const ApiException(409, 'Unsupported finance evidence.');
      if (mounted && epoch == generation) setState(() => data = result);
    } catch (e) {
      if (mounted && epoch == generation) {
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
          return;
        }
        setState(() => error = e);
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  String? dateError(String? value, EvStrings s) {
    if (value == null || value.isEmpty) return null;
    final parsed = DateTime.tryParse(value);
    return RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value) &&
            parsed != null &&
            parsed.year >= 1 &&
            parsed.toIso8601String().startsWith(value)
        ? null
        : s.t('invalidDate');
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data;
    return Scaffold(
      appBar: AppBar(title: Text(s.t('invoiceHistory'))),
      body: RefreshIndicator(
        onRefresh: () => load(page: target),
        child: ListView(
          padding: const EdgeInsets.all(16),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            Text(s.t('invoiceDisclaimer')),
            Form(
              key: form,
              child: Column(
                children: [
                  TextFormField(
                    controller: prefix,
                    decoration: InputDecoration(
                      labelText: s.t('invoicePrefix'),
                    ),
                    maxLength: 80,
                    validator: (v) =>
                        v == null ||
                            v.isEmpty ||
                            RegExp(r'^[A-Za-z0-9-]+$').hasMatch(v)
                        ? null
                        : s.t('invalidInvoicePrefix'),
                  ),
                  TextFormField(
                    controller: currency,
                    decoration: InputDecoration(labelText: s.t('currency')),
                    maxLength: 3,
                    textCapitalization: TextCapitalization.characters,
                    validator: (v) =>
                        v == null ||
                            v.isEmpty ||
                            RegExp(r'^[A-Z]{3}$').hasMatch(v.toUpperCase())
                        ? null
                        : s.t('invalidCurrency'),
                  ),
                  TextFormField(
                    controller: from,
                    decoration: InputDecoration(labelText: s.t('invoiceFrom')),
                    keyboardType: TextInputType.datetime,
                    validator: (v) => dateError(v, s),
                  ),
                  TextFormField(
                    controller: to,
                    decoration: InputDecoration(labelText: s.t('invoiceTo')),
                    keyboardType: TextInputType.datetime,
                    validator: (v) {
                      final invalid = dateError(v, s);
                      if (invalid != null) return invalid;
                      if (v != null &&
                          v.isNotEmpty &&
                          from.text.isNotEmpty &&
                          v.compareTo(from.text) < 0)
                        return s.t('invalidDateRange');
                      return null;
                    },
                  ),
                  FilledButton(
                    onPressed: busy
                        ? null
                        : () {
                            if (form.currentState!.validate()) {
                              filters = {
                                if (prefix.text.isNotEmpty) 'q': prefix.text,
                                if (currency.text.isNotEmpty)
                                  'currency': currency.text.toUpperCase(),
                                if (from.text.isNotEmpty) 'from': from.text,
                                if (to.text.isNotEmpty) 'to': to.text,
                              };
                              load();
                            }
                          },
                    child: Text(s.t('filterInvoices')),
                  ),
                ],
              ),
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null) ...[
              Text(s.t(errorKey(error!))),
              TextButton(
                onPressed: () => load(page: target),
                child: Text(s.t('retry')),
              ),
            ],
            if (page != null) ...[
              if (page.items.isEmpty) Text(s.t('noInvoices')),
              for (final invoice in page.items)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${invoice['number']}',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        Text('${invoice['created_at']}'),
                        Text(
                          s.t('invoiceLine', {
                            'label': s.t('total'),
                            'amount': invoice['total_minor'],
                            'currency': invoice['currency'],
                          }),
                        ),
                        OutlinedButton(
                          onPressed: () async {
                            await Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => InvoicePage(
                                  api: widget.api,
                                  invoiceId: invoice['id'] as int,
                                  onExpired: widget.onExpired,
                                ),
                              ),
                            );
                            if (mounted) load(page: page.current);
                          },
                          child: Text(s.t('viewInvoice')),
                        ),
                      ],
                    ),
                  ),
                ),
              Text(
                s.t('page', {
                  'page': page.current,
                  'last': page.last,
                  'total': page.total,
                }),
              ),
              Wrap(
                spacing: 12,
                children: [
                  OutlinedButton(
                    onPressed: page.current > 1
                        ? () => load(page: page.current - 1)
                        : null,
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: page.current < page.last
                        ? () => load(page: page.current + 1)
                        : null,
                    child: Text(s.t('next')),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
