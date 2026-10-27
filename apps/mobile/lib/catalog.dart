import 'package:flutter/material.dart';
import 'api.dart';
import 'strings.dart';
import 'topology_strings.dart';

typedef CatalogLoader =
    Future<ApiPage<Station>> Function(CatalogFilter filter, int page);

class CatalogController extends ChangeNotifier {
  final CatalogLoader loader;
  CatalogFilter filter = const CatalogFilter();
  ApiPage<Station>? data;
  Object? error;
  bool loading = false;
  int _generation = 0;
  int _target = 1;
  bool _disposed = false;
  CatalogController(this.loader);
  Future<void> load({int page = 1, CatalogFilter? filter}) async {
    if (_disposed) return;
    final generation = ++_generation;
    if (filter != null) {
      this.filter = filter;
      data = null;
    }
    _target = page;
    loading = true;
    error = null;
    notifyListeners();
    try {
      final result = await loader(this.filter, page);
      if (!_disposed && generation == _generation) data = result;
    } catch (e) {
      if (!_disposed && generation == _generation) error = e;
    } finally {
      if (!_disposed && generation == _generation) {
        loading = false;
        notifyListeners();
      }
    }
  }

  Future<void> retry() => load(page: _target);
  @override
  void dispose() {
    _disposed = true;
    _generation++;
    super.dispose();
  }
}

class CatalogView extends StatefulWidget {
  final CatalogLoader loader;
  final VoidCallback? onRoaming;
  final void Function(Station)? onChooseConnectors;
  final bool customer;
  final bool busy;
  final Future<void> Function(Station) onReserve;
  const CatalogView({
    super.key,
    required this.loader,
    this.onRoaming,
    this.onChooseConnectors,
    required this.customer,
    this.busy = false,
    required this.onReserve,
  });
  @override
  State<CatalogView> createState() => CatalogViewState();
}

class CatalogViewState extends State<CatalogView> {
  Future<void> refresh() =>
      controller.load(page: controller.data?.current ?? 1);
  late CatalogController controller;
  final search = TextEditingController();
  final country = TextEditingController();
  final power = TextEditingController();
  final form = GlobalKey<FormState>();
  String connector = '';
  @override
  void initState() {
    super.initState();
    controller = CatalogController(widget.loader)..load();
  }

  void apply() {
    if (form.currentState!.validate())
      controller.load(
        filter: CatalogFilter(
          search: search.text,
          country: country.text,
          connector: connector,
          minimumPower: int.tryParse(power.text),
        ),
      );
  }

