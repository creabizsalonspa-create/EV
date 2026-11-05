import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/ocpi_credentials.dart';
import 'test_support.dart';

class CredentialsApi extends FixtureApi {
  int writes = 0;
  bool lost = false, expired = false, incoming = false;
  Map<String, dynamic>? intent;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'ocpi/providers/7/credentials');
    if (method == 'POST') {
      intent = body;
      writes++;
      lost = true;
      throw const ApiException(503, 'PRIVATE TOKEN');
    }
    if (expired) throw const ApiException(401, 'PRIVATE TOKEN');
    return {
      'configured': true,
      'state': lost
          ? 'outcome_unknown'
          : (incoming ? 'not_exchanged' : 'active'),
      'generation': 1,
      'available_actions': lost
          ? []
          : (incoming ? ['prepare_incoming'] : ['rotate', 'unregister']),
      'charging_commands_enabled': false,
      'billing_eligible': false,
      'operations': {
        'current_page': 1,
        'last_page': 1,
        'total': lost ? 1 : 0,
        'data': lost
            ? [
                {
                  'id': 8,
                  'action': 'rotate',
                  'state': 'outcome_unknown',
                  'attempted_at': '2026-11-05T01:00:00Z',
                },
              ]
            : [],
      },
    };
  }
}

void main() {
  testWidgets(
    'incoming enrollment action uses the approved backend workflow without exposing a bootstrap token',
    (tester) async {
      final api = CredentialsApi()..incoming = true;
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Prepare incoming enrollment'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Approved incoming synthetic partner',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.intent!['action'], 'prepare_incoming');
      expect(api.writes, 1);
      expect(find.textContaining('PRIVATE TOKEN'), findsNothing);
      expect(find.text('Prepare incoming enrollment'), findsNothing);
    },
  );

  testWidgets(
    'credentials uncertainty cannot replay and current source history gates the next action',
    (tester) async {
      final api = CredentialsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Rotate partner credentials'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Approved synthetic rotation',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.intent!['action'], 'rotate');
      expect(
        api.intent!['idempotency_key'],
        matches(RegExp(r'^[a-f0-9-]{36}$')),
      );
      expect(find.textContaining('Outcome uncertain.'), findsOneWidget);
      expect(find.textContaining('PRIVATE TOKEN'), findsNothing);
      expect(find.text('Rotate partner credentials'), findsNothing);
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Exchange outcome uncertain'), findsOneWidget);
      expect(find.text('Rotate partner credentials'), findsNothing);
      expect(find.text('Unregister partner credentials'), findsNothing);
      expect(api.writes, 1);
    },
  );
  testWidgets('expired credentials access clears private operation history', (
    tester,
  ) async {
    final api = CredentialsApi()..lost = true;
    var expired = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: OcpiCredentialsView(
          api: api,
          providerId: 7,
          onExpired: () => expired++,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('#8'), findsOneWidget);
    api.expired = true;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.textContaining('#8'), findsNothing);
    expect(expired, 1);
    expect(find.textContaining('PRIVATE TOKEN'), findsNothing);
  });
}
