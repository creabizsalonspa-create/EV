import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:creabiz_ev/foreground_location.dart';
import 'package:creabiz_ev/station_map.dart';

Position position({
  double lat = 25.2,
  double lng = 55.3,
  DateTime? timestamp,
}) => Position(
  latitude: lat,
  longitude: lng,
  timestamp: timestamp ?? DateTime.now(),
  accuracy: 25,
  altitude: 0,
  altitudeAccuracy: 0,
  heading: 0,
  headingAccuracy: 0,
  speed: 0,
  speedAccuracy: 0,
);

class DeviceLocation extends GeolocatorPlatform {
  bool enabled = true;
  LocationPermission permission = LocationPermission.denied,
      response = LocationPermission.whileInUse;
  int requested = 0, readCount = 0;
  Position value = position();
  Object? failure;
  LocationSettings? settings;
  @override
  Future<bool> isLocationServiceEnabled() async => enabled;
  @override
  Future<LocationPermission> checkPermission() async => permission;
  @override
  Future<LocationPermission> requestPermission() async {
    requested++;
    return response;
  }

  @override
  Future<Position> getCurrentPosition({
    LocationSettings? locationSettings,
  }) async {
    readCount++;
    settings = locationSettings;
    if (failure != null) throw failure!;
    return value;
  }
}

void main() {
  test(
    'foreground lookup requests permission once and uses bounded medium-accuracy current fix',
    () async {
      final device = DeviceLocation();
      final fix = await ForegroundLocationReader(platform: device).read();
      expect(device.requested, 1);
      expect(device.readCount, 1);
      expect(device.settings!.accuracy, LocationAccuracy.medium);
      expect(device.settings!.timeLimit, const Duration(seconds: 10));
      expect(fix.latitude, 25.2);
      expect(fix.accuracyMeters, 25);
    },
  );
  test('disabled or denied permissions never read position', () async {
    for (final reason in ['disabled', 'denied', 'deniedForever']) {
      final d = DeviceLocation();
      if (reason == 'disabled') d.enabled = false;
      if (reason == 'denied') d.response = LocationPermission.denied;
      if (reason == 'deniedForever')
        d.permission = LocationPermission.deniedForever;
      await expectLater(
        ForegroundLocationReader(platform: d).read(),
        throwsA(
          isA<LocationLookupFailure>().having(
            (e) => e.reason,
            'reason',
            reason,
          ),
        ),
      );
      expect(d.readCount, 0);
      if (reason == 'deniedForever') expect(d.requested, 0);
    }
  });
  test(
    'stale and invalid fixes are rejected; zero coordinates remain legitimate',
    () async {
      final d = DeviceLocation()..permission = LocationPermission.whileInUse;
      d.value = position(
        timestamp: DateTime.now().subtract(const Duration(minutes: 3)),
      );
      await expectLater(
        ForegroundLocationReader(platform: d).read(),
        throwsA(
          isA<LocationLookupFailure>().having(
            (e) => e.reason,
            'reason',
            'stale',
          ),
        ),
      );
      d.value = position(lat: double.nan);
      await expectLater(
        ForegroundLocationReader(platform: d).read(),
        throwsA(
          isA<LocationLookupFailure>().having(
            (e) => e.reason,
            'reason',
            'invalid',
          ),
        ),
      );
      d.value = position(lat: 0, lng: 0);
      expect((await ForegroundLocationReader(platform: d).read()).latitude, 0);
    },
  );
  test('timeout errors become a safe manual-search failure', () async {
    final d = DeviceLocation()
      ..failure = TimeoutException('sensitive upstream detail');
    await expectLater(
      ForegroundLocationReader(platform: d).read(),
      throwsA(
        isA<LocationLookupFailure>().having(
          (e) => e.reason,
          'reason',
          'timeout',
        ),
      ),
    );
  });
  testWidgets(
    'app consent precedes location access and coordinates are sent only on explicit Search',
    (tester) async {
      var calls = 0;
      List<double>? searched;
      await tester.pumpWidget(
        MaterialApp(
          home: StationMapView(
            stations: const [],
            locationReader: () async {
              calls++;
              return const ForegroundFix(25.2, 55.3, 25);
            },
            onSearch: (lat, lng) => searched = [lat, lng],
          ),
        ),
      );
      await tester.tap(find.byKey(const Key('native-location-once')));
      await tester.pumpAndSettle();
      expect(calls, 0);
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(calls, 0);
      await tester.tap(find.byKey(const Key('native-location-once')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('location-consent-accept')));
      await tester.pumpAndSettle();
      expect(calls, 1);
      expect(searched, isNull);
      expect(find.textContaining('approximately 25 m'), findsOneWidget);
      expect(find.byKey(const Key('enable-map')), findsOneWidget);
      await tester.ensureVisible(find.text('Search within 25 km here'));
      await tester.tap(find.text('Search within 25 km here'));
      await tester.pumpAndSettle();
      expect(searched, [25.2, 55.3]);
    },
  );
  testWidgets(
    'a location result after map disposal cannot update UI or search',
    (tester) async {
      final result = Completer<ForegroundFix>();
      var searched = false;
      await tester.pumpWidget(
        MaterialApp(
          home: StationMapView(
            stations: const [],
            locationReader: () => result.future,
            onSearch: (_, _) => searched = true,
          ),
        ),
      );
      await tester.tap(find.byKey(const Key('native-location-once')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('location-consent-accept')));
      await tester.pump();
      await tester.pumpWidget(const SizedBox());
      result.complete(const ForegroundFix(25, 55, null));
      await tester.pump();
      expect(searched, false);
      expect(tester.takeException(), isNull);
    },
  );
}
