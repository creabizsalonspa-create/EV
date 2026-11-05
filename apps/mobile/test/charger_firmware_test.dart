import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_firmware.dart';
import 'test_support.dart';

class FirmwareApi extends FixtureApi {
  bool manager = true, fail = true, revoked = false;
  final attempts = <Map<String, dynamic>>[];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.endsWith('/firmware-releases')) {
      expect(query['manufacturer'], 'Synthetic');
      expect(query['model'], 'Fixture');
      expect(query['active'], 'true');
      return {
        'data': revoked
            ? <Map<String, dynamic>>[]
            : [
                {'id': 1, 'version': 'sim-v1', 'sha256': 'synthetic'},
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
      'data': <Map<String, dynamic>>[],
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
