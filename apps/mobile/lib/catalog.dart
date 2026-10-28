import 'package:flutter/material.dart';
import 'api.dart';
import 'station_map.dart';
import 'strings.dart';
import 'topology_strings.dart';
import 'vendor_forms.dart' show vendorDigits;

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

  void favoriteChanged(int id, bool saved) {
    final current = data;
    if (_disposed || current == null) return;
    data = ApiPage(
      items: current.items
          .map((s) => s.id == id ? Station.withFavorite(s, saved) : s)
          .toList(),
      current: current.current,
      last: current.last,
      total: current.total,
    );
    notifyListeners();
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
  final VoidCallback? onRoaming, onFavorites, onExpired;
  final Future<bool> Function(Station, bool)? onFavorite;
  final bool savedOnly;
  final void Function(Station)? onChooseConnectors;
  final bool customer;
  final bool busy;
  final Future<void> Function(Station) onReserve;
  const CatalogView({
    super.key,
    required this.loader,
    this.onRoaming,
    this.onFavorites,
    this.onExpired,
    this.onFavorite,
    this.savedOnly = false,
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
  final latitude = TextEditingController();
  final longitude = TextEditingController();
  final radius = TextEditingController();
  final form = GlobalKey<FormState>();
  String connector = '';
  final favoriteBusy = <int>{};
  final favoriteErrors = <int, Object>{};
  int confirmedFavoriteRead = -1;
  @override
  void initState() {
    super.initState();
    controller = CatalogController(widget.loader);
    controller.addListener(() {
      if (!controller.loading &&
          controller.error == null &&
          confirmedFavoriteRead != controller._generation) {
        confirmedFavoriteRead = controller._generation;
        for (final station in controller.data?.items ?? <Station>[])
          favoriteErrors.remove(station.id);
      }
      final error = controller.error;
      if (!controller.loading &&
          error is ApiException &&
          [401, 403, 419].contains(error.status)) {
        controller.data = null;
        if (error.status != 403) widget.onExpired?.call();
      }
    });
    controller.load();
  }

  void apply() {
    if (form.currentState!.validate())
      controller.load(
        filter: CatalogFilter(
          search: search.text,
          country: country.text,
          connector: connector,
          minimumPower: int.tryParse(power.text),
          latitude: double.tryParse(vendorDigits(latitude.text.trim())),
          longitude: double.tryParse(vendorDigits(longitude.text.trim())),
          radiusKm: double.tryParse(vendorDigits(radius.text.trim())),
        ),
      );
  }

  Future<void> favorite(Station station) async {
    if (favoriteBusy.contains(station.id) || widget.onFavorite == null) return;
    final generation = controller._generation;
    setState(() {
      favoriteBusy.add(station.id);
      favoriteErrors.remove(station.id);
    });
    try {
      final saved = await widget.onFavorite!(station, !station.favorite);
      if (!mounted || generation != controller._generation) return;
      controller.favoriteChanged(station.id, saved);
      if (widget.savedOnly && !saved)
        await controller.load(page: controller.data?.current ?? 1);
    } catch (e) {
      if (!mounted) return;
      if (e is ApiException && [401, 419].contains(e.status))
        widget.onExpired?.call();
      if (mounted && generation == controller._generation)
        setState(() => favoriteErrors[station.id] = e);
    } finally {
      if (mounted) setState(() => favoriteBusy.remove(station.id));
    }
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
                s.t(widget.savedOnly ? 'savedStations' : 'discover'),
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
              if (widget.onFavorites != null && widget.customer)
                OutlinedButton.icon(
                  key: const Key('favorites-open'),
                  onPressed: widget.onFavorites,
                  icon: const Icon(Icons.star_outline),
                  label: Text(s.t('savedStations')),
                ),
              if (page != null && page.items.isNotEmpty)
                OutlinedButton.icon(
                  key: const Key('catalog-map-open'),
                  icon: const Icon(Icons.map_outlined),
                  label: Text(
                    s.arabic
                        ? 'خريطة نتائج هذه الصفحة'
                        : 'Map this page of results',
                  ),
                  onPressed: () async {
                    final selected = await Navigator.of(context).push<Station>(
                      MaterialPageRoute(
                        builder: (_) => StationMapView(
                          stations: page.items,
                          onSearch: widget.savedOnly
                              ? null
                              : (lat, lng) {
                                  if (!mounted) return;
                                  latitude.text = lat.toStringAsFixed(6);
                                  longitude.text = lng.toStringAsFixed(6);
                                  radius.text = '25';
                                  controller.load(
                                    filter: CatalogFilter(
                                      search: search.text,
                                      country: country.text,
                                      connector: connector,
                                      minimumPower: int.tryParse(power.text),
                                      latitude: lat,
                                      longitude: lng,
                                      radiusKm: 25,
                                    ),
                                  );
                                },
                        ),
                      ),
                    );
                    if (selected != null && mounted) {
                      if (widget.onChooseConnectors != null &&
                          selected.reservationPolicy == 'canonical') {
                        widget.onChooseConnectors!(selected);
                      } else if (selected.reservable && widget.customer) {
                        await widget.onReserve(selected);
                      }
                    }
                  },
                ),
              const SizedBox(height: 16),
              if (!widget.savedOnly)
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
                            validator: (v) => (v?.length ?? 0) > 100
                                ? s.t('maxSearch')
                                : null,
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
                          ExpansionTile(
                            key: const PageStorageKey(
                              'catalog-nearby-expansion',
                            ),
                            title: Text(s.t('nearbySearch')),
                            children: [
                              Text(s.t('nearbyPrivacy')),
                              for (final entry in [
                                ('nearbyLatitude', latitude, -90.0, 90.0),
                                ('nearbyLongitude', longitude, -180.0, 180.0),
                                ('radiusKm', radius, 1.0, 200.0),
                              ])
                                TextFormField(
                                  key: PageStorageKey('catalog-${entry.$1}'),
                                  controller: entry.$2,
                                  textDirection: TextDirection.ltr,
                                  keyboardType:
                                      const TextInputType.numberWithOptions(
                                        decimal: true,
                                        signed: true,
                                      ),
                                  decoration: InputDecoration(
                                    labelText: s.t(entry.$1),
                                  ),
                                  validator: (value) {
                                    if ([
                                      latitude,
                                      longitude,
                                      radius,
                                    ].every((c) => c.text.trim().isEmpty))
                                      return null;
                                    final n = double.tryParse(
                                      vendorDigits(value?.trim() ?? ''),
                                    );
                                    return n == null ||
                                            !n.isFinite ||
                                            n < entry.$3 ||
                                            n > entry.$4
                                        ? s.t('invalidNearby')
                                        : null;
                                  },
                                ),
                            ],
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
                                        latitude.clear();
                                        longitude.clear();
                                        radius.clear();
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
                  child: Text(
                    s.t(widget.savedOnly ? 'noSavedStations' : 'noStations'),
                  ),
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
                          station.reservationPolicy == 'canonical'
                              ? topologyText(context, 'capabilities')
                              : '${station.connector} · ${station.powerKw} kW',
                          textDirection: TextDirection.ltr,
                        ),
                        Text(s.t('unknownAvailability')),
                        if (widget.customer && widget.onFavorite != null)
                          OutlinedButton.icon(
                            key: Key('favorite-${station.id}'),
                            onPressed:
                                controller.loading ||
                                    widget.busy ||
                                    favoriteBusy.contains(station.id)
                                ? null
                                : () => favorite(station),
                            icon: favoriteBusy.contains(station.id)
                                ? const SizedBox(
                                    width: 16,
                                    height: 16,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                    ),
                                  )
                                : Icon(
                                    station.favorite
                                        ? Icons.star
                                        : Icons.star_border,
                                  ),
                            label: Text(
                              s.t(
                                station.favorite
                                    ? 'removeFavorite'
                                    : 'saveFavorite',
                              ),
                            ),
                          ),
                        if (favoriteErrors.containsKey(station.id))
                          Semantics(
                            liveRegion: true,
                            child: Text(s.t('favoriteUnconfirmed')),
                          ),
                        if (station.distanceKm != null)
                          Text(
                            s.t('straightDistance', {
                              'km': station.distanceKm!.toStringAsFixed(2),
                            }),
                          ),
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
    latitude.dispose();
    longitude.dispose();
    radius.dispose();
    super.dispose();
  }
}
