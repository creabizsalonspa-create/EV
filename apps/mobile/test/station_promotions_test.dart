import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/station_promotions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class PromotionApi extends EvApi {
  PromotionApi() : super(Uri.parse('https://platform.example'));

  final calls = <Map<String, String>>[];
  bool eligible = true;

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
          key: ValueKey(ids.join(',')),
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
}
