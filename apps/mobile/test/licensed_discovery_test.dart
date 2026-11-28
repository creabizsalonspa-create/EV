import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/foreground_location.dart';
import 'package:creabiz_ev/licensed_discovery.dart';
import 'test_support.dart';

Map<String, dynamic> report() => {
  'id': 'ocm:123',
  'provider': 'open_charge_map',
  'name': '<img src=x>',
  'address': 'Fixture street',
  'city': 'Dubai',
  'country': 'AE',
  'latitude': 25.2,
  'longitude': 55.3,
  'distance_km': 1.2,
  'distance_basis': 'great_circle',
  'operator_label': 'Fixture operator',
  'operator_evidence': 'OPEN_CHARGE_MAP_REPORTED_UNVERIFIED',
  'source_updated_at': '2026-11-20T00:00:00Z',
  'source_age_minutes': 1500,
  'source_clock_evidence': 'CROWD_REPORTED_TIME',
  'source_status_evidence': 'OPEN_CHARGE_MAP_DISCOVERY_ONLY',
  'reported_operational': null,
  'reported_operational_evidence': 'OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED',
  'reported_membership_required': null,
  'reported_access_key_required': false,
  'reported_access': 'Membership may apply',
  'reported_access_evidence': 'OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED',
  'reported_cost_note': 'See operator',
  'reported_cost_evidence': 'OPEN_CHARGE_MAP_CROWD_PRICE_UNVERIFIED',
  'data_provider': 'Fixture licensor',
  'data_license': 'CC BY-SA 4.0',
  'attribution': 'Open Charge Map / Fixture licensor',
  'source_url': 'https://openchargemap.org/site/poi/details/123',
  'availability': 'unknown',
  'remote_control': false,
  'reservable': false,
  'billing_eligible': false,
  'connections': [
    {
      'reported_connector_type': 'Type 2',
      'reported_power_kw': 22,
      'availability': 'unknown',
    },
  ],
};
Map<String, dynamic> sample() => {
  'provider': 'open_charge_map',
  'evidence': 'OPEN_LICENSED_DISCOVERY_ONLY',
  'availability': 'unknown',
  'remote_control': false,
  'reservable': false,
  'billing_eligible': false,
  'distance_basis': 'great_circle',
  'license_policy': 'PER_RECORD_ATTRIBUTION_REQUIRED',
  'count': 1,
  'results': [report()],
  'quality_sample': {
    'evidence': 'FILTERED_LICENSED_CROWD_SAMPLE_ONLY',
    'returned_count': 1,
    'source_time_unknown_or_future_count': 0,
    'source_older_than_24h_count': 1,
    'operational_report_unknown_count': 1,
    'max_source_sample': 25,
    'complete_geographic_coverage': false,
    'live_status_verified': false,
  },
};
Map<String, dynamic> capabilities({bool enabled = true}) => {
  'provider': 'open_charge_map',
  'enabled': enabled,
  'evidence': 'DISCOVERY_ONLY',
  'requires_explicit_consent': true,
  'max_radius_km': 200,
  'max_results': 25,
  'attribution_required_per_record': true,
  'availability': 'unknown',
  'remote_control': false,
  'reservable': false,
};

class LicensedApi extends FixtureApi {
  bool enabled = true;
  int epoch = 0;
  Object? problem;
  Completer<dynamic>? pending;
  Map<String, dynamic>? submitted;
  @override
  bool Function() sessionFence() {
    final capture = epoch;
    return () => capture == epoch;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    if (method == 'GET') return capabilities(enabled: enabled);
    submitted = body;
    if (pending != null) return pending!.future;
    if (problem != null) throw problem!;
    return sample();
  }
}

class SessionEventLicensedApi extends LicensedApi {
  final changes = StreamController<int>.broadcast(sync: true);

  @override
  Stream<int> get sessionEvents => changes.stream;

  void rotate() {
    epoch++;
    changes.add(epoch);
  }

  @override
  void close() {
    unawaited(changes.close());
    super.close();
  }
}

