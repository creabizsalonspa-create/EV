import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'package:creabiz_ev/partner_rewards.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class PartnerRewardStore implements FinancialOperationStore {
  final values = <String, Map<String, dynamic>>{};
  @override
  Future<Map<String, dynamic>?> read(String scope) async => values[scope];
  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    values[scope] = value;
  }

  @override
  Future<void> remove(String scope) async {
    values.remove(scope);
  }
}

class PartnerRewardApi extends FixtureApi {
  PartnerRewardApi(this.owner);

  static const prefix = 'commercial/partner-rewards';
  final int owner;
  bool enabled = true;
  bool verified = true;
  bool loseReply = false;
  bool malformed = false;
  bool rejectClaim = false;
  bool malformedCancellation = false;
  void Function(Map<String, dynamic>)? alterOffers;
  void Function(Map<String, dynamic>)? alterClaims;
  final cancelled = <String>{};
  final cancellationKeys = <String>[];
  String status = 'pending';
  final claimKeys = <String>[];
  final receiptKeys = <String>[];
  final claims = <String, Map<String, dynamic>>{};

  Map<String, dynamic> get evidence => {
    'source': 'RETAIL_PARTNER_REWARD',
    'evidence': 'SANDBOX_MANUAL_NONCASH',
    'external_fulfillment_verified': malformed,
    'cash_value': false,
    'points_cost': 0,
  };

  Map<String, dynamic> page(List<Map<String, dynamic>> data) => {
    'data': data,
    'total': data.length,
    'current_page': 1,
    'last_page': 1,
    'per_page': 25,
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') {
      return {
        'id': owner,
        'role': 'customer',
        'email_verified_at': verified ? '2026-01-01' : null,
      };
    }
    if (path == '$prefix/offers') {
      expect(method, 'GET');
      expect(query['page'], '1');
      if (!enabled) throw const ApiException(503, 'Disabled');
      final response = page([
        {
          ...evidence,
          'review_id': 9,
          'name': 'Test benefit',
          'name_ar': 'منفعة تجريبية',
          'partner_name': 'Test partner',
          'partner_name_ar': 'شريك تجريبي',
          'description': 'Test terms',
          'description_ar': 'شروط تجريبية',
          'ends_at': '2026-12-23 00:00:00',
        },
      ]);
      alterOffers?.call(response);
      return response;
    }
    if (path == '$prefix/claims') {
      expect(method, 'GET');
      final response = page([
        for (final row in claims.values)
          {...row, 'status': status, ...evidence},
      ]);
      alterClaims?.call(response);
      return response;
    }
    expect(method, 'POST');
    final key = body!['idempotency_key'] as String;
    if (path == '$prefix/claim-receipt') {
      receiptKeys.add(key);
      return {'claim': claims[key]};
    }
    if (path == '$prefix/claim-intents/cancel') {
      expect(body['review_id'], 9);
      cancellationKeys.add(key);
      final request = sha256.convert(utf8.encode('partner-reward-claim:$owner:$key')).toString();
      final binding = sha256.convert(utf8.encode('partner-reward-cancel-v1:9:$request')).toString();
      if (!claims.containsKey(key)) cancelled.add(key);
      return {'state': claims.containsKey(key) ? 'accepted' : 'cancelled',
        'review_id': 9, 'request_binding': malformedCancellation ? 'wrong-binding' : binding,
        'claim': claims[key]};
    }
    expect(path, '$prefix/offers/9/claim');
    expect(enabled, true);
    claimKeys.add(key);
    if (rejectClaim || cancelled.contains(key)) throw const ApiException(409, 'Claim blocked');
    final row = claims.putIfAbsent(
      key,
      () => {
        ...evidence,
        'id': 14,
        'review_id': 9,
        'redemption_id': 18,
        'created_at': '2026-11-22 08:00:00',
        'status': 'pending',
      },
    );
    if (loseReply) throw const ApiException(503, 'Lost reply');
    return {'claim': row};
  }
}

Widget app(
  PartnerRewardApi api,
  PartnerRewardStore store,
  int instance, {
  bool ar = false,
}) => MaterialApp(
  key: ValueKey(instance),
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: PartnerRewardsView(api: api, store: store, onExpired: () {}),
);

