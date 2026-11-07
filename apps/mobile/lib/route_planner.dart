import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:intl/intl.dart' show NumberFormat;
import 'api.dart';
import 'address_search.dart';
import 'discovery_client.dart';
import 'strings.dart';
import 'vendor_forms.dart' show vendorDigits;

class RoutePlannerView extends StatefulWidget {
  final DiscoveryClient? client;
  final List<Station> stations;
  final GeoPoint? origin;
  final Station? initialStop;
  final bool tilesEnabled;
  final TileProvider? tileProvider;
  final Future<bool> Function(Uri)? navigate;
  const RoutePlannerView({
    super.key,
    this.client,
    required this.stations,
    this.origin,
    this.initialStop,
    this.tilesEnabled = false,
    this.tileProvider,
    this.navigate,
  });
  @override
  State<RoutePlannerView> createState() => _RoutePlannerViewState();
}

class _RoutePlannerViewState extends State<RoutePlannerView> {
  final form = GlobalKey<FormState>();
  final originLat = TextEditingController(),
      originLng = TextEditingController(),
      destinationLat = TextEditingController(),
      destinationLng = TextEditingController();
  final stops = <Station>[];
  DiscoveryCapabilities? capabilities;
  RouteEstimate? estimate;
  bool consent = false, loading = false, tiles = false;
  Object? error;
  int generation = 0;
  String t(String en, String ar) => EvStrings.of(context).arabic ? ar : en;
  bool valid(Station station) =>
      station.latitude != null &&
      station.longitude != null &&
      station.latitude!.isFinite &&
      station.longitude!.isFinite &&
      station.latitude!.abs() <= 90 &&
      station.longitude!.abs() <= 180;
  @override
  void initState() {
    super.initState();
    tiles = widget.tilesEnabled;
    if (widget.origin != null) {
      originLat.text = '${widget.origin!.latitude}';
      originLng.text = '${widget.origin!.longitude}';
    }
    if (widget.initialStop != null && valid(widget.initialStop!))
      stops.add(widget.initialStop!);
    loadCapabilities();
  }

  Future<void> loadCapabilities() async {
    try {
      final result = await widget.client?.capabilities();
      if (mounted) setState(() => capabilities = result);
    } catch (_) {
      if (mounted) setState(() => capabilities = null);
    }
  }

  void invalidate() {
    generation++;
    setState(() {
      estimate = null;
      error = null;
      loading = false;
    });
  }

  GeoPoint point(TextEditingController lat, TextEditingController lng) =>
      GeoPoint(
        double.parse(vendorDigits(lat.text.trim())),
        double.parse(vendorDigits(lng.text.trim())),
      );
  List<GeoPoint>? points() {
    if (!form.currentState!.validate()) return null;
    return [
      point(originLat, originLng),
      ...stops.map((s) => GeoPoint(s.latitude!, s.longitude!)),
      point(destinationLat, destinationLng),
    ];
  }

  Future<void> preview() async {
    final currentPoints = points();
    if (currentPoints == null ||
        !consent ||
        capabilities?.routing != true ||
        widget.client == null)
      return;
    final current = ++generation;
    setState(() {
      loading = true;
      error = null;
      estimate = null;
    });
    try {
      final result = await widget.client!.route(
        currentPoints,
        EvStrings.of(context).arabic ? 'ar' : 'en',
      );
      if (mounted && generation == current) setState(() => estimate = result);
    } catch (e) {
      if (mounted && generation == current) setState(() => error = e);
    } finally {
      if (mounted && generation == current) setState(() => loading = false);
    }
  }

