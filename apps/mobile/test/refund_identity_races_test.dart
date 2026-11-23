import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/refund_requests.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'test_support.dart';

class RaceOperationStore implements FinancialOperationStore {
  final records = <String, Map<String, dynamic>>{};
  int writes = 0;

  @override
  Future<Map<String, dynamic>?> read(String scope) async => records[scope];

  @override
  Future<void> write(String scope, Map<String, dynamic> record) async {
    writes++;
    records[scope] = record;
  }

  @override
  Future<void> remove(String scope) async {
    records.remove(scope);
  }
}

class RaceFinanceApi extends FixtureApi {
  RaceFinanceApi({this.currentRole = 'customer'});
  String currentRole;
  int generation = 0, posts = 0;
  Completer<dynamic>? meWaiter;

  @override
  bool Function() sessionFence() {
    final epoch = generation;
    return () => epoch == generation;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') {
      final waiting = meWaiter;
      meWaiter = null;
      if (waiting != null) return waiting.future;
      return {'id': 2, 'role': currentRole};
    }
    if (method == 'POST') {
      posts++;
      throw StateError('Unsafe dispatch: stale financial intent');
    }
    throw StateError('Unexpected financial request: $path');
  }
}

Widget refundScreen(RaceFinanceApi api, RaceOperationStore store) =>
    MaterialApp(
      home: RefundRequestView(
        api: api,
        onExpired: () {},
        invoiceId: 9,
        digits: 2,
        currency: 'AED',
        store: store,
      ),
    );

Widget reviewScreen(RaceFinanceApi api, RaceOperationStore store) =>
    MaterialApp(
      home: RefundDecisionView(
        api: api,
        onExpired: () {},
        requestId: 4,
        store: store,
      ),
    );

void main() {
  testWidgets('customer refund restore rejects old identity after delayed me', (
    tester,
  ) async {
    final api = RaceFinanceApi(), store = RaceOperationStore();
    final wait = Completer<dynamic>();
    api.meWaiter = wait;
    await tester.pumpWidget(refundScreen(api, store));
    await tester.pump();
    api.generation++;
    wait.complete({'id': 1, 'role': 'customer'});
    await tester.pump();
    expect(api.posts, 0);
    expect(store.writes, 0);
    expect(find.text('Request a refund'), findsOneWidget);
    expect(tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Submit request'),
    ).onPressed, isNull);
  });

  testWidgets('vendor review restore rejects old owner after delayed me', (
    tester,
  ) async {
    final api = RaceFinanceApi(currentRole: 'vendor');
    final store = RaceOperationStore(), wait = Completer<dynamic>();
    api.meWaiter = wait;
    await tester.pumpWidget(reviewScreen(api, store));
    await tester.pump();
    api.generation++;
    wait.complete({'id': 1, 'role': 'vendor'});
    await tester.pump();
    expect(api.posts, 0);
    expect(store.writes, 0);
    expect(tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Confirm'),
    ).onPressed, isNull);
  });

  testWidgets('customer consent cannot submit when account changed in dialog', (
    tester,
  ) async {
    final api = RaceFinanceApi(), store = RaceOperationStore();
    await tester.pumpWidget(refundScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '1.25');
    await tester.enterText(find.byType(TextField).at(1), 'Customer request');
    await tester.tap(find.widgetWithText(FilledButton, 'Submit request'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsOneWidget);
    api.generation++;
    await tester.tap(find.widgetWithText(FilledButton, 'Confirm'));
    await tester.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.writes, 0);
  });

  testWidgets('vendor consent cannot review refund after account change', (
    tester,
  ) async {
    final api = RaceFinanceApi(currentRole: 'vendor');
    final store = RaceOperationStore();
    await tester.pumpWidget(reviewScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Review denied');
    await tester.tap(find.widgetWithText(FilledButton, 'Confirm').first);
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsOneWidget);
    api.generation++;
    await tester.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await tester.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.writes, 0);
  });
}
