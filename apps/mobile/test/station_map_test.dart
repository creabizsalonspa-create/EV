import 'dart:convert';
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
        MaterialApp(home: StationMapView(stations: [station(null, null)])),
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
