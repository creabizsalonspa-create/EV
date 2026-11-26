import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/loyalty_actions.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'test_support.dart';

class PrivateLoyaltyStore implements FinancialOperationStore {
  final saved = <String, Map<String, dynamic>>{};
  int reads = 0, writes = 0;
  Completer<void>? entered, release;

  @override
  Future<Map<String, dynamic>?> read(String scope) async {
    reads++;
    return saved[scope];
  }

  @override
  Future<void> write(String scope, Map<String, dynamic> record) async {
    writes++;
    if (entered != null && release != null) {
      entered!.complete();
      await release!.future;
      entered = null;
      release = null;
    }
    saved[scope] = record;
  }

  @override
  Future<void> remove(String scope) async {
    saved.remove(scope);
  }
}

class PrivateActionApi extends FixtureApi {
  PrivateActionApi(this.owner);
  final int owner;
  int session = 0, posts = 0;
  Completer<dynamic>? delayedMe, delayedPost;

  @override
  bool Function() sessionFence() {
    final snapshot = session;
    return () => snapshot == session;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') {
      final held = delayedMe;
      delayedMe = null;
      if (held != null) return held.future;
      return {'id': owner, 'role': 'customer'};
    }
    if (method == 'POST') {
      posts++;
      final held = delayedPost;
      delayedPost = null;
      if (held != null) return held.future;
      return {
        'account': {
          'unit': 'NON_MONETARY_POINTS',
          'cash_value': false,
          'transferable': false,
          'evidence': 'TEST_PROMOTIONAL_ECONOMICS',
        },
        'redemption': {'reward_id': 7, 'status': 'pending'},
      };
    }
    throw StateError('Unexpected private loyalty request: $method $path');
  }
}

Widget actionScreen(
  PrivateActionApi api,
  PrivateLoyaltyStore store, {
  String action = 'earn',
  int? reward,
  VoidCallback? onExpired,
}) => MaterialApp(
  home: LoyaltyActionView(
    api: api,
    onExpired: onExpired ?? () {},
    action: action,
    reward: reward,
    store: store,
  ),
);

Future<void> openConsent(WidgetTester tester) async {
  await tester.ensureVisible(find.widgetWithText(FilledButton, 'Confirm'));
  await tester.tap(find.widgetWithText(FilledButton, 'Confirm'));
  await tester.pumpAndSettle();
  expect(find.byType(AlertDialog), findsOneWidget);
}

void main() {
  testWidgets('old delayed account cannot restore private loyalty intent to new API', (
    tester,
  ) async {
    final old = PrivateActionApi(1), next = PrivateActionApi(2);
    final store = PrivateLoyaltyStore();
    final awaiting = Completer<dynamic>();
    old.delayedMe = awaiting;
    var expiry = 0;
    await tester.pumpWidget(actionScreen(old, store, onExpired: () => expiry++));
    await tester.pump();
    await tester.pumpWidget(actionScreen(next, store, onExpired: () => expiry++));
    await tester.pumpAndSettle();
    awaiting.completeError(const ApiException(401, 'Former customer expired'));
    await tester.pumpAndSettle();
    expect(expiry, 0);
    expect(old.posts, 0);
    expect(next.posts, 0);
    expect(store.reads, 1);
    expect(find.widgetWithText(FilledButton, 'Confirm'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('account rotation during points consent cannot submit an old invoice', (
    tester,
  ) async {
    final api = PrivateActionApi(1), store = PrivateLoyaltyStore();
    await tester.pumpWidget(actionScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '18');
    await openConsent(tester);
    api.session++;
    await tester.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await tester.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.saved, isEmpty);
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets('secure intent preparation cannot dispatch to another customer', (
    tester,
  ) async {
    final old = PrivateActionApi(1), next = PrivateActionApi(2);
    final store = PrivateLoyaltyStore();
    store.entered = Completer<void>();
    store.release = Completer<void>();
    await tester.pumpWidget(actionScreen(old, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '18');
    await openConsent(tester);
    await tester.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await tester.pump();
    await store.entered!.future;
    await tester.pumpWidget(actionScreen(next, store));
    await tester.pumpAndSettle();
    store.release!.complete();
    await tester.pumpAndSettle();
    expect(old.posts, 0);
    expect(next.posts, 0);
    expect(find.text('Server confirmed the operation. Review the authoritative account and reward-request history.'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('changing reward target in same State invalidates prior consent intent', (
    tester,
  ) async {
    final api = PrivateActionApi(1), store = PrivateLoyaltyStore();
    await tester.pumpWidget(actionScreen(api, store, action: 'redeem', reward: 7));
    await tester.pumpAndSettle();
    await openConsent(tester);
    await tester.pumpWidget(actionScreen(api, store, action: 'redeem', reward: 8));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Confirm').last);
    await tester.pumpAndSettle();
    expect(api.posts, 0);
    expect(store.saved, isEmpty);
    expect(find.text('Server confirmed the operation. Review the authoritative account and reward-request history.'), findsNothing);
  });

  testWidgets('app background hides draft and only explicit restore rebinds current customer', (
    tester,
  ) async {
    final api = PrivateActionApi(1), store = PrivateLoyaltyStore();
    addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    ));
    await tester.pumpWidget(actionScreen(api, store));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '18');
    final originalReads = store.reads;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.byType(TextField), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(store.reads, originalReads);
    await tester.tap(find.text('Restore the current customer request'));
    await tester.pumpAndSettle();
    expect(store.reads, originalReads + 1);
    expect(tester.widget<TextField>(find.byType(TextField)).controller!.text, '');
    expect(api.posts, 0);
  });
}
