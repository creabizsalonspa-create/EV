import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_diagnostics.dart';
import 'test_support.dart';

class DiagnosticsApi extends FixtureApi {
  bool fail = false;
  int requestCount = 0;
  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    requestCount++;
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

class FaultDiagnosticsApi extends DiagnosticsApi {
  int acknowledgments = 0;
  bool acknowledged = false;
  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.endsWith('/source-diagnostics')) {
      final result = await super.request(path, method: method, body: body, query: query);
      result['can_manage_faults'] = true;
      return result;
    }
    if (path.endsWith('/acknowledge')) {
      expect(method, 'PATCH');
      expect(body!['note'], 'Reviewing source fault safely');
      acknowledgments++;
      acknowledged = true;
      throw const ApiException(503, 'PRIVATE LOST ACKNOWLEDGMENT');
    }
    expect(method, 'GET');
    expect(path, 'vendor-ops/companies/1/chargers/2/fault-alerts');
    return {
      'current_page': 1, 'last_page': 1, 'total': 1, 'can_manage_faults': true,
      'data': [
        {'id': 7, 'evse_number': 1, 'connector_number': 1, 'status': 'Faulted', 'error_code': 'GroundFailure', 'occurred_at': '2026-11-05T01:00:00Z', 'recovery_observed_at': null, 'acknowledged_at': acknowledged ? '2026-11-05T02:00:00Z' : null},
      ],
    };
  }
}

void main() {
  testWidgets('fault acknowledgment has no blind retry after a lost response; read recovers state', (tester) async {
    final api = FaultDiagnosticsApi();
    await tester.pumpWidget(MaterialApp(home: ChargerDiagnosticsView(api: api, companyId: 1, chargerId: 2, onExpired: () {})));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Fault alerts'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Acknowledge fault'));
    await tester.tap(find.text('Acknowledge fault'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Reviewing source fault safely');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 30));
    expect(api.acknowledgments, 1);
    expect(find.textContaining('PRIVATE LOST ACKNOWLEDGMENT'), findsNothing);
    await tester.ensureVisible(find.text('Fault alerts'));
    await tester.tap(find.text('Fault alerts'));
    await tester.pumpAndSettle();
    expect(find.text('Fault acknowledged'), findsOneWidget);
  });
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
    expect(api.requestCount, 2);
  });
}
