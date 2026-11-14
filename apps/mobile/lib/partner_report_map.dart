import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

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

/// Read-only view of just the currently loaded public partner page. It does
/// not acquire GPS, fetch partner records, offer route planning or charging.
class PartnerReportMap extends StatefulWidget {
  final List<PartnerMapPoint> points;
  final TileProvider? tileProvider;

  const PartnerReportMap({
    super.key,
    required this.points,
    this.tileProvider,
  });

  @override
  State<PartnerReportMap> createState() => _PartnerReportMapState();
}

class _PartnerReportMapState extends State<PartnerReportMap> {
  bool enabled = false;
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

  @override
  Widget build(BuildContext context) {
    final points = widget.points;
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
              Text(t('No map-compatible coordinates on this page.',
                  'لا توجد إحداثيات قابلة للعرض على الخريطة في هذه الصفحة.')),
            if (points.isNotEmpty && !enabled)
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
            if (enabled && points.isNotEmpty)
              SizedBox(
                height: math.max(
                  320,
                  MediaQuery.sizeOf(context).height * 0.55,
                ),
                child: FlutterMap(
                  options: MapOptions(
                    initialCenter: LatLng(
                        points.first.latitude, points.first.longitude),
                    initialZoom: 11,
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
                      markers: List.generate(points.length, (index) {
                        final point = points[index];
                        return Marker(
                          point: LatLng(point.latitude, point.longitude),
                          width: 48,
                          height: 48,
                          child: Semantics(
                            label: point.name + (point.platformCatalog ? ' — platform catalog, availability unverified' : ' — unverified partner report'),
                            button: true,
                            child: IconButton(
                              key: ValueKey('partner-report-pin-$index'),
                              tooltip: point.name,
                              onPressed: () => details(point),
                              icon: const Icon(Icons.location_on),
                              color: Theme.of(context).colorScheme.tertiary,
                              style: IconButton.styleFrom(
                                backgroundColor:
                                    Theme.of(context).colorScheme.surface,
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
