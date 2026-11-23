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
  'per_page': 1,
  'total': 2,
  'data': [
    {
      'external_id': 'CREDIT-1',
      'country_code': 'NL',
      'party_id': 'TST',
      'credit': true,
      'source': {'status': 'requires_review'},
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
  bool wrongPage = false;
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
    if (pending != null) return pending!.future;
    final page = int.parse(query['page'] ?? '1');
    final empty = query['currency'] == 'USD';
    return {
      ...result,
      'current_page': wrongPage ? page + 1 : page,
      'last_page': empty ? 1 : result['last_page'],
      'total': empty ? 0 : result['total'],
      'data': empty ? [] : result['data'],
    };
  }
}

void main() {
  testWidgets('already rendered partner invoice and private PDF disappear on logout event',
      (tester) async {
    final api = BillingApi();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
    ));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('SIM-PRIVATE'),
      200, scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('SIM-PRIVATE'), findsOneWidget);
    final before = api.queries.length;
    await api.forgetSession();
    await tester.pump();
    expect(find.text('SIM-PRIVATE'), findsNothing);
    expect(find.text('View filtered private PDF'), findsNothing);
    expect(api.queries.length, before);
  });

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
      await tester.pumpAndSettle();
      // An unapplied filter must not leave another invoice's private
      // amount or allow its prior PDF export.
      expect(find.text('View filtered private PDF'), findsNothing);
      expect(find.text('SIM-PRIVATE'), findsNothing);
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(api.queries.last['currency'], 'USD');
      await tester.scrollUntilVisible(
        find.text('View filtered private PDF'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('View filtered private PDF'));
      await tester.pumpAndSettle();
      expect(api.exportQueries.single, {
        'kind': 'all', 'currency': 'USD', 'locale': 'en',
      });
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
  testWidgets('forged invoice amounts or page identity cannot expose private CDR money',
    (tester) async {
      final api = BillingApi();
      final row = (api.result['data'] as List).first as Map<String, dynamic>;
      (row['billing'] as Map<String, dynamic>)['paid_minor'] = 9007199254740992;
      await tester.pumpWidget(MaterialApp(
        home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('SIM-PRIVATE'), findsNothing);
      expect(find.text('View filtered private PDF'), findsNothing);
      expect(find.textContaining('Report unavailable'), findsOneWidget);

      api.result = response();
      api.wrongPage = true;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('SIM-PRIVATE'), findsNothing);
      expect(find.text('View filtered private PDF'), findsNothing);
    },
  );

  testWidgets('mismatched total or page geometry cannot reclassify CDR revenue',
    (tester) async {
      final api = BillingApi()..result = {
        ...response(),
        'total': 300,
      };
      await tester.pumpWidget(MaterialApp(
        home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('SIM-PRIVATE'), findsNothing);
      expect(find.text('View filtered private PDF'), findsNothing);
      expect(find.textContaining('Report unavailable'), findsOneWidget);
    },
  );

  testWidgets('raw private provider payload is refused rather than stored in finance UI',
    (tester) async {
      final api = BillingApi();
      final row = (api.result['data'] as List).first as Map<String, dynamic>;
      row['payload'] = {'token': 'DO NOT DISPLAY'};
      await tester.pumpWidget(MaterialApp(
        home: PartnerBillingPage(api: api, providerId: 7, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('SIM-PRIVATE'), findsNothing);
      expect(find.text('View filtered private PDF'), findsNothing);
      expect(find.textContaining('DO NOT DISPLAY'), findsNothing);
      expect(find.textContaining('Report unavailable'), findsOneWidget);
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
