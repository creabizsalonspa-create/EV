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
        queryLoader: (f, p) async {
          calls.add(Map.of(f));
          return page(p);
        },
      ),
    ));
    await tester.pumpAndSettle();
    expect(calls.length, 1);
    await tester.scrollUntilVisible(
        find.byKey(const Key('partner-manual-nearby')), 180);
    await tester.tap(find.byKey(const Key('partner-manual-nearby')));
    await tester.pumpAndSettle();
    for (final item in [
      ('partner-lat', '25.2'), ('partner-lng', '55.3'),
      ('partner-radius', '10'),
    ]) {
      await tester.scrollUntilVisible(find.byKey(Key(item.$1)), 180);
      await tester.enterText(find.byKey(Key(item.$1)), item.$2);
    }
    expect(calls.length, 1);
    // Invalid coordinates stay invalid even with the tile collapsed.
    await tester.scrollUntilVisible(find.byKey(const Key('partner-radius')), 180);
    await tester.enterText(find.byKey(const Key('partner-radius')), '201');
    await tester.scrollUntilVisible(
        find.byKey(const Key('partner-manual-nearby')), -180);
    await tester.tap(find.byKey(const Key('partner-manual-nearby')));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
        find.byKey(const Key('partner-search-submit')), 180);
    await tester.tap(find.byKey(const Key('partner-search-submit')));
    await tester.pumpAndSettle();
    expect(calls.length, 1);
    await tester.scrollUntilVisible(
        find.byKey(const Key('partner-manual-nearby')), -180);
    await tester.tap(find.byKey(const Key('partner-manual-nearby')));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.byKey(const Key('partner-radius')), 180);
    await tester.enterText(find.byKey(const Key('partner-radius')), '10');
    await tester.scrollUntilVisible(
        find.byKey(const Key('partner-search-submit')), 180);
    await tester.tap(find.byKey(const Key('partner-search-submit')));
    await tester.pumpAndSettle();
    expect(calls.last['latitude'], '25.2');
    expect(calls.last['longitude'], '55.3');
    expect(calls.last['radius_km'], '10');
    await tester.scrollUntilVisible(
        find.byKey(const Key('partner-clear-filters')), 180);
    await tester.tap(find.byKey(const Key('partner-clear-filters')));
    await tester.pumpAndSettle();
    expect(calls.length, 3);
    expect(calls.last, isEmpty);
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
