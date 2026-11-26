import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart' show FlutterMap;
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/address_search.dart';
import 'package:creabiz_ev/discovery_client.dart';
import 'package:creabiz_ev/route_planner.dart';

Map<String, dynamic> place(String label) => {
  'provider': 'geoapify',
  'evidence': 'GEOCODING_RESULT',
  'places': [
    {
      'id': 'geoapify:fixture',
      'label': label,
      'latitude': 25.2,
      'longitude': 55.3,
    },
  ],
};
Map<String, dynamic> estimate() => {
  'provider': 'geoapify',
  'evidence': 'PROVIDER_ROUTE_ESTIMATE',
  'distance_meters': 1250.0,
  'duration_seconds': 220.0,
  'traffic_verified': false,
  'charging_time_included': false,
  'segments': [
    [
      {'latitude': 25.2, 'longitude': 55.3},
      {'latitude': 25.3, 'longitude': 55.4},
    ],
  ],
};
Station station() => Station.fromJson({
  'id': 1,
  'name': 'Synthetic stop',
  'connector_type': 'CCS2',
  'power_kw': 50,
  'evidence': 'DISCOVERY_ONLY',
  'latitude': 25.25,
  'longitude': 55.35,
});

class ExpiredDiscoveryApi extends EvApi {
  int status = 419;
  ExpiredDiscoveryApi() : super(Uri.parse('http://127.0.0.1:8000'));
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async => throw ApiException(status, 'Synthetic expired session');
}

class DeferredDiscoveryApi extends EvApi {
  final pending = Completer<dynamic>();
  int requests = 0;
  DeferredDiscoveryApi() : super(Uri.parse('http://127.0.0.1:8000'));
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    requests++;
    return pending.future;
  }
}

