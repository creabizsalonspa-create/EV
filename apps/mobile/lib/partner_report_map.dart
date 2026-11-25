import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';
import 'foreground_location.dart';
import 'vendor_forms.dart' show vendorDigits;


/**
 * Bounded rectangular view over existing published records. Antimeridian
 * crossing uses west > east. Map movement itself never performs discovery.
 */
Map<String, String>? discoveryViewportBounds(
  double south, double north, double west, double east,
) {
  if (![south, north, west, east].every((value) => value.isFinite)) return null;
  final s = south.clamp(-90.0, 90.0).toDouble();
  final n = north.clamp(-90.0, 90.0).toDouble();
  if (s >= n) return null;
  final fullWorld = (east - west).abs() >= 360;
  double wrap(double longitude) {
    final shifted = ((longitude + 180) % 360 + 360) % 360;
    return shifted - 180;
  }
  final w = fullWorld ? -180.0 : wrap(west);
  final e = fullWorld ? 180.0 : wrap(east);
  // Match the six-decimal request precision so we cannot send a
  // degenerate rectangle after rounding tiny views.
  final bounds = <String, String>{
    'south': s.toStringAsFixed(6),
    'north': n.toStringAsFixed(6),
    'west': w.toStringAsFixed(6),
    'east': e.toStringAsFixed(6),
  };
  if (bounds['south'] == bounds['north'] ||
      bounds['west'] == bounds['east']) return null;
  return bounds;
}

/// A manually typed map centre is a viewport instruction, never a station
/// location, saved preference, GPS fix or external-provider lookup.
LatLng? manualDiscoveryMapCenter(String latitude, String longitude) {
  final lat = double.tryParse(vendorDigits(latitude.trim()));
  final lon = double.tryParse(vendorDigits(longitude.trim()));
  if (lat == null || lon == null || !lat.isFinite || !lon.isFinite ||
      lat.abs() > 85.05112878 || lon.abs() > 180) return null;
  return LatLng(lat, lon);
}

/// Presentation-only coordinates from an already public, authorized partner
/// catalog page. This is never an OCPI command, payment or booking authority.
class PartnerMapPoint {
  final double latitude;
  final double longitude;
  final String name;
  final String? providerLabel;
  final int? reportedAgeMinutes;
  final bool platformCatalog;
  final String? catalogEvidence;

  const PartnerMapPoint({
    required this.latitude,
    required this.longitude,
    required this.name,
    this.providerLabel,
    this.reportedAgeMinutes,
    this.platformCatalog = false,
    this.catalogEvidence,
  });

  /// A platform pin retains its own provenance; it is never relabeled as a partner.
  static PartnerMapPoint? fromPublishedCatalog(Map<String,dynamic> row) {
    if (row['name'] is! String || row['id'] is! int ||
        (row['id'] as int) < 1 ||
        !['SIMULATED', 'DISCOVERY_ONLY'].contains(row['evidence']) ||
        // Discovery-only location evidence cannot assert booking rights.
        (row['evidence'] == 'DISCOVERY_ONLY' &&
         row['reservable'] == true)) return null;
    final point = fromPublishedLocation(row);
    if (point == null) return null;
    return PartnerMapPoint(latitude:point.latitude,longitude:point.longitude,name:point.name,platformCatalog:true,catalogEvidence:row['evidence'] as String);
  }

  static double? _coordinate(Object? raw) {
    if (raw is num) return raw.toDouble();
    if (raw is String) return double.tryParse(raw.trim());
    return null;
  }

