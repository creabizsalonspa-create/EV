import 'package:creabiz_ev/api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'loyalty_test.dart' as loyalty;

class CouponApi extends loyalty.LoyaltyApi {
  String? claimKey;
  bool failHistory = false;
  bool expire = false;
  bool fulfilled = false;
  bool addMalformedOffer = false;

  Map<String, dynamic> get receipt => {
    'id': 19,
    'status': fulfilled ? 'fulfilled' : 'pending',
    'reward_name': 'Synthetic coupon reward',
    'reward_name_ar': 'مكافأة قسيمة تجريبية',
    'claimed_at': '2026-10-28T00:00:00Z',
    'points_cost': 0,
    'cash_value': false,
    'fulfillment': 'MANUAL_NON_MONETARY',
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
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
  testWidgets('private coupon receipt survives app pause and history refresh', (
    tester,
  ) async {
    final api = CouponApi();
    addTearDown(api.close);
    await tester.pumpWidget(loyalty.app(api));
    await tester.pumpAndSettle();
    await tap(tester, 'Coupons');
    await tester.enterText(find.byType(TextField), 'private-code-abc');
    await tap(tester, 'Claim coupon');
    expect(find.textContaining('could not be confirmed'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
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
    await tester.pumpWidget(loyalty.app(api, ar: true));
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
  testWidgets('coupon session expiry clears discovery and invokes sign-in', (
    tester,
  ) async {
    final api = CouponApi();
    addTearDown(api.close);
    var expired = 0;
    await tester.pumpWidget(loyalty.app(api, expired: () => expired++));
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
