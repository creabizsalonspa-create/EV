import 'dart:async';
import 'package:flutter/material.dart';
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

void main() {
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
}