  /// The server accepts polar search points, but Web Mercator cannot show
  /// latitudes above ±85.05112878. Keep those records in the text catalog.
  static PartnerMapPoint? fromPublishedLocation(
    Map<String, dynamic> location, {
    String? providerLabel,
    int? reportedAgeMinutes,
  }) {
    final latitude = _coordinate(location['latitude']);
    final longitude = _coordinate(location['longitude']);
    if (latitude == null ||
        longitude == null ||
        !latitude.isFinite ||
        !longitude.isFinite ||
        latitude.abs() > 85.05112878 ||
        longitude.abs() > 180) {
      return null;
    }
    final rawName = location['name'];
    final rawId = location['id'];
    final name = rawName is String && rawName.trim().isNotEmpty
        ? rawName
        : rawId is String && rawId.trim().isNotEmpty
        ? rawId
        : 'Unverified partner location';
    return PartnerMapPoint(
      latitude: latitude,
      longitude: longitude,
      name: name,
      providerLabel: providerLabel?.trim().isNotEmpty == true
          ? providerLabel
          : null,
      reportedAgeMinutes: reportedAgeMinutes != null &&
              reportedAgeMinutes >= 0
          ? reportedAgeMinutes
          : null,
    );
  }
}


/// Groups only the map-compatible *loaded page* at an approximate 48-pixel
/// world grid. A group represents several distinct source records, never one
/// charger, occupancy measurement, verified operator or charging permission.
/// Stable insertion order preserves each source's original identity and label.
List<List<PartnerMapPoint>> clusterPublishedMapPoints(
  List<PartnerMapPoint> points,
  double zoom,
) {
  if (!zoom.isFinite || zoom < 0 || zoom > 22) {
    return points.map((point) => [point]).toList();
  }
  // This map renders at most one authenticated catalog page. Compare screen
  // pixel distances in wrapped Web Mercator rather than raw longitude bins:
  // sites 3px apart across the antimeridian must not appear as two distant
  // independent pins. The *first real source point* anchors each group; no
  // synthetic centroid or live charger status is fabricated.
  final worldPixels = 256.0 * math.pow(2, zoom).toDouble();
  final anchors = <({double x, double y})>[];
  final groups = <List<PartnerMapPoint>>[];
  for (final point in points) {
    if (!point.latitude.isFinite ||
        !point.longitude.isFinite ||
        point.latitude.abs() > 85.05112878 ||
        point.longitude.abs() > 180) continue;
    final longitude = point.longitude == 180 ? -180.0 : point.longitude;
    final radians = point.latitude * math.pi / 180;
    final sine = math.sin(radians);
    final x = ((longitude + 180) / 360) * worldPixels;
    final y = (0.5 - math.log((1 + sine) / (1 - sine)) / (4 * math.pi)) * worldPixels;
    var matched = -1;
    for (var i = 0; i < anchors.length; i++) {
      final distanceX = (x - anchors[i].x).abs();
      final wrappedX = math.min(distanceX, worldPixels - distanceX);
      final distanceY = (y - anchors[i].y).abs();
      if (wrappedX * wrappedX + distanceY * distanceY <= 48 * 48) {
        matched = i;
        break;
      }
    }
    if (matched >= 0) {
      groups[matched].add(point);
    } else {
      anchors.add((x: x, y: y));
      groups.add(<PartnerMapPoint>[point]);
    }
  }
  return groups;
}

/// Read-only view of just the currently loaded public partner page. It does
/// not acquire GPS, fetch partner records, offer route planning or charging.
class PartnerReportMap extends StatefulWidget {
  final List<PartnerMapPoint> points;
  final TileProvider? tileProvider;
  final bool allowViewportSearch;
  final Future<ForegroundFix> Function()? locationReader;
  final bool Function()? currentSession;
  final Stream<int>? sessionEvents;

  const PartnerReportMap({
    super.key,
    required this.points,
    this.tileProvider,
    this.allowViewportSearch = false,
    this.locationReader,
    this.currentSession,
    this.sessionEvents,
  });

  @override
  State<PartnerReportMap> createState() => _PartnerReportMapState();
}

