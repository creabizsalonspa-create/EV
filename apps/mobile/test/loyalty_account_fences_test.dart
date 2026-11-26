import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/loyalty.dart';

import 'loyalty_test.dart' as existing;
import 'coupon_test.dart' as coupon;

class AccountScopeApi extends existing.LoyaltyApi {
  AccountScopeApi(this.owner);
  final int owner;
  int epoch = 0;
  Completer<dynamic>? holdAccount;
  Completer<dynamic>? holdDetail;

  @override
  bool Function() sessionFence() {
    final version = epoch;
    return () => version == epoch;
  }

  @override
  Map<String, dynamic> get account => {
    ...super.account,
    'user_id': owner,
    'balance_points': owner * 10,
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'commercial/loyalty/account' && holdAccount != null) {
      final waiting = holdAccount!;
      holdAccount = null;
      return waiting.future;
    }
    if (path.startsWith('commercial/loyalty/redemptions/') &&
        holdDetail != null) {
      final waiting = holdDetail!;
      holdDetail = null;
      return waiting.future;
    }
    if (path.startsWith('commercial/loyalty/redemptions/')) {
      final id = int.parse(path.split('/').last);
      return {
        'redemption': {'id': id, 'points_cost': 30, 'status': 'pending'},
        'reward': {'name': 'Source reward #$id', 'name_ar': 'مكافأة $id'},
        'events': <Map<String, dynamic>>[],
      };
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class AccountScopeCouponApi extends coupon.CouponApi {
  AccountScopeCouponApi(super.owner);
  int epoch = 0;

  @override
  bool Function() sessionFence() {
    final version = epoch;
    return () => epoch == version;
  }
}

Widget details(AccountScopeApi api, int id, {VoidCallback? expired}) =>
    MaterialApp(
      home: LoyaltyRequestView(
        api: api, id: id, onExpired: expired ?? () {},
      ),
    );

void main() {
  testWidgets('same-State account change clears old points and ignores retained refresh', (
    tester,
  ) async {
    final old = AccountScopeApi(1), current = AccountScopeApi(2);
    await tester.pumpWidget(existing.app(old));
    await tester.pumpAndSettle();
    expect(find.text('Posted points: 10'), findsOneWidget);
    // OutlinedButton.icon has its own runtime widget type, so find.byType
    // does not match it even though it is an OutlinedButton subclass.
    final priorRefresh = tester.widget<OutlinedButton>(
      find.ancestor(
        of: find.text('Refresh'),
        matching: find.byWidgetPredicate((w) => w is OutlinedButton),
      ).first,
    ).onPressed!;
    await tester.pumpWidget(existing.app(current));
    await tester.pumpAndSettle();
    expect(find.text('Posted points: 20'), findsOneWidget);
    expect(find.text('Posted points: 10'), findsNothing);
    final reads = current.calls.length;
    priorRefresh();
    await tester.pumpAndSettle();
    expect(current.calls.length, reads,
        reason: 'Old account callback must not read the current owner');
    expect(tester.takeException(), isNull);
  });

  testWidgets('late private points response cannot repopulate or expire successor', (
    tester,
  ) async {
    final old = AccountScopeApi(1), current = AccountScopeApi(2);
    final waiting = Completer<dynamic>();
    old.holdAccount = waiting;
    var expired = 0;
    await tester.pumpWidget(existing.app(old, expired: () => expired++));
    await tester.pump();
    await tester.pumpWidget(existing.app(current, expired: () => expired++));
    await tester.pumpAndSettle();
    waiting.completeError(const ApiException(401, 'Prior identity'));
    await tester.pumpAndSettle();
    expect(expired, 0);
    expect(find.text('Posted points: 20'), findsOneWidget);
    expect(find.text('Posted points: 10'), findsNothing);
  });

  testWidgets('points and source data are hidden on app inactivity until refresh', (
    tester,
  ) async {
    final api = AccountScopeApi(1);
    addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    ));
    await tester.pumpWidget(existing.app(api));
    await tester.pumpAndSettle();
    expect(find.text('Posted points: 10'), findsOneWidget);
    final first = api.calls.length;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.text('Posted points: 10'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.calls.length, first);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.calls.length, first + 1);
    expect(find.text('Posted points: 10'), findsOneWidget);
  });

  testWidgets('deep link change evicts prior private redemption receipt', (
    tester,
  ) async {
    final api = AccountScopeApi(1);
    await tester.pumpWidget(details(api, 9));
    await tester.pumpAndSettle();
    expect(find.text('Source reward #9'), findsOneWidget);
    await tester.pumpWidget(details(api, 12));
    expect(find.text('Source reward #9'), findsNothing);
    await tester.pumpAndSettle();
    expect(find.text('Source reward #12'), findsOneWidget);
    expect(find.text('Source reward #9'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('old private redemption request never expires current account', (
    tester,
  ) async {
    final old = AccountScopeApi(1), next = AccountScopeApi(2);
    final waiting = Completer<dynamic>();
    old.holdDetail = waiting;
    var expired = 0;
    await tester.pumpWidget(details(old, 9, expired: () => expired++));
    await tester.pump();
    await tester.pumpWidget(details(next, 12, expired: () => expired++));
    await tester.pumpAndSettle();
    waiting.completeError(const ApiException(401, 'Old detail rejected'));
    await tester.pumpAndSettle();
    expect(expired, 0);
    expect(find.text('Source reward #12'), findsOneWidget);
    expect(find.text('Source reward #9'), findsNothing);
  });

  testWidgets('old coupon claim callback cannot dispatch after session epoch changes', (
    tester,
  ) async {
    addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    ));
    final api = AccountScopeCouponApi(1);
    final store = coupon.CouponStore();
    await tester.pumpWidget(existing.app(api, couponStore: store));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Coupons'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'private-code-abc');
    await tester.pump();
    final priorClaim = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Claim coupon'),
    ).onPressed!;
    api.epoch++;
    priorClaim();
    await tester.pump();
    expect(api.claimKey, isNull);
    expect(store.rows, isEmpty);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.text('Claim coupon'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
