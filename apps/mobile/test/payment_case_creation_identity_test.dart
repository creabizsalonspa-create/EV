import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/payment_cases.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'test_support.dart';

class SessionCaseStore implements FinancialOperationStore {
  final entries = <String, Map<String, dynamic>>{};
  int reads = 0, writes = 0;

  @override
  Future<Map<String, dynamic>?> read(String scope) async {
    reads++;
    return entries[scope];
  }

  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    writes++;
    entries[scope] = value;
  }

  @override
  Future<void> remove(String scope) async {
    entries.remove(scope);
  }
}

class FencedCaseApi extends FixtureApi {
  int identity = 0, posts = 0, reads = 0;
  final events = StreamController<int>.broadcast(sync: true);
  @override
  Stream<int> get sessionEvents => events.stream;
  void revoke() { identity++; events.add(identity); }
  Completer<dynamic>? delayedInvoice, delayedPost;

  @override
  bool Function() sessionFence() {
    final epoch = identity;
    return () => epoch == identity;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') {
      reads++;
      return {'id': 1, 'role': 'customer'};
    }
    if (path == 'finance/invoices/3') {
      final waiting = delayedInvoice;
      delayedInvoice = null;
      if (waiting != null) return waiting.future;
      return {
        'invoice': {
          'id': 3,
          'user_id': 1,
          'minor_units': 2,
          'currency': 'AED',
        },
        'real_payment_processed': false,
        'net_collected_minor': 300,
      };
    }
    if (method == 'POST' && path == 'commercial/cases') {
      posts++;
      final waiting = delayedPost;
      delayedPost = null;
      if (waiting != null) return waiting.future;
      return {
        ...body!,
        'id': 7,
        'user_id': 1,
        'status': 'open',
        'real_money_moved': false,
        'evidence': 'MOCK_PROVIDER',
      };
    }
    throw StateError('Unexpected financial fixture request: $method $path');
  }

}

Widget caseScreen(FencedCaseApi api, SessionCaseStore store) =>
    MaterialApp(home: CreatePaymentCaseView(
      api: api,
      onExpired: () {},
      invoiceId: 3,
      store: store,
    ));

Future<void> tapVisible(WidgetTester tester, String label) async {
  await tester.scrollUntilVisible(
    find.text(label),
    180,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.ensureVisible(find.text(label));
  await tester.pump();
  await tester.tap(find.text(label));
  await tester.pump();
}

void main() {
  testWidgets('stale invoice cannot initialize a new identity payment case', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<dynamic>();
    api.delayedInvoice = waiting;
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pump();
    api.revoke();
    waiting.complete({
      'invoice': {
        'id': 3, 'user_id': 1, 'minor_units': 2, 'currency': 'AED',
      },
      'real_payment_processed': false,
      'net_collected_minor': 300,
    });
    await tester.pumpAndSettle();
    expect(find.byType(CheckboxListTile), findsNothing);
    expect(store.reads, 0);
    expect(api.posts, 0);
  });

  testWidgets('late case receipt cannot confirm old identity request', (
    tester,
  ) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    final waiting = Completer<dynamic>();
    api.delayedPost = waiting;
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '1.25');
    await tester.enterText(find.byType(TextField).last, 'Disputed captured amount');
    await tester.ensureVisible(find.byType(CheckboxListTile));
    await tester.pump();
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Confirm saved request'));
    await tapVisible(tester, 'Confirm saved request');
    expect(api.posts, 1);
    expect(store.writes, 1);
    api.revoke();
    waiting.complete({
      'id': 7, 'invoice_id': 3, 'user_id': 1, 'kind': 'dispute',
      'amount_minor': 125, 'reason': 'Disputed captured amount',
      'status': 'open', 'real_money_moved': false, 'evidence': 'MOCK_PROVIDER',
    });
    await tester.pump();
    expect(find.text('Server confirmed. Refresh authoritative history. No provider submission or real refund is claimed.'), findsNothing);
    expect(store.entries.length, 1);
  });

  testWidgets('idle dispute form immediately redacts on session change', (tester) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, 'Former account private reason');
    api.revoke();
    await tester.pumpAndSettle();
    expect(find.byType(TextField), findsNothing);
    expect(find.text('Former account private reason'), findsNothing);
    expect(api.posts, 0);
  });

  testWidgets('inactive dispute mount never reads private invoice until resume', (tester) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    addTearDown(() => tester.binding
        .handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pumpAndSettle();
    expect(api.reads, 0);
    expect(store.reads, 0);
    expect(find.byType(TextField), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.reads, 1);
    expect(find.byType(TextField), findsWidgets);
  });

  testWidgets('background clears dispute draft and retires old submit callback', (tester) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    addTearDown(() => tester.binding
        .handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '1.25');
    await tester.enterText(find.byType(TextField).last, 'Private old draft');
    await tester.ensureVisible(find.byType(CheckboxListTile));
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    final submit = tester.widget<FilledButton>(find.byType(FilledButton)).onPressed!;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.text('Private old draft'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    submit();
    await tester.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.writes, 0);
    expect(find.text('Private old draft'), findsNothing);
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value, false);
  });

  testWidgets('editing dispute amount or reason requires renewed consent', (tester) async {
    final api = FencedCaseApi(), store = SessionCaseStore();
    await tester.pumpWidget(caseScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '1.25');
    await tester.enterText(find.byType(TextField).last, 'Initial reason');
    await tester.ensureVisible(find.byType(CheckboxListTile));
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    final stale = tester.widget<FilledButton>(find.byType(FilledButton)).onPressed!;
    await tester.enterText(find.byType(TextField).last, 'Changed reason');
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value, false);
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed, isNull);
    stale();
    await tester.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.writes, 0);
  });
}
