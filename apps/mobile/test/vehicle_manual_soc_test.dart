import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_manual_soc.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Deterministic contract fixture. Real HTTP transport is covered by api_test.dart
/// and the Laravel manual-SOC feature tests; widget tests must not create sockets
/// under Flutter's fake-async test binding.
class _SocApi extends EvApi {
  _SocApi() : super(Uri.parse('https://example.invalid'));
  final writes = <Map<String, dynamic>>[];
  int revision = 0;
  int? percent;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'vehicles/17/manual-soc' && method == 'GET') {
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
      writes.add(submitted);
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
}
