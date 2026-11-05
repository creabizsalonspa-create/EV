import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_diagnostics.dart';
import 'test_support.dart';

class DiagnosticsApi extends FixtureApi {
  bool fail = false;
  int calls = 0;
  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls++;
    expect(method, 'GET');
    expect(path, 'vendor-ops/companies/1/chargers/2/source-diagnostics');
    if (fail) throw ApiException(503, 'PRIVATE SOURCE DETAILS');
    return {
      'protocol': '2.0.1', 'source_registered': true, 'source_online': true,
      'recent_message_observed': false, 'last_message_at': '2026-11-05T01:00:00Z',
      'firmware_version': '<untrusted-firmware>', 'next_before': null,
      'events': [
        {'connectorStatus': 'Faulted', 'errorCode': 'GroundFailure', 'connectorId': 1, 'evseId': 1, 'timestamp': '2026-11-05T01:00:00Z'},
      ],
    };
  }
}

void main() {
  testWidgets('source faults and stale evidence stay observational; refresh failure clears old data', (tester) async {
    final api = DiagnosticsApi();
    await tester.pumpWidget(MaterialApp(home: ChargerDiagnosticsView(
      api: api, companyId: 1, chargerId: 2, onExpired: () {},
    )));
    await tester.pumpAndSettle();
    expect(find.text('Source reports online'), findsOneWidget);
    expect(find.text('No recent source message'), findsOneWidget);
    expect(find.text('Faulted · GroundFailure'), findsOneWidget);
    expect(find.text('<untrusted-firmware>'), findsOneWidget);
    api.fail = true;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Source reports online'), findsNothing);
    expect(find.textContaining('PRIVATE SOURCE DETAILS'), findsNothing);
    expect(api.calls, 2);
  });
}
