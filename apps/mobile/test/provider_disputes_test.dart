import 'package:creabiz_ev/support.dart' show providerDisputeNotificationTarget;
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
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
  String eventType = 'charge.dispute.created';
  String eventStatus = 'needs_response';
  String operationStatus = 'uncertain';
  int epoch = 0;
  final changes = StreamController<int>.broadcast(sync: true);
  @override
  Stream<int> get sessionEvents => changes.stream;
  void rotate() { epoch++; changes.add(epoch); }
  final methods = <String>[];
  Completer<dynamic>? pending;
  Map<String, dynamic>? listResponse;
  final queries = <Map<String, String>>[];
  @override
  bool Function() sessionFence() {
    final captured = epoch;
    return () => live && captured == epoch;
  }
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
    if (path.contains('/8'))
      return {
        'dispute': {...receipt(), 'id': 8, 'invoice_id': 20},
        'can_reconcile': false,
        'events': [],
        'evidence_submissions': [],
      };
    if (path.contains('/7'))
      return {
        'dispute': receipt(),
        'can_reconcile': admin,
        'events': [
          {
            'type': eventType,
            'status': eventStatus,
            'created_at': '2026-11-12 10:00:00',
          },
        ],
        'evidence_submissions': admin
            ? [
                {
                  'status': operationStatus,
                  'submit': 0,
                  'retry_until': '2026-11-15 10:00:00',
                },
              ]
            : [],
      };
    return listResponse ?? {
      'data': [receipt()],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

void main() {
  test('provider receipt accepts only canonical dispute states', () {
    for (final status in [
      'warning_needs_response', 'warning_under_review', 'warning_closed',
      'needs_response', 'under_review', 'won', 'lost',
    ]) {
      expect(providerDispute({...receipt(), 'status': status})['status'], status);
    }
    for (final status in [null, 1, '', 'refunded', 'private-provider-token']) {
      expect(() => providerDispute({...receipt(), 'status': status}),
          throwsFormatException);
    }
  });
  testWidgets('unknown provider history tokens fail closed and recover through list',
      (tester) async {
    final api = DisputeApi()..admin = true;
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(MaterialApp(
      home: ProviderDisputesView(api: api, onExpired: () {}),
    ));
    await tester.pumpAndSettle();
    for (var field = 0; field < 3; field++) {
      api.eventType = field == 0 ? 'private-invalid-token' : 'provider.inspect';
      api.eventStatus = field == 1 ? 'private-invalid-token' : 'needs_response';
      api.operationStatus = field == 2 ? 'private-invalid-token' : 'confirmed';
      await tester.tap(find.text('Inspect receipt'));
      await tester.pumpAndSettle();
      expect(find.text('Invoice #19'), findsNothing);
      expect(find.textContaining('private-invalid-token'), findsNothing);
      expect(find.text('Could not load provider receipts. Refresh to retry.'),
          findsOneWidget);
      await tester.tap(find.text('Refresh list'));
      await tester.pumpAndSettle();
      expect(find.text('Invoice #19'), findsOneWidget);
    }
    api.operationStatus = 'confirmed';
    await tester.tap(find.text('Inspect receipt'));
    await tester.pumpAndSettle();
    expect(find.textContaining('provider.inspect'), findsOneWidget);
    expect(api.methods.every((method) => method == 'GET'), isTrue);
    expect(tester.takeException(), isNull);
  });
  test('provider dispute page binds requested page and exact unique receipt IDs', () {
    final first = <String, dynamic>{
      'data': [for (var i = 1; i <= 25; i++) {...receipt(), 'id': i}],
      'current_page': 1, 'last_page': 2, 'total': 26, 'per_page': 25,
    };
    expect(providerDisputePage(first, 1).items.length, 25);
    final last = {...first, 'data': [{...receipt(), 'id': 26}], 'current_page': 2};
    expect(providerDisputePage(last, 2).items.single['id'], 26);
    expect(providerDisputePage({'data': [], 'current_page': 1, 'last_page': 1,
      'total': 0}, 1).items, isEmpty);
    for (final invalid in [
      {...first, 'current_page': 2},
      {...first, 'last_page': 3},
      {...first, 'total': -1},
      {...first, 'per_page': 20},
      {...first, 'data': [for (var i = 0; i < 25; i++) receipt()]},
      {...first, 'data': [receipt()]},
      {...last, 'data': [{...receipt(), 'id': 0}]},
    ]) {
      expect(() => providerDisputePage(invalid, 1), throwsFormatException);
    }
    expect(() => providerDisputePage({...last, 'data': [{...receipt(), 'id': 0}]}, 2),
      throwsFormatException);
    expect(() => providerDisputePage({...last, 'data': [{...receipt(), 'invoice_id': -1}]}, 2),
      throwsFormatException);
  });
  testWidgets('invalid receipt page has no private actions; explicit refresh recovers', (tester) async {
    final api = DisputeApi()..listResponse = {
      'data': [receipt(), receipt()], 'current_page': 1, 'last_page': 1, 'total': 2,
    };
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(MaterialApp(home: ProviderDisputesView(api: api, onExpired: () {})));
    await tester.pumpAndSettle();
    expect(find.text('Invoice #19'), findsNothing);
    expect(find.text('Inspect receipt'), findsNothing);
    expect(find.text('Could not load provider receipts. Refresh to retry.'), findsOneWidget);
    api.listResponse = null;
    await tester.tap(find.text('Refresh list'));
    await tester.pumpAndSettle();
    expect(find.text('Invoice #19'), findsOneWidget);
    expect(api.queries.last['page'], '1');
    expect(api.methods.every((method) => method == 'GET'), isTrue);
  });
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
    'native provider receipts remain read-only despite unexpected mutation hints',
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
      expect(find.text('Reconcile provider status'), findsNothing);
      expect(api.methods.every((method) => method == 'GET'), isTrue);
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
  test(
    'provider notice routing requires typed test evidence and owned IDs',
    () {
      final metadata = {
        'invoice_id': 19,
        'external_dispute_id': 7,
        'evidence': 'STRIPE_TEST_CONTRACT',
        'real_money_moved': false,
        'physical_verification': false,
        'review_only': true,
      };
      expect(
        providerDisputeNotificationTarget({
          'kind': 'payment.provider_dispute.test',
          'metadata': metadata,
        }),
        7,
      );
      expect(
        providerDisputeNotificationTarget({
          'kind': 'payment.provider_dispute.test',
          'metadata': {...metadata, 'real_money_moved': 'false'},
        }),
        isNull,
      );
      expect(
        providerDisputeNotificationTarget({
          'kind': 'support.reply',
          'metadata': metadata,
        }),
        isNull,
      );
    },
  );
  testWidgets('notice opens its exact canonical dispute receipt', (
    tester,
  ) async {
    final api = DisputeApi();
    await tester.pumpWidget(
      MaterialApp(
        home: ProviderDisputesView(
          api: api,
          onExpired: () {},
          initialDisputeId: 7,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(api.calls.single, 'commercial/external-disputes/7');
    expect(find.text('Invoice #19'), findsOneWidget);
  });
  testWidgets('reused notification view never displays a prior dispute ID', (
    tester,
  ) async {
    final api = DisputeApi();
    Widget screen(int id) => MaterialApp(
      home: ProviderDisputesView(
        api: api,
        onExpired: () {},
        initialDisputeId: id,
      ),
    );
    await tester.pumpWidget(screen(7));
    await tester.pumpAndSettle();
    expect(find.text('Invoice #19'), findsOneWidget);
    await tester.pumpWidget(screen(8));
    expect(find.text('Invoice #19'), findsNothing);
    await tester.pumpAndSettle();
    expect(api.calls.last, 'commercial/external-disputes/8');
    expect(find.text('Invoice #20'), findsOneWidget);
    expect(find.text('Invoice #19'), findsNothing);
    expect(api.methods.every((method) => method == 'GET'), isTrue);
  });


  for (final ar in [false, true]) {
    testWidgets('revoked dispute view redacts immediately and cannot reopen old receipt ($ar)', (tester) async {
      final api = DisputeApi();
      addTearDown(api.close);
      addTearDown(api.changes.close);
      Widget screen(int? id) => MaterialApp(
        locale: Locale(ar ? 'ar' : 'en'),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: ProviderDisputesView(api: api, onExpired: () {}, initialDisputeId: id),
      );
      await tester.pumpWidget(screen(null));
      await tester.pumpAndSettle();
      final invoice = ar ? 'الفاتورة #19' : 'Invoice #19';
      expect(find.text(invoice), findsOneWidget);
      final staleInspect = tester.widget<TextButton>(find.widgetWithText(TextButton, ar ? 'عرض الإيصال' : 'Inspect receipt')).onPressed!;
      final count = api.calls.length;
      api.rotate();
      await tester.pumpAndSettle();
      expect(find.text(invoice), findsNothing);
      staleInspect();
      await tester.pumpAndSettle();
      expect(api.calls.length, count);
      await tester.pumpWidget(screen(8));
      await tester.pumpAndSettle();
      expect(api.calls.length, count, reason: 'A changed notification cannot rebind a revoked same-API route.');
      expect(find.text(ar ? 'الفاتورة #20' : 'Invoice #20'), findsNothing);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('retained dispute callback cannot open old target after new notification', (tester) async {
    final api = DisputeApi();
    addTearDown(api.close);
    addTearDown(api.changes.close);
    Widget screen(int? id) => MaterialApp(home: ProviderDisputesView(
      api: api, onExpired: () {}, initialDisputeId: id,
    ));
    await tester.pumpWidget(screen(null));
    await tester.pumpAndSettle();
    final inspect = tester.widget<TextButton>(find.widgetWithText(TextButton, 'Inspect receipt')).onPressed!;
    await tester.pumpWidget(screen(8));
    await tester.pumpAndSettle();
    expect(find.text('Invoice #20'), findsOneWidget);
    final count = api.calls.length;
    inspect();
    await tester.pumpAndSettle();
    expect(api.calls.length, count);
    expect(find.text('Invoice #20'), findsOneWidget);
    expect(find.text('Invoice #19'), findsNothing);
  });
}
