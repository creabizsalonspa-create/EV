import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/connector_fleet.dart';
import 'package:creabiz_ev/charger_diagnostics.dart';
import 'test_support.dart';

class FleetApi extends FixtureApi {
  int reads = 0;
  bool unsafe = false, navigation = false;
  String snapshot = '2026-11-15T12:00:00Z';
  int sourceStation = 7;
  void Function(Map<String,dynamic>)? alter;
  int diagnosticsReads = 0;
  Completer<dynamic>? delayed;
  final queries = <Map<String, String>>[];
  Map<String, dynamic> result(int page) {
    final result = <String,dynamic>{
    'scope': 'recorded_connector_fleet',
    'as_of': snapshot,
    'evidence': 'SIMULATED_SOURCE',
    'physical_availability_verified': unsafe,
    'remote_control': false,
    'billing_authority': false,
    'connectors': {
      'current_page': page,
      'last_page': 2,
      'data': [
        {
          'mapping_id': page,
          'station_id': sourceStation,
          'station_name': 'Private fleet $page',
          'protocol': '2.0.1',
          'inventory_eligible_at_read': true,
          if (navigation)
            'operations_target': {'company_id': 7, 'charger_id': 9},
          'status_freshness': {
            'state': page == 1 ? 'recent' : 'stale',
            'reported_status': page == 1 ? 'Charging' : null,
            'evidence': 'SIMULATED_SOURCE',
            'physical_availability_verified': false,
            'observed_at': page == 1 ? '2026-11-15T11:59:00Z' : '2026-11-15T11:50:00Z',
            'checked_at': '2026-11-15T12:00:00Z',
            'valid_until': page == 1 ? '2026-11-15T12:04:00Z' : '2026-11-15T11:55:00Z',
            'max_age_seconds': 300,
          },
        },
      ],
    },
    };
    alter?.call(result);
    return result;
  }
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'vendor-ops/companies/7/chargers/9/source-diagnostics') {
      expect(method, 'GET');
      diagnosticsReads++;
      return Completer<dynamic>().future;
    }
    expect(path, 'charging-operations/connectors');
    expect(method, 'GET');
    reads++;
    queries.add(Map.of(query));
    return delayed?.future ?? result(int.parse(query['page']!));
  }
}

Widget app(FleetApi api) => MaterialApp(
  home: ConnectorFleetView(api: api, onExpired: () {}),
);
void main() {
  test('fleet snapshot uses exact microsecond source boundary', () {
    final f = <String,dynamic>{
      'state':'recent','reported_status':'Faulted',
      'evidence':'SIMULATED_SOURCE','physical_availability_verified':false,
      'max_age_seconds':300,'observed_at':'2026-11-15T11:55:00.000005Z',
      'checked_at':'2026-11-15T11:59:59.999999Z',
      'valid_until':'2026-11-15T12:00:00.000005Z',
    };
    expect(connectorFleetStateAtSnapshot(f,'2026-11-15T12:00:00.000004Z'),'Faulted');
    expect(connectorFleetStateAtSnapshot(f,'2026-11-15T12:00:00.000005Z'),'stale');
    expect(connectorFleetStateAtSnapshot(f,'2026-11-15T11:59:59.999998Z'),'unknown');
    for(final clock in ['2026-02-30T12:00:00Z','2026-11-15 12:00:00','2026-11-15T12:00:00+00:00',null]) {
      expect(connectorFleetStateAtSnapshot(f,clock),'unknown');
    }
  });
  testWidgets('fleet rejects mismatched scope and retired navigation; expires during snapshot',(tester) async {
    final api=FleetApi()..navigation=true;
    await tester.pumpWidget(app(api));await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'7');
    final read=find.text('Read connector snapshot');
    await tester.tap(read);await tester.pumpAndSettle();
    final retired=tester.widget<TextButton>(find.widgetWithText(TextButton,'Open source diagnostics')).onPressed!;
    api.snapshot='2026-11-15T12:04:00Z';
    await tester.tap(read);await tester.pumpAndSettle();
    expect(find.text('Stale report; current state unknown'),findsOneWidget);
    expect(find.textContaining('Recent report at snapshot'),findsNothing);
    retired();await tester.pumpAndSettle();expect(api.diagnosticsReads,0);
    api.sourceStation=999;
    await tester.tap(read);await tester.pumpAndSettle();
    expect(find.textContaining('Private fleet'),findsNothing);
    expect(find.textContaining('snapshot unavailable'),findsOneWidget);
    api.sourceStation=7;api.snapshot='2026-02-30T12:00:00Z';
    await tester.tap(read);await tester.pumpAndSettle();
    expect(find.textContaining('Private fleet'),findsNothing);
    api.snapshot='2026-11-15T12:00:00Z';
    api.alter=(r){final rows=r['connectors']['data'] as List;rows.add(rows.first);};
    await tester.tap(read);await tester.pumpAndSettle();
    expect(find.textContaining('Private fleet'),findsNothing);
  });
  testWidgets(
    'diagnostics requires explicit navigation to the scoped existing workspace',
    (tester) async {
      final api = FleetApi()..navigation = true;
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Read connector snapshot'));
      await tester.pumpAndSettle();
      expect(api.diagnosticsReads, 0);
      final open = find.text('Open source diagnostics');
      await tester.ensureVisible(open);
      await tester.tap(open);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      final view = tester.widget<ChargerDiagnosticsView>(
        find.byType(ChargerDiagnosticsView),
      );
      expect(view.companyId, 7);
      expect(view.chargerId, 9);
      expect(api.diagnosticsReads, 1);
      await tester.pumpWidget(const SizedBox());
    },
  );

  testWidgets(
    'explicit snapshot, frozen station pagination, stale label and background privacy',
    (tester) async {
      final api = FleetApi();
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      expect(api.reads, 0);
      await tester.enterText(find.byType(TextField), '7');
      await tester.tap(find.text('Read connector snapshot'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Recent report at snapshot: Charging'),
        findsOneWidget,
      );
      await tester.enterText(find.byType(TextField), '999');
      await tester.ensureVisible(find.text('Next'));
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(api.queries.last, {'page': '2', 'station_id': '7'});
      expect(find.text('Stale report; current state unknown'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.textContaining('Private fleet'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.textContaining('Private fleet'), findsNothing);
      expect(api.reads, 2);
    },
  );
  testWidgets(
    'unexpected authority and late replaced-account response remain private',
    (tester) async {
      final api = FleetApi()..unsafe = true;
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Read connector snapshot'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Private fleet'), findsNothing);
      expect(find.textContaining('snapshot unavailable'), findsOneWidget);
      api.unsafe = false;
      api.delayed = Completer<dynamic>();
      await tester.tap(find.text('Read connector snapshot'));
      await tester.pump();
      final replacement = FleetApi();
      await tester.pumpWidget(app(replacement));
      await tester.pumpAndSettle();
      api.delayed!.complete(api.result(1));
      await tester.pumpAndSettle();
      expect(find.textContaining('Private fleet'), findsNothing);
      expect(replacement.reads, 0);
      await tester.tap(find.text('Read connector snapshot'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Private fleet 1'), findsOneWidget);
    },
  );
}
