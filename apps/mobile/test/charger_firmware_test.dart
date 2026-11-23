import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_firmware.dart';
import 'test_support.dart';

class FirmwareApi extends FixtureApi {
  bool manager = true,
      fail = true,
      revoked = false,
      policy = false,
      blocked = false;
  final cancellations = <Map<String, dynamic>>[];
  final attempts = <Map<String, dynamic>>[];
  bool active = true;
  bool failCompatibility = false;
  bool invalidCompatibility = false;
  int compatibilityReads = 0;
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
    if (path.endsWith('/firmware-operations/7/cancel')) {
      expect(method, 'POST');
      cancellations.add(Map<String, dynamic>.from(body!));
      blocked = false;
      return {'cancelled': true, 'source_command_sent': false};
    }
    if (path.endsWith('/firmware-compatibility')) {
      expect(method, 'GET');
      compatibilityReads++;
      if (failCompatibility) {
        throw const ApiException(503, 'PRIVATE SOURCE SECRET');
      }
      return {
        'charger_id': 2,
        'manufacturer': 'Synthetic',
        'model': 'Fixture',
        'protocol': '1.6',
        'observed_source_firmware': 'sim-fw-v1',
        'source_last_message_at': '2026-11-23T02:00:00Z',
        'source_freshness_seconds': 300,
        'source_snapshot_verified': !invalidCompatibility,
        'physical_hardware_accepted': false,
        'firmware_dispatch_authorized': false,
        'releases': [
          {
            'release_id': 1,
            'version': 'sim-v1',
            'signed_compatibility_policy': true,
            'revoked': false,
            'matches_observed_source': true,
          },
        ],
      };
    }
    if (path.endsWith('/firmware-releases')) {
      expect(query['manufacturer'], 'Synthetic');
      expect(query['model'], 'Fixture');
      expect(query['active'], 'true');
      return {
        'data': revoked
            ? <Map<String, dynamic>>[]
            : [
                {
                  'id': 1,
                  'version': 'sim-v1',
                  'sha256': 'synthetic',
                  if (policy)
                    'compatibility_policy': {
                      'protocols': ['1.6'],
                      'from_versions': ['sim-fw-v1'],
                      'manifest_sha256': 'synthetic-hash',
                    },
                },
              ],
        'current_page': 1,
        'last_page': 1,
        'total': revoked ? 0 : 1,
      };
    }
    expect(path, 'vendor-ops/companies/1/chargers/2/firmware-operations');
    if (method == 'POST') {
      attempts.add(Map<String, dynamic>.from(body!));
      if (fail) throw const ApiException(503, 'PRIVATE SOURCE URL');
      return {'id': 7, 'status': 'outcome_unknown'};
    }
    return {
      'data': blocked
          ? [
              {
                'id': 7,
                'release_id': 1,
                'version': 'sim-v1',
                'sha256': 'synthetic',
                'status': 'not_dispatched',
                'can_cancel': true,
                'attempted_at': '2026-11-14',
                'reviewed_at': null,
                'created_at': '2026-11-14',
              },
            ]
          : <Map<String, dynamic>>[],
      'current_page': 1,
      'last_page': 1,
      'total': 0,
      'can_manage': manager,
      'adapter_enabled': true,
    };
  }
}

void main() {
  testWidgets(
    'unknown firmware response keeps exact release and identity even after catalog revocation',
    (tester) async {
      final api = FirmwareApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerFirmwareView(
            api: api,
            companyId: 1,
            chargerId: 2,
            manufacturer: 'Synthetic',
            model: 'Fixture',
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Request signed firmware'));
      await tester.tap(find.text('Request signed firmware'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextFormField),
        'Approved signed simulator image only',
      );
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.attempts, hasLength(1));
      expect(find.textContaining('PRIVATE SOURCE URL'), findsNothing);
      await tester.pump(const Duration(seconds: 10));
      expect(api.attempts, hasLength(1));
      api.revoked = true;
      await tester.ensureVisible(find.text('Refresh'));
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      api.fail = false;
      await tester.ensureVisible(find.text('Retry saved request'));
      await tester.tap(find.text('Retry saved request'));
      await tester.pumpAndSettle();
      expect(api.attempts, hasLength(2));
      expect(api.attempts[0], api.attempts[1]);
      expect(api.attempts[0]['release_id'], 1);
    },
  );
  testWidgets('explicit source firmware matrix read is private and never sends commands', (tester) async {
    final api = FirmwareApi()..policy = true;
    await tester.pumpWidget(MaterialApp(
      home: ChargerFirmwareView(
        api: api,
        companyId: 1,
        chargerId: 2,
        manufacturer: 'Synthetic',
        model: 'Fixture',
        onExpired: () {},
      ),
    ));
    await tester.pumpAndSettle();
    expect(api.compatibilityReads, 0, reason: 'Source reads require explicit action.');
    final button = find.text('Check live source firmware compatibility');
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(api.compatibilityReads, 1);
    expect(find.text('Authenticated source snapshot'), findsOneWidget);
    expect(find.textContaining('sim-fw-v1'), findsWidgets);
    expect(find.textContaining('Signed source match'), findsOneWidget);
    expect(find.textContaining('physical installation'), findsWidgets);
    expect(api.attempts, isEmpty);

    api.failCompatibility = true;
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(find.text('Authenticated source snapshot'), findsNothing);
    expect(find.textContaining('could not be verified'), findsOneWidget);
    expect(find.textContaining('PRIVATE SOURCE SECRET'), findsNothing);
    expect(api.attempts, isEmpty);

    api.failCompatibility = false;
    api.invalidCompatibility = true;
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(find.text('Authenticated source snapshot'), findsNothing);
    expect(find.textContaining('could not be verified'), findsOneWidget);
    expect(api.attempts, isEmpty);
  });

  testWidgets('read-only inventory has no firmware deployment action', (
    tester,
  ) async {
    final api = FirmwareApi()..manager = false;
    await tester.pumpWidget(
      MaterialApp(
        home: ChargerFirmwareView(
          api: api,
          companyId: 1,
          chargerId: 2,
          manufacturer: 'Synthetic',
          model: 'Fixture',
          onExpired: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Request signed firmware'), findsNothing);
    expect(api.attempts, isEmpty);
  });
}
