import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'package:creabiz_ev/api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'loyalty_test.dart' as loyalty;

class CouponStore implements FinancialOperationStore {
  final rows = <String, Map<String, dynamic>>{};
  @override
  Future<Map<String, dynamic>?> read(String scope) async => rows[scope];
  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    rows[scope] = value;
  }

  @override
  Future<void> remove(String scope) async {
    rows.remove(scope);
  }
}

String digest(String value) => sha256.convert(utf8.encode(value)).toString();

class CouponApi extends loyalty.LoyaltyApi {
  CouponApi([this.owner = 1]);
  final int owner;
  String? claimKey;
  bool failHistory = false;
  bool disableClaims = false;
  bool reviewed = false;
  bool recorded = true;
  bool expire = false;
  bool fulfilled = false;
  bool addMalformedOffer = false;
  bool cancelAccepted = false;
  bool wrongCancellationBinding = false;
  bool cancellationTimeout = false;
  int cancellationCalls = 0;

  Map<String, dynamic> get receipt => {
    'id': 19,
    'coupon_id': 7,
    'request_binding': digest(
      "${digest('PRIVATE-CODE-ABC')}:${digest('coupon-claim:$owner:$claimKey')}",
    ),
    'status': fulfilled ? 'fulfilled' : 'pending',
    'reward_name': 'Synthetic coupon reward',
    'reward_name_ar': 'مكافأة قسيمة تجريبية',
    'claimed_at': '2026-10-28T00:00:00Z',
    'points_cost': 0,
    'cash_value': false,
    'source': 'COUPON',
    'delivery_verified': false,
    'activation_mode': reviewed ? 'reviewed_manual' : 'sandbox',
    'activation_recorded': recorded,
    'evidence': reviewed
        ? 'REVIEWED_MANUAL_NONCASH'
        : 'TEST_PROMOTIONAL_ECONOMICS',
    'fulfillment': 'MANUAL_NON_MONETARY',
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') return {'id': owner, 'role': 'customer'};
    if (path == 'commercial/noncash/status') {
      expect(method, 'GET');
      return {
        'enabled': !disableClaims,
        'activation_mode': disableClaims ? 'disabled' : 'sandbox',
        'points_actions_enabled': !disableClaims,
        'referrals_enabled': !disableClaims,
        'money_movement': false,
        'fulfillment': 'MANUAL_NON_MONETARY',
        'delivery_verified': false,
        'verified_live_source_required': false,
      };
    }
    if (!path.startsWith('commercial/coupons/')) {
      return super.request(path, method: method, body: body, query: query);
    }
    if (expire) {
      throw ApiException(
        401,
        'Synthetic expiry',
        expirationIsCurrent: () => true,
      );
    }
    if (path.endsWith('/eligible')) {
      expect(method, 'GET');
      return {
        'current_page': 1,
        'last_page': 1,
        'total': 1,
        'data': [
          {
            'id': 7,
            'reward_name': 'Synthetic coupon reward',
            'reward_name_ar': 'مكافأة قسيمة تجريبية',
            if (!addMalformedOffer) 'ends_at': '2026-10-29 00:00:00',
          },
        ],
      };
    }
    if (path.endsWith('/claim')) {
      expect(method, 'POST');
      final payload = body!;
      if (path.endsWith('/eligible/7/claim')) {
        expect(payload.keys, ['idempotency_key']);
      } else {
        expect(payload['code'], 'PRIVATE-CODE-ABC');
      }
      final key = payload['idempotency_key'] as String;
      if (claimKey != null) {
        expect(key, claimKey);
        return {'claim': receipt};
      }
      claimKey = key;
      throw const ApiException(503, 'Uncertain response');
    }
    if (path.endsWith('/claim-intents/cancel')) {
      expect(method, 'POST');
      expect(body, {'coupon_id': 7, 'idempotency_key': claimKey});
      cancellationCalls++;
      if (cancellationTimeout) throw const ApiException(503, 'Uncertain cancellation');
      return {
        'coupon_id': 7,
        'request_binding': wrongCancellationBinding
            ? 'invalid'
            : digest('coupon-cancel-v1:7:${digest('coupon-claim:$owner:$claimKey')}'),
        'state': cancelAccepted ? 'accepted' : 'cancelled',
        'claim': cancelAccepted ? receipt : null,
      };
    }
    if (path.endsWith('/claim-receipt')) {
      expect(method, 'POST');
      expect(body, {'idempotency_key': claimKey});
      return {'claim': receipt};
    }
    expect(path, 'commercial/coupons/claims');
    expect(method, 'GET');
    if (failHistory) throw const ApiException(503, 'Private network fault');
    return {
      'current_page': 1,
      'last_page': 1,
      'total': 1,
      'data': [receipt],
    };
  }
}

