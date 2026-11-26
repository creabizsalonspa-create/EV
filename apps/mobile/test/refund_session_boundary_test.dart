import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/refund_requests.dart';
import 'native_financial_operations_test.dart' show FinancialApi, MemoryOperations, wrap;

class RevocableRefundApi extends FinancialApi {
  final events = StreamController<int>.broadcast(sync: true);
  int epoch = 0;
  @override
  Stream<int> get sessionEvents => events.stream;
  @override
  bool Function() sessionFence() {
    final openingEpoch = epoch;
    return () => epoch == openingEpoch;
  }

  void revoke() {
    epoch++;
    events.add(epoch);
  }

  @override
  void close() {
    events.close();
    super.close();
  }
}

class ForgedAcknowledgementApi extends RevocableRefundApi {
  bool forge = true;
  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    final result = await super.request(path, method: method, body: body, query: query);
    if (forge && method == 'POST' && path.endsWith('/refund-requests')) {
      return {...(result as Map), 'request_receipt': 'unbound-private-receipt'};
    }
    return result;
  }
}

void main() {
  testWidgets('wrong private binding retains the exact durable intent until explicit valid replay', (t) async {
    final api = ForgedAcknowledgementApi();
    addTearDown(api.close);
    final store = MemoryOperations();
    await t.pumpWidget(wrap(RefundRequestView(api: api, onExpired: () {},
      invoiceId: 3, digits: 2, currency: 'AED', store: store)));
    await t.pumpAndSettle();
    await t.enterText(find.byType(TextField).first, '1.25');
    await t.enterText(find.byType(TextField).at(1), 'Synthetic private receipt');
    await t.ensureVisible(find.text('Submit request'));
    await t.tap(find.text('Submit request'));
    await t.pumpAndSettle();
    await t.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await t.pumpAndSettle();
    expect(api.posts, 1);
    expect(store.values.length, 1);
    expect(find.textContaining('Request confirmed.'), findsNothing);
    api.forge = false;
    await t.ensureVisible(find.text('Resume the same request'));
    await t.tap(find.text('Resume the same request'));
    await t.pumpAndSettle();
    await t.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await t.pumpAndSettle();
    expect(api.posts, 2);
    expect(api.bodies[1], api.bodies[0]);
    expect(store.values, isEmpty);
    expect(find.textContaining('Request confirmed.'), findsOneWidget);
  });


  testWidgets('revoked customer confirmation cannot save or dispatch old refund', (t) async {
    final api = RevocableRefundApi();
    addTearDown(api.close);
    final store = MemoryOperations();
    await t.pumpWidget(wrap(RefundRequestView(api: api, onExpired: () {},
      invoiceId: 3, digits: 2, currency: 'AED', store: store)));
    await t.pumpAndSettle();
    await t.enterText(find.byType(TextField).first, '1.25');
    await t.enterText(find.byType(TextField).at(1), 'Private old customer reason');
    await t.ensureVisible(find.text('Submit request'));
    await t.tap(find.text('Submit request'));
    await t.pumpAndSettle();
    api.revoke();
    await t.pumpAndSettle();
    await t.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await t.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.values, isEmpty);
    expect(find.text('Private old customer reason'), findsNothing);
    expect(find.text('Submit request'), findsNothing);
  });

  testWidgets('revoked vendor cannot dispatch a stale review dialog', (t) async {
    final api = RevocableRefundApi()..actor = 'vendor';
    addTearDown(api.close);
    final store = MemoryOperations();
    await t.pumpWidget(wrap(RefundDecisionView(api: api, onExpired: () {},
      requestId: 8, store: store)));
    await t.pumpAndSettle();
    await t.enterText(find.byType(TextField), 'Private decision note');
    await t.tap(find.widgetWithText(FilledButton, 'Confirm'));
    await t.pumpAndSettle();
    api.revoke();
    await t.pumpAndSettle();
    await t.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await t.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.values, isEmpty);
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets('refund history hides private rows immediately and remains revoked', (t) async {
    final api = RevocableRefundApi();
    addTearDown(api.close);
    await t.pumpWidget(wrap(RefundRequestsView(api: api, onExpired: () {},
      role: 'customer')));
    await t.pumpAndSettle();
    expect(find.text('Literal <script> reason'), findsOneWidget);
    api.revoke();
    await t.pumpAndSettle();
    expect(find.text('Literal <script> reason'), findsNothing);
    expect(find.text('Refresh'), findsNothing);
    expect(api.posts, 0);
  });

  testWidgets('background history requires a fresh read after foreground return', (t) async {
    final api = RevocableRefundApi();
    addTearDown(api.close);
    await t.pumpWidget(wrap(RefundRequestsView(api: api, onExpired: () {},
      role: 'customer')));
    await t.pumpAndSettle();
    expect(find.text('Literal <script> reason'), findsOneWidget);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pump();
    expect(find.text('Literal <script> reason'), findsNothing);
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(find.text('Literal <script> reason'), findsOneWidget);
  });
}
