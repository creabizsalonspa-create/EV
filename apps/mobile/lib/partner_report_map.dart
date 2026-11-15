import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';


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
    if (row['name'] is! String || row['id'] is! int || (row['id'] as int) < 1 || !['SIMULATED','DISCOVERY_ONLY'].contains(row['evidence'])) return null;
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
  final scale = 256 * math.pow(2, zoom) / 48;
  final grouped = <String, List<PartnerMapPoint>>{};
  for (final point in points) {
    if (!point.latitude.isFinite ||
        !point.longitude.isFinite ||
        point.latitude.abs() > 85.05112878 ||
        point.longitude.abs() > 180) continue;
    final longitude = point.longitude == 180 ? -180.0 : point.longitude;
    final phi = point.latitude * math.pi / 180;
    final sine = math.sin(phi);
    final x = (((longitude + 180) / 360) * scale).floor();
    final y = ((0.5 - math.log((1 + sine) / (1 - sine)) / (4 * math.pi)) * scale).floor();
    (grouped['$x:$y'] ??= <PartnerMapPoint>[]).add(point);
  }
  return grouped.values.toList();
}

/// Read-only view of just the currently loaded public partner page. It does
/// not acquire GPS, fetch partner records, offer route planning or charging.
class PartnerReportMap extends StatefulWidget {
  final List<PartnerMapPoint> points;
  final TileProvider? tileProvider;
  final bool allowViewportSearch;

  const PartnerReportMap({
    super.key,
    required this.points,
    this.tileProvider,
    this.allowViewportSearch = false,
  });

  @override
  State<PartnerReportMap> createState() => _PartnerReportMapState();
}

class _PartnerReportMapState extends State<PartnerReportMap> {
  bool enabled = false;
  double currentZoom = 11;
  final mapController = MapController();
  @override
  void dispose() { mapController.dispose(); super.dispose(); }
  String t(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  Future<void> details(PartnerMapPoint point) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => SafeArea(
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
      ),
    );
  }


  Future<void> showCluster(List<PartnerMapPoint> group) async {
    if (group.length < 2) return;
    final chosen = await showModalBottomSheet<PartnerMapPoint>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => SafeArea(
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
                onTap: () => Navigator.of(sheet).pop(point),
              ),
          ],
        ),
      ),
    );
    if (mounted && chosen != null) await details(chosen);
  }

  @override
  Widget build(BuildContext context) {
    final points = widget.points;
    // Only an explicit unified viewport search may open an empty map.
    // Standalone partner maps still require actual published point evidence.
    final exploratory = widget.allowViewportSearch && points.isEmpty;
    final groups = clusterPublishedMapPoints(points, currentZoom);
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.points.any((p)=>p.platformCatalog) ? t('Published location map (not live)','خريطة المواقع المنشورة (ليست مباشرة)') : t('Partner report map (not live)',
            'خريطة تقارير الشركاء (ليست مباشرة)')),
      ),
      body: SafeArea(
        child: ListView(
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
                onPressed: () => setState(() => enabled = true),
                child: Text(t('Enable report map', 'تفعيل خريطة التقارير')),
              ),
            if (enabled)
              OutlinedButton(
                key: const Key('partner-map-disable'),
                onPressed: () => setState(() => enabled = false),
                child: Text(t('Disable report map', 'إيقاف خريطة التقارير')),
              ),
            if (enabled && widget.allowViewportSearch)
              OutlinedButton.icon(
                key: const Key('unified-native-viewport-search'),
                icon: const Icon(Icons.search),
                onPressed: () {
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
                  mapController: mapController,
                  options: MapOptions(
                    // Initial neutral world view is only a viewport-editing
                    // surface, never an invented station, GPS position or
                    // proof that any chargers exist around (0, 0).
                    initialCenter: points.isEmpty
                        ? const LatLng(0, 0)
                        : LatLng(points.first.latitude, points.first.longitude),
                    initialZoom: points.isEmpty ? 2 : 11,
                    onPositionChanged: (camera, _) {
                      if (mounted && (camera.zoom - currentZoom).abs() > 0.005) {
                        setState(() => currentZoom = camera.zoom);
                      }
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
                                : point.name + (point.platformCatalog
                                    ? ' — platform catalog, availability unverified'
                                    : ' — unverified partner report'),
                            button: true,
                            child: IconButton(
                              key: ValueKey(clustered
                                  ? 'partner-report-cluster-$index'
                                  : 'partner-report-pin-$index'),
                              tooltip: tooltip,
                              onPressed: clustered
                                  ? () => showCluster(group)
                                  : () => details(point),
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
                onTap: () => details(point),
              ),
          ],
        ),
      ),
    );
  }
}