void main() {
  testWidgets('route coordinate inputs distinguish origin and destination in their labels', (tester) async {
    await tester.pumpWidget(MaterialApp(home:RoutePlannerView(stations:[])));
    await tester.pumpAndSettle();
    for (final entry in {
      'origin-lat':'Origin: Latitude',
      'origin-lng':'Origin: Longitude',
      'destination-lat':'Destination: Latitude',
      'destination-lng':'Destination: Longitude',
    }.entries) {
      final field=find.byKey(Key(entry.key));
      await tester.scrollUntilVisible(field,150);
      final textField=find.descendant(of:field,matching:find.byType(TextField));
      expect(tester.widget<TextField>(textField).decoration!.labelText,
        startsWith(entry.value));
    }
    expect(tester.takeException(),isNull);
  });

  test(
    'current expiry clears the owning native session; stale-generation conflicts never sign it out',
    () async {
      final api = ExpiredDiscoveryApi();
      addTearDown(api.close);
      var expired = 0;
      final client = DiscoveryClient(api, onExpired: () => expired++);
      await expectLater(client.capabilities(), throwsA(isA<ApiException>()));
      expect(expired, 1);
      api.status = 409;
      await expectLater(client.capabilities(), throwsA(isA<ApiException>()));
      expect(expired, 1);
    },
  );
  test('native discovery fences late old-epoch results, errors and next-account logout callbacks', () async {
    final api = DeferredDiscoveryApi();
    addTearDown(api.close);
    var onExpired = 0;
    final client = DiscoveryClient(api, onExpired: () => onExpired++);
    final result = client.capabilities();
    expect(api.requests, 1);
    await api.forgetSession();
    expect(client.currentSession(), false);
    api.pending.completeError(const ApiException(401, 'Synthetic previous account expired'));
    await expectLater(result, throwsA(isA<ApiException>()));
    expect(onExpired, 0,
        reason: 'Late previous-account 401 must never sign out the next account');
    await expectLater(client.capabilities(), throwsA(isA<StateError>()));
    expect(api.requests, 1,
        reason: 'Stale discovery client must not submit another provider read');

    final successApi = DeferredDiscoveryApi();
    addTearDown(successApi.close);
    final successClient = DiscoveryClient(successApi);
    final pending = successClient.capabilities();
    await successApi.forgetSession();
    successApi.pending.complete({
      'provider': 'geoapify',
      'geocoding': true,
      'routing': true,
      'max_charging_stops': 3,
    });
    await expectLater(pending, throwsA(isA<StateError>()),
        reason: 'Late previous-account provider result must not become new authority');
    expect(successClient.currentSession(), false);
  });

  test(
    'navigation URL is fixed-origin, bounded and preserves ordered stops',
    () {
      final uri = navigationUri(GeoPoint(0, 0), GeoPoint(25, 55), [
        GeoPoint(1, 2),
        GeoPoint(3, 4),
      ]);
      expect(uri.scheme, 'https');
      expect(uri.host, 'www.google.com');
      expect(uri.queryParameters['origin'], '0.000000,0.000000');
      expect(
        uri.queryParameters['waypoints'],
        '1.000000,2.000000|3.000000,4.000000',
      );
      expect(
        () => navigationUri(
          GeoPoint(0, 0),
          GeoPoint(25, 55),
          List.filled(4, GeoPoint(1, 2)),
        ),
        throwsArgumentError,
      );
      expect(() => GeoPoint(double.nan, 0), throwsFormatException);
    },
  );
  test(
    'route and place evidence reject forged capabilities or malformed geometry',
    () async {
      final bad = estimate()..['traffic_verified'] = true;
      expect(() => RouteEstimate.fromJson(bad), throwsFormatException);
      final client = DiscoveryClient.withRequest(
        (_, _, _) async => place('Address')..['evidence'] = 'LIVE_AVAILABLE',
      );
      await expectLater(
        client.addresses('Address', 'en'),
        throwsFormatException,
      );
    },
  );
  testWidgets(
    'address search is consent gated, debounced and stale results never replace a newer query',
    (tester) async {
      final requests = <Map<String, dynamic>>[];
      final pending = <Completer<dynamic>>[];
      final client = DiscoveryClient.withRequest((_, _, body) {
        requests.add(body!);
        final result = Completer<dynamic>();
        pending.add(result);
        return result.future;
      });
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: AddressSearch(client: client, onSelected: (_) {}),
          ),
        ),
      );
      expect(
        tester
            .widget<TextField>(find.byKey(const Key('address-query')))
            .enabled,
        false,
      );
      expect(requests, isEmpty);
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      await tester.enterText(find.byKey(const Key('address-query')), 'Dubai');
      await tester.pump(const Duration(milliseconds: 599));
      expect(requests, isEmpty);
      await tester.pump(const Duration(milliseconds: 2));
      expect(requests.length, 1);
      await tester.enterText(find.byKey(const Key('address-query')), 'Sharjah');
      await tester.pump(const Duration(milliseconds: 601));
      expect(requests.length, 2);
      pending[1].complete(place('New address'));
      await tester.pump();
      pending[0].complete(place('Old address'));
      await tester.pump();
      expect(find.text('New address'), findsOneWidget);
      expect(find.text('Old address'), findsNothing);
      expect(requests.first['consent'], true);
      expect(requests.first['language'], 'en');
    },
  );
  testWidgets(
    'native route sends exact endpoint/stop order and shows only sourced estimates',
    (tester) async {
      Map<String, dynamic>? payload;
      final client = DiscoveryClient.withRequest((path, _, body) async {
        if (path == 'capabilities')
          return {
            'provider': 'geoapify',
            'geocoding': false,
            'routing': true,
            'max_charging_stops': 3,
          };
        payload = body;
        return estimate();
      });
      await tester.pumpWidget(
        MaterialApp(
          home: RoutePlannerView(
            client: client,
            stations: [station()],
            initialStop: station(),
          ),
        ),
      );
      await tester.pumpAndSettle();
      for (final entry in {
        'origin-lat': '٢٥٫٢',
        'origin-lng': '٥٥٫٣',
        'destination-lat': '25.3',
        'destination-lng': '55.4',
      }.entries) {
        await tester.ensureVisible(find.byKey(Key(entry.key)));
        await tester.enterText(find.byKey(Key(entry.key)), entry.value);
      }
      final consent = find.text(
        'Send these coordinates and stops to Geoapify for a route estimate.',
      );
      await tester.ensureVisible(consent);
      await tester.pumpAndSettle();
      expect(consent.hitTestable(), findsOneWidget);
      await tester.tap(consent);
      await tester.pump();
      await tester.ensureVisible(find.byKey(const Key('route-preview')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('route-preview')).hitTestable(),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('route-preview')));
      await tester.pumpAndSettle();
      expect(payload!['points'], [
        {'latitude': 25.2, 'longitude': 55.3},
        {'latitude': 25.25, 'longitude': 55.35},
        {'latitude': 25.3, 'longitude': 55.4},
      ]);
      expect(find.text('1.3 km · 4 min'), findsOneWidget);
      expect(
        find.textContaining('charging, queues and stop duration are excluded'),
        findsOneWidget,
      );
    },
  );
  testWidgets(
    'unconfigured provider still supports explicit-consent manual multi-stop navigation',
    (tester) async {
      // The expanded connector-filter UI is now taller than the default
      // 800x600 widget harness. Test the unchanged consent/navigation journey
      // in a viewport large enough to reach the actual, visible button.
      await tester.binding.setSurfaceSize(const Size(900, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      Uri? opened;
      await tester.pumpWidget(
        MaterialApp(
          home: RoutePlannerView(
            stations: [station()],
            origin: GeoPoint(0, 0),
            initialStop: station(),
            navigate: (uri) async {
              opened = uri;
              return true;
            },
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('destination-lat')));
      await tester.enterText(find.byKey(const Key('destination-lat')), '25.3');
      await tester.ensureVisible(find.byKey(const Key('destination-lng')));
      await tester.enterText(find.byKey(const Key('destination-lng')), '55.4');
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-navigation')),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<FilledButton>(find.byKey(const Key('route-preview')))
            .onPressed,
        isNull,
      );
      await tester.tap(find.byKey(const Key('route-navigation')));
      await tester.pumpAndSettle();
      expect(opened, isNull);
      await tester.tap(find.byKey(const Key('navigation-consent')));
      await tester.pumpAndSettle();
      expect(opened!.queryParameters['waypoints'], '25.250000,55.350000');
    },
  );

  testWidgets(
    'late previous-account routing capabilities cannot enable a new account provider',
    (tester) async {
      final previous = Completer<dynamic>();
      final current = Completer<dynamic>();
      final first = DiscoveryClient.withRequest((path, method, body) {
        expect(path, 'capabilities');
        return previous.future;
      });
      final second = DiscoveryClient.withRequest((path, method, body) {
        expect(path, 'capabilities');
        return current.future;
      });
      Widget screen(DiscoveryClient client) => MaterialApp(
        home: RoutePlannerView(
          key: const ValueKey('same-route-context'),
          client: client,
          stations: const [],
        ),
      );
      await tester.pumpWidget(screen(first));
      await tester.pump();
      await tester.pumpWidget(screen(second));
      await tester.pump();
      current.complete({
        'provider': 'geoapify',
        'max_charging_stops': 3,
        'geocoding': false,
        'routing': false,
      });
      await tester.pumpAndSettle();
      previous.complete({
        'provider': 'geoapify',
        'max_charging_stops': 3,
        'geocoding': true,
        'routing': true,
      });
      await tester.pumpAndSettle();
      // Route preview is below the fold in the lazily built ListView.
      // Materialize it before asserting its disabled state on Android/iOS.
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-preview')),
        240,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(
        tester.widget<FilledButton>(find.byKey(const Key('route-preview'))).onPressed,
        isNull,
        reason: 'Old-account routing permission cannot enable the new account',
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'backgrounding clears private route coordinates, selected stops, consent and late provider results',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(900, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final lateRoute = Completer<dynamic>();
      var routeRequests = 0;
      final client = DiscoveryClient.withRequest((path, _, body) {
        if (path == 'capabilities') {
          return Future<dynamic>.value({
            'provider': 'geoapify',
            'geocoding': false,
            'routing': true,
            'max_charging_stops': 3,
          });
        }
        expect(path, 'route');
        routeRequests++;
        return lateRoute.future;
      });
      await tester.pumpWidget(MaterialApp(
        home: RoutePlannerView(
          client: client,
          stations: [station()],
          initialStop: station(),
          origin: GeoPoint(25.2, 55.3),
        ),
      ));
      await tester.pumpAndSettle();
      expect(
        tester.widget<TextFormField>(find.byKey(const Key('origin-lat'))).controller!.text,
        '25.2',
      );
      expect(find.textContaining('Synthetic stop'), findsWidgets);
      for (final field in {
        'destination-lat': '25.3',
        'destination-lng': '55.4',
      }.entries) {
        await tester.ensureVisible(find.byKey(Key(field.key)));
        await tester.enterText(find.byKey(Key(field.key)), field.value);
      }
      // Render the newly edited itinerary before re-authorizing its consent.
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-provider-consent')),
        240,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byKey(const Key('route-provider-consent')));
      await tester.pump();
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-preview')),
        240,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byKey(const Key('route-preview')));
      await tester.pump();
      expect(routeRequests, 1);

      // Simulate an actual mobile lifecycle, not a detached test shortcut.
      for (final state in [
        AppLifecycleState.inactive,
        AppLifecycleState.hidden,
        AppLifecycleState.paused,
      ]) {
        tester.binding.handleAppLifecycleStateChanged(state);
        await tester.pump();
      }
      expect(
        find.text('Route planning hidden while the app is inactive.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('origin-lat')), findsNothing);
      expect(find.textContaining('Synthetic stop'), findsNothing);

      lateRoute.complete(estimate());
      await tester.pump();
      for (final state in [
        AppLifecycleState.hidden,
        AppLifecycleState.inactive,
        AppLifecycleState.resumed,
      ]) {
        tester.binding.handleAppLifecycleStateChanged(state);
        await tester.pump();
      }
      await tester.pumpAndSettle();
      for (final key in [
        'origin-lat',
        'origin-lng',
        'destination-lat',
        'destination-lng',
      ]) {
        await tester.scrollUntilVisible(
          find.byKey(Key(key)),
          key.startsWith('origin-') ? -240 : 240,
          scrollable: find.byType(Scrollable).first,
        );
        expect(
          tester.widget<TextFormField>(find.byKey(Key(key))).controller!.text,
          isEmpty,
        );
      }
      expect(find.textContaining('Synthetic stop'), findsNothing);
      // Render the newly edited itinerary before re-authorizing its consent.
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-provider-consent')),
        240,
        scrollable: find.byType(Scrollable).first,
      );
      expect(
        tester.widget<CheckboxListTile>(
          find.byKey(const Key('route-provider-consent')),
        ).value,
        false,
      );
      expect(find.textContaining('km ·'), findsNothing);
      expect(routeRequests, 1,
          reason: 'Foreground resume must not replay an old provider route');
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'editing reviewed route coordinates requires renewed provider consent',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(900, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final queries = <Map<String, dynamic>>[];
      final client = DiscoveryClient.withRequest((path, _, body) async {
        if (path == 'capabilities') {
          return {
            'provider': 'geoapify',
            'geocoding': false,
            'routing': true,
            'max_charging_stops': 3,
          };
        }
        queries.add(Map<String, dynamic>.from(body!));
        return estimate();
      });
      await tester.pumpWidget(MaterialApp(
        home: RoutePlannerView(
          client: client,
          stations: [station()],
          origin: GeoPoint(25.2, 55.3),
        ),
      ));
      await tester.pumpAndSettle();
      for (final entry in {
        'destination-lat': '25.3',
        'destination-lng': '55.4',
      }.entries) {
        await tester.ensureVisible(find.byKey(Key(entry.key)));
        await tester.enterText(find.byKey(Key(entry.key)), entry.value);
      }
      // Render the newly edited itinerary before re-authorizing its consent.
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      final consent = find.byKey(const Key('route-provider-consent'));
      await tester.ensureVisible(consent);
      await tester.tap(consent);
      await tester.pumpAndSettle();
      expect(tester.widget<CheckboxListTile>(consent).value, isTrue);
      expect(tester.widget<FilledButton>(
        find.byKey(const Key('route-preview'))).onPressed, isNotNull);
      await tester.ensureVisible(find.byKey(const Key('destination-lat')));
      await tester.enterText(find.byKey(const Key('destination-lat')), '25.35');
      await tester.pumpAndSettle();
      expect(tester.widget<CheckboxListTile>(consent).value, isFalse);
      expect(tester.widget<FilledButton>(
        find.byKey(const Key('route-preview'))).onPressed, isNull);
      expect(queries, isEmpty, reason:'Editing a route never contacts Geoapify');
      await tester.ensureVisible(consent);
      await tester.tap(consent);
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('route-preview')));
      await tester.tap(find.byKey(const Key('route-preview')));
      await tester.pumpAndSettle();
      expect(queries.single['consent'], true);
      expect((queries.single['points'] as List).last, {
        'latitude': 25.35, 'longitude': 55.4,
      });
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'changing authenticated discovery client invalidates pending navigation permission and private route fields',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(900, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final first = DiscoveryClient.withRequest((_, _, _) async => <String, dynamic>{});
      final second = DiscoveryClient.withRequest((_, _, _) async => <String, dynamic>{});
      // Keep the widget identity stable, changing only its authenticated
      // discovery client while the external-navigation dialog is present.
      var launches = 0;
      Widget route(DiscoveryClient client) => MaterialApp(
        home: RoutePlannerView(
          key: const ValueKey('same-route-planner'),
          client: client,
          stations: [station()],
          origin: GeoPoint(0, 0),
          initialStop: station(),
          navigate: (_) async {
            launches++;
            return true;
          },
        ),
      );
      await tester.pumpWidget(route(first));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('destination-lat')));
      await tester.enterText(find.byKey(const Key('destination-lat')), '25.3');
      await tester.ensureVisible(find.byKey(const Key('destination-lng')));
      await tester.enterText(find.byKey(const Key('destination-lng')), '55.4');
      await tester.scrollUntilVisible(find.byKey(const Key('route-navigation')),
          250, scrollable: find.byType(Scrollable).first);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('route-navigation')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('navigation-consent')), findsOneWidget);
      await tester.pumpWidget(route(second));
      await tester.pumpAndSettle();
      // The dialog may still be visible. Accepting the previous session's
      // consent must not open any third-party navigation app.
      await tester.tap(find.byKey(const Key('navigation-consent')));
      await tester.pumpAndSettle();
      expect(launches, 0);
      // The next RoutePlannerView was deliberately given the SAME old
      // initial origin. A client rotation must reject that cached location
      // rather than silently exposing it to the next signed-in customer.
      for (final key in ['origin-lat', 'origin-lng', 'destination-lat', 'destination-lng']) {
        final coordinate = tester.widget<TextFormField>(find.byKey(Key(key)));
        expect(coordinate.controller!.text, isEmpty,
            reason: 'Private route input $key must not cross account rotation');
      }
      expect(find.textContaining('1. Synthetic stop'), findsNothing,
        reason:'Previous account stop must not survive source change');
      expect(find.byKey(const Key('route-stop-1')), findsNothing,
        reason:'Previous account catalog requires new authenticated results');
      expect(find.text('Provider estimate only'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'route connector filter blocks mismatched selected stops but preserves an explicit removal path',
    (tester) async {
      final type2 = Station.fromJson({
        'id': 2,
        'name': 'Different plug',
        'connector_type': 'Type2',
        'power_kw': 22,
        'evidence': 'DISCOVERY_ONLY',
        'latitude': 25.26,
        'longitude': 55.36,
      });
      await tester.binding.setSurfaceSize(const Size(900, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      await tester.pumpWidget(MaterialApp(home: RoutePlannerView(
        stations: [station(), type2],
        initialStop: type2,
      )));
      await tester.pumpAndSettle();
      final filter = find.byKey(const Key('route-connector-filter'));
      await tester.ensureVisible(filter);
      await tester.tap(filter);
      await tester.pumpAndSettle();
      await tester.tap(find.text('CCS2').last);
      await tester.pumpAndSettle();
      expect(tester.widget<DropdownButton<String>>(filter).value, 'CCS2');
      expect(find.byKey(const Key('route-connector-conflict')), findsOneWidget);
      expect(tester.widget<OutlinedButton>(find.byKey(const Key('route-navigation'))).onPressed, isNull);
      expect(find.textContaining('not verified compatibility with your vehicle'), findsOneWidget);
      final remove = find.byTooltip('Remove stop');
      await tester.ensureVisible(remove);
      await tester.tap(remove);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('route-connector-conflict')), findsNothing);
      expect(tester.widget<OutlinedButton>(find.byKey(const Key('route-navigation'))).onPressed, isNotNull);
      final expand = find.text('Add charging stops from results');
      await tester.ensureVisible(expand);
      await tester.tap(expand);
      await tester.pumpAndSettle();
      final incompatible = find.byKey(const Key('route-stop-2'));
      final compatible = find.byKey(const Key('route-stop-1'));
      await tester.ensureVisible(incompatible);
      expect(tester.widget<CheckboxListTile>(incompatible).onChanged, isNull);
      await tester.ensureVisible(compatible);
      expect(tester.widget<CheckboxListTile>(compatible).onChanged, isNotNull);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('route connector selection is not a verified charging permission or a background provider call',(tester) async {
    var calls = 0;
    final client = DiscoveryClient.withRequest((path, method, body) async {
      calls++;
      if(path=='capabilities')return {
        'provider':'geoapify','routing':false,'geocoding':false,'max_charging_stops':3,
      };
      throw StateError('Unexpected navigation or licensed provider call');
    });
    final type2=Station.fromJson({
      'id':3,'name':'Type2 station','connector_type':'Type2','power_kw':7,
      'evidence':'DISCOVERY_ONLY','latitude':25.2,'longitude':55.3,
    });
    await tester.binding.setSurfaceSize(const Size(900, 1600));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(home:RoutePlannerView(
      client:client,stations:[station(),type2],
    )));
    await tester.pumpAndSettle();
    final initialCalls=calls;
    final filter=find.byKey(const Key('route-connector-filter'));
    await tester.ensureVisible(filter);
    await tester.tap(filter);await tester.pumpAndSettle();
    await tester.tap(find.text('Type2').last);await tester.pumpAndSettle();
    expect(calls, initialCalls, reason: 'Connector selection must never contact a routing provider');
    expect(find.textContaining('not verified compatibility with your vehicle'),findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('route-preview'))).onPressed,isNull);
    expect(tester.takeException(),isNull);
  });

  testWidgets(
    'same discovery client cannot retain old account route or launch stale navigation after session epoch change',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(900, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      var authenticated = true;
      var launches = 0;
      final pendingCapabilities = Completer<dynamic>();
      final client = DiscoveryClient.withRequest(
        (path, _, __) {
          expect(path, 'capabilities');
          return pendingCapabilities.future;
        },
        currentSession: () => authenticated,
      );
      Widget route() => MaterialApp(
        home: RoutePlannerView(
          key: const ValueKey('same-client-new-account'),
          client: client,
          origin: GeoPoint(25.2, 55.3),
          stations: [station()],
          initialStop: station(),
          navigate: (_) async { launches++; return true; },
        ),
      );
      await tester.pumpWidget(route());
      await tester.pump();
      expect(
        tester.widget<TextFormField>(find.byKey(const Key('origin-lat'))).controller!.text,
        '25.2',
      );
      await tester.ensureVisible(find.byKey(const Key('destination-lat')));
      await tester.enterText(find.byKey(const Key('destination-lat')), '25.3');
      await tester.ensureVisible(find.byKey(const Key('destination-lng')));
      await tester.enterText(find.byKey(const Key('destination-lng')), '55.4');
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-navigation')),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 350));
      await tester.tap(find.byKey(const Key('route-navigation')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 350));
      expect(find.byKey(const Key('navigation-consent')), findsOneWidget);

      // The EvApi account epoch can change without changing the
      // DiscoveryClient object. An already-open Google Maps confirmation
      // and an in-flight provider capability read must both lose authority.
      authenticated = false;
      pendingCapabilities.complete({
        'provider': 'geoapify',
        'routing': true,
        'geocoding': true,
        'max_charging_stops': 3,
      });
      await tester.pump();
      await tester.pumpWidget(route());
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('navigation-consent')));
      await tester.pumpAndSettle();
      expect(launches, 0);
      expect(find.text('Account changed. Reopen route planning.'), findsOneWidget);
      expect(find.byKey(const Key('origin-lat')), findsNothing);
      expect(find.text('Synthetic stop'), findsNothing);
      expect(find.byKey(const Key('route-provider-consent')), findsNothing);
      expect(find.byType(FlutterMap), findsNothing);

      // Re-entering with the very same client object does not resurrect
      // stale origin/destination, old stops or earlier provider consent.
      authenticated = true;
      await tester.pumpWidget(route());
      await tester.pumpAndSettle();
      for (final key in ['origin-lat', 'origin-lng', 'destination-lat', 'destination-lng']) {
        final field = tester.widget<TextFormField>(find.byKey(Key(key)));
        expect(field.controller!.text, isEmpty);
      }
      expect(find.byKey(const Key('route-stop-1')), findsNothing);
      expect(find.byKey(const Key('route-provider-consent')), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
