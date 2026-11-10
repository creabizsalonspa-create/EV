import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/roaming.dart';

Map<String, dynamic> record() => {
  'location': {
    'id': 'synthetic',
    'name': 'Literal <img src=x>',
    'address': 'Test address',
    'city': 'Test city',
    'country': 'ARE',
    'latitude': '25.2',
    'longitude': '55.3',
    'last_updated': '2026-10-27T00:00:00Z',
    'evses': [
      {
        'uid': 'test-1',
        'reported_status': 'AVAILABLE',
        'connectors': [
          {'id': '1', 'standard': 'IEC_62196_T2_COMBO'},
        ],
      },
    ],
  },
  'received_at': '2026-10-27T00:01:00Z',
  'evidence': 'PARTNER_REPORTED_UNVERIFIED',
  'availability': 'unknown',
  'remote_control': false,
  'reservable': false,
};
ApiPage<RoamingLocation> page(int number) => ApiPage(
  items: [RoamingLocation.fromJson(record())],
  current: number,
  last: 2,
  total: 26,
);
Widget app(RoamingLoader loader) => MaterialApp(
  locale: const Locale('ar'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: RoamingView(loader: loader),
);
void main() {
  test('roaming cannot promote partner capabilities to charging authority', () {
    for (final entry in {
      'reservable': true,
      'remote_control': true,
      'availability': 'available',
      'evidence': 'VERIFIED',
    }.entries) {
      expect(
        () => RoamingLocation.fromJson({...record(), entry.key: entry.value}),
        throwsFormatException,
      );
    }
    expect(
      RoamingLocation.fromJson(
        record(),
      ).location['evses'][0]['reported_status'],
      'AVAILABLE',
    );
  });
  testWidgets(
    'Arabic RTL preserves partner text; pagination failure is retryable without stale cards',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final requests = <int>[];
      var fail = true;
      await tester.pumpWidget(
        app((number) async {
          requests.add(number);
          if (number == 2 && fail) {
            fail = false;
            throw Exception('offline');
          }
          return page(number);
        }),
      );
      await tester.pumpAndSettle();
      expect(find.text('Literal <img src=x>'), findsOneWidget);
      expect(
        Directionality.of(tester.element(find.text('Literal <img src=x>'))),
        TextDirection.rtl,
      );
      expect(find.text('التوافر المباشر غير معروف.'), findsOneWidget);
      expect(find.byType(FilledButton), findsNothing);
      await tester.scrollUntilVisible(
        find.text('التالي'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('التالي'));
      await tester.pumpAndSettle();
      expect(find.text('Literal <img src=x>'), findsNothing);
      await tester.scrollUntilVisible(
        find.text('إعادة المحاولة'),
        -300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('إعادة المحاولة'));
      await tester.pumpAndSettle();
      expect(requests, [1, 2, 2]);
      expect(find.text('الصفحة 2 من 2 · 26 سجل'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );


  test('partner report distance is not driving or live availability', () {
    final report = RoamingLocation.fromJson({
      ...record(),
      'provider_label': 'Unverified Network',
      'provider_label_evidence': 'PROVIDER_CONFIGURED_UNVERIFIED',
      'reported_source_age_minutes': 1550,
      'source_clock_evidence': 'PARTNER_REPORTED_TIME',
      'distance_basis': 'great_circle',
      'distance_km': 25.123,
    });
    expect(report.providerLabel, 'Unverified Network');
    expect(report.reportedSourceAgeMinutes, 1550);
    expect(report.distanceKm, 25.123);
    expect(RoamingLocation.fromJson({
      ...record(), 'distance_basis': 'great_circle',
      'distance_km': double.infinity,
    }).distanceKm, isNull);
    expect(RoamingLocation.fromJson({
      ...record(), 'distance_basis': 'driving',
      'distance_km': 10.0,
    }).distanceKm, isNull);
    expect(RoamingLocation.fromJson({
      ...record(), 'provider_label': 'Untrusted',
      'provider_label_evidence': 'PROVIDER_CERTIFIED',
    }).providerLabel, isNull);
  });

  testWidgets('partner coordinates require explicit Search', (tester) async {
    final calls = <Map<String, String>>[];
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('en'),
      home: RoamingView(
        loader: (p) async => page(p),
        queryLoader: (filters, p) async {
          calls.add(Map.of(filters));
          return page(p);
        },
      ),
    ));
    await tester.pumpAndSettle();
    expect(calls.length, 1);

    // The Form is eager and the test tree can contain several Scrollables
    // (including editable text). Drive only the outer ListView and verify
    // each field is in the hit-test viewport before editing/tapping.
    Future<void> reveal(Finder target) async {
      final height =
          tester.view.physicalSize.height / tester.view.devicePixelRatio;
      for (var attempt = 0; attempt < 24; attempt++) {
        final dy = tester.getRect(target).center.dy;
        if (dy >= 80 && dy <= height - 40) return;
        await tester.drag(
          find.byType(ListView).first,
          Offset(0, dy > height - 40 ? -180 : 180),
        );
        await tester.pumpAndSettle();
      }
      final dy = tester.getRect(target).center.dy;
      expect(dy, greaterThanOrEqualTo(80));
      expect(dy, lessThanOrEqualTo(height - 40));
    }

    final tileLabel =
        find.text('Manual partner-nearby search (1–200 km)');
    await reveal(tileLabel);
    await tester.tap(tileLabel);
    await tester.pumpAndSettle();

    for (final item in [
      ('partner-lat', '25.2'),
      ('partner-lng', '55.3'),
      ('partner-radius', '10'),
    ]) {
      final field = find.byKey(Key(item.$1));
      await reveal(field);
      await tester.enterText(field, item.$2);
      await tester.pumpAndSettle();
    }
    expect(calls.length, 1);

    // A radius above the backend's 200km maximum must fail even if the
    // nearby section has been collapsed with fields kept alive.
    final radius = find.byKey(const Key('partner-radius'));
    await reveal(radius);
    await tester.enterText(radius, '201');
    await reveal(tileLabel);
    await tester.tap(tileLabel);
    await tester.pumpAndSettle();

    final submit = find.byKey(const Key('partner-search-submit'));
    await reveal(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();
    expect(calls.length, 1);

    await reveal(tileLabel);
    await tester.tap(tileLabel);
    await tester.pumpAndSettle();
    await reveal(radius);
    await tester.enterText(radius, '10');
    await reveal(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();
    expect(calls.length, 2);
    expect(calls.last['latitude'], '25.2');
    expect(calls.last['longitude'], '55.3');
    expect(calls.last['radius_km'], '10');

    final clear = find.byKey(const Key('partner-clear-filters'));
    await reveal(clear);
    await tester.tap(clear);
    await tester.pumpAndSettle();
    expect(calls.length, 3);
    expect(calls.last, isEmpty);
    expect(tester.takeException(), isNull);
  });

  testWidgets('partner report map remains tile-free before map consent',
      (tester) async {
    await tester.pumpWidget(app((number) async => page(number)));
    await tester.pumpAndSettle();
    final button = find.byKey(const Key('partner-report-map-open'));
    expect(button, findsOneWidget);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('partner-map-enable')), findsOneWidget);
    expect(find.text('Start charging'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('disposed route ignores a pending response', (tester) async {
    final pending = Completer<ApiPage<RoamingLocation>>();
    await tester.pumpWidget(app((_) => pending.future));
    await tester.pump();
    await tester.pumpWidget(const SizedBox());
    pending.complete(page(1));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
}