  Future<void> navigate() async {
    final currentPoints = points();
    if (currentPoints == null) return;
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        scrollable: true,
        title: Text(t('Open Google Maps?', 'فتح خرائط Google؟')),
        content: Text(
          t(
            'Send origin, destination and selected stops to Google Maps? Its privacy policy applies. Its route can differ from the provider preview. Stops are not reserved and charging availability is unverified.',
            'إرسال نقطة البداية والوجهة والتوقفات المحددة إلى خرائط Google؟ تطبق سياسة خصوصية المزود وقد يختلف المسار عن المعاينة. التوقفات غير محجوزة وتوفر الشحن غير مؤكد.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialog, false),
            child: Text(t('Cancel', 'إلغاء')),
          ),
          FilledButton(
            key: const Key('navigation-consent'),
            onPressed: () => Navigator.pop(dialog, true),
            child: Text(t('Continue', 'متابعة')),
          ),
        ],
      ),
    );
    if (accepted != true || !mounted) return;
    final uri = navigationUri(
      currentPoints.first,
      currentPoints.last,
      currentPoints.sublist(1, currentPoints.length - 1),
    );
    try {
      final opened =
          await (widget.navigate?.call(uri) ??
              launchUrl(uri, mode: LaunchMode.externalApplication));
      if (!opened) throw StateError('unavailable');
    } catch (_) {
      if (mounted)
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              t(
                'Navigation unavailable. Your coordinates remain available for manual use.',
                'الملاحة غير متاحة. تبقى الإحداثيات متاحة للاستخدام اليدوي.',
              ),
            ),
          ),
        );
    }
  }

  Widget field(String key, TextEditingController value, bool latitude) =>
      TextFormField(
        key: Key(key),
        controller: value,
        keyboardType: const TextInputType.numberWithOptions(
          signed: true,
          decimal: true,
        ),
        textDirection: TextDirection.ltr,
        onChanged: (_) => invalidate(),
        decoration: InputDecoration(
          labelText: latitude
              ? t('Latitude (−90 to 90)', 'خط العرض (−90 إلى 90)')
              : t('Longitude (−180 to 180)', 'خط الطول (−180 إلى 180)'),
        ),
        validator: (text) {
          final n = double.tryParse(vendorDigits(text?.trim() ?? ''));
          return n == null || !n.isFinite || n.abs() > (latitude ? 90 : 180)
              ? t(
                  'Enter a coordinate within the displayed range.',
                  'أدخل إحداثية ضمن النطاق المعروض.',
                )
              : null;
        },
      );
  Widget endpoint(
    String title,
    TextEditingController lat,
    TextEditingController lng,
    String prefix,
  ) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          field('$prefix-lat', lat, true),
          field('$prefix-lng', lng, false),
          if (capabilities?.geocoding == true && widget.client != null)
            ExpansionTile(
              title: Text(t('Find an address', 'البحث عن عنوان')),
              children: [
                AddressSearch(
                  client: widget.client!,
                  onSelected: (place) {
                    lat.text = '${place.point.latitude}';
                    lng.text = '${place.point.longitude}';
                    invalidate();
                  },
                ),
              ],
            ),
        ],
      ),
    ),
  );
  @override
  void dispose() {
    generation++;
    for (final c in [originLat, originLng, destinationLat, destinationLng]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final lines =
        estimate?.segments
            .where((line) => line.every((p) => p.latitude.abs() <= 85.05112878))
            .toList() ??
        <List<GeoPoint>>[];
    return Scaffold(
      appBar: AppBar(title: Text(t('Plan charging route', 'تخطيط مسار الشحن'))),
      body: Form(
        key: form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              t(
                'Plan up to three charging stops from this catalog page. Adding a stop does not reserve it or verify availability, compatibility, energy or price. Coordinates and itinerary stay in memory.',
                'خطط لما يصل إلى ثلاث توقفات شحن من صفحة النتائج الحالية. إضافة توقف لا تحجزه ولا تثبت التوفر أو التوافق أو الطاقة أو السعر. تبقى الإحداثيات والمسار في الذاكرة.',
              ),
            ),
            endpoint(
              t('Origin', 'نقطة البداية'),
              originLat,
              originLng,
              'origin',
            ),
            endpoint(
              t('Destination', 'الوجهة'),
              destinationLat,
              destinationLng,
              'destination',
            ),
            Text(
              t('Ordered charging stops', 'توقفات الشحن بالترتيب'),
              style: Theme.of(context).textTheme.titleLarge,
            ),
            for (var i = 0; i < stops.length; i++)
              Card(
                child: ListTile(
                  title: Text('${i + 1}. ${stops[i].name}'),
                  subtitle: Text(
                    '${stops[i].source} · ${t('Not reserved', 'غير محجوز')}',
                  ),
                  trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      IconButton(
                        tooltip: t('Move earlier', 'نقل للأعلى'),
                        onPressed: i == 0
                            ? null
                            : () {
                                final s = stops.removeAt(i);
                                stops.insert(i - 1, s);
                                invalidate();
                              },
                        icon: const Icon(Icons.arrow_upward),
                      ),
                      IconButton(
                        tooltip: t('Remove stop', 'إزالة التوقف'),
                        onPressed: () {
                          stops.removeAt(i);
                          invalidate();
                        },
                        icon: const Icon(Icons.close),
                      ),
                    ],
                  ),
                ),
              ),
            ExpansionTile(
              title: Text(
                t(
                  'Add charging stops from results',
                  'إضافة توقفات شحن من النتائج',
                ),
              ),
              children: [
                for (final station in widget.stations)
                  CheckboxListTile(
                    key: Key('route-stop-${station.id}'),
                    value: stops.any((s) => s.id == station.id),
                    title: Text(station.name),
                    subtitle: Text(
                      valid(station)
                          ? '${station.connector} · ${station.powerKw} kW · ${station.source}'
                          : t(
                              'Coordinates unavailable',
                              'الإحداثيات غير متاحة',
                            ),
                    ),
                    onChanged:
                        !valid(station) ||
                            (!stops.any((s) => s.id == station.id) &&
                                stops.length >= 3)
                        ? null
                        : (selected) {
                            if (selected == true) {
                              stops.add(station);
                            } else {
                              stops.removeWhere((s) => s.id == station.id);
                            }
                            invalidate();
                          },
                  ),
              ],
            ),
            if (capabilities?.routing != true)
              Text(
                t(
                  'Provider route estimates are not configured. Manual coordinates and external navigation remain available.',
                  'تقديرات مسار المزود غير مهيأة. تبقى الإحداثيات اليدوية والملاحة الخارجية متاحتين.',
                ),
              ),
            if (capabilities?.routing == true)
              CheckboxListTile(
                key: const Key('route-provider-consent'),
                value: consent,
                onChanged: (v) => setState(() => consent = v ?? false),
                title: Text(
                  t(
                    'Send these coordinates and stops to Geoapify for a route estimate.',
                    'إرسال هذه الإحداثيات والتوقفات إلى Geoapify لتقدير المسار.',
                  ),
                ),
              ),
            FilledButton.icon(
              key: const Key('route-preview'),
              onPressed: loading || !consent || capabilities?.routing != true
                  ? null
                  : preview,
              icon: const Icon(Icons.route),
              label: Text(t('Preview provider route', 'معاينة مسار المزود')),
            ),
            if (loading) const LinearProgressIndicator(),
            if (error != null)
              Semantics(
                liveRegion: true,
                child: Text(
                  error is ApiException && (error as ApiException).status == 429
                      ? t(
                          'Route lookup limit reached. Wait before trying again.',
                          'تم بلوغ حد البحث عن المسار. انتظر قبل المحاولة مجدداً.',
                        )
                      : t(
                          'Route estimate unavailable. Try manual navigation or change your points.',
                          'تقدير المسار غير متاح. استخدم الملاحة اليدوية أو غيّر نقاط المسار.',
                        ),
                ),
              ),
            if (estimate != null)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${NumberFormat('0.0', EvStrings.of(context).arabic ? 'ar' : 'en').format(estimate!.meters / 1000)} ${t('km', 'كم')} · ${NumberFormat('0', EvStrings.of(context).arabic ? 'ar' : 'en').format((estimate!.seconds / 60).ceil())} ${t('min', 'دقيقة')}',
                        textDirection: TextDirection.ltr,
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      Text(
                        t(
                          'Provider driving estimate only. Traffic is not verified; charging, queues and stop duration are excluded. Navigation may choose a different route.',
                          'تقدير قيادة من المزود فقط. حركة المرور غير مؤكدة؛ أوقات الشحن والانتظار والتوقف غير مشمولة. قد تختار الملاحة مساراً مختلفاً.',
                        ),
                      ),
                      const Text(
                        'Routing © Geoapify / OpenStreetMap contributors',
                      ),
                    ],
                  ),
                ),
              ),
            if (estimate != null && !tiles)
              OutlinedButton(
                onPressed: () => setState(() => tiles = true),
                child: Text(
                  t(
                    'Show map — sends viewed area/IP to OpenStreetMap',
                    'عرض الخريطة — يرسل المنطقة وعنوان الإنترنت إلى OpenStreetMap',
                  ),
                ),
              ),
            if (tiles && lines.isNotEmpty)
              SizedBox(
                height: 300,
                child: FlutterMap(
                  options: MapOptions(
                    initialCenter: LatLng(
                      lines.first.first.latitude,
                      lines.first.first.longitude,
                    ),
                    initialZoom: 10,
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
                    PolylineLayer(
                      polylines: lines
                          .map(
                            (line) => Polyline(
                              points: line
                                  .map((p) => LatLng(p.latitude, p.longitude))
                                  .toList(),
                              strokeWidth: 4,
                              color: Theme.of(context).colorScheme.primary,
                            ),
                          )
                          .toList(),
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
            OutlinedButton.icon(
              key: const Key('route-navigation'),
              onPressed: navigate,
              icon: const Icon(Icons.navigation_outlined),
              label: Text(
                t(
                  'Open navigation with selected stops',
                  'فتح الملاحة بالتوقفات المحددة',
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