class LocationFixture extends ForegroundLocationReader {
  int calls = 0;
  Completer<ForegroundFix>? pending;
  @override
  Future<ForegroundFix> read() async {
    calls++;
    return pending == null
        ? const ForegroundFix(25.2, 55.3, 10)
        : await pending!.future;
  }
}

class LicensedTiles extends TileProvider {
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

Widget app(
  LicensedApi api, {
  bool ar = false,
  TileProvider? tiles,
  ForegroundLocationReader? location,
  Future<bool> Function(Uri)? open,
  Future<bool> Function(Uri)? navigation,
  VoidCallback? onExpired,
}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: LicensedDiscoveryScreen(
    api: api,
    onExpired: onExpired,
    locationReader: location,
    openSource: open,
    openNavigation: navigation,
    mapTileProvider: tiles,
  ),
);
Future<void> prepare(
  WidgetTester tester,
  LicensedApi api, {
  bool ar = false,
  LocationFixture? location,
  TileProvider? tiles,
  Future<bool> Function(Uri)? open,
  Future<bool> Function(Uri)? navigation,
  VoidCallback? onExpired,
}) async {
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  await tester.pumpWidget(
    app(
      api,
      ar: ar,
      location: location,
      open: open,
      tiles: tiles,
      navigation: navigation,
      onExpired: onExpired,
    ),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const Key('licensed-consent')));
  await tester.pump();
  for (final entry in {'latitude': '25.2', 'longitude': '55.3'}.entries) {
    await tester.ensureVisible(find.byKey(Key('licensed-${entry.key}')));
    await tester.enterText(
      find.byKey(Key('licensed-${entry.key}')),
      entry.value,
    );
  }
}

Future<void> search(WidgetTester tester) async {
  await tester.ensureVisible(find.byKey(const Key('licensed-search')));
  await tester.tap(find.byKey(const Key('licensed-search')));
  await tester.pumpAndSettle();
}

