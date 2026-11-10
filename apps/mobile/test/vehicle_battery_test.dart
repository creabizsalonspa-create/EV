import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_battery.dart';
import 'test_support.dart';

class BatteryApi extends FixtureApi {
  Map<String, dynamic> prefs = {
    'vehicle_id': 3,
    'connector_type': 'Type2',
    'alerts_enabled': true,
    'threshold_percent': 20,
    'version': 1,
    'can_simulate': true,
  };
  final observations = <Map<String, dynamic>>[];
  Map<String, dynamic>? saved;
  bool loseFirst = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'PUT') {
      saved = Map.from(body!);
      prefs = {...prefs, ...body, 'version': (prefs['version'] as int) + 1};
      return prefs;
    }
    if (path.endsWith('/battery-simulation')) {
      observations.add(Map.from(body!));
      if (loseFirst && observations.length == 1)
        throw const ApiException(503, 'Unknown observation result');
      return {
        'source': 'simulator',
        'alerted':
            prefs['alerts_enabled'] == true &&
            (body['battery_percent'] as int) <=
                (prefs['threshold_percent'] as int),
      };
    }
    return prefs;
  }
}

Widget page(EvApi api, {ValueChanged<String>? discover}) => MaterialApp(
  home: Scaffold(
    body: SingleChildScrollView(
      child: VehicleBatteryView(
        api: api,
        vehicleId: 3,
        onExpired: () {},
        onDiscover: discover ?? (_) {},
      ),
    ),
  ),
);
void main() {
  testWidgets(
    'persist preferences with server version and launch compatible discovery',
    (tester) async {
      final api = BatteryApi();
      addTearDown(api.close);
      String? chosen;
      await tester.pumpWidget(page(api, discover: (value) => chosen = value));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField).first, '١٠');
      await tester.tap(find.byType(CheckboxListTile));
      await tester.tap(find.text('Save battery preferences'));
      await tester.pumpAndSettle();
      expect(api.saved, {
        'alerts_enabled': false,
        'threshold_percent': 10,
        'expected_version': 1,
      });
      await tester.ensureVisible(find.text('Find compatible chargers'));
      await tester.tap(find.text('Find compatible chargers'));
      expect(chosen, 'Type2');
      expect(api.saved!.containsKey('latitude'), false);
    },
  );
  testWidgets('unknown observation retries the frozen key and percentage', (
    tester,
  ) async {
    final api = BatteryApi()..loseFirst = true;
    addTearDown(api.close);
    await tester.pumpWidget(page(api));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Record simulator observation'));
    await tester.tap(find.text('Record simulator observation'));
    await tester.pumpAndSettle();
    expect(
      tester.widget<TextField>(find.byType(TextField).last).enabled,
      false,
    );
    await tester.ensureVisible(find.text('Retry same simulator observation'));
    await tester.tap(find.text('Retry same simulator observation'));
    await tester.pumpAndSettle();
    expect(api.observations.length, 2);
    expect(api.observations[0], api.observations[1]);
    expect(
      find.text('Simulated alert saved in Notifications.'),
      findsOneWidget,
    );
  });
  testWidgets(
    'simulator controls stay absent when server capability is disabled',
    (tester) async {
      final api = BatteryApi()..prefs['can_simulate'] = false;
      addTearDown(api.close);
      await tester.pumpWidget(page(api));
      await tester.pumpAndSettle();
      expect(find.text('Record simulator observation'), findsNothing);
      expect(find.text('Save battery preferences'), findsOneWidget);
    },
  );
}
