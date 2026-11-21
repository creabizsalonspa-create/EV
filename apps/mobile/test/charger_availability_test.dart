import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_availability.dart';
import 'test_support.dart';

class AvailabilityApi extends FixtureApi {
  static const endpoint =
      'vendor-ops/companies/1/chargers/2/availability-operations';
  final requests = <Map<String, dynamic>>[];
  bool admitted = false, confirmed = false, fail = true, write = true;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == '$endpoint/1/observe') {
      expect(method, 'POST');
      confirmed = true;
      return {};
    }
    expect(path, endpoint);
    if (method == 'POST') {
      requests.add({...body!});
      if (fail) throw const ApiException(503, 'PRIVATE source detail');
      admitted = true;
      return {};
    }
    return {
      'data': [
        if (admitted)
          {
            'id': 1,
            'status': confirmed ? 'scheduled' : 'transport_submitted',
            'created_at': '2026-11-21',
            'evidence': 'SIMULATED_SOURCE',
            'can_observe': !confirmed,
            'physical_availability_verified': false,
          },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': admitted ? 1 : 0,
      'can_request': write,
      'connectors': [
        {'id': 18, 'connector_number': 2},
      ],
    };
  }
}

void main() {
  testWidgets(
    'lost simulator response requires explicit retry with the same target and identity',
    (tester) async {
      final api = AvailabilityApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerAvailabilityView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButton<int>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Connector 2').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Review simulator settings',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Request simulated availability change'));
      await tester.pumpAndSettle();
      expect(api.requests, hasLength(1));
      expect(api.requests.single['connector_id'], 18);
      expect(api.requests.single['requested_state'], 'Inoperative');
      expect(find.textContaining('PRIVATE'), findsNothing);
      await tester.pump(const Duration(seconds: 60));
      expect(api.requests, hasLength(1));

      api.fail = false;
      await tester.tap(find.text('Retry saved request'));
      await tester.pumpAndSettle();
      expect(api.requests[1], api.requests[0]);
      expect(
        find.textContaining('Awaiting simulator response'),
        findsOneWidget,
      );
      await tester.ensureVisible(find.text('Check response'));
      await tester.tap(find.text('Check response'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Simulator scheduled'), findsOneWidget);
      expect(api.requests, hasLength(2));
    },
  );

  testWidgets('inventory readers see simulator evidence without a write form', (
    tester,
  ) async {
    final api = AvailabilityApi()
      ..admitted = true
      ..write = false;
    await tester.pumpWidget(
      MaterialApp(
        home: ChargerAvailabilityView(
          api: api,
          companyId: 1,
          chargerId: 2,
          onExpired: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Request simulated availability change'), findsNothing);
    expect(find.textContaining('Awaiting simulator response'), findsOneWidget);
    expect(api.requests, isEmpty);
  });
}
