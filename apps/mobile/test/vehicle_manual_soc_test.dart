import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_manual_soc.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Deterministic contract fixture. Real HTTP transport is covered by api_test.dart
/// and the Laravel manual-SOC feature tests; widget tests must not create sockets
/// under Flutter's fake-async test binding.
class _SocApi extends EvApi {
  _SocApi() : super(Uri.parse('https://example.invalid'));
  final writes = <Map<String, dynamic>>[];
  final requestPaths = <String>[];
  Completer<void>? holdVehicle17;
  bool failReadbackOnce = false;
  int revision = 0;
  int? percent;
  int otherRevision = 4;
  int otherPercent = 80;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    requestPaths.add('$method $path');
    if (path == 'vehicles/18/manual-soc' && method == 'GET') {
      return {
        'vehicle_id': 18, 'revision': otherRevision,
        'battery_percent': otherPercent,
        'source': 'CUSTOMER_MANUAL_SELF_REPORT',
        'measured_by_phone': false, 'measured_by_vehicle_api': false,
        'charging_capability_verified': false,
        'reported_at': '2026-11-07T14:00:00+00:00',
      };
    }
    if (path == 'vehicles/18/manual-soc/history' && method == 'GET') {
      return {
        'current_page': 1, 'last_page': 1, 'total': 1,
        'data': [{
          'vehicle_id': 18, 'battery_percent': otherPercent,
          'revision': otherRevision, 'reported_at': '2026-11-07T14:00:00+00:00',
          'source': 'CUSTOMER_MANUAL_SELF_REPORT',
        }],
      };
    }
    if (path == 'vehicles/18/manual-soc' && method == 'POST') {
      writes.add(Map<String, dynamic>.from(body!));
      if (body['expected_revision'] != otherRevision) {
        throw const ApiException(409, 'stale vehicle 18');
      }
      otherPercent = body['battery_percent'] as int;
      otherRevision++;
      return {'vehicle_id': 18, 'revision': otherRevision,
        'battery_percent': otherPercent, 'source': 'CUSTOMER_MANUAL_SELF_REPORT'};
    }
    if (path == 'vehicles/17/manual-soc' && method == 'GET') {
      if (holdVehicle17 != null) await holdVehicle17!.future;
      if (revision > 0 && failReadbackOnce) {
        failReadbackOnce = false;
        throw const ApiException(503, 'readback failed after accepted POST');
      }
      return {
        'vehicle_id': 17, 'revision': revision, 'battery_percent': percent,
        'source': 'CUSTOMER_MANUAL_SELF_REPORT',
        'measured_by_phone': false, 'measured_by_vehicle_api': false,
        'charging_capability_verified': false,
        'reported_at': percent == null ? null : '2026-11-07 14:00:00',
        'replayed': false,
      };
    }
    if (path == 'vehicles/17/manual-soc/history' && method == 'GET') {
      return {
        'current_page': 1, 'last_page': 1, 'total': revision,
        'data': revision == 0 ? [] : [
          {'vehicle_id': 17, 'battery_percent': percent, 'revision': revision,
            'reported_at': '2026-11-07 14:00:00',
            'source': 'CUSTOMER_MANUAL_SELF_REPORT'},
        ],
      };
    }
    if (path == 'vehicles/17/manual-soc' && method == 'POST') {
      final submitted = Map<String, dynamic>.from(body!);
      final replay = writes.any((prior) =>
        prior['operation_key'] == submitted['operation_key']);
      writes.add(submitted);
      if (replay) {
        return {'revision': revision, 'battery_percent': percent,
          'source': 'CUSTOMER_MANUAL_SELF_REPORT'};
      }
      if (submitted['expected_revision'] != revision) {
        throw const ApiException(409, 'stale report');
      }
      revision++;
      percent = submitted['battery_percent'] as int;
      return {'revision': revision, 'battery_percent': percent,
        'source': 'CUSTOMER_MANUAL_SELF_REPORT',
        'measured_by_phone': false, 'measured_by_vehicle_api': false};
    }
    throw ApiException(404, 'Unknown test endpoint $path');
  }
}