  @override
  Widget build(BuildContext context) {
    final s = EvStrings.of(context);
    return AnimatedBuilder(
      animation: controller,
      builder: (context, _) {
        final page = controller.data;
        return RefreshIndicator(
          onRefresh: () => controller.load(page: page?.current ?? 1),
          child: ListView(
            key: const PageStorageKey('catalog-scroll'),
            padding: const EdgeInsets.all(16),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              Text(
                s.t('discover'),
                style: Theme.of(context).textTheme.headlineLarge,
              ),
              Text(s.t('development')),
              if (widget.onRoaming != null)
                OutlinedButton.icon(
                  key: const Key('roaming-open'),
                  onPressed: widget.onRoaming,
                  icon: const Icon(Icons.public),
                  label: Text(s.t('roaming')),
                ),
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Form(
                    key: form,
                    child: Column(
                      children: [
                        TextFormField(
                          key: const Key('catalog-search'),
                          controller: search,
                          decoration: InputDecoration(
                            labelText: s.t('stationName'),
                            prefixIcon: const Icon(Icons.search),
                          ),
                          onFieldSubmitted: (_) => apply(),
                          validator: (v) =>
                              (v?.length ?? 0) > 100 ? s.t('maxSearch') : null,
                        ),
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          key: ValueKey('catalog-connector-$connector'),
                          initialValue: connector,
                          isExpanded: true,
                          decoration: InputDecoration(
                            labelText: s.t('connector'),
                          ),
                          items: ['', 'CCS2', 'Type2', 'CHAdeMO']
                              .map(
                                (v) => DropdownMenuItem(
                                  value: v,
                                  child: Text(
                                    v.isEmpty ? s.t('allConnectors') : v,
                                  ),
                                ),
                              )
                              .toList(),
                          onChanged: (v) => setState(() => connector = v!),
                        ),
                        const SizedBox(height: 12),
                        TextFormField(
                          key: const Key('catalog-country'),
                          controller: country,
                          textCapitalization: TextCapitalization.characters,
                          maxLength: 2,
                          decoration: InputDecoration(
                            labelText: s.t('country'),
                            hintText: s.t('countryHint'),
                          ),
                          validator: (v) =>
                              v!.trim().isNotEmpty &&
                                  !RegExp(r'^[A-Za-z]{2}$').hasMatch(v.trim())
                              ? s.t('invalidCountry')
                              : null,
                        ),
                        TextFormField(
                          key: const Key('catalog-power'),
                          controller: power,
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(
                            labelText: s.t('minimumPower'),
                          ),
                          validator: (v) =>
                              v!.isNotEmpty &&
                                  (int.tryParse(v) == null ||
                                      int.parse(v) < 1 ||
                                      int.parse(v) > 1000)
                              ? s.t('invalidPower')
                              : null,
                        ),
                        const SizedBox(height: 16),
                        Wrap(
                          spacing: 8,
                          children: [
                            FilledButton(
                              onPressed: controller.loading ? null : apply,
                              child: Text(s.t('applyFilters')),
                            ),
                            TextButton(
                              onPressed: controller.loading
                                  ? null
                                  : () {
                                      form.currentState!.reset();
                                      search.clear();
                                      country.clear();
                                      power.clear();
                                      setState(() => connector = '');
                                      controller.load(
                                        filter: const CatalogFilter(),
                                      );
                                    },
                              child: Text(s.t('clearFilters')),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              if (controller.loading)
                Semantics(
                  liveRegion: true,
                  label: s.t('loading'),
                  child: const LinearProgressIndicator(),
                ),
              if (controller.error != null)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        Text(
                          s.t(errorKey(controller.error!)),
                          key: const Key('catalog-error'),
                        ),
                        TextButton(
                          onPressed: controller.loading
                              ? null
                              : controller.retry,
                          child: Text(s.t('retry')),
                        ),
                      ],
                    ),
                  ),
                ),
              if (!controller.loading &&
                  controller.error == null &&
                  (page?.items.isEmpty ?? false))
                Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(s.t('noStations')),
                ),
              for (final station in page?.items ?? <Station>[])
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Chip(
                          avatar: Icon(
                            station.reservable
                                ? Icons.science_outlined
                                : Icons.explore_outlined,
                            size: 18,
                          ),
                          label: Text(
                            s.t(
                              station.source == 'SIMULATED'
                                  ? 'simulated'
                                  : 'discoveryOnly',
                            ),
                          ),
                        ),
                        Text(
                          station.name,
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        Text(
                          station.reservationPolicy == 'canonical' ? topologyText(context,'capabilities') : '${station.connector} · ${station.powerKw} kW',
                          textDirection: TextDirection.ltr,
                        ),
                        Text(s.t('unknownAvailability')),
                        if (station.reservationPolicy == 'managed_blocked')
                          Text(s.t('managedUnavailable')),
                        if (station.reservationPolicy == 'canonical' &&
                            widget.customer &&
                            widget.onChooseConnectors != null)
                          FilledButton.tonal(
                            onPressed: controller.loading || widget.busy
                                ? null
                                : () => widget.onChooseConnectors!(station),
                            child: Text(topologyText(context, 'choose')),
                          ),
                        if (station.reservable && widget.customer)
                          FilledButton(
                            onPressed: controller.loading || widget.busy
                                ? null
                                : () => widget.onReserve(station),
                            child: Text(s.t('reserveSimulator')),
                          ),
                      ],
                    ),
                  ),
                ),
              if (page != null)
                Column(
                  children: [
                    Text(
                      s.t('page', {
                        'page': page.current,
                        'last': page.last,
                        'total': page.total,
                      }),
                      key: const Key('catalog-page'),
                    ),
                    Wrap(
                      spacing: 12,
                      children: [
                        OutlinedButton(
                          key: const Key('catalog-previous'),
                          onPressed: controller.loading || page.current <= 1
                              ? null
                              : () => controller.load(page: page.current - 1),
                          child: Text(s.t('previous')),
                        ),
                        OutlinedButton(
                          key: const Key('catalog-next'),
                          onPressed:
                              controller.loading || page.current >= page.last
                              ? null
                              : () => controller.load(page: page.current + 1),
                          child: Text(s.t('next')),
                        ),
                      ],
                    ),
                  ],
                ),
              const SizedBox(height: 96),
            ],
          ),
        );
      },
    );
  }

  @override
  void dispose() {
    controller.dispose();
    search.dispose();
    country.dispose();
    power.dispose();
    super.dispose();
  }
}
