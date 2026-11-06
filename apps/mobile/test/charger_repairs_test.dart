import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_repairs.dart';
import 'test_support.dart';

class RepairApi extends FixtureApi {
  bool lose = false, unsafe = false, denied = false;
  int attempts = 0, writes = 0;
  Map<String, dynamic>? first;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (denied) throw const ApiException(401, 'PRIVATE identity detail');
    if (method == 'POST') {
      attempts++;
      first ??= Map<String, dynamic>.from(body!);
      expect(body, first);
      if (attempts == 1) writes++;
      if (lose) {
        lose = false;
        throw const ApiException(503, 'PRIVATE transport detail');
      }
      return {'state': 'open', 'version': 1};
    }
    return {
      'data': <dynamic>[],
      'current_page': 1,
      'last_page': 1,
      'total': 0,
      'can_manage': true,
      'commands_sent': false,
      'capacity_changed': unsafe,
      'physical_repair_verified': false,
    };
  }
}

Widget app(RepairApi api, VoidCallback expired) => MaterialApp(
  home: ChargerRepairsView(
    api: api,
    companyId: 2,
    chargerId: 3,
    onExpired: expired,
  ),
);
void main() {
  testWidgets(
    'lost repair receipt keeps the original version and identity for explicit resolution',
    (tester) async {
      final api = RepairApi()..lose = true;
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), '7');
      await tester.tap(find.text('Open repair work order'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField).last,
        'Private synthetic repair note',
      );
      await tester.pump();
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      expect(api.writes, 1);
      expect(find.textContaining('Repair update uncertain.'), findsOneWidget);
      expect(find.text('Open repair work order'), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      await tester.tap(find.text('Resolve original repair update'));
      await tester.pumpAndSettle();
      expect(api.attempts, 2);
      expect(api.writes, 1);
      expect(api.first!['expected_version'], 0);
      expect(find.text('Open repair work order'), findsOneWidget);
    },
  );
  testWidgets(
    'unexpected repair capability fails closed and expiry clears private state',
    (tester) async {
      final api = RepairApi()..unsafe = true;
      var expired = 0;
      await tester.pumpWidget(app(api, () => expired++));
      await tester.pumpAndSettle();
      expect(find.text('Open repair work order'), findsNothing);
      api.unsafe = false;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Open repair work order'), findsOneWidget);
      api.denied = true;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Open repair work order'), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
    },
  );
}
