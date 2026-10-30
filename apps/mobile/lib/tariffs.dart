import 'dart:math';
import 'package:flutter/material.dart';
import 'api.dart';
import 'money_input.dart';
import 'strings.dart';

/// Native vendor pricing only. Super Admin commission management remains web-only.
class TariffWorkspaceView extends StatefulWidget {
  final EvApi api;
  final VoidCallback onExpired;
  final Map<String, dynamic>? station;
  const TariffWorkspaceView({
    super.key,
    required this.api,
    required this.onExpired,
    this.station,
  });
  @override
  State<TariffWorkspaceView> createState() => _TariffWorkspaceViewState();
}

class _TariffWorkspaceViewState extends State<TariffWorkspaceView> {
  final form = GlobalKey<FormState>();
  final price = TextEditingController(), tax = TextEditingController();
  ApiPage<Map<String, dynamic>>? data;
  Map<String, dynamic>? pricingContext;
  bool busy = false, uncertain = false;
  Object? error;
  int generation = 0, target = 1;
  late String key;
  @override
  void initState() {
    super.initState();
    renewKey();
    load();
  }

  void renewKey() {
    final random = Random.secure();
    key = List.generate(
      16,
      (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
    ).join();
  }

  @override
  void dispose() {
    generation++;
    price.dispose();
    tax.dispose();
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
      final response = await widget.api.request(
        widget.station == null
            ? 'finance/stations'
            : 'stations/${widget.station!['id']}/tariffs',
        query: {'page': '$page'},
      );
      final result = ApiPage.fromJson(response, (row) => row);
      if (widget.station != null &&
          response['pricing_context']?['evidence'] != 'SIMULATED')
        throw const ApiException(409, 'Unsupported pricing evidence.');
      if (widget.station == null &&
          result.items.any((row) => row['evidence'] != 'SIMULATED'))
        throw const ApiException(409, 'Unsupported pricing evidence.');
      if (mounted && epoch == generation)
        setState(() {
          data = result;
          uncertain = false;
          renewKey();
          if (widget.station != null)
            pricingContext = Map<String, dynamic>.from(
              response['pricing_context'] as Map,
            );
          if (widget.station != null && page == 1) {
            final latest = result.items.isEmpty ? null : result.items.first;
            price.text = latest == null
                ? ''
                : minorDecimal(
                    latest['rate_minor_per_kwh'] as int,
                    latest['minor_units'] as int,
                  );
            tax.text = latest == null
                ? ''
                : minorDecimal(latest['tax_bps'] as int, 2);
          }
        });
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

  Future<void> publish() async {
    if (!form.currentState!.validate() || busy || uncertain) return;
    final epoch = generation, station = widget.station!;
    final body = {
      'rate_minor_per_kwh': decimalMinor(
        price.text,
        pricingContext!['minor_units'] as int,
      ),
      'tax_bps': decimalMinor(tax.text, 2, maximum: 10000),
      'commission_bps': 0,
      'idempotency_key': key,
      'expected_minor_units': pricingContext!['minor_units'],
      'expected_currency': pricingContext!['currency'],
    };
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.request(
        'stations/${station['id']}/tariffs',
        method: 'POST',
        body: body,
      );
      if (mounted && epoch == generation) await load();
    } catch (e) {
      if (mounted && epoch == generation) {
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
          return;
        }
        setState(() {
          error = e;
          uncertain = !(e is ApiException && [422, 429].contains(e.status));
        });
      }
    } finally {
      if (mounted && epoch == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data, station = widget.station;
    final digits = pricingContext?['minor_units'];
    return Scaffold(
      appBar: AppBar(
        title: Text(station == null ? s.t('pricing') : '${station['name']}'),
      ),
      body: RefreshIndicator(
        onRefresh: () => load(page: target),
        child: ListView(
          padding: const EdgeInsets.all(16),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            Text(s.t('tariffDisclaimer')),
            TextButton(
              onPressed: busy ? null : () => load(page: target),
              child: Text(s.t('refreshPricing')),
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null)
              Text(s.t(uncertain ? 'pricingUncertain' : errorKey(error!))),
            if (error != null && !busy && !uncertain)
              TextButton(
                onPressed: () => load(page: target),
                child: Text(s.t('retry')),
              ),
            if (page != null) ...[
              if (station != null) ...[
                Text(s.t('tariffSnapshot')),
                if (page.current == 1 &&
                    digits is int &&
                    digits >= 0 &&
                    digits <= 3)
                  Form(
                    key: form,
                    child: Column(
                      children: [
                        Text(
                          s.t('pricingMaximum', {
                            'amount': minorDecimal(1000000, digits),
                            'currency': pricingContext!['currency'],
                          }),
                        ),
                        TextFormField(
                          controller: price,
                          enabled: !busy && !uncertain,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          maxLength: 20,
                          decoration: InputDecoration(
                            labelText: s.t('pricePerKwh', {
                              'currency': pricingContext!['currency'],
                            }),
                          ),
                          validator: (v) =>
                              decimalMinor(v ?? '', digits) != null
                              ? null
                              : s.t('invalidExactPrice'),
                        ),
                        TextFormField(
                          controller: tax,
                          enabled: !busy && !uncertain,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          maxLength: 20,
                          decoration: InputDecoration(
                            labelText: s.t('taxPercent'),
                          ),
                          validator: (v) =>
                              decimalMinor(v ?? '', 2, maximum: 10000) != null
                              ? null
                              : s.t('invalidExactPrice'),
                        ),
                        Text(s.t('commissionRetained')),
                        FilledButton(
                          onPressed: busy || uncertain ? null : publish,
                          child: Text(s.t('publishPricing')),
                        ),
                      ],
                    ),
                  ),
                if (digits == null) Text(s.t('pricingRegionMissing')),
                if (page.items.isEmpty) Text(s.t('noPricingVersions')),
                for (final row in page.items)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            s.t('pricingVersion', {'id': row['id']}),
                            style: Theme.of(context).textTheme.titleMedium,
                          ),
                          Text(
                            '${minorDecimal(row['rate_minor_per_kwh'] as int, row['minor_units'] as int)} ${row['currency']} / kWh',
                          ),
                          Text(
                            s.t('pricingPercentages', {
                              'tax': minorDecimal(row['tax_bps'] as int, 2),
                              'commission': minorDecimal(
                                row['commission_bps'] as int,
                                2,
                              ),
                            }),
                          ),
                          Text('${row['effective_from']}'),
                        ],
                      ),
                    ),
                  ),
              ] else ...[
                if (page.items.isEmpty) Text(s.t('noPricingStations')),
                for (final row in page.items)
                  Card(
                    child: ListTile(
                      title: Text('${row['name']}'),
                      subtitle: Text('${row['currency']}'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => TariffWorkspaceView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            station: row,
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
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
                    onPressed: busy || page.current <= 1
                        ? null
                        : () => load(page: page.current - 1),
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: busy || page.current >= page.last
                        ? null
                        : () => load(page: page.current + 1),
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
