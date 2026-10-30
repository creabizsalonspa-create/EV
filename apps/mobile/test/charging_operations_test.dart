import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charging_operations.dart';
import 'test_support.dart';

class OpsApi extends FixtureApi {
  int writes = 0;
  bool open = false, proof = false, lost = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') {
      writes++;
      expect(path, 'charging-operations/7/case');
      if (lost) throw const ApiException(0, 'private diagnostic');
      expect(body!['note'], 'Investigating source absence');
      open = true;
      return {};
    }
    return {
      'data': [
        {
          'id': 7,
          'station_id': 1,
          'action': 'start',
          'status': 'outcome_unknown',
          'can_inspect': true,
          'source_observed': proof,
          'case': open
              ? {
                  'id': 1,
                  'state': 'awaiting_source',
                  'operator_note': 'Investigating source absence',
                }
              : null,
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

void main() {
  test('recovery translation resources match', () {
    expect(opsAr.keys.toSet(), opsEn.keys.toSet());
  });
  testWidgets(
    'uncertain command can enter investigation but cannot offer manual resolution without proof',
    (t) async {
      final api = OpsApi();
      await t.pumpWidget(
        MaterialApp(
          home: ChargingOperationsView(api: api, onExpired: () {}),
        ),
      );
      await t.pumpAndSettle();
      await t.tap(find.text('Open recovery case'));
      await t.pumpAndSettle();
      await t.enterText(
        find.byType(TextFormField),
        'Investigating source absence',
      );
      await t.tap(find.text('Save'));
      await t.pumpAndSettle();
      expect(api.writes, 1);
      await t.tap(find.text('Review case'));
      await t.pumpAndSettle();
      await t.tap(find.byType(DropdownButtonFormField<String>));
      await t.pumpAndSettle();
      expect(find.text('Resolved with source evidence'), findsNothing);
      expect(find.text('Awaiting source evidence'), findsWidgets);
    },
  );
  testWidgets('lost case response is not automatically replayed', (t) async {
    final api = OpsApi()..lost = true;
    await t.pumpWidget(
      MaterialApp(
        home: ChargingOperationsView(api: api, onExpired: () {}),
      ),
    );
    await t.pumpAndSettle();
    await t.tap(find.text('Open recovery case'));
    await t.pumpAndSettle();
    await t.enterText(
      find.byType(TextFormField),
      'Investigating source absence',
    );
    await t.tap(find.text('Save'));
    await t.pumpAndSettle();
    await t.pump(const Duration(seconds: 30));
    expect(api.writes, 1);
    expect(find.textContaining('private diagnostic'), findsNothing);
  });
}
