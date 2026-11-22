import 'dart:async';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_promotions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class PromotionApi extends EvApi {
  PromotionApi() : super(Uri.parse('https://platform.example'));

  final calls = <Map<String, String>>[];
  bool eligible = true;
  Future<void>? nextResponse;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'commercial/campaign-placements');
    expect(method, 'GET');
    expect(body, isNull);
    calls.add(query);
    final response = nextResponse;
    nextResponse = null;
    if (response != null) await response;
    return {
      'current_page': 1,
      'last_page': 1,
      'ranking_basis': 'SPONSORED_ID_ORDER_NOT_PERFORMANCE',
      'client_telemetry_billable': false,
      'placements': [
        if (eligible)
          {
            'station_id': int.parse(
              query['station_id'] ?? query['station_ids[0]']!,
            ),
            'title': 'Synthetic offer',
            'title_ar': 'عرض تجريبي',
            'description': 'Sponsor details',
            'description_ar': 'تفاصيل الراعي',
            'sponsored': true,
            'compatibility_verified': false,
            'availability_verified': false,
            'capability_override': false,
          },
      ],
    };
  }
}

void main() {
  testWidgets(
    'station promotions reread authority on reopen and exclude withdrawn offers',
    (tester) async {
      final api = PromotionApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: StationPromotions(
              api: api,
              stationId: 42,
              country: 'AE',
              onExpired: () {},
            ),
          ),
        ),
      );
      final toggle = find.text('Sponsored promotions');
      expect(api.calls, isEmpty);
      await tester.tap(toggle);
      await tester.pumpAndSettle();
      expect(api.calls.single, {
        'country': 'AE',
        'station_id': '42',
        'placement': 'station_detail',
        'page': '1',
      });
      expect(find.text('Synthetic offer'), findsOneWidget);
      await tester.tap(toggle);
      await tester.pumpAndSettle();
      expect(find.text('Synthetic offer'), findsNothing);
      api.eligible = false;
      await tester.tap(toggle);
      await tester.pumpAndSettle();
      expect(api.calls, hasLength(2));
      expect(find.text('Synthetic offer'), findsNothing);
      expect(find.text('No promotions on this page'), findsOneWidget);
    },
  );

  testWidgets('discovery placements use only the current station page', (
    tester,
  ) async {
    final api = PromotionApi();
    addTearDown(api.close);
    Widget page(List<int> ids) => MaterialApp(
      home: Scaffold(
        body: StationPromotions.discovery(
          api: api,
          discoveryStations: ids,
          onExpired: () {},
        ),
      ),
    );
    await tester.pumpWidget(page([42, 43]));
    await tester.pumpAndSettle();
    expect(api.calls.single, {
      'station_ids[0]': '42',
      'station_ids[1]': '43',
      'placement': 'discovery_banner',
      'page': '1',
    });
    expect(find.text('Synthetic offer'), findsOneWidget);
    api.eligible = false;
    await tester.pumpWidget(page([99]));
    await tester.pumpAndSettle();
    expect(api.calls.last['station_ids[0]'], '99');
    expect(api.calls.last.containsKey('station_ids[1]'), false);
    expect(find.text('Synthetic offer'), findsNothing);
  });

  testWidgets('background hides placements and starts a fresh discovery read', (
    tester,
  ) async {
    final api = PromotionApi();
    addTearDown(api.close);
    final oldResponse = Completer<void>();
    api.nextResponse = oldResponse.future;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StationPromotions.discovery(
            api: api,
            discoveryStations: const [42],
            onExpired: () {},
          ),
        ),
      ),
    );
    expect(api.calls, hasLength(1));

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    oldResponse.complete();
    await tester.pump();
    expect(find.text('Synthetic offer'), findsNothing);
    expect(api.calls, hasLength(1));

    api.eligible = false;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.calls, hasLength(2));
    expect(find.text('Synthetic offer'), findsNothing);
  });

  testWidgets('only an expiration still owned by this session is delivered', (
    tester,
  ) async {
    final api = PromotionApi();
    addTearDown(api.close);
    var expirationIsCurrent = false;
    var expirations = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StationPromotions(
            api: api,
            stationId: 42,
            country: 'AE',
            onExpired: () => expirations++,
          ),
        ),
      ),
    );

    final error = ApiException(
      401,
      'Expired',
      expirationIsCurrent: () => expirationIsCurrent,
    );
    final toggle = find.text('Sponsored promotions');
    final stale = Completer<void>();
    api.nextResponse = stale.future;
    await tester.tap(toggle);
    await tester.pump();
    stale.completeError(error);
    await tester.pumpAndSettle();
    expect(expirations, 0);

    final current = Completer<void>();
    api.nextResponse = current.future;
    expirationIsCurrent = true;
    await tester.tap(find.text('Retry promotions'));
    await tester.pump();
    current.completeError(error);
    await tester.pumpAndSettle();
    expect(expirations, 1);
  });
}
