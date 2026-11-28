import 'dart:async';
import 'dart:math' as math;
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

/// Local planning arithmetic only; no telemetry or charging guarantee.
class RouteEnergyReview {
  final double budget, km;
  final double? needed, firstKm;
  const RouteEnergyReview(this.budget, this.km, this.needed, this.firstKm);
  bool? get totalExceeds => needed == null ? null : needed! > budget + 1e-9;
  bool? get firstExceeds => firstKm == null ? null : firstKm! > km + 1e-9;
}

double straightLineKm(GeoPoint a, GeoPoint b) {
  const rad = math.pi / 180;
  final dlat = (b.latitude-a.latitude)*rad;
  final dlng = (b.longitude-a.longitude)*rad;
  final h = math.pow(math.sin(dlat/2),2) +
      math.cos(a.latitude*rad)*math.cos(b.latitude*rad)*math.pow(math.sin(dlng/2),2);
  return 6371*2*math.asin(math.sqrt(h.clamp(0,1)));
}

class RouteEnergyCandidate {
  final Station station;
  final double km;
  const RouteEnergyCandidate(this.station,this.km);
}

/// Current catalog page only; no availability or road reachability claim.
List<RouteEnergyCandidate> routeEnergyCandidates(Map<String,String> raw,
    GeoPoint? origin, List<Station> stations, {String connector = ''}) {
  final review = routeEnergyReview(raw);
  if (review == null || origin == null || stations.length > 25 ||
      !['','CCS2','Type2','CHAdeMO'].contains(connector)) return [];
  final counts = <int,int>{};
  for(final s in stations) {
    counts[s.id] = (counts[s.id] ?? 0)+1;
  }
  final results = <RouteEnergyCandidate>[];
  for(final s in stations) {
    final lat = s.latitude, lng = s.longitude;
    if(s.id < 1 || s.id > 9007199254740991 || counts[s.id] != 1 ||
       s.name.trim().isEmpty || !['SIMULATED','DISCOVERY_ONLY'].contains(s.source) ||
       !['CCS2','Type2','CHAdeMO'].contains(s.connector) ||
       (connector.isNotEmpty && s.connector != connector) ||
       s.powerKw < 1 || s.powerKw > 1000 ||
       lat == null || lng == null || !lat.isFinite || !lng.isFinite ||
       lat.abs() > 90 || lng.abs() > 180) continue;
    final km = straightLineKm(origin,GeoPoint(lat,lng));
    if (km <= review.km+1e-9) results.add(RouteEnergyCandidate(s,km));
  }
  results.sort((a,b) {
    final distance = a.km.compareTo(b.km);
    return distance == 0 ? a.station.id.compareTo(b.station.id) : distance;
  });
  return results.take(5).toList(growable:false);
}

RouteEnergyReview? routeEnergyReview(Map<String, String> raw, {
  double? routeMeters, GeoPoint? origin, GeoPoint? firstStop,
}) {
  final values = <String,double>{};
  for (final field in [
    ('capacity',1.0,250.0), ('soc',0.0,100.0),
    ('reserve',0.0,100.0), ('consumption',1.0,100.0),
  ]) {
    final text = vendorDigits((raw[field.$1] ?? '').trim());
    if (!RegExp(r'^[0-9]{1,3}(?:\.[0-9]{1,2})?$').hasMatch(text)) return null;
    final n = double.tryParse(text);
    if (n == null || !n.isFinite || n < field.$2 || n > field.$3) return null;
    values[field.$1] = n;
  }
  if (values['reserve']! >= values['soc']!) return null;
  final budget = values['capacity']! * (values['soc']!-values['reserve']!)/100;
  final km = budget/values['consumption']!*100;
  double? firstKm;
  if (origin != null && firstStop != null) {
    firstKm = straightLineKm(origin,firstStop);
  }
  final needed = routeMeters != null && routeMeters.isFinite &&
      routeMeters >= 0 && routeMeters <= 40000000
      ? routeMeters/1000*values['consumption']!/100 : null;
  return RouteEnergyReview(budget,km,needed,firstKm);
}

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

