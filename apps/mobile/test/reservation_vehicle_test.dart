import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/reservation_vehicle.dart';
import 'test_support.dart';

class VehicleFixture extends FixtureApi {
  final queries = <Map<String, String>>[];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    queries.add(Map.from(query));
    final second = query['page'] == '2';
    return {
      'data': [
        {
          'id': second ? 21 : 20,
          'name': second ? 'Second EV' : 'My EV',
          'connector_type': 'Type2',
        },
        if (!second)
          {'id': 99, 'name': 'Wrong connector', 'connector_type': 'CCS2'},
      ],
      'current_page': second ? 2 : 1,
      'last_page': 2,
      'total': 2,
    };
  }
}

class PendingVehicles extends FixtureApi {
  final response = Completer<dynamic>();
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) => response.future;
}

void main() {
  testWidgets(
    'owned compatible vehicle picker paginates and returns explicit selection',
    (tester) async {
      final api = VehicleFixture();
      addTearDown(api.close);
      Map<String, dynamic>? result;
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () async {
                  result = await showDialog<Map<String, dynamic>>(
                    context: context,
                    builder: (_) => ReservationVehiclePicker(
                      api: api,
                      connector: 'Type2',
                      onExpired: () {},
                    ),
                  );
                },
                child: const Text('Open'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();
      expect(api.queries.single['connector_type'], 'Type2');
      expect(find.text('Wrong connector'), findsNothing);
      await tester.tap(find.text('More vehicles'));
      await tester.pumpAndSettle();
      expect(api.queries.last['page'], '2');
      await tester.tap(find.text('Second EV'));
      await tester.pumpAndSettle();
      expect(result!['id'], 21);
    },
  );
  testWidgets('legacy unassociated selection remains explicit', (tester) async {
    final api = VehicleFixture();
    addTearDown(api.close);
    Map<String, dynamic>? result;
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () async {
                result = await showDialog<Map<String, dynamic>>(
                  context: context,
                  builder: (_) => ReservationVehiclePicker(
                    api: api,
                    connector: 'Type2',
                    onExpired: () {},
                  ),
                );
              },
              child: const Text('Open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Continue without a vehicle'));
    await tester.pumpAndSettle();
    expect(result!['unassigned'], true);
    expect(result!.containsKey('id'), false);
  });
  testWidgets('closed picker discards a stale unauthorized response', (
    tester,
  ) async {
    final api = PendingVehicles();
    addTearDown(api.close);
    var expired = false;
    await tester.pumpWidget(
      MaterialApp(
        home: ReservationVehiclePicker(
          api: api,
          connector: 'Type2',
          onExpired: () => expired = true,
        ),
      ),
    );
    await tester.pumpWidget(const SizedBox());
    api.response.completeError(const ApiException(401, 'old request'));
    await tester.pumpAndSettle();
    expect(expired, false);
    expect(tester.takeException(), null);
  });

  testWidgets('reused picker drops a prior account response and garage', (
    tester,
  ) async {
    final prior = PendingVehicles();
    final current = VehicleFixture();
    addTearDown(prior.close);
    addTearDown(current.close);
    var expired = false;

    await tester.pumpWidget(
      MaterialApp(
        home: ReservationVehiclePicker(
          api: prior,
          connector: 'Type2',
          onExpired: () => expired = true,
        ),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: ReservationVehiclePicker(
          api: current,
          connector: 'Type2',
          onExpired: () => expired = true,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('My EV'), findsOneWidget);
    expect(find.text('Wrong connector'), findsNothing);

    // An old authenticated GET may settle after this widget is reused.
    prior.response.complete({
      'data': [
        {'id': 77, 'name': 'Prior account EV', 'connector_type': 'Type2'},
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    });
    await tester.pumpAndSettle();
    expect(find.text('Prior account EV'), findsNothing);
    expect(find.text('My EV'), findsOneWidget);
    expect(expired, false);
    expect(tester.takeException(), null);
  });
}
