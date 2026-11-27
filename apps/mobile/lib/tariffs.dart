import 'dart:async';
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

class _TariffWorkspaceViewState extends State<TariffWorkspaceView> with WidgetsBindingObserver {
  final form = GlobalKey<FormState>();
  final price = TextEditingController(), tax = TextEditingController();
  ApiPage<Map<String, dynamic>>? data;
  Map<String, dynamic>? pricingContext, effectiveTariff;
  String? tariffAsOf;
  bool compatible = false;
  bool busy = false, uncertain = false;
  Object? error;
  int generation = 0, target = 1;
  late String key;
  late bool Function() accountFence;
  StreamSubscription<int>? subscription;
  bool foreground = true;
  bool get active => mounted && foreground && accountFence();
  bool current(int epoch, EvApi source) =>
      active && epoch == generation && identical(source, widget.api);
  void redact() {
    generation++;
    data = null;
    pricingContext = effectiveTariff = null;
    tariffAsOf = null;
    price.clear();
    tax.clear();
    busy = compatible = uncertain = false;
    target = 1;
    error = null;
  }
  void subscribe() {
    final source = widget.api;
    subscription = source.sessionEvents.listen((_) {
      if (mounted && identical(source, widget.api) && !accountFence()) {
        setState(redact);
      }
    });
  }
  VoidCallback guard(VoidCallback action) {
    final epoch = generation, source = widget.api;
    return () { if (current(epoch, source) && !busy) action(); };
  }
  @override
  void initState() {
    super.initState();
    accountFence = widget.api.sessionFence();
    final state = WidgetsBinding.instance.lifecycleState;
    foreground = state == null || state == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribe();
    renewKey();
    if (active) load();
  }

  @override
  void didUpdateWidget(covariant TariffWorkspaceView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.api, widget.api)) {
      unawaited(subscription?.cancel());
      redact();
      accountFence = widget.api.sessionFence();
      subscribe();
      if (active) load();
    } else if (!accountFence()) {
      redact();
    } else if (oldWidget.station?['id'] != widget.station?['id']) {
      redact();
      if (active) load();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (!mounted || resumed == foreground) return;
    setState(() { foreground = resumed; redact(); });
    // Refresh explicitly; never replay an unacknowledged price publication.
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
    WidgetsBinding.instance.removeObserver(this);
    unawaited(subscription?.cancel());
    price.dispose();
    tax.dispose();
    super.dispose();
  }

  Future<void> load({int page = 1, bool afterPublish = false}) async {
    if (!active || (busy && !afterPublish)) return;
    final epoch = ++generation, source = widget.api;
    target = page;
    setState(() {
      busy = true;
      error = null;
      data = null;
      pricingContext = null;
      effectiveTariff = null;
      tariffAsOf = null;
      compatible = false;
      price.clear();
      tax.clear();
    });
    try {
      final response = await source.request(
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
      Map<String, dynamic>? effective;
      bool matches = false;
      if (widget.station != null) {
        final raw = response['active_tariff'];
        final ctx = response['pricing_context'];
        matches = response['active_tariff_context_matches'] == true;
        bool bounded(dynamic v, int max) => v is int && v >= 0 && v <= max;
        if (!response.containsKey('active_tariff') ||
            response['active_tariff_context_matches'] is! bool ||
            response['tariff_as_of'] is! String ||
            DateTime.tryParse(response['tariff_as_of'] as String) == null ||
            (raw == null && matches) ||
            (raw != null && (raw is! Map ||
                !bounded(raw['id'], 9007199254740991) || raw['id'] == 0 ||
                raw['station_id'] != widget.station!['id'] ||
                !bounded(raw['rate_minor_per_kwh'], 1000000) ||
                !bounded(raw['tax_bps'], 10000) || !bounded(raw['commission_bps'], 10000) ||
                (matches && (raw['currency'] != ctx['currency'] ||
                    raw['minor_units'] != ctx['minor_units']))))) {
          throw const FormatException('Invalid effective tariff authority.');
        }
        if (raw != null) {
          effective = Map<String, dynamic>.from(raw as Map);
          for (final row in result.items.where((r) => r['id'] == effective!['id'])) {
            for (final field in ['station_id', 'rate_minor_per_kwh', 'tax_bps',
              'commission_bps', 'currency', 'minor_units', 'effective_from']) {
              if (row[field] != effective[field]) {
                throw const FormatException('Conflicting effective tariff history.');
              }
            }
          }
        }
      }
      if (current(epoch, source))
        setState(() {
          data = result;
          uncertain = false;
          renewKey();
          if (widget.station != null)
            pricingContext = Map<String, dynamic>.from(
              response['pricing_context'] as Map,
            );
          if (widget.station != null) {
            effectiveTariff = effective;
            tariffAsOf = response['tariff_as_of'] as String;
            compatible = effective == null || matches;
          }
          if (widget.station != null && page == 1 && compatible) {
            final latest = effective;
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
      if (current(epoch, source)) {
        if (e is ApiException && [401, 419].contains(e.status)) {
          widget.onExpired();
          return;
        }
        setState(() => error = e);
      }
    } finally {
      if (current(epoch, source)) setState(() => busy = false);
    }
  }

  Future<void> publish() async {
    if (!active || !compatible || busy || uncertain || form.currentState?.validate() != true) return;
    final epoch = ++generation, station = widget.station!, source = widget.api;
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
      'expected_active_tariff_id': effectiveTariff?['id'],
    };
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await source.request(
        'stations/${station['id']}/tariffs',
        method: 'POST',
        body: body,
      );
      if (current(epoch, source)) await load(afterPublish: true);
    } catch (e) {
      if (current(epoch, source)) {
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
      if (current(epoch, source)) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context), page = data, station = widget.station;
    final digits = pricingContext?['minor_units'];
    if (!active) return Scaffold(
      appBar: AppBar(title: Text(s.t('pricing'))),
      body: Center(child: Text(s.arabic
          ? 'أعد فتح التسعير أو حدّث عند العودة.'
          : 'Reopen pricing or refresh when active.')),
    );
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
              onPressed: busy ? null : guard(() { load(page: target); }),
              child: Text(s.t('refreshPricing')),
            ),
            if (busy) const LinearProgressIndicator(),
            if (error != null)
              Text(s.t(uncertain ? 'pricingUncertain' : errorKey(error!))),
            if (error != null && !busy && !uncertain)
              TextButton(
                onPressed: guard(() { load(page: target); }),
                child: Text(s.t('retry')),
              ),
            if (page != null) ...[
              if (station != null) ...[
                Text(s.t('tariffSnapshot')),
                if (effectiveTariff != null)
                  Text('Effective pricing version / إصدار التسعير الساري #${effectiveTariff!['id']} · $tariffAsOf',
                    key: const Key('effective-tariff-version'))
                else
                  const Text('No effective pricing version / لا يوجد إصدار تسعير سارٍ'),
                if (!compatible)
                  const Text('Effective tariff denomination mismatch / اختلاف عملة أو دقة التسعير الساري'),
                if (compatible && page.current == 1 &&
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
                          onPressed: busy || uncertain ? null : guard(() { publish(); }),
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
                      onTap: busy ? null : guard(() { Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => TariffWorkspaceView(
                            api: widget.api,
                            onExpired: widget.onExpired,
                            station: row,
                          ),
                        ),
                      ); }),
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
                        : guard(() { load(page: page.current - 1); }),
                    child: Text(s.t('previous')),
                  ),
                  OutlinedButton(
                    onPressed: busy || page.current >= page.last
                        ? null
                        : guard(() { load(page: page.current + 1); }),
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