class _RoutePlannerViewState extends State<RoutePlannerView> with WidgetsBindingObserver {
  final form = GlobalKey<FormState>();
  final originLat = TextEditingController(),
      originLng = TextEditingController(),
      destinationLat = TextEditingController(),
      destinationLng = TextEditingController();
  final stops = <Station>[];
  final energyFields = {for (final key in ['capacity','soc','reserve','consumption']) key: TextEditingController()};
  void clearEnergy() { for(final field in energyFields.values) { field.clear(); } }
  DiscoveryCapabilities? capabilities;
  RouteEstimate? estimate;
  bool consent = false, loading = false, tiles = false, foreground = true;
  bool checkingCapabilities = false;
  int capabilityGeneration = 0;
  StreamSubscription<int>? sessionSubscription;
  String requiredConnector = '';
  // Do not reuse a prior account's in-memory station choices when an
  // authenticated discovery client changes before new catalog results arrive.
  bool awaitingFreshAccountCatalog = false;
  bool matchesAdvertisedConnector(Station station) => requiredConnector.isEmpty || station.connector == requiredConnector;
  bool get incompatibleStops => stops.any((stop) => !matchesAdvertisedConnector(stop));
  Object? error;
  int generation = 0;
  bool get sessionCurrent => foreground && (widget.client?.currentSession() ?? true);
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
    final lifecycle = WidgetsBinding.instance.lifecycleState;
    foreground = lifecycle == null || lifecycle == AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    subscribeSession();
    tiles = foreground && widget.tilesEnabled;
    if (sessionCurrent && widget.origin != null) {
      originLat.text = '${widget.origin!.latitude}';
      originLng.text = '${widget.origin!.longitude}';
    }
    if (sessionCurrent && widget.initialStop != null && valid(widget.initialStop!))
      stops.add(widget.initialStop!);
    loadCapabilities();
  }

  void resetPrivateRoute({required bool accountChanged}) {
    generation++;
    capabilityGeneration++;
    checkingCapabilities = false;
    originLat.clear();
    originLng.clear();
    destinationLat.clear();
    destinationLng.clear();
    clearEnergy();
    stops.clear();
    requiredConnector = '';
    consent = false;
    estimate = null;
    error = null;
    loading = false;
    tiles = false;
    if (accountChanged) {
      awaitingFreshAccountCatalog = true;
      capabilities = null;
    }
  }

  void subscribeSession() {
    final client = widget.client;
    if (client == null) return;
    sessionSubscription = client.sessionEvents?.listen((_) {
      if (mounted && identical(widget.client, client) && !client.currentSession()) {
        setState(() => resetPrivateRoute(accountChanged: true));
      }
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final resumed = state == AppLifecycleState.resumed;
    if (!mounted || resumed == foreground) return;
    setState(() {
      foreground = resumed;
      if (!resumed) resetPrivateRoute(accountChanged: false);
    });
  }

  @override
  void didUpdateWidget(covariant RoutePlannerView oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.client, widget.client)) {
      unawaited(sessionSubscription?.cancel());
      resetPrivateRoute(accountChanged: true);
      subscribeSession();
      loadCapabilities();
    } else if (!(widget.client?.currentSession() ?? true)) {
      resetPrivateRoute(accountChanged: true);
    } else if (awaitingFreshAccountCatalog &&
        !identical(oldWidget.stations, widget.stations)) {
      awaitingFreshAccountCatalog = false;
    }
  }

  Future<void> loadCapabilities() async {
    final client = widget.client;
    if (client == null || !sessionCurrent || checkingCapabilities) return;
    final current = generation;
    final read = ++capabilityGeneration;
    setState(() => checkingCapabilities = true);
    bool active() => mounted && read == capabilityGeneration &&
        identical(widget.client, client) && sessionCurrent;
    try {
      final result = await client.capabilities();
      if (active()) {
        if (!result.routing) {
          // A provider withdrawal supersedes any earlier itinerary consent,
          // even if the driver edited that itinerary while checking.
          invalidate();
          setState(() => capabilities = result);
        } else if (current == generation) {
          setState(() => capabilities = result);
        }
      }
    } catch (_) {
      if (active()) {
        invalidate();
        setState(() => capabilities = null);
      }
    } finally {
      // An itinerary edit can discard the response without stranding retry.
      // A prior account/background read cannot clear a newer read's spinner.
      if (active()) setState(() => checkingCapabilities = false);
    }
  }

  void invalidate() {
    generation++;
    setState(() {
      // Consent applies to the exact origin, destination and stops reviewed.
      // Editing any route input must not silently authorize sending a new
      // itinerary to the third-party routing provider.
      consent = false;
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
    if (!mounted || !sessionCurrent || !(form.currentState?.validate() ?? false)) return null;
    return [
      point(originLat, originLng),
      ...stops.map((s) => GeoPoint(s.latitude!, s.longitude!)),
      point(destinationLat, destinationLng),
    ];
  }

  Future<void> preview() async {
    final currentPoints = points();
    if (currentPoints == null ||
        loading || incompatibleStops ||
        !consent ||
        capabilities?.routing != true ||
        widget.client == null || !sessionCurrent)
      return;
    final client = widget.client!;
    final current = ++generation;
    setState(() {
      loading = true;
      error = null;
      estimate = null;
    });
    try {
      final result = await client.route(
        currentPoints,
        EvStrings.of(context).arabic ? 'ar' : 'en',
      );
      if (mounted && generation == current && identical(widget.client, client) && sessionCurrent) setState(() => estimate = result);
    } catch (e) {
      if (mounted && generation == current && identical(widget.client, client) && sessionCurrent) setState(() => error = e);
    } finally {
      if (mounted && generation == current && identical(widget.client, client)) {
        if (sessionCurrent) {
          setState(() => loading = false);
        } else if (!client.currentSession()) {
          setState(() => resetPrivateRoute(accountChanged: true));
        }
      }
    }
  }

  Future<void> navigate() async {
    final currentPoints = points();
    if (currentPoints == null || incompatibleStops) return;
    final intentGeneration = generation;
    final intentClient = widget.client;
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
    if (accepted != true || !mounted || generation != intentGeneration ||
        !identical(widget.client, intentClient) || !sessionCurrent || incompatibleStops) return;
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
      if (mounted && sessionCurrent && generation == intentGeneration &&
          identical(widget.client, intentClient))
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

  Widget field(String key, TextEditingController value, bool latitude, String endpointTitle) =>
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
          labelText: '$endpointTitle: ${latitude
              ? t('Latitude (−90 to 90)', 'خط العرض (−90 إلى 90)')
              : t('Longitude (−180 to 180)', 'خط الطول (−180 إلى 180)')}',
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
          Semantics(header: true, child: Text(title, style: Theme.of(context).textTheme.titleLarge)),
          field('$prefix-lat', lat, true, title),
          field('$prefix-lng', lng, false, title),
          if (capabilities?.geocoding == true && widget.client != null)
            ExpansionTile(
              title: Text(t('Find an address', 'البحث عن عنوان')),
              children: [
                AddressSearch(
                  client: widget.client!,
                  onSelected: (place) {
                    if (!mounted || !sessionCurrent) return;
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
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    generation++;
    for (final c in [originLat, originLng, destinationLat, destinationLng, ...energyFields.values]) {
      c.dispose();
    }
    super.dispose();
  }

  Widget energyReviewPanel() {
    GeoPoint? origin;
    try { origin = point(originLat, originLng); } catch (_) {}
    final first = stops.isEmpty || !valid(stops.first) ? null :
      GeoPoint(stops.first.latitude!,stops.first.longitude!);
    final raw = {for(final e in energyFields.entries)e.key:e.value.text};
    final result = routeEnergyReview(raw, routeMeters:estimate?.meters, origin:origin, firstStop:first);
    final candidates = awaitingFreshAccountCatalog ? <RouteEnergyCandidate>[] :
        routeEnergyCandidates(raw,origin,widget.stations,connector:requiredConnector);
    String number(double n) => NumberFormat('0.0',EvStrings.of(context).arabic?'ar':'en').format(n);
    return ExpansionTile(
      key: const Key('journey-energy-review'),
      title: Text(t('Review estimated battery energy','مراجعة طاقة البطارية التقديرية')),
      children: [
        Text(t('Driver-entered assumptions only','افتراضات يُدخلها السائق فقط')),
        for(final field in [
          ('capacity','Usable battery capacity (kWh)','سعة البطارية القابلة للاستخدام (كيلوواط ساعة)'),
          ('soc','Starting charge (%)','الشحن عند البداية (%)'),
          ('reserve','Minimum reserve (%)','الاحتياطي الأدنى (%)'),
          ('consumption','Assumed consumption (kWh/100 km)','الاستهلاك المفترض (كيلوواط ساعة/١٠٠ كم)'),
        ]) Padding(
          padding: const EdgeInsets.all(8),
          child: TextField(
            key: Key('energy-${field.$1}'), controller: energyFields[field.$1],
            textDirection: TextDirection.ltr,
            maxLength: 6,
            keyboardType: const TextInputType.numberWithOptions(decimal:true),
            decoration: InputDecoration(labelText:t(field.$2,field.$3)),
            onChanged: (_) { if (sessionCurrent) setState(() {}); },
          ),
        ),
        Text(t(
          'Local arithmetic only. Not live vehicle SOC, verified range, station availability, road reachability or charging advice. Weather, terrain and charging losses are excluded. No recharge is assumed. These inputs are never sent to the provider.',
          'حساب محلي فقط. لا يمثل شحن المركبة الفعلي أو مدى مؤكداً أو توفر المحطات أو إمكانية الوصول أو نصيحة شحن. لا يشمل الطقس أو التضاريس أو خسائر الشحن. لا يفترض إعادة الشحن. لا تُرسل المدخلات إلى المزود.',
        )),
        if(result == null && raw.values.any((v)=>v.trim().isNotEmpty))
          Semantics(liveRegion:true,child: Text(t(
            'Enter capacity 1–250, charge and reserve 0–100 (reserve below charge), and consumption 1–100; up to two decimals.',
            'أدخل السعة ١–٢٥٠ والشحن والاحتياطي ٠–١٠٠ (الاحتياطي أقل من الشحن) والاستهلاك ١–١٠٠؛ حتى منزلتين عشريتين.',
          ))),
        if(result != null) Semantics(
          liveRegion:true,
          child: Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
            Text(t('Estimated energy above reserve: ${number(result.budget)} kWh · assumed range: ${number(result.km)} km',
              'الطاقة التقديرية فوق الاحتياطي: ${number(result.budget)} كيلوواط ساعة · المدى المفترض: ${number(result.km)} كم')),
            if(result.firstKm != null) ...[
              Text(t('First stop straight-line distance: ${number(result.firstKm!)} km',
                'المسافة الجوية إلى أول توقف: ${number(result.firstKm!)} كم')),
              Text(result.firstExceeds == true ? t('First stop exceeds the assumed range even in a straight line.',
                'أول توقف يتجاوز المدى المفترض حتى بخط مستقيم.') :
                t('First stop not ruled out by straight-line distance; road reachability is unverified.',
                  'لا تستبعد المسافة الجوية أول توقف؛ الوصول عبر الطريق غير مؤكد.')),
            ],
            if(result.needed != null) ...[
              Text(t('Whole-route assumed energy: ${number(result.needed!)} kWh',
                'الطاقة المفترضة للمسار الكامل: ${number(result.needed!)} كيلوواط ساعة')),
              Text(result.totalExceeds == true ? t('Whole-route estimate exceeds starting energy above reserve. Charging may be needed.',
                'يتجاوز تقدير المسار الكامل الطاقة عند البداية فوق الاحتياطي. قد يلزم الشحن.') :
                t('Whole-route estimate is within the assumed starting energy. This is not a reachability guarantee.',
                  'تقدير المسار الكامل ضمن طاقة البداية المفترضة. لا يضمن ذلك الوصول.')),
            ] else Text(t('Preview a provider route for a whole-route energy estimate.',
              'عاين مسار المزود لتقدير طاقة المسار الكامل.')),
            if(origin != null && !awaitingFreshAccountCatalog) ...[
              Text(t('First-stop candidates from this result page (nearest straight-line distance first). Review a candidate in the existing charging-stop selector; nothing is selected automatically.',
                'مرشحات التوقف الأول من صفحة النتائج الحالية (الأقرب بالمسافة الجوية أولاً). راجع المرشح في محدد توقفات الشحن؛ لا يُختار شيء تلقائياً.')),
              if(candidates.isEmpty)
                Text(t('No supported candidate on this page within the assumed straight-line range. Search another area or revise your assumptions.',
                  'لا يوجد مرشح مدعوم في هذه الصفحة ضمن المدى الجوي المفترض. ابحث في منطقة أخرى أو راجع افتراضاتك.')),
              for(final candidate in candidates)
                ListTile(
                  key: Key('energy-candidate-${candidate.station.id}'),
                  title: Text(candidate.station.name),
                  subtitle: Text(t(
                    '${number(candidate.km)} km straight line · ${candidate.station.connector} · ${candidate.station.powerKw} kW advertised · ${candidate.station.source}',
                    '${number(candidate.km)} كم جواً · ${candidate.station.connector} · ${candidate.station.powerKw} كيلوواط معلنة · ${candidate.station.source}')),
                ),
              if(candidates.isNotEmpty)
                Text(t('Candidates are not verified reachable by road, compatible, available or reservable.',
                  'لم يتم التحقق من إمكانية الوصول إلى المرشحات عبر الطريق أو توافقها أو توفرها أو قابليتها للحجز.')),
            ],
          ]),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    if (!foreground) {
      return Scaffold(
        appBar: AppBar(title: Text(t('Plan charging route', 'تخطيط مسار الشحن'))),
        body: Center(child: Text(t('Route planning hidden while the app is inactive.', 'تخطيط المسار مخفي أثناء عدم نشاط التطبيق.'))),
      );
    }
    // The widget can outlive a logout even when its DiscoveryClient object
    // remains identical. A parent refresh must never redraw old account
    // coordinates, catalog stops, estimates, or map tiles in the new epoch.
    if (!sessionCurrent) {
      return Scaffold(
        appBar: AppBar(title: Text(t('Plan charging route', 'تخطيط مسار الشحن'))),
        body: Center(child: Text(t('Account changed. Reopen route planning.', 'تغير الحساب. أعد فتح تخطيط المسار.'))),
      );
    }
    final consentGeneration = generation;
    final consentClient = widget.client;
    final renderedStations = widget.stations;
    bool currentIntent() => mounted && sessionCurrent &&
        generation == consentGeneration &&
        identical(widget.client, consentClient);
    bool currentCatalog() => currentIntent() &&
        !awaitingFreshAccountCatalog &&
        identical(renderedStations, widget.stations);
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
                                if (!currentIntent() || i >= stops.length) return;
                                final s = stops.removeAt(i);
                                stops.insert(i - 1, s);
                                invalidate();
                              },
                        icon: const Icon(Icons.arrow_upward),
                      ),
                      IconButton(
                        tooltip: t('Remove stop', 'إزالة التوقف'),
                        onPressed: () {
                          if (!currentIntent() || i >= stops.length) return;
                          stops.removeAt(i);
                          invalidate();
                        },
                        icon: const Icon(Icons.close),
                      ),
                    ],
                  ),
                ),
              ),
            InputDecorator(
              decoration: InputDecoration(
                labelText: t('Filter by advertised connector', 'تصفية حسب الموصل المعلن'),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  key: const Key('route-connector-filter'),
                  value: requiredConnector,
                  isExpanded: true,
                  items: [
                    for (final option in [
                      ('', 'All advertised connectors', 'كل الموصلات المعلنة'),
                      ('CCS2', 'CCS2', 'CCS2'),
                      ('Type2', 'Type2', 'Type2'),
                      ('CHAdeMO', 'CHAdeMO', 'CHAdeMO'),
                    ])
                      DropdownMenuItem(
                        value: option.$1,
                        child: Text(t(option.$2, option.$3)),
                      ),
                  ],
                  onChanged: (value) {
                    if (!currentIntent() || !['','CCS2','Type2','CHAdeMO'].contains(value)) return;
                    requiredConnector = value ?? '';
                    invalidate();
                  },
                ),
              ),
            ),
            Text(t(
              'These are catalog connector labels, not verified compatibility with your vehicle, plug, energy needs or live charger status.',
              'هذه تسميات موصلات الكتالوج وليست تحققاً من التوافق مع مركبتك أو القابس أو احتياجات الطاقة أو حالة الشاحن المباشرة.',
            )),
            if (incompatibleStops)
              Semantics(
                liveRegion: true,
                child: Text(
                  t(
                    'Some selected stops do not match this connector. Remove them or change the filter before previewing or navigating.',
                    'بعض التوقفات المحددة لا تطابق هذا الموصل. أزلها أو غيّر التصفية قبل المعاينة أو الملاحة.',
                  ),
                  key: const Key('route-connector-conflict'),
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
                for (final station in awaitingFreshAccountCatalog
                    ? const <Station>[]
                    : widget.stations)
                  CheckboxListTile(
                    key: Key('route-stop-${station.id}'),
                    value: stops.any((s) => s.id == station.id),
                    title: Text(station.name),
                    subtitle: Text(
                      valid(station)
                          ? '${station.connector} · ${station.powerKw} kW · ${station.source}${matchesAdvertisedConnector(station) ? '' : ' · ${t('Different advertised connector', 'موصل معلن مختلف')}'}'
                          : t(
                              'Coordinates unavailable',
                              'الإحداثيات غير متاحة',
                            ),
                    ),
                    onChanged:
                        !valid(station) ||
                            (!stops.any((s) => s.id == station.id) &&
                                (!matchesAdvertisedConnector(station) || stops.length >= 3))
                        ? null
                        : (selected) {
                            if (!currentCatalog() || !valid(station) ||
                                !widget.stations.any((s) => identical(s, station))) return;
                            if (selected == true) {
                              if (stops.length >= 3 || !matchesAdvertisedConnector(station) ||
                                  stops.any((s) => s.id == station.id)) return;
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
                  capabilities == null
                      ? 'Routing-service configuration is unverified. Check it before requesting a provider estimate.'
                      : 'Provider route estimates are not configured. Manual coordinates and external navigation remain available.',
                  capabilities == null
                      ? 'لم يتم التحقق من إعدادات خدمة المسار. تحقق منها قبل طلب تقدير المزود.'
                      : 'تقديرات مسار المزود غير مهيأة. تبقى الإحداثيات اليدوية والملاحة الخارجية متاحتين.',
                ),
              ),
            if (capabilities == null && widget.client != null)
              OutlinedButton.icon(
                key: const Key('route-capability-check'),
                onPressed: checkingCapabilities ? null : loadCapabilities,
                icon: checkingCapabilities
                    ? const SizedBox(width: 18, height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.refresh),
                label: Text(checkingCapabilities
                    ? t('Checking routing services…', 'جارٍ التحقق من خدمات المسار…')
                    : t('Check routing services', 'التحقق من خدمات المسار')),
              ),
            if (capabilities?.routing == true)
              CheckboxListTile(
                key: const Key('route-provider-consent'),
                value: consent,
                onChanged: (v) {
                  if (!mounted || !sessionCurrent ||
                      generation != consentGeneration ||
                      !identical(widget.client, consentClient)) return;
                  if (v != true) {
                    // Withdrawal must also discard an in-flight or displayed
                    // route, including its derived whole-route energy estimate.
                    invalidate();
                  } else {
                    setState(() => consent = true);
                  }
                },
                title: Text(
                  t(
                    'Send these coordinates and stops to Geoapify for a route estimate.',
                    'إرسال هذه الإحداثيات والتوقفات إلى Geoapify لتقدير المسار.',
                  ),
                ),
              ),
            FilledButton.icon(
              key: const Key('route-preview'),
              onPressed: loading || incompatibleStops || !consent || capabilities?.routing != true
                  ? null
                  : () { if (currentIntent()) preview(); },
              icon: const Icon(Icons.route),
              label: Text(t('Preview provider route', 'معاينة مسار المزود')),
            ),
            if (loading)
              Semantics(
                liveRegion: true,
                child: LinearProgressIndicator(
                  semanticsLabel: t('Estimating route', 'جارٍ تقدير المسار'),
                ),
              ),
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
                onPressed: () { if (currentIntent() && estimate != null) setState(() => tiles = true); },
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
            energyReviewPanel(),
            OutlinedButton.icon(
              key: const Key('route-navigation'),
              onPressed: incompatibleStops ? null : () { if (currentIntent()) navigate(); },
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
