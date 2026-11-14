import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/partner_billing.dart';
import 'package:creabiz_ev/api.dart';
import 'test_support.dart';

Map<String, dynamic> response({int provider = 7}) => {
  'schema_version': 1,
  'provider_id': provider,
  'read_only': true,
  'billing_authority': false,
  'payment_execution': false,
  'current_page': 1,
  'last_page': 2,
  'data': [
    {
      'external_id': 'CREDIT-1',
      'country_code': 'NL',
      'party_id': 'TST',
      'credit': true,
      'source': {'status': 'requires_review'},
      'payload': {'token': 'DO NOT DISPLAY'},
      'billing': {
        'status': 'test_invoice_linked',
        'state': 'credited',
        'invoice': {
          'id': 42,
          'number': 'SIM-PRIVATE',
          'currency': 'KWD',
          'minor_units': 3,
          'total_minor': 264,
        },
        'paid_minor': 0,
        'refunded_minor': 0,
        'unpaid_credited_minor': 264,
        'due_minor': 0,
        'refund_review_count': 1,
        'ledger_balanced': true,
      },
    },
  ],
};

class BillingApi extends FixtureApi {
  Map<String, dynamic> result = response();
  Completer<dynamic>? pending;
  final exportQueries = <Map<String, String>>[];
  Completer<PrivateDocument>? exportPending;
  @override
  Future<PrivateDocument> downloadPartnerBilling(
    int providerId,
    Map<String, String> query,
  ) async {
    expect(providerId, 7);
    exportQueries.add(Map.of(query));
    if (exportPending != null) return exportPending!.future;
    throw const ApiException(409, 'Narrow filters');
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(path, 'ocpi/providers/7/billing-reconciliation');
    queries.add(query);
    return pending != null ? pending!.future : result;
  }
}

void main() {
  testWidgets(
    'private PDF uses applied filters and reports bounded export failure',
    (tester) async {
      final api = BillingApi();
      await tester.pumpWidget(
        MaterialApp(
          home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Currency'), 'USD');
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.scrollUntilVisible(
        find.text('View filtered private PDF'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('View filtered private PDF'));
      await tester.pumpAndSettle();
      expect(api.exportQueries.single, {'kind': 'all', 'locale': 'en'});
      expect(find.textContaining('Export unavailable.'), findsOneWidget);
    },
  );

  testWidgets('backgrounded export cannot open a stale private report', (
    tester,
  ) async {
    final api = BillingApi()..exportPending = Completer<PrivateDocument>();
    await tester.pumpWidget(
      MaterialApp(
        home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
      ),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('View filtered private PDF'),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('View filtered private PDF'));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    api.exportPending!.completeError(const ApiException(409, 'Expired source'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Export unavailable.'), findsNothing);
    expect(find.text('View filtered private PDF'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
    'exact amounts, noncash and refund-review separation and scoped pagination',
    (tester) async {
      final api = BillingApi();
      await tester.pumpWidget(
        MaterialApp(
          home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Currency'), 'USD');
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('SIM-PRIVATE'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Noncash credit: KWD 0.264'), findsOneWidget);
      expect(find.text('Refunded in TEST ledger: KWD 0.000'), findsOneWidget);
      expect(find.text('Refund reviews (not refunds): 1'), findsOneWidget);
      expect(find.textContaining('DO NOT DISPLAY'), findsNothing);
      await tester.scrollUntilVisible(
        find.text('Next'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.ensureVisible(find.text('Next'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(api.queries.last['page'], '2');
      expect(api.queries.last.containsKey('currency'), false);
    },
  );
  testWidgets(
    'wrong provider rejected and background drops late source response',
    (tester) async {
      final api = BillingApi()..result = response(provider: 8);
      await tester.pumpWidget(
        MaterialApp(
          home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('SIM-PRIVATE'), findsNothing);
      expect(find.textContaining('Report unavailable'), findsOneWidget);
      api.pending = Completer<dynamic>();
      await tester.tap(find.text('Refresh'));
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      api.pending!.complete(response());
      await tester.pumpAndSettle();
      expect(find.text('SIM-PRIVATE'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
    },
  );
  testWidgets('changed account cannot receive outstanding report', (
    tester,
  ) async {
    final api = BillingApi()..pending = Completer<dynamic>();
    await tester.pumpWidget(
      MaterialApp(
        home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
      ),
    );
    await api.forgetSession();
    api.pending!.complete(response());
    await tester.pumpAndSettle();
    expect(find.text('SIM-PRIVATE'), findsNothing);
  });
}
