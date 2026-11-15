import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/roaming_meter_history.dart';
import 'test_support.dart';

class HistoryApi extends FixtureApi {
  bool unsafe = false;
  final queries = <Map<String, String>>[];
  final paths = <String>[];
  Completer<dynamic>? delayed;
  Map<String, dynamic> report(int page) => {
    'scope': 'roaming_source_meter_history',
    'order_id': 3,
    'through_id': 41,
    'source_bound': true,
    'recorded_since': '2026-11-15T10:00:00Z',
    'coverage': 'ACCEPTED_VERSIONS_SINCE_INSTRUMENTATION',
    'backfilled': false,
    'evidence': 'PARTNER_REPORTED_UNVERIFIED',
    'physical_energy_verified': unsafe,
    'billing_authority': false,
    'commands_sent': false,
    'observations': {
      'current_page': page,
      'last_page': 2,
      'data': [
        {
          'status': 'ACTIVE',
          'reported_energy_kwh': page == 1 ? '0.009' : '0.010',
          'energy_wh': 9,
          'meter_regressed': true,
          'precision_requires_review': false,
          'source_clock_ahead': false,
          'source_updated_at': '2026-11-15T10:01:00Z',
          'received_at': '2026-11-15T10:01:01Z',
        },
      ],
    },
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    paths.add(path);
    queries.add(Map.of(query));
    return delayed?.future ?? report(int.parse(query['page']!));
  }
}

Widget app(HistoryApi api, {int? provider}) => MaterialApp(
  home: RoamingMeterHistoryView(
    api: api,
    orderId: 3,
    providerId: provider,
    onExpired: () {},
  ),
);
void main() {
  testWidgets(
    'own source readings paginate without command writes and hide on background',
    (tester) async {
      final api = HistoryApi();
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      expect(api.paths.single, 'roaming/sandbox-orders/3/meter-history');
      expect(find.text('ACTIVE · 0.009 kWh'), findsOneWidget);
      expect(
        find.text('Meter decreased; reconciliation required.'),
        findsOneWidget,
      );
      await tester.ensureVisible(find.text('Next'));
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(api.queries.last, {'page': '2', 'through': '41'});
      expect(find.text('ACTIVE · 0.010 kWh'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.textContaining('kWh'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.paths.length, 2);
      expect(find.textContaining('kWh'), findsNothing);
    },
  );
  testWidgets(
    'operator targets stay scoped and unexpected source authority is hidden',
    (tester) async {
      final api = HistoryApi()..unsafe = true;
      await tester.pumpWidget(app(api, provider: 7));
      await tester.pumpAndSettle();
      expect(
        api.paths.single,
        'ocpi/providers/7/sandbox-orders/3/meter-history',
      );
      expect(find.textContaining('kWh'), findsNothing);
      expect(find.textContaining('Source history unavailable'), findsOneWidget);
    },
  );
  testWidgets('a late source response cannot populate another account', (
    tester,
  ) async {
    final api = HistoryApi()..delayed = Completer<dynamic>();
    await tester.pumpWidget(app(api));
    await tester.pump();
    final other = HistoryApi();
    await tester.pumpWidget(app(other));
    await tester.pumpAndSettle();
    api.delayed!.complete(api.report(1));
    await tester.pumpAndSettle();
    expect(find.textContaining('kWh'), findsNothing);
    expect(other.paths, isEmpty);
    await tester.tap(find.text('Refresh history'));
    await tester.pumpAndSettle();
    expect(find.text('ACTIVE · 0.009 kWh'), findsOneWidget);
    expect(other.paths.length, 1);
  });
}
