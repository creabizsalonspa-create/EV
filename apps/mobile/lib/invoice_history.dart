import 'dart:async';

import 'package:flutter/material.dart';
import 'api.dart';
import 'invoice.dart';
import 'reservation_times.dart';
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

class _InvoiceHistoryViewState extends State<InvoiceHistoryView>
    with WidgetsBindingObserver {
  final prefix = TextEditingController(), currency = TextEditingController();
  final from = TextEditingController(), to = TextEditingController();
  final form = GlobalKey<FormState>();
  Map<String, String> filters = {};
  ApiPage<Map<String, dynamic>>? data;
  Object? error;
  bool busy = false;
  int generation = 0, target = 1;
  late bool Function() authorized;
  StreamSubscription<int>? sessionChanges;
  bool foreground = true;
  bool current(int n, bool Function() session) =>
      mounted && foreground && authorized() && session() && n == generation;

  void clearPrivate() {
    generation++;
    data = null;
    error = null;
    busy = false;
    target = 1;
    filters = {};
    prefix.clear();
    currency.clear();
    from.clear();
    to.clear();
  }

  @override
  void initState() {
    super.initState();
    final state = WidgetsBinding.instance.lifecycleState;
    foreground = state == null || state == AppLifecycleState.resumed;
    bindSession();
    WidgetsBinding.instance.addObserver(this);
    if (foreground) load();
  }

  void bindSession() {
    sessionChanges?.cancel();
    final source = widget.api;
    authorized = source.sessionFence();
    sessionChanges = source.sessionEvents.listen((_) {
      if (mounted && identical(source, widget.api)) setState(clearPrivate);
    });
  }

  @override
  void didUpdateWidget(covariant InvoiceHistoryView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api) {
      clearPrivate();
      bindSession();
      final replacementApi = widget.api;
      // This widget is already scheduled to rebuild. Start the new actor's
      // read only after the update frame, never inside the build traversal.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted &&
            identical(widget.api, replacementApi) &&
            foreground &&
            authorized()) {
          load();
        }
      });
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
    sessionChanges?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    prefix.dispose();
    currency.dispose();
    from.dispose();
    to.dispose();
    super.dispose();
  }

  Future<void> load({int page = 1}) async {
    if (!foreground || !authorized() || busy) return;
    final session = widget.api.sessionFence();
    final epoch = ++generation;
    target = page;
    setState(() {
      busy = true;
      error = null;
      data = null;
    });
    try {
      final response = await widget.api.request(
        'finance/invoices',
        query: {...filters, 'page': '$page'},
      );
      if (!current(epoch, session)) return;
      final result = ApiPage.fromJson(response, (row) => row);
      // Refuse malformed private invoice projections before building receipt
      // navigation or formatting currency, rather than rendering null or
      // granting a detail action with an unverified record identifier.
      if (result.items.any((row) {
        final id = row['id'];
        final number = row['number'];
        final date = row['created_at'];
        final currency = row['currency'];
        final total = row['total_minor'];
        return row['evidence'] != 'SIMULATED' ||
            id is! int ||
            id < 1 ||
            number is! String ||
            number.isEmpty ||
            number.length > 100 ||
            date is! String ||
            serverUtcTime(context, date) == '—' ||
            currency is! String ||
            !RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
            total is! int ||
            total < 0;
      })) {
        throw const ApiException(409, 'Unsupported finance evidence.');
      }
      if (current(epoch, session)) setState(() => data = result);
    } catch (e) {
      if (!current(epoch, session)) return;
      if (e is ApiException && [401, 419].contains(e.status)) {
        setState(clearPrivate);
        widget.onExpired();
        return;
      }
      setState(() => error = e);
    } finally {
      if (current(epoch, session)) setState(() => busy = false);
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
    final viewEpoch = generation;
    final viewApi = widget.api;
    final viewSession = viewApi.sessionFence();
    bool canUsePage() => current(viewEpoch, viewSession) &&
        identical(widget.api, viewApi) && identical(data, page);
    if (!authorized()) return Scaffold(
      appBar: AppBar(title: Text(s.t('invoiceHistory'))),
      body: Center(child: Text(s.t('expired'))),
    );
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
            if (!busy && data == null && error == null)
              TextButton(
                onPressed: () => load(page: 1),
                child: Text(
                  Localizations.localeOf(context).languageCode == 'ar'
                      ? 'تحديث سجل الفواتير'
                      : 'Refresh invoice history',
                ),
              ),
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
                        Text(serverEventTime(context, invoice['created_at'])),
                        Text(
                          s.t('invoiceLine', {
                            'label': s.t('total'),
                            'amount': invoice['total_minor'],
                            'currency': invoice['currency'],
                          }),
                        ),
                        OutlinedButton(
                          onPressed: () async {
                            if (!canUsePage()) return;
                            final actorApi = viewApi;
                            final session = viewSession;
                            final pageNumber = page.current;
                            await Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => canUsePage()
                                    ? InvoicePage(
                                        api: actorApi,
                                        invoiceId: invoice['id'] as int,
                                        onExpired: widget.onExpired,
                                      )
                                    : const Scaffold(),
                              ),
                            );
                            if (canUsePage() && session()) {
                              await load(page: pageNumber);
                            }
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
                        ? () {
                            if (canUsePage()) load(page: page.current - 1);
                          }
                        : null,
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: page.current < page.last
                        ? () {
                            if (canUsePage()) load(page: page.current + 1);
                          }
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