Future<void> tap(WidgetTester tester, String label) async {
  final control = find.text(label);
  await tester.ensureVisible(control);
  await tester.tap(control);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'reopen restores owner receipt lookup without a saved private code',
    (tester) async {
      final api = CouponApi();
      final foreign = CouponApi(2);
      final store = CouponStore();
      addTearDown(api.close);
      addTearDown(foreign.close);
      await tester.pumpWidget(loyalty.app(api, couponStore: store));
      await tester.pumpAndSettle();
      await tap(tester, 'Coupons');
      await tester.enterText(find.byType(TextField), 'private-code-abc');
      await tap(tester, 'Claim coupon');
      expect(store.rows.values.single['key'], api.claimKey);
      expect(jsonEncode(store.rows), isNot(contains('PRIVATE-CODE-ABC')));
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pumpWidget(loyalty.app(foreign, couponStore: store));
      await tester.pumpAndSettle();
      await tap(tester, 'Coupons');
      expect(find.text('Check last claim receipt'), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pumpWidget(loyalty.app(api, couponStore: store));
      await tester.pumpAndSettle();
      await tap(tester, 'Coupons');
      expect(
        tester.widget<TextField>(find.byType(TextField)).controller!.text,
        '',
      );
      await tap(tester, 'Check last claim receipt');
      expect(find.text('Pending fulfillment'), findsOneWidget);
      expect(store.rows, isEmpty);
    },
  );

  testWidgets('disabled claiming retains provenance-checked receipt history', (
    tester,
  ) async {
    final api = CouponApi()
      ..disableClaims = true
      ..reviewed = true;
    addTearDown(api.close);
    await tester.pumpWidget(loyalty.app(api, couponStore: CouponStore()));
    await tester.pumpAndSettle();
    await tap(tester, 'Coupons');
    final claim = find.widgetWithText(FilledButton, 'Claim coupon');
    expect(tester.widget<FilledButton>(claim).onPressed, isNull);
    await tap(tester, 'Refresh coupon history');
    expect(
      find.textContaining('Reviewed manual noncash record'),
      findsOneWidget,
    );
    api.recorded = false;
    await tap(tester, 'Refresh coupon history');
    expect(find.textContaining('Reviewed manual noncash record'), findsNothing);
    expect(find.text('Could not load coupons. Please retry.'), findsOneWidget);
    expect(api.claimKey, isNull);
  });

  testWidgets('private coupon receipt survives app pause and history refresh', (
    tester,
  ) async {
    final api = CouponApi();
    addTearDown(api.close);
    await tester.pumpWidget(loyalty.app(api, couponStore: CouponStore()));
    await tester.pumpAndSettle();
    await tap(tester, 'Coupons');
    await tester.enterText(find.byType(TextField), 'private-code-abc');
    await tap(tester, 'Claim coupon');
    expect(find.textContaining('could not be confirmed'), findsOneWidget);
    for (final state in [
      AppLifecycleState.inactive,
      AppLifecycleState.hidden,
      AppLifecycleState.paused,
      AppLifecycleState.hidden,
      AppLifecycleState.inactive,
      AppLifecycleState.resumed,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
    }
    await tester.pumpAndSettle();
    expect(
      tester.widget<TextField>(find.byType(TextField)).controller!.text,
      '',
    );
    await tap(tester, 'Check last claim receipt');
    expect(find.text('Pending fulfillment'), findsOneWidget);
    expect(find.text('Synthetic coupon reward'), findsOneWidget);
    api.fulfilled = true;
    await tap(tester, 'Refresh coupon history');
    expect(find.text('Fulfilled'), findsOneWidget);
    api.failHistory = true;
    await tap(tester, 'Refresh coupon history');
    expect(find.text('Could not load coupons. Please retry.'), findsOneWidget);
    expect(find.text('Fulfilled'), findsNothing);
  });

  testWidgets('Arabic discovery and listed claim retries use the same key', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final api = CouponApi()..addMalformedOffer = true;
    addTearDown(api.close);
    await tester.pumpWidget(
      loyalty.app(api, ar: true, couponStore: CouponStore()),
    );
    await tester.pumpAndSettle();
    await tap(tester, 'القسائم');
    await tap(tester, 'تصفح القسائم المؤهلة');
    expect(find.text('تعذر تحميل القسائم. أعد المحاولة.'), findsOneWidget);
    expect(find.text('مكافأة قسيمة تجريبية'), findsNothing);
    api.addMalformedOffer = false;
    await tap(tester, 'تصفح القسائم المؤهلة');
    final reward = find.text('مكافأة قسيمة تجريبية');
    expect(Directionality.of(tester.element(reward)), TextDirection.rtl);
    await tap(tester, 'مطالبة');
    expect(find.textContaining('تعذر تأكيد طلب القسيمة'), findsOneWidget);
    await tap(tester, 'مطالبة');
    expect(find.text('بانتظار التسليم'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
  testWidgets('listed cancellation retains draft until bound server acknowledgement', (
    tester,
  ) async {
    final api = CouponApi()..wrongCancellationBinding = true;
    final store = CouponStore();
    addTearDown(api.close);
    await tester.pumpWidget(loyalty.app(api, couponStore: store));
    await tester.pumpAndSettle();
    await tap(tester, 'Coupons');
    await tap(tester, 'Browse eligible coupons');
    await tap(tester, 'Claim');
    final key = store.rows.values.single['key'];
    await tap(tester, 'Clear coupon draft');
    expect(store.rows.values.single['key'], key);
    expect(find.textContaining('Cancellation not confirmed'), findsOneWidget);
    api.wrongCancellationBinding = false;
    api.cancellationTimeout = true;
    await tap(tester, 'Clear coupon draft');
    expect(store.rows.values.single['key'], key);
    api.cancellationTimeout = false;
    await tap(tester, 'Clear coupon draft');
    expect(store.rows, isEmpty);
    expect(api.cancellationCalls, 3);
    expect(find.text('Saved claim cancelled by the server.'), findsOneWidget);
  });

  testWidgets('listed cancellation returns an accepted claim rather than discarding it', (
    tester,
  ) async {
    final api = CouponApi()..cancelAccepted = true;
    final store = CouponStore();
    addTearDown(api.close);
    await tester.pumpWidget(loyalty.app(api, couponStore: store));
    await tester.pumpAndSettle();
    await tap(tester, 'Coupons');
    await tap(tester, 'Browse eligible coupons');
    await tap(tester, 'Claim');
    await tap(tester, 'Clear coupon draft');
    expect(store.rows, isEmpty);
    expect(find.text('Pending fulfillment'), findsOneWidget);
    expect(find.text('Synthetic coupon reward'), findsOneWidget);
    expect(api.cancellationCalls, 1);
  });

  testWidgets('coupon session expiry clears discovery and invokes sign-in', (
    tester,
  ) async {
    final api = CouponApi();
    addTearDown(api.close);
    var expired = 0;
    await tester.pumpWidget(
      loyalty.app(api, expired: () => expired++, couponStore: CouponStore()),
    );
    await tester.pumpAndSettle();
    await tap(tester, 'Coupons');
    await tap(tester, 'Browse eligible coupons');
    expect(find.text('Synthetic coupon reward'), findsOneWidget);
    api.expire = true;
    await tap(tester, 'Refresh coupon history');
    expect(expired, 1);
    expect(find.text('Synthetic coupon reward'), findsNothing);
  });
}
