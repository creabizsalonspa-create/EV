import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('nearby query retains zero coordinates and decodes server distance', () {
    const filter = CatalogFilter(latitude: 0, longitude: 180, radiusKm: 10);
    expect(filter.query, {
      'latitude': '0.0',
      'longitude': '180.0',
      'radius_km': '10.0',
    });
    final station = Station.fromJson({
      'id': 1,
      'name': 'Date line',
      'connector_type': 'CCS2',
      'evidence': 'DISCOVERY_ONLY',
      'power_kw': 50,
      'reservable': false,
      'distance_km': 1.112,
    });
    expect(station.distanceKm, 1.112);
    expect(station.reservable, isFalse);
  });

  testWidgets(
    'Arabic coordinates validate together, normalize digits and clear',
    (tester) async {
      tester.view.physicalSize = const Size(1000, 2200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final filters = <CatalogFilter>[];
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: Scaffold(
            body: CatalogView(
              loader: (filter, page) async {
                filters.add(filter);
                return const ApiPage<Station>(
                  items: [],
                  current: 1,
                  last: 1,
                  total: 0,
                );
              },
              customer: true,
              onReserve: (_) async {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('البحث قرب إحداثيات'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const PageStorageKey('catalog-nearbyLatitude')),
        '٢٥٫٢',
      );
      await tester.tap(find.text('تطبيق التصفية'));
      await tester.pumpAndSettle();
      expect(filters, hasLength(1));
      expect(
        find.text('أدخل القيم الثلاث ضمن النطاقات المحددة.'),
        findsNWidgets(2),
      );
      await tester.enterText(
        find.byKey(const PageStorageKey('catalog-nearbyLongitude')),
        '٥٥٫٣',
      );
      await tester.enterText(
        find.byKey(const PageStorageKey('catalog-radiusKm')),
        '١٠',
      );
      await tester.tap(find.text('تطبيق التصفية'));
      await tester.pumpAndSettle();
      expect(filters.last.latitude, 25.2);
      expect(filters.last.longitude, 55.3);
      expect(filters.last.radiusKm, 10);
      expect(
        Directionality.of(tester.element(find.byType(CatalogView))),
        TextDirection.rtl,
      );
      await tester.tap(find.text('مسح التصفية'));
      await tester.pumpAndSettle();
      expect(filters.last.query, isEmpty);
    },
  );
}
