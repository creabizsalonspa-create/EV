import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/provider_disputes.dart';
import 'test_support.dart';

Map<String, dynamic> receipt() => {
  'id': 7,
  'invoice_id': 19,
  'evidence': 'STRIPE_TEST_CONTRACT',
  'real_money_moved': false,
  'settlement_hold': true,
  'deadline_passed': true,
  'requires_financial_resolution': true,
  'status': 'lost',
  'evidence_due_at': '2026-11-12 10:00:00',
};

class DisputeApi extends FixtureApi {
  bool live = true, admin = false;
  final methods = <String>[];
  Completer<dynamic>? pending;
  final queries = <Map<String, String>>[];
  @override
  bool Function() sessionFence() =>
      () => live;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add(path);
    methods.add(method);
    queries.add(query);
    if (pending != null) return pending!.future;
    if (path.contains('/7'))
      return {
        'dispute': receipt(),
        'can_reconcile': admin,
        'events': [
          {
            'type': 'charge.dispute.created',
            'status': 'needs_response',
            'created_at': '2026-11-12 10:00:00',
          },
        ],
        'evidence_submissions': admin
            ? [
                {
                  'status': 'uncertain',
                  'submit': 0,
                  'retry_until': '2026-11-15 10:00:00',
                },
              ]
            : [],
      };
    return {
      'data': [receipt()],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

void main() {
  test('rejects live, secret-bearing and wrong-identity provider receipts', () {
    expect(
      () => providerDispute({...receipt(), 'real_money_moved': true}),
      throwsFormatException,
    );
    expect(
      () => providerDispute({...receipt(), 'provider_reference': 'private'}),
      throwsFormatException,
    );
    expect(
      () => providerDispute(receipt(), expectedId: 8),
      throwsFormatException,
    );
  });
  testWidgets(
    'shows scoped deadlines and holds, opens receipt without mutation',
    (tester) async {
      final api = DisputeApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ProviderDisputesView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Invoice #19'), findsOneWidget);
      expect(find.text('Evidence deadline passed'), findsOneWidget);
      expect(find.text('Settlement hold recorded'), findsOneWidget);
      await tester.tap(find.text('Inspect receipt'));
      await tester.pumpAndSettle();
      expect(api.calls.last, 'commercial/external-disputes/7');
      expect(find.text('Invoice #19'), findsOneWidget);
      expect(find.text('Inspect receipt'), findsNothing);
    },
  );
  testWidgets('late private response cannot repopulate a revoked identity', (
    tester,
  ) async {
    final api = DisputeApi()..pending = Completer<dynamic>();
    await tester.pumpWidget(
      MaterialApp(
        home: ProviderDisputesView(api: api, onExpired: () {}),
      ),
    );
    api.live = false;
    api.pending!.complete({
      'data': [receipt()],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    });
    await tester.pumpAndSettle();
    expect(find.text('Invoice #19'), findsNothing);
  });
  testWidgets(
    'only explicit Admin confirmation invokes status reconciliation',
    (tester) async {
      final api = DisputeApi()..admin = true;
      await tester.pumpWidget(
        MaterialApp(
          home: ProviderDisputesView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Inspect receipt'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Reconcile provider status'));
      await tester.tap(find.text('Reconcile provider status'));
      await tester.pumpAndSettle();
      expect(api.methods.every((method) => method == 'GET'), isTrue);
      await tester.tap(find.text('Reconcile'));
      await tester.pumpAndSettle();
      expect(api.calls.last, 'commercial/external-disputes/7/reconcile');
      expect(api.methods.last, 'POST');
    },
  );
  testWidgets(
    'background clears private receipts and requires manual refresh',
    (tester) async {
      final api = DisputeApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ProviderDisputesView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Invoice #19'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.text('Invoice #19'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      expect(find.text('Invoice #19'), findsNothing);
      await tester.tap(find.text('Refresh list'));
      await tester.pumpAndSettle();
      expect(find.text('Invoice #19'), findsOneWidget);
    },
  );
}
