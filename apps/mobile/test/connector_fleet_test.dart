import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/connector_fleet.dart';
import 'package:creabiz_ev/charger_diagnostics.dart';
import 'test_support.dart';

class FleetApi extends FixtureApi {
  int reads = 0;
  bool unsafe = false, navigation = false, multi = false;
  final liveCalls = <String>[];
  int diagnosticsReads = 0;
  Completer<dynamic>? delayed;
  final queries = <Map<String, String>>[];
  Map<String, dynamic> result(int page) => {
    'scope': 'recorded_connector_fleet',
    'as_of': '2026-11-15T12:00:00Z',
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
          'station_id': 7,
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
            'observed_at': '2026-11-15T11:59:00Z',
          },
        },
        if (multi)
          for (final id in [2, 3])
            {
              'mapping_id': id,
              'station_id': 7,
              'station_name': 'Private fleet $id',
              'protocol': '2.0.1',
              'inventory_eligible_at_read': true,
              'operations_target': {'company_id': 7, 'charger_id': id == 2 ? 9 : 10},
              'status_freshness': {
                'state': 'unknown',
                'reported_status': null,
                'evidence': 'SIMULATED_SOURCE',
                'physical_availability_verified': false,
              },
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
    if (path.endsWith('/live-connectors')) {
      expect(method, 'GET');
      liveCalls.add(path);
      return {
        'company_id': 7,
        'charger_id': path.contains('/10/') ? 999 : 9,
        'protocol': '2.0.1',
        'evidence': 'SIMULATED_CSMS_SOURCE',
        'source_identity_rechecked': true,
        'source_online': true,
        'source_topology_matches_local': true,
        'observed_at': '2026-11-15T12:00:00Z',
        'physical_availability_verified': false,
        'remote_control_authorized': false,
        'commands_sent': false,
        'connectors': [
          for (final id in [1, 2])
            {
              'mapping_id': id,
              'state': 'recent',
              'reported_status': 'Occupied',
              'source_event_at': '2026-11-15T11:59:00Z',
              'physical_available': false,
              'remote_control_authorized': false,
            },
        ],
      };
    }
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

class DirectFleetApi extends FleetApi {
  int directReads = 0, activeReads = 0, peakReads = 0;
  bool active = true;
  final pending = <Completer<dynamic>>[];
  @override
  bool Function() sessionFence() => () => active;

  Map<String, dynamic> directPage(int page) => {
    'scope': 'direct_charger_fleet',
    'evidence': 'INVENTORY_ONLY',
    'as_of': '2026-11-28T12:00:00Z',
    'physical_availability_verified': false,
    'remote_control_authorized': false,
    'billing_authority': false,
    'chargers': {
      'current_page': page, 'last_page': 2,
      'data': [
        for (final id in page == 1 ? [1,2,3,4,5] : [6])
          {
            'company_id': 7, 'charger_id': id, 'station_id': 7,
            'station_name': 'Direct station', 'asset_reference': 'Asset $id',
            'protocol': id.isOdd ? '1.6' : '2.0.1', 'state': 'not_inspected',
          },
      ],
    },
  };

  Map<String, dynamic> directReply(int id) => {
    'company_id': 7, 'charger_id': id == 3 ? 999 : id,
    'protocol': id.isOdd ? '1.6' : '2.0.1',
    'evidence': 'REGISTERED_DIRECT_SOURCE',
    'source_identity_rechecked': true, 'source_online': true,
    'source_topology_matches_local': false, 'source_topology_complete': true,
    'local_mapping_verified': false,
    'observed_at': '2026-11-28T12:00:00Z',
    'physical_availability_verified': false,
    'remote_control_authorized': false, 'commands_sent': false,
    'connectors': [{
      'mapping_id': null, 'operator_maintenance_blocked': null,
      'source_evse_number': id.isOdd ? 0 : 1, 'source_connector_number': 2,
      'state': 'recent', 'reported_status': 'Available',
      'source_event_at': '2026-11-28T11:59:00Z',
      'physical_available': false, 'remote_control_authorized': false,
    }],
  };

  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(body, isNull);
    if (path == 'charging-operations/registered-chargers') {
      directReads++;
      queries.add(Map.of(query));
      return directPage(int.parse(query['page']!));
    }
    final id = int.parse(path.split('/')[4]);
    expect(path, 'vendor-ops/companies/7/chargers/$id/live-connectors');
    liveCalls.add(path);
    activeReads++;
    if (activeReads > peakReads) peakReads = activeReads;
    final completer = Completer<dynamic>();
    pending.add(completer);
    try {
      return await completer.future;
    } finally {
      activeReads--;
    }
  }
}

Widget app(FleetApi api) => MaterialApp(
  home: ConnectorFleetView(api: api, onExpired: () {}),
);
void main() {
  testWidgets('direct fleet requires explicit inventory and bounded independent source reads',
      (tester) async {
    final api = DirectFleetApi();
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    expect(api.directReads, 0);
    await tester.enterText(find.byType(TextField), '7');
    await tester.tap(find.text('Read direct charger inventory'));
    await tester.pumpAndSettle();
    expect(api.directReads, 1);
    expect(api.liveCalls, isEmpty);
    final read = find.text('Read registered sources for this page');
    await tester.ensureVisible(read);
    await tester.tap(read);
    await tester.pump();
    expect(api.liveCalls.length, 4);
    expect(api.peakReads, 4);
    for (var i = 0; i < 4; i++) {
      api.pending[i].complete(api.directReply(i + 1));
    }
    await tester.pump();
    expect(api.liveCalls.length, 5);
    api.pending[4].complete(api.directReply(5));
    await tester.pumpAndSettle();
    expect(find.text('0/2 · Available'), findsWidgets);
    await tester.scrollUntilVisible(
      find.text('Registered source unavailable or unverified; status unknown.'), 200,
    );
    expect(find.text('Registered source unavailable or unverified; status unknown.'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.textContaining('Direct station'), findsNothing);
    expect(api.liveCalls.length, 5);
  });

  testWidgets('explicit page live refresh deduplicates chargers and rejects foreign source',
      (tester) async {
    final api = FleetApi()..navigation = true..multi = true;
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Read connector snapshot'));
    await tester.pumpAndSettle();
    expect(api.liveCalls, isEmpty);
    final read = find.text('Read live sources for this page');
    await tester.ensureVisible(read);
    await tester.tap(read);
    await tester.pumpAndSettle();
    expect(api.liveCalls, [
      'vendor-ops/companies/7/chargers/9/live-connectors',
      'vendor-ops/companies/7/chargers/10/live-connectors',
    ]);
    expect(find.textContaining('Live source report at read'), findsWidgets);
    await tester.scrollUntilVisible(
      find.text('Live source unavailable or unqualified; state unknown.'),
      200,
    );
    expect(find.text('Live source unavailable or unqualified; state unknown.'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.textContaining('Live source report at read'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.liveCalls.length, 2);
    expect(find.textContaining('Private fleet'), findsNothing);
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