class _PartnerReportMapState extends State<PartnerReportMap>
    with WidgetsBindingObserver {
  bool enabled = false, mapReady = false;
  bool locating = false, foreground = true;
  bool get authorized => mounted && (widget.currentSession?.call() ?? true);
  bool currentMap(int epoch) => authorized && foreground && epoch == mapGeneration;
  StreamSubscription<int>? sessionSubscription;
  void redact() {
    mapGeneration++; locationGeneration++;
    enabled = mapReady = locating = refreshAfterInactive = false;
    inactiveGpsCentre = resumedGpsCentre = null;
    inactiveGpsGeneration = null;
    locationError = null;
    manualLatitude.clear(); manualLongitude.clear();
  }
  Widget scopedSheet(BuildContext sheet, Widget child) => StreamBuilder<int>(
    stream:widget.sessionEvents,
    builder:(context,_) => authorized ? child : SafeArea(child:TextButton(
      onPressed:()=>Navigator.of(sheet).pop(),
      child:Text(Localizations.localeOf(sheet).languageCode=='ar'?'إغلاق':'Close'),
    )),
  );
  String? locationError;
  // Inactive OS permission prompts are not true backgrounding. Never move a
  // flutter_map camera while its framework viewport is suspended/inactive.
  // Hold only one in-memory fix until resumed; discard on pause/dispose.
  LatLng? inactiveGpsCentre;
  // When permission resolves while the OS sheet is inactive, initialize a
  // fresh FlutterMap camera on resume instead of moving the old controller
  // while its MapInteractiveViewer element may already be defunct.
  LatLng? resumedGpsCentre;
  int? inactiveGpsGeneration;
  bool refreshAfterInactive = false;
  int locationGeneration = 0, mapGeneration = 0;
  double currentZoom = 11;
  // A map that was detached while the OS permission dialog was open must
  // not keep the previous flutter_map camera's subscriptions alive on resume.
  MapController mapController = MapController();

  void replaceMapController() {
    final retired = mapController;
    mapController = MapController();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      retired.dispose();
    });
  }
  final manualLatitude=TextEditingController();
  final manualLongitude=TextEditingController();
  @override
  void initState() {
    super.initState();
    final state=WidgetsBinding.instance.lifecycleState;
    foreground=state==null||state==AppLifecycleState.resumed;
    WidgetsBinding.instance.addObserver(this);
    sessionSubscription=widget.sessionEvents?.listen((_){
      if(mounted&&!authorized)setState(redact);
    });
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // A foreground OS permission sheet can transiently mark the app
    // "inactive"; do not cancel that explicit one-shot permission workflow.
    // Paused/hidden/detached always invalidate pending GPS responses.
    final previousForeground=foreground;
    foreground = state == AppLifecycleState.resumed ||
        (state == AppLifecycleState.inactive && previousForeground);
    if(!authorized){if(mounted)setState(redact);return;}
    if (state == AppLifecycleState.resumed) {
      final centre = inactiveGpsCentre;
      final request = inactiveGpsGeneration;
      inactiveGpsCentre = null;
      inactiveGpsGeneration = null;
      if (mounted && centre != null && request == locationGeneration &&
          enabled && mapReady) {
        // A route/map shell may have disposed MapInteractiveViewer while
        // Flutter was inactive. Rebuild an initialized map once, without
        // invoking MapController.move on a stale descendant.
        resumedGpsCentre = centre;
        replaceMapController();
        mapGeneration++;
        mapReady = false;
        currentZoom = 12;
        refreshAfterInactive = true;
      }
      if ((refreshAfterInactive || !previousForeground) && mounted) {
        refreshAfterInactive = false;
        setState(() {});
      }
    }
    if (!foreground) {
      inactiveGpsCentre = null;
      resumedGpsCentre = null;
      inactiveGpsGeneration = null;
      refreshAfterInactive = false;
      locationGeneration++;
      mapGeneration++;
      if (mounted) {
        setState(() {
          // Tiles and the consented GPS viewport leave the widget tree
          // when the app is truly backgrounded. Reopening requires an
          // explicit map action again; inactive permission sheets are exempt.
          enabled = false;
          mapReady = false;
          locating = false;
          locationError = null;
          manualLatitude.clear();
          manualLongitude.clear();
        });
      }
    }
  }
  @override
  void dispose() {
    inactiveGpsCentre = null;
    resumedGpsCentre = null;
    inactiveGpsGeneration = null;
    locationGeneration++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(sessionSubscription?.cancel());
    mapController.dispose();
    manualLatitude.dispose();
    manualLongitude.dispose();
    super.dispose();
  }

  void scheduleGpsCamera(LatLng centre, int request) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      // The OS may still be showing its permission sheet after this frame.
      // Never mutate MapInteractiveViewer during inactive, or after a route,
      // map-toggle, or auth/lifecycle generation has been replaced.
      if (!authorized || !enabled || !mapReady || !foreground ||
          WidgetsBinding.instance.lifecycleState == AppLifecycleState.inactive ||
          request != locationGeneration) return;
      mapController.move(centre, 12);
    });
  }

  /// One foreground fix, gated twice: this dialog and the native runtime
  /// permission prompt. It never invokes catalog search or saves a GPS pin.
  Future<void> locateOnce() async {
    if (!authorized || !foreground || !enabled || !mapReady || locating) return;
    final reviewedMap = mapGeneration;
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: Text(t('Use device location once?', 'استخدام موقع الجهاز مرة واحدة؟')),
        content: Text(t(
          'Read one foreground location only after permission. The map will move near it, so OpenStreetMap receives the displayed area while tiles are enabled. No coordinates are sent to the station search until you separately press Search. No background tracking or saved location.',
          'قراءة الموقع مرة واحدة في المقدمة بعد الإذن. ستتحرك الخريطة بالقرب منه، لذلك سيتلقى OpenStreetMap المنطقة المعروضة عند تفعيل البلاطات. لا تُرسل الإحداثيات لبحث المحطات حتى تضغط بحث بشكل منفصل. لا تتبع في الخلفية ولا حفظ للموقع.',
        )),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialog, false),
            child: Text(t('Cancel','إلغاء'))),
          FilledButton(key: const Key('unified-native-gps-consent'),
            onPressed: () => Navigator.pop(dialog, true),
            child: Text(t('Allow once','السماح مرة واحدة'))),
        ],
      ),
    );
    if (accepted != true || !currentMap(reviewedMap) || !enabled || !mapReady) return;
    final request = ++locationGeneration;
    setState(() { locating = true; locationError = null; });
    try {
      final reader = widget.locationReader ?? ForegroundLocationReader().read;
      final point = await reader();
      if (!authorized || !foreground || !enabled || !mapReady ||
          request != locationGeneration) return;
      if (!point.latitude.isFinite || !point.longitude.isFinite ||
          point.latitude.abs() > 85.05112878 || point.longitude.abs() > 180) {
        throw const LocationLookupFailure('invalid');
      }
      final centre = LatLng(point.latitude, point.longitude);
      if (WidgetsBinding.instance.lifecycleState == AppLifecycleState.inactive) {
        // Permission succeeded while the OS sheet made Flutter inactive.
        // Preserve the explicit one-shot fix without touching map layout
        // until the actual resume event. This is not background tracking.
        inactiveGpsCentre = centre;
        inactiveGpsGeneration = request;
      } else {
        scheduleGpsCamera(centre, request);
      }
    } catch (_) {
      if (authorized && foreground && request == locationGeneration) {
        final message = t(
          'Location unavailable or outside this map area. Move the map manually.',
          'الموقع غير متاح أو خارج نطاق عرض الخريطة. حرّك الخريطة يدوياً.',
        );
        if (WidgetsBinding.instance.lifecycleState == AppLifecycleState.inactive) {
          locationError = message;
          refreshAfterInactive = true;
        } else {
          setState(() => locationError = message);
        }
      }
    } finally {
      if (authorized && request == locationGeneration) {
        if (WidgetsBinding.instance.lifecycleState == AppLifecycleState.inactive) {
          locating = false;
          refreshAfterInactive = true;
        } else {
          setState(() => locating = false);
        }
      }
    }
  }
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  Future<void> details(PartnerMapPoint point) async {
    if(!authorized||!foreground||!widget.points.contains(point))return;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => scopedSheet(sheet,SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(point.name,
                  style: Theme.of(sheet).textTheme.titleLarge),
              if (point.platformCatalog) Text(t('Platform catalog · ${point.catalogEvidence}. Availability and physical charging are unverified. Booking remains in the station catalog.', 'كتالوج المنصة · ${point.catalogEvidence}. التوفر والشحن الفعلي غير مؤكدين. يظل الحجز في كتالوج المحطات.'))
              else Text(t(
                'Partner-reported place; live availability and connector access are unknown. This map cannot reserve, authorize, charge or bill.',
                'موقع أبلغ عنه الشريك؛ التوافر المباشر والوصول إلى الموصل غير معروفين. لا يمكن لهذه الخريطة الحجز أو تشغيل الشحن أو الفوترة.',
              )),
              if (point.providerLabel != null)
                Text(t('Network label (unverified): ',
                        'اسم الشبكة (غير مؤكد): ') +
                    point.providerLabel!),
              if (point.reportedAgeMinutes != null)
                Text(t('Source report age (not live): ',
                        'عمر تقرير المصدر (ليس مباشراً): ') +
                    point.reportedAgeMinutes.toString() + ' min'),
              SelectableText(
                point.latitude.toStringAsFixed(6) + ', ' +
                    point.longitude.toStringAsFixed(6),
                textDirection: TextDirection.ltr,
              ),
              TextButton(
                key: const Key('partner-report-details-close'),
                onPressed: () => Navigator.pop(sheet),
                child: Text(t('Close', 'إغلاق')),
              ),
            ],
          ),
        ),
      )),
    );
  }


  Future<void> showCluster(List<PartnerMapPoint> group) async {
    if (!authorized || !foreground || group.length < 2 || !group.every(widget.points.contains)) return;
    final chosen = await showModalBottomSheet<PartnerMapPoint>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => scopedSheet(sheet,SafeArea(
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.all(16),
          children: [
            Text(t(
              '${group.length} loaded locations · availability unverified',
              '${group.length} مواقع محملة · التوفر غير مؤكد',
            ), style: Theme.of(sheet).textTheme.titleMedium),
            Text(t(
              'A cluster is not one charger or a live availability count.',
              'التجمع ليس شاحناً واحداً ولا عدداً للتوفر المباشر.',
            )),
            for (final point in group)
              ListTile(
                title: Text(point.name),
                subtitle: Text(point.platformCatalog
                    ? t('Platform catalog · not live', 'كتالوج المنصة · ليست بيانات مباشرة')
                    : t('Partner report · not live', 'تقرير شريك · ليست بيانات مباشرة')),
                onTap: () { if(authorized&&foreground)Navigator.of(sheet).pop(point); },
              ),
          ],
        ),
      )),
    );
    if (authorized && foreground && chosen != null) await details(chosen);
  }

  @override
  Widget build(BuildContext context) {
    if(!authorized||!foreground)return Scaffold(appBar:AppBar(title:Text(t('Published location map (not live)','خريطة المواقع المنشورة (ليست مباشرة)'))),
      body:Center(child:Text(t('Private map hidden. Reopen discovery when authorized.','الخريطة الخاصة مخفية. أعد فتح البحث عند توفر الصلاحية.'))));
    final points = widget.points;
    // Only an explicit unified viewport search may open an empty map.
    // Standalone partner maps still require actual published point evidence.
    final exploratory = widget.allowViewportSearch && points.isEmpty;
    final renderedMapGeneration = mapGeneration;
    final groups = clusterPublishedMapPoints(points, currentZoom);
    return Scaffold(
      appBar: AppBar(
        title: Text(exploratory
          ? t('Choose discovery search area','اختر منطقة البحث على الخريطة')
          : widget.points.any((p)=>p.platformCatalog)
            ? t('Published location map (not live)','خريطة المواقع المنشورة (ليست مباشرة)')
            : t('Partner report map (not live)','خريطة تقارير الشركاء (ليست مباشرة)')),
      ),
      body: SafeArea(
        child: ListView(
          // The map comes after the optional manual/GPS controls. Once
          // consented, build it within a short-screen cache region so the
          // controller can attach before the area Search button is used.
          cacheExtent: enabled ? 2000.0 : null,
          padding: const EdgeInsets.all(12),
          children: [
            if (points.any((p)=>p.platformCatalog)) Text(t('Only the loaded platform and authorized partner results are shown. Source labels remain distinct; no verified live availability, charging or billing.','تُعرض نتائج المنصة والشركاء المصرح لهم المحملة فقط. تظل تسميات المصادر مميزة؛ لا تحقق من التوفر المباشر أو الشحن أو الفوترة.'))
            else Text(t(
              'Only this loaded page of authorized published partner reports is shown. Sources may be stale. No verified live availability, booking, charging or billing.',
              'تُعرض هذه الصفحة المحملة فقط من تقارير الشركاء المنشورة والمصرح بها. قد تكون البيانات قديمة. لا يوجد تحقق من التوافر المباشر أو الحجز أو الشحن أو الفوترة.',
            )),
            Text(t(
              'Enable map tiles explicitly. OpenStreetMap then receives your IP address and the displayed map area. No device GPS or new partner search is requested.',
              'فعّل بلاطات الخريطة صراحةً. ستتلقى OpenStreetMap عنوان الإنترنت والمنطقة المعروضة. لا يُطلب موقع الجهاز أو بحث جديد عن الشركاء.',
            )),
            if (points.isEmpty)
              Text(t(
                exploratory
                    ? 'No loaded map pins. Start from the neutral world view, move or zoom to an area, then explicitly press Search. The map centre is not your location or an inventory coverage claim.'
                    : 'No map-compatible coordinates on this page.',
                exploratory
                    ? 'لا توجد علامات محملة على الخريطة. ابدأ من عرض العالم المحايد، ثم حرّك الخريطة أو كبّر المنطقة واضغط بحث صراحةً. مركز الخريطة ليس موقعك ولا دليلاً على تغطية المحطات.'
                    : 'لا توجد إحداثيات قابلة للعرض على الخريطة في هذه الصفحة.',
              )),
            if ((points.isNotEmpty || exploratory) && !enabled)
              FilledButton(
                key: const Key('partner-map-enable'),
                onPressed: () { if(!currentMap(renderedMapGeneration)||enabled)return; setState(() { replaceMapController(); mapGeneration++; enabled = true; mapReady = false; resumedGpsCentre = null; currentZoom = points.isEmpty ? 2 : 11; }); },
                child: Text(exploratory
                    ?t('Enable discovery area map','تفعيل خريطة منطقة البحث')
                    :t('Enable report map','تفعيل خريطة التقارير')),
              ),
            if (enabled)
              OutlinedButton(
                key: const Key('partner-map-disable'),
                onPressed: () { if(currentMap(renderedMapGeneration))setState(redact); },
                child: Text(t('Disable report map', 'إيقاف خريطة التقارير')),
              ),
            if (enabled && widget.allowViewportSearch)
              OutlinedButton.icon(
                key: const Key('unified-native-gps-once'),
                icon: const Icon(Icons.my_location),
                onPressed: !mapReady || !foreground || locating ? null : locateOnce,
                label: Text(t('Use my location once (optional)', 'استخدام موقعي مرة واحدة (اختياري)')),
              ),
            if (locating)
              const LinearProgressIndicator(key: Key('unified-native-gps-loading')),
            if (locationError != null)
              Text(locationError!, key: const Key('unified-native-gps-error')),
            if (enabled && widget.allowViewportSearch) ...[
              Text(t(
                'Manual map centre (optional). Coordinates only move this opted-in map; press Search separately to query published locations.',
                'مركز الخريطة اليدوي (اختياري). تحرك الإحداثيات هذه الخريطة المفعّلة فقط؛ اضغط بحث بشكل منفصل للاستعلام عن المواقع المنشورة.',
              )),
              Row(children: [
                Expanded(child:TextField(
                  key: const Key('unified-map-manual-latitude'),
                  controller:manualLatitude,
                  maxLength:30,
                  textDirection:TextDirection.ltr,
                  keyboardType:const TextInputType.numberWithOptions(
                    signed:true,decimal:true),
                  decoration:InputDecoration(
                    labelText:t('Latitude','خط العرض'),
                  ),
                )),
                const SizedBox(width:8),
                Expanded(child:TextField(
                  key: const Key('unified-map-manual-longitude'),
                  controller:manualLongitude,
                  maxLength:30,
                  textDirection:TextDirection.ltr,
                  keyboardType:const TextInputType.numberWithOptions(
                    signed:true,decimal:true),
                  decoration:InputDecoration(
                    labelText:t('Longitude','خط الطول'),
                  ),
                )),
              ]),
              OutlinedButton.icon(
                key:const Key('unified-native-manual-centre'),
                icon:const Icon(Icons.place_outlined),
                onPressed:!mapReady||!foreground||locating?null:(){
                  if(!currentMap(renderedMapGeneration)||!enabled||!mapReady)return;
                  final centre=manualDiscoveryMapCenter(
                    manualLatitude.text,manualLongitude.text);
                  if(centre==null){
                    setState(()=>locationError=t(
                      'Map coordinates invalid. Use latitude within ±85.0511 and longitude within ±180.',
                      'إحداثيات الخريطة غير صالحة. أدخل خط عرض ضمن ±85.0511 وخط طول ضمن ±180.',
                    ));
                    return;
                  }
                  setState(()=>locationError=null);
                  mapController.move(centre,12);
                },
                label:Text(t('Move map to these coordinates',
                  'تحريك الخريطة إلى هذه الإحداثيات')),
              ),
            ],
            if (enabled && widget.allowViewportSearch)
              OutlinedButton.icon(
                key: const Key('unified-native-viewport-search'),
                icon: const Icon(Icons.search),
                onPressed: !mapReady ? null : () {
                  if(!currentMap(renderedMapGeneration)||!enabled||!mapReady)return;
                  final box = mapController.camera.visibleBounds;
                  final bounds = discoveryViewportBounds(
                    box.south, box.north, box.west, box.east,
                  );
                  if (bounds == null) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                      content: Text(t(
                        'Visible map bounds cannot be searched. Zoom out or reposition and retry.',
                        'لا يمكن البحث في حدود الخريطة الحالية. غيّر التكبير أو الموضع وأعد المحاولة.',
                      )),
                    ));
                    return;
                  }
                  Navigator.of(context).pop(bounds);
                },
                label: Text(t(
                  'Search platform and partner locations in this map view',
                  'البحث في مواقع المنصة والشركاء ضمن مساحة الخريطة',
                )),
              ),
            if (enabled && (points.isNotEmpty || exploratory))
              SizedBox(
                height: math.max(
                  320,
                  MediaQuery.sizeOf(context).height * 0.55,
                ),
                child: FlutterMap(
                  key: ValueKey('published-map-$renderedMapGeneration'),
                  mapController: mapController,
                  options: MapOptions(
                    // Initial neutral world view is only a viewport-editing
                    // surface, never an invented station, GPS position or
                    // proof that any chargers exist around (0, 0).
                    initialCenter: resumedGpsCentre ??
                        (points.isEmpty
                            ? const LatLng(0, 0)
                            : LatLng(points.first.latitude, points.first.longitude)),
                    initialZoom: resumedGpsCentre != null ? 12 : (points.isEmpty ? 2 : 11),
                    // The area action is disabled until FlutterMap has
                    // attached its controller, including on short screens
                    // where the lazily built map sits below the button.
                    onMapReady: () {
                      // A map from an earlier disable/reenable generation
                      // cannot activate this map's viewport or GPS buttons.
                      WidgetsBinding.instance.addPostFrameCallback((_) {
                        if (authorized && enabled && !mapReady &&
                            mapGeneration == renderedMapGeneration) {
                          setState(() => mapReady = true);
                        }
                      });
                    },
                    onPositionChanged: (camera, _) {
                      // flutter_map may report camera changes while rebuilding
                      // MapInteractiveViewer; never rebuild its ancestor from
                      // inside that synchronous callback. In particular this
                      // happens after an inactive -> resumed location sheet.
                      final zoom = camera.zoom;
                      if (!authorized || !enabled ||
                          mapGeneration != renderedMapGeneration ||
                          (zoom - currentZoom).abs() <= 0.005) return;
                      WidgetsBinding.instance.addPostFrameCallback((_) {
                        if (authorized && foreground && enabled &&
                            mapGeneration == renderedMapGeneration &&
                            (zoom - currentZoom).abs() > 0.005) {
                          setState(() => currentZoom = zoom);
                        }
                      });
                    },
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
                      markers: List.generate(groups.length, (index) {
                        final group = groups[index];
                        final point = group.first;
                        final clustered = group.length > 1;
                        final tooltip = clustered
                            ? t('${group.length} loaded locations · not live',
                                '${group.length} مواقع محملة · ليست بيانات مباشرة')
                            : point.name;
                        return Marker(
                          // The first published coordinate anchors a group;
                          // a centroid would invent a new physical location.
                          point: LatLng(point.latitude, point.longitude),
                          width: 56,
                          height: 56,
                          child: Semantics(
                            label: clustered
                                ? t('${group.length} separate published locations, availability unverified',
                                    '${group.length} مواقع منشورة منفصلة، التوفر غير مؤكد')
                                : t(
                                    '${point.name} — ${point.platformCatalog ? 'platform catalog, availability unverified' : 'unverified partner report'}',
                                    '${point.name} — ${point.platformCatalog ? 'كتالوج المنصة، التوفر غير مؤكد' : 'تقرير شريك غير مؤكد'}',
                                  ),
                            button: true,
                            child: IconButton(
                              key: ValueKey(clustered
                                  ? 'partner-report-cluster-$index'
                                  : 'partner-report-pin-$index'),
                              tooltip: tooltip,
                              onPressed: () { if(!currentMap(renderedMapGeneration)||!enabled)return; if(clustered){showCluster(group);}else{details(point);} },
                              icon: clustered
                                  ? Text('${group.length}',
                                      style: Theme.of(context).textTheme.titleMedium)
                                  : const Icon(Icons.location_on),
                              color: Theme.of(context).colorScheme.tertiary,
                              style: IconButton.styleFrom(
                                backgroundColor: Theme.of(context).colorScheme.surface,
                              ),
                            ),
                          ),
                        );
                      }),
                    ),
                    RichAttributionWidget(
                      attributions: [
                        TextSourceAttribution(
                          'OpenStreetMap contributors',
                          onTap: () => launchUrl(
                            Uri.parse(
                                'https://www.openstreetmap.org/copyright'),
                            mode: LaunchMode.externalApplication,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            // The text rows remain usable even when markers overlap or tiles
            // are disabled, and there is deliberately no charging action.
            for (final point in points)
              ListTile(
                title: Text(point.name),
                subtitle: point.platformCatalog ? Text(t('Platform catalog · ${point.catalogEvidence} · not live','كتالوج المنصة · ${point.catalogEvidence} · ليست بيانات مباشرة')) : Text(t(
                  'Unverified partner location · not live',
                  'موقع شريك غير مؤكد · ليست بيانات مباشرة',
                )),
                onTap: () { if(currentMap(renderedMapGeneration))details(point); },
              ),
          ],
        ),
      ),
    );
  }
}
