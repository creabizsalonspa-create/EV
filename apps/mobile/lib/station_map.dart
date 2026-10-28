import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'api.dart';
import 'strings.dart';
import 'foreground_location.dart';
import 'discovery_client.dart';
import 'address_search.dart';
import 'route_planner.dart';

/// Missing coordinates never become a real location at (0, 0).
bool stationHasMapPoint(Station station) =>
    station.latitude != null &&
    station.longitude != null &&
    station.latitude!.isFinite &&
    station.longitude!.isFinite &&
    station.latitude!.abs() <= 85.05112878 &&
    station.longitude!.abs() <= 180;

List<List<Station>> clusterStations(List<Station> stations, double zoom) {
  final groups = <String, List<Station>>{};
  final scale = 256 * math.pow(2, zoom);
  for (final station in stations.where(stationHasMapPoint)) {
    final sin = math.sin(station.latitude! * math.pi / 180);
    final x = ((station.longitude! + 180) / 360 * scale / 48).floor();
    final y =
        ((0.5 - math.log((1 + sin) / (1 - sin)) / (4 * math.pi)) * scale / 48)
            .floor();
    (groups['$x:$y'] ??= []).add(station);
  }
  return groups.values.toList();
}

class StationMapView extends StatefulWidget {
  final List<Station> stations;
  final TileProvider? tileProvider;
  final bool customer;
  final DiscoveryClient? discoveryClient;
  final Future<ForegroundFix> Function()? locationReader;
  final void Function(double latitude, double longitude)? onSearch;
  const StationMapView({
    super.key,
    required this.stations,
    this.tileProvider,
    this.customer = false,
    this.discoveryClient,
    this.locationReader,
    this.onSearch,
  });
  @override
  State<StationMapView> createState() => _StationMapViewState();
}

class _StationMapViewState extends State<StationMapView> {
  bool enabled = false;
  DiscoveryCapabilities? discoveryCapabilities;
  bool locating = false;
  String? locationError;
  ForegroundFix? fix;
  int locationGeneration = 0;
  double zoom = 11;
  LatLng? searchPoint;
  final controller = MapController();
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  @override
  void initState() {
    super.initState();
    loadDiscoveryCapabilities();
  }

  Future<void> loadDiscoveryCapabilities() async {
    try {
      final result = await widget.discoveryClient?.capabilities();
      if (mounted) setState(() => discoveryCapabilities = result);
    } catch (_) {
      if (mounted) setState(() => discoveryCapabilities = null);
    }
  }

