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
import 'vendor_forms.dart' show vendorDigits;

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

class _StationMapViewState extends State<StationMapView> with WidgetsBindingObserver {
  bool enabled = false;
  late bool foreground;
  int consentEpoch = 0;
  final manualLatitude = TextEditingController();
  final manualLongitude = TextEditingController();
  final manualForm = GlobalKey<FormState>();
  bool get sessionCurrent => widget.discoveryClient?.currentSession() ?? true;
  bool get available => mounted && foreground && sessionCurrent;
  bool active(int epoch) => available && epoch == consentEpoch;
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
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    if (available) loadDiscoveryCapabilities();
  }

  Future<void> loadDiscoveryCapabilities() async {
    if (!available) return;
    final epoch = consentEpoch;
    final client = widget.discoveryClient;
    try {
      final result = await client?.capabilities();
      if (active(epoch) && identical(client, widget.discoveryClient)) {
        setState(() => discoveryCapabilities = result);
      }
    } catch (_) {
      if (active(epoch)) setState(() => discoveryCapabilities = null);
    }
  }

  void clearTransient() {
    consentEpoch++;
    locationGeneration++;
    enabled = false;
    locating = false;
    fix = null;
    searchPoint = null;
    locationError = null;
    discoveryCapabilities = null;
    manualLatitude.clear();
    manualLongitude.clear();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (!mounted || foreground == resumed) return;
    setState(() {
      foreground = resumed;
      if (!resumed) clearTransient();
    });
    if (available) loadDiscoveryCapabilities();
  }

  @override
  void didUpdateWidget(covariant StationMapView old) {
    super.didUpdateWidget(old);
    if (!identical(old.discoveryClient, widget.discoveryClient) ||
        !identical(old.stations, widget.stations) ||
        old.customer != widget.customer || !sessionCurrent) {
      clearTransient();
      if (available) {
        final epoch = consentEpoch;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (active(epoch)) loadDiscoveryCapabilities();
        });
      }
    }
  }

  void planRoute([Station? initialStop]) {
    if (!available) return;
    final epoch = consentEpoch;
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => !active(epoch) ? const SizedBox.shrink() : RoutePlannerView(
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
    consentEpoch++;
    WidgetsBinding.instance.removeObserver(this);
    manualLatitude.dispose();
    manualLongitude.dispose();
    controller.dispose();
    super.dispose();
  }

  Future<void> locate() async {
    if (!available || locating) return;
    final epoch = consentEpoch;
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
    if (accepted != true || !active(epoch) || locating) return;
    final generation = ++locationGeneration;
    setState(() {
      locating = true;
      locationError = null;
    });
    try {
      final result =
          await (widget.locationReader ?? ForegroundLocationReader().read)();
      if (!active(epoch) || generation != locationGeneration) return;
      setState(() {
        fix = result;
        searchPoint = LatLng(result.latitude, result.longitude);
      });
      if (enabled && result.latitude.abs() <= 85.05112878)
        controller.move(searchPoint!, 13);
    } catch (error) {
      if (!active(epoch) || generation != locationGeneration) return;
      setState(
        () => locationError = error is LocationLookupFailure
            ? error.reason
            : 'unavailable',
      );
    } finally {
      if (active(epoch) && generation == locationGeneration)
        setState(() => locating = false);
    }
  }

  Future<void> details(Station station) async {
    if (!available || !stationHasMapPoint(station)) return;
    final epoch = consentEpoch;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => SafeArea(
        child: SingleChildScrollView(child: Padding(
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
                  if (!active(epoch)) return;
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
                  if (consent != true || !active(epoch) || !sheet.mounted) return;
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
                  if (!active(epoch)) return;
                  Navigator.pop(sheet);
                  planRoute(station);
                },
              ),
              if (widget.customer &&
                  (station.reservable ||
                      station.reservationPolicy == 'canonical'))
                FilledButton(
                  onPressed: () {
                    if (!active(epoch)) return;
                    Navigator.pop(sheet);
                    Navigator.pop(context, station);
                  },
                  child: Text(t('View charging options', 'عرض خيارات الشحن')),
                ),
            ],
          ),
        )),
      ),
    );
  }

  double? coordinate(String input, double limit) {
    final text = vendorDigits(input.trim());
    if (!RegExp(r'^-?[0-9]{1,3}(\.[0-9]{1,6})?$').hasMatch(text)) return null;
    final number = double.tryParse(text);
    return number != null && number.isFinite && number.abs() <= limit ? number : null;
  }

  void choosePoint(LatLng point) {
    locationGeneration++;
    setState(() {
      locating = false;
      fix = null;
      locationError = null;
      searchPoint = point;
    });
  }

  void submitManual(int epoch) {
    if (!active(epoch) || widget.onSearch == null || !(manualForm.currentState?.validate() ?? false)) return;
    final lat = coordinate(manualLatitude.text, 90)!;
    final lng = coordinate(manualLongitude.text, 180)!;
    FocusManager.instance.primaryFocus?.unfocus();
    choosePoint(LatLng(lat, lng));
    if (enabled && lat.abs() <= 85.05112878) controller.move(searchPoint!, 13);
  }

  @override
  Widget build(BuildContext context) {
    final points = widget.stations.where(stationHasMapPoint).toList();
    final epoch = consentEpoch;
    if (!available) return Scaffold(
      appBar: AppBar(title: Text(t('Station map', 'خريطة المحطات'))),
      body: Center(child: Text(t('Map hidden. Return to discovery if the account changed.',
        'الخريطة مخفية. ارجع إلى البحث إذا تغير الحساب.'))),
    );
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
              onPressed: () { if (active(epoch)) planRoute(); },
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
                      if (!active(epoch)) return;
                      choosePoint(LatLng(place.point.latitude, place.point.longitude));
                      if (enabled && place.point.latitude.abs() <= 85.05112878)
                        controller.move(searchPoint!, 13);
                    },
                  ),
                ],
              ),
            OutlinedButton.icon(
              key: const Key('native-location-once'),
              onPressed: locating ? null : () { if (active(epoch)) locate(); },
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
                onPressed: () { if (active(epoch)) setState(() => enabled = true); },
                child: Text(
                  t('Enable interactive map', 'تفعيل الخريطة التفاعلية'),
                ),
              ),
            if (enabled)
              TextButton(key: const Key('disable-map'),
                onPressed: () { if (active(epoch)) setState(() => enabled = false); },
                child: Text(t('Disable interactive map', 'إيقاف الخريطة التفاعلية'))),
            if (widget.onSearch != null && (enabled || searchPoint != null))
              Padding(
                padding: const EdgeInsets.all(8),
                child: Column(
                  children: [
                    Text(
                      t(
                        'Choose a search centre on the map or enter it manually. Coordinates are sent only when you search.',
                        'اختر مركز البحث على الخريطة أو أدخله يدوياً. تُرسل الإحداثيات عند البحث فقط.',
                      ),
                    ),
                    if (searchPoint != null)
                      Text('${searchPoint!.latitude}, ${searchPoint!.longitude}',
                        key: const Key('map-selected-centre'), textDirection: TextDirection.ltr),
                    if (searchPoint != null)
                      FilledButton(
                        onPressed: () {
                          if (!active(epoch) || searchPoint == null) return;
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
                      if (active(epoch) && enabled && camera.zoom != zoom)
                        setState(() => zoom = camera.zoom);
                    },
                    onTap: (_, point) { if (active(epoch) && enabled) choosePoint(point); },
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
                        final markerLabel = group.length == 1 ? station.name :
                          t('${group.length} published stations, open list', '${group.length} محطات منشورة، افتح القائمة');
                        return Marker(
                          point: LatLng(station.latitude!, station.longitude!),
                          width: 48,
                          height: 48,
                          child: Semantics(
                            label: markerLabel,
                            button: true,
                            child: IconButton(
                              tooltip: markerLabel,
                              onPressed: () async {
                                if (!active(epoch) || !enabled) return;
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
                                                if (!active(epoch)) return;
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
                          onTap: () { if (active(epoch) && enabled) {
                            launchUrl(Uri.parse('https://www.openstreetmap.org/copyright'),
                              mode: LaunchMode.externalApplication);
                          } },
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            if (widget.onSearch != null)
              ExpansionTile(
                key: const Key('manual-map-centre'),
                title: Text(t('Enter search centre manually', 'أدخل مركز البحث يدوياً')),
                children: [
                  Form(key: manualForm, child: Column(children: [
                    for (final field in [
                      ('map-latitude', 'Latitude', 'خط العرض', manualLatitude, 90.0),
                      ('map-longitude', 'Longitude', 'خط الطول', manualLongitude, 180.0),
                    ]) Padding(
                      padding: const EdgeInsets.all(8),
                      child: TextFormField(
                        key: Key(field.$1),
                        controller: field.$4,
                        textDirection: TextDirection.ltr,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
                        decoration: InputDecoration(labelText: t(field.$2, field.$3)),
                        validator: (value) => coordinate(value ?? '', field.$5) == null
                          ? t('Enter valid coordinates, up to six decimal places.',
                              'أدخل إحداثيات صحيحة، حتى ست منازل عشرية.') : null,
                      ),
                    ),
                    Text(t('Choosing a centre does not send a search or enable tiles.',
                      'اختيار المركز لا يرسل بحثاً ولا يفعّل الخرائط.')),
                    OutlinedButton(key: const Key('choose-manual-map-centre'),
                      onPressed: () => submitManual(epoch),
                      child: Text(t('Choose centre', 'اختيار المركز'))),
                  ])),
                ],
              ),
            ExpansionTile(
              key: const Key('station-map-list'),
              title: Text(t('Published stations: list alternative', 'المحطات المنشورة: عرض قائمة بديلة')),
              children: [
                for (final station in widget.stations)
                  ListTile(
                    title: Text(station.name),
                    subtitle: Text('${station.source} · ${stationHasMapPoint(station)
                      ? t('Published location; availability unverified.', 'موقع منشور؛ التوفر غير مؤكد.')
                      : t('Map coordinates unavailable.', 'إحداثيات الخريطة غير متاحة.')}'),
                    trailing: stationHasMapPoint(station) ? const Icon(Icons.chevron_right) : null,
                    onTap: stationHasMapPoint(station) ? () {
                      if (active(epoch)) details(station);
                    } : null,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