void main() {
  test('consumes exact native Laravel license/provenance/quality contract', () {
    final fixture = jsonDecode(
      File(
        '../../tests/Fixtures/native-licensed-discovery.json',
      ).readAsStringSync(),
    );
    final parsed = LicensedSample.fromJson(fixture);
    expect(parsed.reports.single.id, 'ocm:12345');
    expect(parsed.reports.single.license, 'CC BY-SA 4.0');
    expect(parsed.reports.single.cost, contains('<script>'));
    expect(parsed.unknownTime, 1);
    expect(parsed.reports.single.age, isNull);
  });

  test(
    'typed source retains attribution and cannot acquire charging capabilities',
    () {
      final result = LicensedSample.fromJson(sample());
      expect(result.reports.single.name, '<img src=x>');
      expect(result.reports.single.source.host, 'openchargemap.org');
      expect(result.oldTime, 1);
      expect(result.unknownOperation, 1);
      expect(() => result.reports.clear(), throwsUnsupportedError);
    },
  );
  test(
    'rejects capability, licensing, identity, timestamp, connector and sample forgeries',
    () {
      for (final difference in <Map<String, dynamic>>[
        {'remote_control': true},
        {'reservable': true},
        {'billing_eligible': true},
        {'availability': 'available'},
        {'data_license': ''},
        {'attribution': 'different'},
        {'source_url': 'https://evil.example/123'},
        {'id': 'ocm:2147483648'},
        {'source_updated_at': '2026-02-30T00:00:00Z'},
        {'source_clock_evidence': 'SOURCE_TIME_UNKNOWN'},
        {'reported_operational': 'true'},
        {'latitude': double.nan},
        {'data_license': '🔌' * 241},
        {
          'connections': [
            {
              'reported_connector_type': 'CCS',
              'reported_power_kw': 50,
              'availability': 'unknown',
              'reservable': false,
            },
          ],
        },
      ]) {
        expect(
          () => LicensedSample.fromJson({
            ...sample(),
            'results': [
              {...report(), ...difference},
            ],
          }),
          throwsFormatException,
          reason: difference.keys.join(','),
        );
      }
      for (final difference in [
        {'complete_geographic_coverage': true},
        {'live_status_verified': true},
        {'source_older_than_24h_count': 0},
        {'max_source_sample': 1000},
      ]) {
        expect(
          () => LicensedSample.fromJson({
            ...sample(),
            'quality_sample': {
              ...sample()['quality_sample'] as Map,
              ...difference,
            },
          }),
          throwsFormatException,
        );
      }
      expect(
        () => LicensedSample.fromJson({
          ...sample(),
          'count': 2,
          'results': [report(), report()],
        }),
        throwsFormatException,
      );
    },
  );
  testWidgets(
    'disabled source never searches or prompts GPS and can be explicitly rechecked',
    (tester) async {
      final api = LicensedApi()..enabled = false;
      final gps = LocationFixture();
      await tester.pumpWidget(app(api, location: gps));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('licensed-consent')), findsNothing);
      expect(gps.calls, 0);
      expect(api.calls, ['GET discovery/open-charge-map/capabilities']);
      api.enabled = true;
      await tester.tap(find.text('Check source availability'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('licensed-consent')), findsOneWidget);
      expect(api.submitted, isNull);
    },
  );
  testWidgets(
    'explicit search sends bounded coordinates only after consent and renders literal attribution',
    (tester) async {
      final api = LicensedApi();
      final opened = <Uri>[];
      await prepare(
        tester,
        api,
        open: (uri) async {
          opened.add(uri);
          return true;
        },
      );
      expect(api.submitted, isNull);
      await search(tester);
      expect(api.submitted, {
        'consent': true,
        'latitude': 25.2,
        'longitude': 55.3,
        'radius_km': 25.0,
      });
      await tester.ensureVisible(
        find.text('Open original source (external website)'),
      );
      expect(find.text('Open Charge Map / Fixture licensor'), findsOneWidget);
      expect(find.text('CC BY-SA 4.0'), findsOneWidget);
      await tester.tap(find.text('Open original source (external website)'));
      await tester.pump();
      expect(
        opened.single.toString(),
        'https://openchargemap.org/site/poi/details/123',
      );
      expect(api.calls.where((c) => c.startsWith('POST')).length, 1);
    },
  );
  testWidgets(
    'query editing clears old reports and invalid coordinates never post',
    (tester) async {
      final api = LicensedApi();
      await prepare(tester, api);
      await search(tester);
      await tester.ensureVisible(find.byKey(const Key('licensed-latitude')));
      await tester.enterText(find.byKey(const Key('licensed-latitude')), '91');
      await tester.pump();
      expect(find.text('<img src=x>'), findsNothing);
      await search(tester);
      expect(api.calls.where((c) => c.startsWith('POST')).length, 1);
    },
  );
  testWidgets('revoking consent discards held reply and coordinate drafts', (
    tester,
  ) async {
    final api = LicensedApi()..pending = Completer<dynamic>();
    await prepare(tester, api);
    await tester.ensureVisible(find.byKey(const Key('licensed-search')));
    await tester.tap(find.byKey(const Key('licensed-search')));
    await tester.pump();
    await tester.ensureVisible(find.byKey(const Key('licensed-consent')));
    await tester.tap(find.byKey(const Key('licensed-consent')));
    await tester.pump();
    api.pending!.complete(sample());
    await tester.pumpAndSettle();
    expect(find.text('<img src=x>'), findsNothing);
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('licensed-latitude')))
          .controller!
          .text,
      isEmpty,
    );
  });
  testWidgets(
    'background purges GPS, pending reports and consent without a foreground replay',
    (tester) async {
      final api = LicensedApi()..pending = Completer<dynamic>();
      await prepare(tester, api);
      await tester.ensureVisible(find.byKey(const Key('licensed-search')));
      await tester.tap(find.byKey(const Key('licensed-search')));
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      api.pending!.complete(sample());
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('<img src=x>'), findsNothing);
      expect(api.calls.length, 2);
      expect(find.byKey(const Key('licensed-consent')), findsNothing);
    },
  );
  testWidgets(
    'GPS is explicit, uses existing permission reader and never automatically searches',
    (tester) async {
      final api = LicensedApi();
      final gps = LocationFixture();
      await prepare(tester, api, location: gps);
      await tester.ensureVisible(
        find.text('Use GPS once (permission required)'),
      );
      await tester.tap(find.text('Use GPS once (permission required)'));
      await tester.pumpAndSettle();
      expect(gps.calls, 1);
      expect(api.submitted, isNull);
      expect(
        tester
            .widget<TextField>(find.byKey(const Key('licensed-latitude')))
            .controller!
            .text,
        '25.200000',
      );
    },
  );
  testWidgets('provider errors do not replay and manual retry can recover', (
    tester,
  ) async {
    final api = LicensedApi()
      ..problem = const ApiException(503, 'PRIVATE upstream secret');
    await prepare(tester, api);
    await search(tester);
    expect(find.textContaining('PRIVATE'), findsNothing);
    expect(api.calls.length, 2);
    api.problem = null;
    await search(tester);
    expect(api.calls.length, 3);
  });
  testWidgets('Arabic 390px layout retains source evidence and LTR units', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final api = LicensedApi();
    await prepare(tester, api, ar: true);
    await search(tester);
    await tester.ensureVisible(find.text('CC BY-SA 4.0'));
    expect(
      Directionality.of(tester.element(find.text('CC BY-SA 4.0'))),
      TextDirection.rtl,
    );
    expect(tester.takeException(), isNull);
  });
  testWidgets(
    'licensed map has independent tile consent and never searches on pan',
    (tester) async {
      final api = LicensedApi();
      final tiles = LicensedTiles();
      await prepare(tester, api, tiles: tiles);
      await search(tester);
      expect(find.byType(FlutterMap), findsNothing);
      expect(tiles.requests, 0);
      await tester.ensureVisible(find.byKey(const Key('licensed-map-toggle')));
      await tester.tap(find.byKey(const Key('licensed-map-toggle')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('licensed-report-map')));
      await tester.pumpAndSettle();
      expect(tiles.requests, greaterThan(0));
      await tester.drag(
        find.byKey(const Key('licensed-report-map')),
        const Offset(60, 40),
      );
      await tester.pumpAndSettle();
      expect(api.calls.length, 2);
      await tester.ensureVisible(find.byKey(const Key('licensed-map-toggle')));
      await tester.tap(find.byKey(const Key('licensed-map-toggle')));
      await tester.pumpAndSettle();
      expect(find.byType(FlutterMap), findsNothing);
      expect(api.calls.length, 2);
      await tester.tap(find.byKey(const Key('licensed-map-toggle')));
      await tester.pumpAndSettle();
      expect(find.byType(FlutterMap), findsOneWidget);
      await search(tester);
      expect(find.byType(FlutterMap), findsNothing);
      expect(
        api.calls.length,
        3,
        reason: 'Refreshing a query requires fresh tile consent.',
      );
    },
  );
  for (final ar in [false, true]) {
    testWidgets(
      'rendered licensed map and private drafts clear on same API revocation ($ar)',
      (tester) async {
        final api = SessionEventLicensedApi();
        addTearDown(api.close);
        final tiles = LicensedTiles();
        await prepare(tester, api, ar: ar, tiles: tiles);
        await search(tester);
        await tester.ensureVisible(find.byKey(const Key('licensed-map-toggle')));
        await tester.tap(find.byKey(const Key('licensed-map-toggle')));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.byKey(const Key('licensed-report-map')));
        await tester.pumpAndSettle();
        expect(find.byType(FlutterMap), findsOneWidget);
        expect(tiles.requests, greaterThan(0));
        expect(api.calls.length, 2);
        api.rotate();
        await tester.pumpAndSettle();
        expect(find.byType(FlutterMap), findsNothing);
        expect(find.byKey(const Key('licensed-latitude')), findsNothing);
        expect(find.byKey(const Key('licensed-consent')), findsNothing);
        expect(find.text('<img src=x>'), findsNothing);
        expect(
          find.text(
            ar ? 'أعد فتح الاكتشاف بعد تسجيل الدخول.' : 'Reopen discovery after signing in.',
          ),
          findsOneWidget,
        );
        expect(api.calls.length, 2);
        expect(tester.takeException(), isNull);
      },
    );
  }

  for (final status in [401, 419]) {
    for (final replaced in [false, true]) {
      testWidgets(
        'session invalidation preserves only current expiry notification ($status/$replaced)',
        (tester) async {
          final api = SessionEventLicensedApi();
          addTearDown(api.close);
          int expired = 0;
          await prepare(tester, api, onExpired: () => expired++);
          final pending = Completer<dynamic>();
          api.pending = pending;
          await tester.ensureVisible(find.byKey(const Key('licensed-search')));
          await tester.tap(find.byKey(const Key('licensed-search')));
          await tester.pump();
          api.rotate();
          final invalidatedEpoch = api.epoch;
          if (replaced) api.rotate();
          pending.completeError(ApiException(
            status,
            'Session expired',
            expirationIsCurrent: () => api.epoch == invalidatedEpoch,
          ));
          await tester.pumpAndSettle();
          expect(expired, replaced ? 0 : 1);
          expect(find.byKey(const Key('licensed-consent')), findsNothing);
          expect(api.calls.length, 2);
          expect(tester.takeException(), isNull);
        },
      );
    }
  }

  testWidgets('account transition rejects held report', (tester) async {
    final api = LicensedApi()..pending = Completer<dynamic>();
    await prepare(tester, api);
    await tester.ensureVisible(find.byKey(const Key('licensed-search')));
    await tester.tap(find.byKey(const Key('licensed-search')));
    await tester.pump();
    api.epoch++;
    api.pending!.complete(sample());
    await tester.pumpAndSettle();
    expect(find.text('<img src=x>'), findsNothing);
    expect(find.text('Reopen discovery after signing in.'), findsOneWidget);
    expect(api.calls.length, 2);
  });
  testWidgets(
    'typed advanced filters and boolean crowd flags use native API contract',
    (tester) async {
      final api = LicensedApi();
      await prepare(tester, api);
      for (final entry in {
        'country': 'ae',
        'min_reported_power_kw': '50',
        'max_source_age_hours': '24',
        'address_contains': 'Dubai',
        'access_contains': 'Membership',
      }.entries) {
        await tester.ensureVisible(find.byKey(Key('licensed-${entry.key}')));
        await tester.enterText(
          find.byKey(Key('licensed-${entry.key}')),
          entry.value,
        );
      }
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('licensed-membership')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('licensed-membership')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Reported yes only').last);
      await tester.pumpAndSettle();
      await search(tester);
      expect(api.submitted!['country'], 'AE');
      expect(api.submitted!['min_reported_power_kw'], 50.0);
      expect(api.submitted!['max_source_age_hours'], 24);
      expect(api.submitted!['address_contains'], 'Dubai');
      expect(api.submitted!['access_contains'], 'Membership');
      expect(api.submitted!['reported_membership_required'], true);
    },
  );
  testWidgets(
    'external navigation shares destination only on explicit current-account tap',
    (tester) async {
      final api = LicensedApi();
      final opened = <Uri>[];
      await prepare(
        tester,
        api,
        navigation: (uri) async {
          opened.add(uri);
          return true;
        },
      );
      await search(tester);
      final nav = find.text('Send destination to Google Maps (external)');
      await tester.ensureVisible(nav);
      final action = tester
          .widget<TextButton>(
            find.ancestor(of: nav, matching: find.byType(TextButton)),
          )
          .onPressed!;
      expect(opened, isEmpty);
      action();
      await tester.pump();
      expect(opened.single.host, 'www.google.com');
      expect(opened.single.queryParameters['destination'], '25.2,55.3');
      expect(opened.single.queryParameters.containsKey('origin'), false);
      api.epoch++;
      action();
      await tester.pump();
      expect(opened.length, 1);
    },
  );
}
