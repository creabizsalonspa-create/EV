import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_diagnostics.dart';
import 'test_support.dart';

class DiagnosticsApi extends FixtureApi {
  bool fail = false;
  int requestCount = 0;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    requestCount++;
    expect(method, 'GET');
    expect(path, 'vendor-ops/companies/1/chargers/2/source-diagnostics');
    if (fail) throw ApiException(503, 'PRIVATE SOURCE DETAILS');
    return {
      'protocol': '2.0.1',
      'source_registered': true,
      'source_online': true,
      'recent_message_observed': false,
      'last_message_at': '2026-11-05T01:00:00Z',
      'firmware_version': '<untrusted-firmware>',
      'next_before': null,
      'events': [
        {
          'connectorStatus': 'Faulted',
          'errorCode': 'GroundFailure',
          'connectorId': 1,
          'evseId': 1,
          'timestamp': '2026-11-05T01:00:00Z',
        },
      ],
    };
  }
}

class LiveDiagnosticsApi extends DiagnosticsApi {
  bool malformed = false, forgedTopology = false, failing = false, expired = false, active = true;
  int sourceReads = 0, writes = 0;
  Completer<dynamic>? deferred;
  @override
  bool Function() sessionFence() => () => active;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.endsWith('/live-connectors')) {
      expect(method, 'GET');
      sourceReads++;
      if (deferred != null) return deferred!.future;
      if (expired) throw const ApiException(401, 'PRIVATE AUTH TOKEN');
      if (failing) throw const ApiException(503, 'PRIVATE UPSTREAM SECRET');
      return {
        'company_id': 1, 'charger_id': 2, 'protocol': '2.0.1',
        'source_online': true,
        'source_topology_matches_local': !forgedTopology,
        'source_identity_rechecked': true,
        'source_firmware_version': 'sim-fw-v1',
        'source_last_message_at': '2026-11-23T06:00:00Z',
        'observed_at': '2026-11-23T06:00:00Z',
        'evidence': 'SIMULATED_CSMS_SOURCE',
        'physical_availability_verified': false,
        'remote_control_authorized': false,
        'commands_sent': false,
        'connectors': [{
          'mapping_id': 9,
          'source_evse_number': 1,
          'source_connector_number': 2,
          'operator_maintenance_blocked': false,
          'state': 'recent',
          'reported_status': 'Charging',
          'error_code': null,
          'source_event_at': '2026-11-23T05:59:00Z',
          'physical_available': malformed,
          'remote_control_authorized': false,
        }],
      };
    }
    if (method != 'GET') writes++;
    return super.request(path, method: method, body: body, query: query);
  }
}

class RegisteredDiagnosticsApi extends FixtureApi {
  bool active = true;
  int requests = 0;
  String protocol = '2.0.1';
  String scenario = 'healthy';
  Completer<dynamic>? deferred;

  @override
  bool Function() sessionFence() => () => active;

  Map<String, dynamic> reply() {
    final incomplete = scenario == 'incomplete';
    final row = <String, dynamic>{
      'mapping_id': scenario == 'forged_mapping' ? 9 : null,
      'source_evse_number': protocol == '1.6' ? 0 : 1,
      'source_connector_number': 2,
      'operator_maintenance_blocked': null,
      'state': incomplete ? 'unknown' : 'recent',
      'reported_status': incomplete ? null : 'Available',
      'error_code': incomplete ? null : 'NoError',
      'source_event_at': '2026-11-23T05:59:00Z',
      'physical_available': false,
      'remote_control_authorized': false,
    };
    return {
      'company_id': 1,
      'charger_id': 2,
      'protocol': protocol,
      'source_online': true,
      'source_topology_matches_local': false,
      'source_topology_complete': !incomplete,
      'local_mapping_verified': false,
      'source_identity_rechecked': true,
      'source_last_message_at': '2026-11-23T06:00:00Z',
      'observed_at': '2026-11-23T06:00:00Z',
      'evidence': scenario == 'wrong_evidence'
          ? 'SIMULATED_CSMS_SOURCE'
          : 'REGISTERED_DIRECT_SOURCE',
      'physical_availability_verified': false,
      'remote_control_authorized': false,
      'commands_sent': false,
      'connectors': [row, if (scenario == 'duplicate') Map.of(row)],
    };
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(path, 'vendor-ops/companies/1/chargers/2/live-connectors');
    requests++;
    if (deferred != null) return deferred!.future;
    return reply();
  }
}

class FaultDiagnosticsApi extends DiagnosticsApi {
  int acknowledgments = 0;
  bool acknowledged = false;
  bool reviewClosed = false;
  bool active = true;
  @override
  bool Function() sessionFence() =>
      () => active;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.endsWith('/source-diagnostics')) {
      final result = await super.request(
        path,
        method: method,
        body: body,
        query: query,
      );
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
      'current_page': 1,
      'last_page': 1,
      'total': 1,
      'can_manage_faults': true,
      'data': [
        {
          'id': 7,
          'evse_number': 1,
          'connector_number': 1,
          'status': 'Faulted',
          'error_code': 'GroundFailure',
          'occurred_at': '2026-11-05T01:00:00Z',
          'recovery_observed_at': reviewClosed ? '2026-11-05T02:30:00Z' : null,
          'repair_review_closed_at': reviewClosed
              ? '2026-11-05T03:00:00Z'
              : null,
          'acknowledged_at': acknowledged ? '2026-11-05T02:00:00Z' : null,
        },
      ],
    };
  }
}

