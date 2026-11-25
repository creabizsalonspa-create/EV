import 'dart:convert';
import 'dart:async';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:creabiz_ev/foreground_location.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_map.dart';

Station station(Object? lat, Object? lng) => Station.fromJson({
  'id': 1,
  'name': 'Test',
  'connector_type': 'CCS2',
  'evidence': 'DISCOVERY_ONLY',
  'power_kw': 50,
  'latitude': lat,
  'longitude': lng,
});

class FixtureTiles extends TileProvider {
  int requests = 0;
  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) {
    requests++;
    return MemoryImage(
      base64Decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=',
      ),
    );
  }
}

void main() {
  for (final ar in [false, true]) {
    testWidgets('manual map centre and list alternative do not request tiles ($ar)', (tester) async {
      final tiles = FixtureTiles();
      final searched = <List<double>>[];
      tester.view.physicalSize = const Size(390, 1040);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final missing = Station.fromJson({
        'id': 2, 'name': 'No coordinates', 'connector_type': 'CCS2',
        'evidence': 'DISCOVERY_ONLY', 'power_kw': 50,
      });
      await tester.pumpWidget(MaterialApp(
        locale: Locale(ar ? 'ar' : 'en'),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: StationMapView(
          stations: [station(25.2, 55.3), missing],
          tileProvider: tiles,
          onSearch: (lat, lng) => searched.add([lat, lng]),
        ),
      ));
      await tester.pumpAndSettle();
      Future<void> tap(Finder f) async {
        await tester.ensureVisible(f);
        await tester.tap(f);
        await tester.pumpAndSettle();
      }
      await tap(find.byKey(const Key('station-map-list')));
      expect(find.text('No coordinates'), findsOneWidget);
      final missingRow = tester.widget<ListTile>(
        find.ancestor(of: find.text('No coordinates'), matching: find.byType(ListTile)),
      );
      expect(missingRow.onTap, isNull);
      await tap(find.text('Test'));
      expect(find.text(ar
        ? 'التوفر غير مؤكد. ظهور المحطة على الخريطة لا يتيح الشحن.'
        : 'Availability is unverified. Map placement does not enable charging.'), findsOneWidget);
      expect(tiles.requests, 0);
      expect(searched, isEmpty);
      Navigator.of(tester.element(find.text(ar
        ? 'التوفر غير مؤكد. ظهور المحطة على الخريطة لا يتيح الشحن.'
        : 'Availability is unverified. Map placement does not enable charging.'))).pop();
      await tester.pumpAndSettle();
      await tap(find.byKey(const Key('manual-map-centre')));
      final lat = find.byKey(const Key('map-latitude'));
      final lng = find.byKey(const Key('map-longitude'));
      await tester.ensureVisible(lat);
      await tester.enterText(lat, '91');
      await tester.ensureVisible(lng);
      await tester.enterText(lng, '55.3');
      await tap(find.byKey(const Key('choose-manual-map-centre')));
      expect(find.byKey(const Key('map-selected-centre')), findsNothing);
      expect(searched, isEmpty);
      await tester.ensureVisible(lat);
      await tester.enterText(lat, ar ? '٢٥٫٢' : '25.2');
      await tester.ensureVisible(lng);
      await tester.enterText(lng, ar ? '٥٥٫٣' : '55.3');
      await tap(find.byKey(const Key('choose-manual-map-centre')));
      expect(searched, isEmpty);
      expect(tiles.requests, 0);
      final search = find.text(ar ? 'البحث ضمن ٢٥ كم هنا' : 'Search within 25 km here');
      await tester.scrollUntilVisible(search, -200, scrollable: find.byType(Scrollable).first);
      await tap(search);
      expect(searched, [[25.2, 55.3]]);
      expect(tiles.requests, 0);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('background invalidates one-time location and disables tile consent', (tester) async {
    final tiles = FixtureTiles();
    final pending = Completer<ForegroundFix>();
    var searches = 0;
    await tester.pumpWidget(MaterialApp(home: StationMapView(
      stations: [station(25.2, 55.3)],
      tileProvider: tiles,
      locationReader: () => pending.future,
      onSearch: (_, _) => searches++,
    )));
    await tester.tap(find.byKey(const Key('native-location-once')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('location-consent-accept')));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    addTearDown(() => tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    pending.complete(const ForegroundFix(25.2, 55.3, 25));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('enable-map')), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('map-selected-centre')), findsNothing);
    expect(searches, 0);
    expect(tiles.requests, 0);
    await tester.tap(find.byKey(const Key('enable-map')));
    await tester.pumpAndSettle();
    expect(tiles.requests, greaterThan(0));
    await tester.tap(find.byKey(const Key('disable-map')));
    await tester.pumpAndSettle();
    expect(find.byType(FlutterMap), findsNothing);
    expect(find.byKey(const Key('enable-map')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });


  testWidgets(
    'native map creates no tiles before consent and opens truthful station details',
    (tester) async {
      final tiles = FixtureTiles();
      await tester.pumpWidget(
        MaterialApp(
          home: StationMapView(
            stations: [station(25.2, 55.3)],
            tileProvider: tiles,
          ),
        ),
      );
      expect(tiles.requests, 0);
      await tester.tap(find.byKey(const Key('enable-map')));
      await tester.pumpAndSettle();
      expect(tiles.requests, greaterThan(0));
      await tester.tap(find.byTooltip('Test'));
      await tester.pumpAndSettle();
      expect(
        find.text(
          'Availability is unverified. Map placement does not enable charging.',
        ),
        findsOneWidget,
      );
      expect(find.text('View charging options'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'charging options remain customer-only even for reservable markers',
    (tester) async {
      final reservable = Station.fromJson({
        'id': 1,
        'name': 'Test',
        'connector_type': 'CCS2',
        'evidence': 'SIMULATED',
        'power_kw': 50,
        'latitude': 25.2,
        'longitude': 55.3,
        'reservable': true,
      });
      for (final customer in [false, true]) {
        await tester.pumpWidget(
          MaterialApp(
            home: StationMapView(
              key: ValueKey(customer),
              stations: [reservable],
              customer: customer,
              tileProvider: FixtureTiles(),
            ),
          ),
        );
        await tester.tap(find.byKey(const Key('enable-map')));
        await tester.pumpAndSettle();
        await tester.tap(find.byTooltip('Test'));
        await tester.pumpAndSettle();
        expect(
          find.text('View charging options'),
          customer ? findsOneWidget : findsNothing,
        );
        await tester.pumpWidget(const SizedBox());
        await tester.pumpAndSettle();
      }
    },
  );
  test(
    'clusters preserve all valid stations without treating a cluster as one charger',
    () {
      final same = station(25.2, 55.3);
      final distant = station(-25.2, -55.3);
      final groups = clusterStations([
        same,
        same,
        distant,
        station(null, null),
      ], 11);
      expect(groups.length, 2);
      expect(groups.map((g) => g.length).reduce((a, b) => a + b), 3);
      expect(groups.first.length, 2);
    },
  );
  test(
    'map points reject absent, malformed, infinite and unsupported polar coordinates',
    () {
      for (final point in [
        station(null, null),
        station('', 55),
        station('NaN', 55),
        station(86, 55),
        station(25, 181),
      ]) {
        expect(stationHasMapPoint(point), false);
      }
      expect(stationHasMapPoint(station(0, 0)), true);
      expect(
        stationHasMapPoint(Station.withFavorite(station('25.2', '55.3'), true)),
        true,
      );
    },
  );
  testWidgets(
    'tiles require explicit consent and absent coordinates stay out of map',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: StationMapView(
            stations: [station(null, null)],
            tileProvider: FixtureTiles(),
          ),
        ),
      );
      expect(find.byKey(const Key('enable-map')), findsOneWidget);
      expect(
        find.text('No supported coordinates on this page.'),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('enable-map')));
      await tester.pump();
      expect(find.byKey(const Key('enable-map')), findsNothing);
    },
  );
}