Future<void> tap(WidgetTester tester, String label) async {
  final control = find.text(label);
  await tester.ensureVisible(control);
  await tester.tap(control);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('malformed offer page cannot expose actionable rows', (tester) async {
    final api = PartnerRewardApi(1);
    addTearDown(api.close);
    await tester.pumpWidget(app(api, PartnerRewardStore(), 1));
    await tester.pumpAndSettle();
    expect(find.text('Claim reward'), findsOneWidget);
    for (final change in <void Function(Map<String, dynamic>)>[
      (r) => r['current_page'] = 2,
      (r) => r['per_page'] = 26,
      (r) => r['total'] = 2,
      (r) => r['last_page'] = 99,
      (r) { r['data'].add(r['data'][0]); r['total'] = 2; },
    ]) {
      api.alterOffers = change;
      await tap(tester, 'Refresh offers');
      expect(find.text('Claim reward'), findsNothing);
      expect(find.textContaining('Partner reward offers are unavailable'), findsOneWidget);
    }
    expect(api.claimKeys, isEmpty);
  });

  testWidgets('malformed claim history cannot display duplicate receipts', (tester) async {
    final api = PartnerRewardApi(1);
    addTearDown(api.close);
    await tester.pumpWidget(app(api, PartnerRewardStore(), 1));
    await tester.pumpAndSettle();
    await tap(tester, 'Claim reward');
    await tap(tester, 'Claim');
    api.alterClaims = (r) {
      r['data'].insert(0, {...Map<String, dynamic>.from(r['data'][0]), 'id': 15});
      r['total'] = 2;
    };
    await tap(tester, 'My claim history');
    expect(find.text('Could not load partner reward history.'), findsOneWidget);
    expect(find.text('Claim #14'), findsNothing);
    expect(find.text('Claim #15'), findsNothing);
    expect(api.claimKeys, hasLength(1));
  });

  testWidgets('cancelled intent retains its exact key until verified server acknowledgement', (tester) async {
    final api = PartnerRewardApi(1)..rejectClaim = true;
    final store = PartnerRewardStore();
    addTearDown(api.close);
    await tester.pumpWidget(app(api, store, 1));
    await tester.pumpAndSettle();
    await tap(tester, 'Claim reward');
    await tap(tester, 'Claim');
    final key = api.claimKeys.single;
    expect(store.values.values.single['key'], key);
    api.malformedCancellation = true;
    await tap(tester, 'Cancel saved claim');
    await tap(tester, 'Confirm cancellation');
    expect(store.values.values.single['key'], key);
    expect(find.textContaining('Cancellation not confirmed'), findsOneWidget);
    api.malformedCancellation = false;
    await tap(tester, 'Cancel saved claim');
    await tap(tester, 'Confirm cancellation');
    expect(api.cancellationKeys, [key, key]);
    expect(api.cancelled, contains(key));
    expect(store.values, isEmpty);
    expect(api.claims, isEmpty);
    expect(find.text('Saved request cancelled by the server.'), findsOneWidget);
  });

  testWidgets('cancelling a lost accepted claim recovers its receipt without replacement', (tester) async {
    final api = PartnerRewardApi(1)..loseReply = true;
    final store = PartnerRewardStore();
    addTearDown(api.close);
    await tester.pumpWidget(app(api, store, 1));
    await tester.pumpAndSettle();
    await tap(tester, 'Claim reward');
    await tap(tester, 'Claim');
    final key = api.claimKeys.single;
    api.enabled = false;
    await tap(tester, 'Cancel saved claim');
    await tap(tester, 'Confirm cancellation');
    expect(api.cancellationKeys, [key]);
    expect(api.claimKeys, [key]);
    expect(api.claims, hasLength(1));
    expect(api.cancelled, isEmpty);
    expect(store.values, isEmpty);
    expect(find.text('Claim receipt'), findsOneWidget);
  });

  testWidgets(
    'Arabic discovery, confirmed claim and current fulfillment history',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = PartnerRewardApi(1);
      addTearDown(api.close);
      await tester.pumpWidget(app(api, PartnerRewardStore(), 1, ar: true));
      await tester.pumpAndSettle();
      expect(find.text('منفعة تجريبية'), findsOneWidget);
      expect(find.text('شريك تجريبي'), findsOneWidget);
      expect(
        Directionality.of(tester.element(find.text('شريك تجريبي'))),
        TextDirection.rtl,
      );
      await tap(tester, 'المطالبة بالمكافأة');
      expect(api.claimKeys, isEmpty);
      await tap(tester, 'مطالبة');
      expect(find.text('إيصال المطالبة'), findsOneWidget);
      expect(find.text('المطالبة رقم 14'), findsOneWidget);
      expect(find.textContaining('بانتظار التسليم اليدوي'), findsOneWidget);
      api.status = 'fulfilled';
      await tap(tester, 'سجل مطالباتي');
      expect(find.textContaining('تم تسجيل التسليم اليدوي'), findsOneWidget);
      expect(api.claims, hasLength(1));
    },
  );

  testWidgets(
    'lost response is recoverable after reopen with activation disabled and outside other accounts',
    (tester) async {
      final api = PartnerRewardApi(1)..loseReply = true;
      final foreign = PartnerRewardApi(2);
      final store = PartnerRewardStore();
      addTearDown(api.close);
      addTearDown(foreign.close);
      await tester.pumpWidget(app(api, store, 1));
      await tester.pumpAndSettle();
      await tap(tester, 'Claim reward');
      await tap(tester, 'Claim');
      final key = api.claimKeys.single;
      expect(store.values.values.single['key'], key);
      await tester.pumpWidget(app(foreign, store, 2));
      await tester.pumpAndSettle();
      expect(find.text('Check saved claim receipt'), findsNothing);
      await tap(tester, 'My claim history');
      expect(find.text('No partner reward claims.'), findsOneWidget);
      api.enabled = false;
      await tester.pumpWidget(app(api, store, 3));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Partner reward offers are unavailable'),
        findsOneWidget,
      );
      await tap(tester, 'Check saved claim receipt');
      expect(find.text('Claim receipt'), findsOneWidget);
      expect(api.receiptKeys, [key]);
      expect(api.claimKeys, [key]);
      expect(store.values, isEmpty);
    },
  );

  testWidgets(
    'unverified email and malformed sandbox evidence block claim controls',
    (tester) async {
      final api = PartnerRewardApi(1)..verified = false;
      addTearDown(api.close);
      await tester.pumpWidget(app(api, PartnerRewardStore(), 1));
      await tester.pumpAndSettle();
      expect(find.text('Verify your email to claim a reward.'), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Claim reward'),
            )
            .onPressed,
        isNull,
      );
      api.malformed = true;
      await tap(tester, 'Refresh offers');
      expect(find.text('Claim reward'), findsNothing);
      expect(
        find.textContaining('Partner reward offers are unavailable'),
        findsOneWidget,
      );
      expect(api.claimKeys, isEmpty);
    },
  );
}