void main() {
  testWidgets('customer explicitly records a battery estimate with source labels',
      (tester) async {
    final api = _SocApi();
    try {
      await tester.pumpWidget(MaterialApp(home: VehicleManualSocView(
        api: api, vehicleId: 17, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      expect(find.textContaining('phone does not measure vehicle SOC'), findsOneWidget);
      expect(find.text('No report yet.'), findsOneWidget);
      await tester.enterText(find.byKey(const Key('manual-soc-percent')), '61');
      await tester.ensureVisible(find.byKey(const Key('manual-soc-save')));
      await tester.tap(find.byKey(const Key('manual-soc-save')));
      await tester.pumpAndSettle();
      expect(api.writes, hasLength(1));
      expect(api.writes.single['battery_percent'], 61);
      expect(api.writes.single['expected_revision'], 0);
      expect((api.writes.single['operation_key'] as String).length, 36);
      expect(find.text('Manual battery report recorded.'), findsOneWidget);
      expect(find.textContaining('Latest estimate: 61%'), findsOneWidget);
    } finally {
      api.close();
    }
  });
  testWidgets('post-commit readback failure keeps same-key retry in native SOC',
      (tester) async {
    final api = _SocApi()..failReadbackOnce = true;
    try {
      await tester.pumpWidget(MaterialApp(home: VehicleManualSocView(
        api: api, vehicleId: 17, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('manual-soc-percent')), '61');
      await tester.ensureVisible(find.byKey(const Key('manual-soc-save')));
      await tester.tap(find.byKey(const Key('manual-soc-save')));
      await tester.pumpAndSettle();
      expect(api.writes, hasLength(1));
      expect(find.text('Retry identical report'), findsOneWidget);
      expect(find.byKey(const Key('manual-soc-error')), findsOneWidget);
      await tester.tap(find.byKey(const Key('manual-soc-save')));
      await tester.pumpAndSettle();
      expect(api.writes, hasLength(2));
      expect(api.writes[1]['operation_key'], api.writes[0]['operation_key']);
      expect(api.writes[1]['expected_revision'], api.writes[0]['expected_revision']);
      expect(find.text('Manual battery report recorded.'), findsOneWidget);
      expect(find.text('Save manual report'), findsOneWidget);
    } finally {
      api.close();
    }
  });

  testWidgets('native manual SOC resets when a reused widget switches vehicle',
      (tester) async {
    final api = _SocApi()..holdVehicle17 = Completer<void>();
    try {
      await tester.pumpWidget(MaterialApp(home: VehicleManualSocView(
        api: api, vehicleId: 17, onExpired: () {},
      )));
      await tester.pump();
      expect(api.requestPaths, contains('GET vehicles/17/manual-soc'));
      await tester.pumpWidget(MaterialApp(home: VehicleManualSocView(
        api: api, vehicleId: 18, onExpired: () {},
      )));
      await tester.pumpAndSettle();
      expect(api.requestPaths, contains('GET vehicles/18/manual-soc'));
      expect(find.textContaining('Latest estimate: 80%'), findsOneWidget);
      api.holdVehicle17!.complete();
      await tester.pumpAndSettle();
      expect(find.textContaining('Latest estimate: 80%'), findsOneWidget);
      await tester.enterText(find.byKey(const Key('manual-soc-percent')), '55');
      await tester.ensureVisible(find.byKey(const Key('manual-soc-save')));
      await tester.tap(find.byKey(const Key('manual-soc-save')));
      await tester.pumpAndSettle();
      expect(api.requestPaths, contains('POST vehicles/18/manual-soc'));
      expect(api.writes.last['expected_revision'], 4);
      expect(api.writes.last['battery_percent'], 55);
    } finally {
      if (!(api.holdVehicle17?.isCompleted ?? true)) api.holdVehicle17!.complete();
      api.close();
    }
  });

}
