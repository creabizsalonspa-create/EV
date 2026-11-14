import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_resets.dart';
import 'test_support.dart';

class ResetApi extends FixtureApi {
  bool manager = true, fail = true;
  final attempts = <Map<String, dynamic>>[];
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
    expect(path, 'vendor-ops/companies/1/chargers/2/reset-operations');
    if (method == 'POST') {
      attempts.add(Map<String, dynamic>.from(body!));
      if (fail) throw const ApiException(503, 'PRIVATE SOURCE TRANSPORT');
      return {
        'id': 7,
        'status': 'outcome_unknown',
        'physical_execution_verified': false,
      };
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
    'lost reset response never automatically resends; explicit retry preserves original request',
    (tester) async {
      final api = ResetApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerResetsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Request safe reset'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextFormField),
        'Reviewing idle simulator safely',
      );
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.attempts, hasLength(1));
      expect(find.textContaining('PRIVATE SOURCE'), findsNothing);
      await tester.pump(const Duration(seconds: 30));
      expect(api.attempts, hasLength(1));
      api.fail = false;
      await tester.tap(find.text('Retry saved request'));
      await tester.pumpAndSettle();
      expect(api.attempts, hasLength(2));
      expect(api.attempts[0], api.attempts[1]);
      expect(find.text('Request safe reset'), findsOneWidget);
    },
  );
  testWidgets(
    'read-only inventory access exposes history without reset action',
    (tester) async {
      final api = ResetApi()..manager = false;
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerResetsView(
            api: api,
            companyId: 1,
            chargerId: 2,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Request safe reset'), findsNothing);
      expect(api.attempts, isEmpty);
    },
  );
}