class DeferredDiagnosticsApi extends DiagnosticsApi {
  final response = Completer<dynamic>();
  bool active = true;
  @override
  bool Function() sessionFence() =>
      () => active;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) => response.future;
}

void main() {
  for (final protocol in ['1.6', '2.0.1']) {
    testWidgets('registered $protocol inspection is explicit and read only',
        (tester) async {
      final api = RegisteredDiagnosticsApi()..protocol = protocol;
      await tester.pumpWidget(MaterialApp(
        home: ChargerDiagnosticsView(
          api: api,
          companyId: 1,
          chargerId: 2,
          registeredDirectProtocol: protocol,
          onExpired: () {},
        ),
      ));
      await tester.pumpAndSettle();
      expect(api.requests, 0);
      expect(find.text('Fault alerts'), findsNothing);
      expect(find.text('Repair work orders'), findsNothing);
      await tester.tap(find.byKey(const Key('read-live-connector-source')));
      await tester.pumpAndSettle();
      expect(api.requests, 1);
      expect(find.text('Registered direct source snapshot'), findsOneWidget);
      expect(find.text('Source topology reported; local mappings not verified'),
          findsOneWidget);
      expect(find.text('Recent CSMS report: Available'), findsOneWidget);
      expect(find.text(protocol == '1.6' ? '0/2' : '1/2'), findsOneWidget);
      for (final bad in ['forged_mapping', 'wrong_evidence', 'duplicate']) {
        api.scenario = bad;
        await tester.tap(find.byKey(const Key('read-live-connector-source')));
        await tester.pumpAndSettle();
        expect(find.text('Registered source unavailable; no control is authorized.'),
            findsOneWidget);
        expect(find.text('Recent CSMS report: Available'), findsNothing);
      }
      api.scenario = 'incomplete';
      await tester.tap(find.byKey(const Key('read-live-connector-source')));
      await tester.pumpAndSettle();
      expect(find.text('Source topology incomplete; live status unknown'), findsOneWidget);
      expect(find.text('Connector status unknown'), findsOneWidget);
    });
  }

  testWidgets('registered source rejects response after background or logout',
      (tester) async {
    final api = RegisteredDiagnosticsApi();
    await tester.pumpWidget(MaterialApp(
      home: ChargerDiagnosticsView(
        api: api,
        companyId: 1,
        chargerId: 2,
        registeredDirectProtocol: '2.0.1',
        onExpired: () {},
      ),
    ));
    api.deferred = Completer<dynamic>();
    await tester.tap(find.byKey(const Key('read-live-connector-source')));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    api.deferred!.complete(api.reply());
    await tester.pumpAndSettle();
    expect(find.text('Registered direct source snapshot'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    api.deferred = Completer<dynamic>();
    await tester.tap(find.byKey(const Key('read-live-connector-source')));
    await tester.pump();
    api.active = false;
    api.deferred!.complete(api.reply());
    await tester.pump();
    expect(find.text('Registered direct source snapshot'), findsNothing);
    expect(api.requests, 2);
  });

  testWidgets(
    'explicit native source inspection is read-only and leaves physical connector status unverified',
    (tester) async {
      final api = LiveDiagnosticsApi();
      await tester.pumpWidget(MaterialApp(home: ChargerDiagnosticsView(
        api: api, companyId: 1, chargerId: 2, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      expect(api.sourceReads, 0);
      await tester.tap(find.text('Read live connector source'));
      await tester.pumpAndSettle();
      expect(api.sourceReads, 1);
      expect(find.textContaining('Recent CSMS report: Charging'), findsOneWidget);
      expect(find.textContaining('no physical connector availability'), findsOneWidget);
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'malformed or unavailable source loses recent status and never exposes transport secrets',
    (tester) async {
      final api = LiveDiagnosticsApi()..malformed = true;
      await tester.pumpWidget(MaterialApp(home: ChargerDiagnosticsView(
        api: api, companyId: 1, chargerId: 2, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Read live connector source'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Recent CSMS report'), findsNothing);
      expect(find.textContaining('Live connector source unavailable'), findsOneWidget);
      api.malformed = false;
      await tester.tap(find.text('Read live connector source'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Recent CSMS report: Charging'), findsOneWidget);
      api.forgedTopology = true;
      await tester.tap(find.text('Read live connector source'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Recent CSMS report'), findsNothing);
      api.forgedTopology = false;
      api.failing = true;
      await tester.tap(find.text('Read live connector source'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Recent CSMS report'), findsNothing);
      expect(find.textContaining('PRIVATE UPSTREAM SECRET'), findsNothing);
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'backgrounded source request cannot repopulate a private connector snapshot',
    (tester) async {
      final api = LiveDiagnosticsApi()..deferred = Completer<dynamic>();
      await tester.pumpWidget(MaterialApp(home: ChargerDiagnosticsView(
        api: api, companyId: 1, chargerId: 2, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Read live connector source'));
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      api.deferred!.complete({'connectors': ['PRIVATE LATE SOURCE']});
      await tester.pumpAndSettle();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.textContaining('PRIVATE LATE SOURCE'), findsNothing);
      expect(find.textContaining('Recent CSMS report'), findsNothing);
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'expired source access clears private data and invokes the session-expiry callback',
    (tester) async {
      final api = LiveDiagnosticsApi();
      int expired = 0;
      await tester.pumpWidget(MaterialApp(home: ChargerDiagnosticsView(
        api: api, companyId: 1, chargerId: 2,
        onExpired: () { expired++; api.active = false; },
      )));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Read live connector source'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Recent CSMS report: Charging'), findsOneWidget);
      api.expired = true;
      await tester.tap(find.text('Read live connector source'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.textContaining('Recent CSMS report'), findsNothing);
      expect(find.textContaining('PRIVATE AUTH TOKEN'), findsNothing);
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'Arabic live source inspection is explicit and read-only',
    (tester) async {
      final api = LiveDiagnosticsApi();
      await tester.pumpWidget(MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: ChargerDiagnosticsView(
          api: api, companyId: 1, chargerId: 2, onExpired: () {},
        ),
      ));
      await tester.pumpAndSettle();
      expect(api.sourceReads, 0);
      await tester.tap(find.text('قراءة حالة موصلات المصدر المباشر'));
      await tester.pumpAndSettle();
      expect(find.textContaining('تقرير حديث من نظام الشحن: Charging'), findsOneWidget);
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'background hides private observations and drops late source reads',
    (tester) async {
      final api = DeferredDiagnosticsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerDiagnosticsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      api.response.complete({
        'firmware_version': 'PRIVATE-LATE-FIRMWARE',
        'events': [],
      });
      await tester.pumpAndSettle();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.textContaining('PRIVATE-LATE-FIRMWARE'), findsNothing);
      expect(find.text('Refresh'), findsOneWidget);
    },
  );
  testWidgets(
    'old account cannot submit a retained fault acknowledgment dialog',
    (tester) async {
      final api = FaultDiagnosticsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerDiagnosticsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Fault alerts'));
      await tester.pumpAndSettle();
      await Scrollable.ensureVisible(
        tester.element(find.text('Acknowledge fault')),
        alignment: 0.5,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Acknowledge fault'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Reviewing source fault safely',
      );
      await tester.pump();
      api.active = false;
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(api.acknowledgments, 0);
    },
  );

  testWidgets(
    'background clears operator notes and invalidates the retained confirmation',
    (tester) async {
      final api = FaultDiagnosticsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerDiagnosticsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Fault alerts'));
      await tester.pumpAndSettle();
      await Scrollable.ensureVisible(
        tester.element(find.text('Acknowledge fault')),
        alignment: 0.5,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Acknowledge fault'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'PRIVATE OPERATOR NOTE');
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.text('PRIVATE OPERATOR NOTE'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'New note cannot revive old confirmation',
      );
      await tester.pump();
      expect(
        tester
            .widget<FilledButton>(find.widgetWithText(FilledButton, 'Save'))
            .onPressed,
        isNull,
      );
      expect(api.acknowledgments, 0);
    },
  );
  testWidgets(
    'fault alerts display current repair closure without sending commands',
    (tester) async {
      final api = FaultDiagnosticsApi()..reviewClosed = true;
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerDiagnosticsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Fault alerts'));
      await tester.pumpAndSettle();
      expect(find.text('Recovery source observed'), findsOneWidget);
      expect(find.text('Repair review closed'), findsOneWidget);

      api.reviewClosed = false;
      await tester.tap(find.text('Fault alerts'));
      await tester.pumpAndSettle();
      expect(find.text('Repair review closed'), findsNothing);
      expect(api.acknowledgments, 0);
    },
  );

  testWidgets(
    'fault acknowledgment has no blind retry after a lost response; read recovers state',
    (tester) async {
      final api = FaultDiagnosticsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerDiagnosticsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Fault alerts'));
      await tester.pumpAndSettle();
      await Scrollable.ensureVisible(
        tester.element(find.text('Acknowledge fault')),
        alignment: 0.5,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Acknowledge fault'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Reviewing source fault safely',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      await tester.pump(const Duration(seconds: 30));
      expect(api.acknowledgments, 1);
      expect(find.textContaining('PRIVATE LOST ACKNOWLEDGMENT'), findsNothing);
      await Scrollable.ensureVisible(
        tester.element(find.text('Fault alerts')),
        alignment: 0.5,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Fault alerts'));
      await tester.pumpAndSettle();
      expect(find.text('Fault acknowledged'), findsOneWidget);
    },
  );
  testWidgets(
    'source faults and stale evidence stay observational; refresh failure clears old data',
    (tester) async {
      final api = DiagnosticsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerDiagnosticsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
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
    },
  );
}