  void planRoute([Station? initialStop]) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => RoutePlannerView(
          client: widget.discoveryClient,
          stations: widget.stations,
          origin: searchPoint == null
              ? null
              : GeoPoint(searchPoint!.latitude, searchPoint!.longitude),
          initialStop: initialStop,
          tilesEnabled: enabled,
        ),
      ),
    );
  }

  @override
  void dispose() {
    locationGeneration++;
    controller.dispose();
    super.dispose();
  }

  Future<void> locate() async {
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        scrollable: true,
        title: Text(
          t('Use device location once?', 'استخدام موقع الجهاز مرة واحدة؟'),
        ),
        content: Text(
          t(
            'Allow a one-time foreground location lookup. It is not saved and does not report arrival. Coordinates go to nearby search only when you press Search; enabling map tiles sends the viewed area to OpenStreetMap. You can enter coordinates manually instead.',
            'السماح بتحديد الموقع مرة واحدة أثناء استخدام التطبيق. لا يُحفظ الموقع ولا يُبلغ عن الوصول. تُرسل الإحداثيات للبحث القريب عند الضغط على بحث فقط؛ تفعيل الخرائط يرسل المنطقة المعروضة إلى OpenStreetMap. يمكنك إدخال الإحداثيات يدوياً.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialog, false),
            child: Text(t('Cancel', 'إلغاء')),
          ),
          FilledButton(
            key: const Key('location-consent-accept'),
            onPressed: () => Navigator.pop(dialog, true),
            child: Text(t('Allow once', 'السماح مرة واحدة')),
          ),
        ],
      ),
    );
    if (accepted != true || !mounted) return;
    final generation = ++locationGeneration;
    setState(() {
      locating = true;
      locationError = null;
    });
    try {
      final result =
          await (widget.locationReader ?? ForegroundLocationReader().read)();
      if (!mounted || generation != locationGeneration) return;
      setState(() {
        fix = result;
        searchPoint = LatLng(result.latitude, result.longitude);
      });
      if (enabled && result.latitude.abs() <= 85.05112878)
        controller.move(searchPoint!, 13);
    } catch (error) {
      if (!mounted || generation != locationGeneration) return;
      setState(
        () => locationError = error is LocationLookupFailure
            ? error.reason
            : 'unavailable',
      );
    } finally {
      if (mounted && generation == locationGeneration)
        setState(() => locating = false);
    }
  }

  Future<void> details(Station station) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                station.name,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              Text(
                '${station.connector} · ${station.powerKw} kW · ${station.source}',
              ),
              Text(
                t(
                  'Availability is unverified. Map placement does not enable charging.',
                  'التوفر غير مؤكد. ظهور المحطة على الخريطة لا يتيح الشحن.',
                ),
              ),
              OutlinedButton.icon(
                icon: const Icon(Icons.directions),
                label: Text(
                  t('Open external directions', 'فتح الاتجاهات الخارجية'),
                ),
                onPressed: () async {
                  final consent = await showDialog<bool>(
                    context: sheet,
                    builder: (dialog) => AlertDialog(
                      scrollable: true,
                      content: Text(
                        t(
                          'Send this destination to Google Maps? Its privacy policy applies. No route or travel time is verified.',
                          'إرسال الوجهة إلى خرائط Google؟ تطبق سياسة خصوصية المزود. لم يتم التحقق من المسار أو زمن الرحلة.',
                        ),
                      ),
                      actions: [
                        TextButton(
                          onPressed: () => Navigator.pop(dialog, false),
                          child: Text(t('Cancel', 'إلغاء')),
                        ),
                        FilledButton(
                          onPressed: () => Navigator.pop(dialog, true),
                          child: Text(t('Continue', 'متابعة')),
                        ),
                      ],
                    ),
                  );
                  if (consent != true || !mounted) return;
                  final uri = Uri.https('www.google.com', '/maps/dir/', {
                    'api': '1',
                    'destination': '${station.latitude},${station.longitude}',
                  });
                  try {
                    final opened = await launchUrl(
                      uri,
                      mode: LaunchMode.externalApplication,
                    );
                    if (!opened) throw StateError('unavailable');
                  } catch (_) {
                    if (mounted)
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text(
                            t(
                              'Directions unavailable. Use the coordinates manually.',
                              'الاتجاهات غير متاحة. استخدم الإحداثيات يدوياً.',
                            ),
                          ),
                        ),
                      );
                  }
                },
              ),
              Text(
                '${station.latitude}, ${station.longitude}',
                textDirection: TextDirection.ltr,
              ),
              OutlinedButton.icon(
                icon: const Icon(Icons.route),
                label: Text(
                  t(
                    'Plan route with this charging stop',
                    'تخطيط مسار مع توقف الشحن هذا',
                  ),
                ),
                onPressed: () {
                  Navigator.pop(sheet);
                  planRoute(station);
                },
              ),
              if (widget.customer &&
                  (station.reservable ||
                      station.reservationPolicy == 'canonical'))
                FilledButton(
                  onPressed: () {
                    Navigator.pop(sheet);
                    Navigator.pop(context, station);
                  },
                  child: Text(t('View charging options', 'عرض خيارات الشحن')),
                ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final points = widget.stations.where(stationHasMapPoint).toList();
    return Scaffold(
      appBar: AppBar(title: Text(t('Station map', 'خريطة المحطات'))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(8),
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(
                t(
                  'Only this page of results is shown. Stations without supported coordinates remain in the list. OpenStreetMap receives your IP and viewed area when enabled.',
                  'تظهر نتائج هذه الصفحة فقط. تبقى المحطات التي لا تتوفر لها إحداثيات مدعومة في القائمة. تستقبل OpenStreetMap عنوان الإنترنت والمنطقة المعروضة عند التفعيل.',
                ),
              ),
            ),
            OutlinedButton.icon(
              key: const Key('charging-route-open'),
              onPressed: () => planRoute(),
              icon: const Icon(Icons.route),
              label: Text(t('Plan charging route', 'تخطيط مسار الشحن')),
            ),
            if (discoveryCapabilities?.geocoding == true &&
                widget.discoveryClient != null)
              ExpansionTile(
                title: Text(t('Find an address', 'البحث عن عنوان')),
                children: [
                  AddressSearch(
                    client: widget.discoveryClient!,
                    onSelected: (place) {
                      setState(
                        () => searchPoint = LatLng(
                          place.point.latitude,
                          place.point.longitude,
                        ),
                      );
                      if (enabled && place.point.latitude.abs() <= 85.05112878)
                        controller.move(searchPoint!, 13);
                    },
                  ),
                ],
              ),
            OutlinedButton.icon(
              key: const Key('native-location-once'),
              onPressed: locating ? null : locate,
              icon: locating
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.my_location),
              label: Text(t('Use my location once', 'استخدام موقعي مرة واحدة')),
            ),
            if (locationError != null)
              Semantics(
                liveRegion: true,
                child: Text(
                  locationError == 'deniedForever'
                      ? t(
                          'Location permission is disabled in device settings. Enter coordinates manually, or change permission yourself in Settings.',
                          'إذن الموقع معطل في إعدادات الجهاز. أدخل الإحداثيات يدوياً أو غيّر الإذن من الإعدادات.',
                        )
                      : locationError == 'disabled'
                      ? t(
                          'Device location services are off. Manual coordinate search remains available.',
                          'خدمات الموقع في الجهاز متوقفة. يبقى البحث اليدوي بالإحداثيات متاحاً.',
                        )
                      : t(
                          'A fresh location could not be obtained. Enter coordinates manually.',
                          'تعذر الحصول على موقع حديث. أدخل الإحداثيات يدوياً.',
                        ),
                ),
              ),
            if (fix != null)
              Text(
                t(
                  fix!.accuracyMeters == null
                      ? 'Device-reported location; accuracy unavailable. Not proof of arrival.'
                      : 'Device-reported accuracy approximately ${fix!.accuracyMeters!.round()} m. Not proof of arrival.',
                  fix!.accuracyMeters == null
                      ? 'موقع أبلغ عنه الجهاز؛ الدقة غير متاحة. لا يثبت الوصول.'
                      : 'دقة أبلغ عنها الجهاز تقارب ${fix!.accuracyMeters!.round()} م. لا يثبت الوصول.',
                ),
              ),
            if (!enabled)
              FilledButton(
                key: const Key('enable-map'),
                onPressed: () => setState(() => enabled = true),
                child: Text(
                  t('Enable interactive map', 'تفعيل الخريطة التفاعلية'),
                ),
              ),
            if (widget.onSearch != null && (enabled || searchPoint != null))
              Padding(
                padding: const EdgeInsets.all(8),
                child: Column(
                  children: [
                    Text(
                      t(
                        'Tap the map to choose a search centre. Coordinates are sent only when you search.',
                        'اضغط الخريطة لتحديد مركز البحث. تُرسل الإحداثيات عند البحث فقط.',
                      ),
                    ),
                    if (searchPoint != null)
                      FilledButton(
                        onPressed: () {
                          final p = searchPoint!;
                          Navigator.pop(context);
                          widget.onSearch!(p.latitude, p.longitude);
                        },
                        child: Text(
                          t('Search within 25 km here', 'البحث ضمن ٢٥ كم هنا'),
                        ),
                      ),
                  ],
                ),
              ),
            if (points.isEmpty)
              Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  t(
                    'No supported coordinates on this page.',
                    'لا توجد إحداثيات مدعومة في هذه الصفحة.',
                  ),
                ),
              ),
            if (enabled)
              SizedBox(
                height: math.max(300, MediaQuery.sizeOf(context).height * 0.55),
                child: FlutterMap(
                  mapController: controller,
                  options: MapOptions(
                    initialCenter:
                        searchPoint != null &&
                            searchPoint!.latitude.abs() <= 85.05112878
                        ? searchPoint!
                        : points.isEmpty
                        ? const LatLng(24.45, 54.38)
                        : LatLng(
                            points.first.latitude!,
                            points.first.longitude!,
                          ),
                    initialZoom: 11,
                    onPositionChanged: (camera, _) {
                      if (camera.zoom != zoom)
                        setState(() => zoom = camera.zoom);
                    },
                    onTap: (_, point) => setState(() => searchPoint = point),
                  ),
                  children: [
                    TileLayer(
                      urlTemplate:
                          'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                      userAgentPackageName: 'com.creabiz.ev',
                      tileProvider: widget.tileProvider,
                      panBuffer: 0,
                      maxZoom: 19,
                    ),
                    MarkerLayer(
                      markers: clusterStations(points, zoom).map((group) {
                        final station = group.first;
                        return Marker(
                          point: LatLng(station.latitude!, station.longitude!),
                          width: 48,
                          height: 48,
                          child: Semantics(
                            label: station.name,
                            button: true,
                            child: IconButton(
                              tooltip: station.name,
                              onPressed: () async {
                                if (group.length == 1) {
                                  await details(station);
                                  return;
                                }
                                if (!mounted) return;
                                await showModalBottomSheet<void>(
                                  context: context,
                                  builder: (sheet) => SafeArea(
                                    child: ListView(
                                      shrinkWrap: true,
                                      children: group
                                          .map(
                                            (s) => ListTile(
                                              title: Text(s.name),
                                              subtitle: Text(s.source),
                                              onTap: () {
                                                Navigator.pop(sheet);
                                                details(s);
                                              },
                                            ),
                                          )
                                          .toList(),
                                    ),
                                  ),
                                );
                              },
                              icon: group.length == 1
                                  ? const Icon(Icons.location_on)
                                  : Text("${group.length}"),
                              color: Theme.of(context).colorScheme.primary,
                              style: IconButton.styleFrom(
                                backgroundColor: Theme.of(
                                  context,
                                ).colorScheme.surface,
                              ),
                            ),
                          ),
                        );
                      }).toList(),
                    ),
                    RichAttributionWidget(
                      attributions: [
                        TextSourceAttribution(
                          'OpenStreetMap contributors',
                          onTap: () => launchUrl(
                            Uri.parse(
                              'https://www.openstreetmap.org/copyright',
                            ),
                            mode: LaunchMode.externalApplication,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
