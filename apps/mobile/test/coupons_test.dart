import 'dart:async';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/coupons.dart';
import 'package:creabiz_ev/noncash_capabilities.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';
import 'native_financial_operations_test.dart' show MemoryOperations;
import 'vendor_meter_auth_expiration_test.dart' show BufferedTransportApi;

const fixtureCode = 'EV-COUPON-2026';
Map<String, dynamic> claimReceipt(String binding) => {
  'id': 1,
  'coupon_id': 2,
  'redemption_id': 3,
  'reward_name': 'Literal <reward>',
  'reward_name_ar': 'مكافأة تجريبية',
  'status': 'pending',
  'claimed_at': '2026-11-21T12:00:00.000000Z',
  'request_binding': binding,
  'source': 'COUPON',
  'evidence': 'TEST_PROMOTIONAL_ECONOMICS',
  'delivery_verified': false,
  'points_cost': 0,
  'cash_value': false,
  'fulfillment': 'MANUAL_NON_MONETARY',
};

class CouponApi extends FixtureApi {
  CouponApi({super.role});
  int actor = 1;
  bool lose = false,
      forge = false,
      neverAccepted = false,
      inspectAbsent = false;
  bool reviewed = false, unrecorded = false, disabled = false;
  Map<String, dynamic>? accepted;
  final posts = <Map<String, dynamic>>[];
  final methods = <String>[];
  Completer<dynamic>? held;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    methods.add('$method $path');
    if (path == 'me') return {'id': actor, 'role': role};
    if (path == 'commercial/noncash/status')
      return {
        'enabled': !disabled,
        'activation_mode': disabled
            ? 'disabled'
            : reviewed
            ? 'reviewed_manual'
            : 'sandbox',
        'points_actions_enabled': !disabled && !reviewed,
        'money_movement': false,
        'fulfillment': 'MANUAL_NON_MONETARY',
        'delivery_verified': false,
        'referrals_enabled': !disabled,
        'verified_live_source_required': reviewed,
      };
    if (path == 'commercial/coupons/claims')
      return {
        'data': <dynamic>[],
        'current_page': int.parse(query['page']!),
        'last_page': 1,
        'total': 0,
      };
    expect(method, 'POST');
    posts.add({'path': path, 'body': Map<String, dynamic>.from(body!)});
    if (held != null) return await held!.future;
    if (path.endsWith('claim-intents/cancel'))
      return {
        'state': accepted == null ? 'cancelled' : 'accepted',
        'claim': accepted,
        'request_binding': couponRequestBinding(
          body['code'],
          actor,
          body['idempotency_key'],
        ),
      };
    if (path.endsWith('claim-receipt'))
      return {'claim': inspectAbsent ? null : accepted};
    final binding = couponRequestBinding(
      body['code'],
      actor,
      body['idempotency_key'],
    );
    if (!neverAccepted)
      accepted = {
        ...claimReceipt(forge ? List.filled(64, '0').join() : binding),
        'activation_mode': reviewed ? 'reviewed_manual' : 'sandbox',
        'activation_recorded': !unrecorded,
        'evidence': reviewed
            ? 'REVIEWED_MANUAL_NONCASH'
            : 'TEST_PROMOTIONAL_ECONOMICS',
      };
    if (lose) throw const ApiException(0, 'PRIVATE-unconfirmed');
    return {'claim': accepted};
  }
}

Widget view(
  EvApi api,
  MemoryOperations store, {
  VoidCallback? expired,
  bool ar = false,
}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: CustomerCouponsView(
    api: api,
    store: store,
    onExpired: expired ?? () {},
  ),
);
Future<void> click(WidgetTester t, String key) async {
  await t.ensureVisible(find.byKey(Key(key)));
  await t.tap(find.byKey(Key(key)));
  await t.pumpAndSettle();
}

Future<void> enterClaim(WidgetTester t) async {
  await t.enterText(find.byKey(const Key('coupon-code')), fixtureCode);
  await click(t, 'coupon-claim');
}

void main() {
  test(
    'noncash capabilities and immutable provenance cannot enable money or relabel a legacy sandbox',
    () {
      final reviewed = {
        'enabled': true,
        'activation_mode': 'reviewed_manual',
        'points_actions_enabled': false,
        'money_movement': false,
        'fulfillment': 'MANUAL_NON_MONETARY',
        'delivery_verified': false,
        'referrals_enabled': false,
        'verified_live_source_required': true,
      };
      expect(NoncashCapabilities.parse(reviewed).mode, 'reviewed_manual');
      for (final entry in {
        'points_actions_enabled': true,
        'money_movement': true,
        'delivery_verified': true,
        'activation_mode': 'live_wallet',
      }.entries) {
        expect(
          () =>
              NoncashCapabilities.parse({...reviewed, entry.key: entry.value}),
          throwsFormatException,
        );
      }
      expect(
        NoncashReceiptEvidence.parse({
          'evidence': 'TEST_PROMOTIONAL_ECONOMICS',
        }).mode,
        'sandbox',
      );
      expect(
        () => NoncashReceiptEvidence.parse({
          'evidence': 'REVIEWED_MANUAL_NONCASH',
        }),
        throwsFormatException,
      );
      expect(
        () => NoncashReceiptEvidence.parse({
          'activation_mode': 'reviewed_manual',
          'activation_recorded': false,
          'evidence': 'REVIEWED_MANUAL_NONCASH',
        }),
        throwsFormatException,
      );
    },
  );
  testWidgets(
    'reviewed noncash confirmation is classified without test-only or delivery claims',
    (t) async {
      final api = CouponApi()..reviewed = true;
      await t.pumpWidget(view(api, MemoryOperations()));
      await t.pumpAndSettle();
      await enterClaim(t);
      expect(find.text('Reviewed manual noncash record'), findsOneWidget);
      expect(find.text('Sandbox test record'), findsNothing);
    },
  );
  testWidgets(
    'an unrecorded reviewed claim never clears its saved recovery identity',
    (t) async {
      final api = CouponApi()
            ..reviewed = true
            ..unrecorded = true,
          store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      expect(store.values, hasLength(1));
      expect(find.text('Reviewed manual noncash record'), findsNothing);
    },
  );
  testWidgets(
    'changing the runtime mode cannot relabel an existing sandbox receipt',
    (t) async {
      final api = CouponApi()..lose = true, store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      api.reviewed = true;
      await click(t, 'coupon-restore');
      await click(t, 'coupon-inspect');
      expect(find.text('Sandbox test record'), findsOneWidget);
      expect(find.text('Reviewed manual noncash record'), findsNothing);
    },
  );
  testWidgets(
    'disabled claims retain read-only receipt and cancellation access',
    (t) async {
      final api = CouponApi()..lose = true, store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      api.disabled = true;
      await click(t, 'coupon-restore');
      expect(
        t.widget<FilledButton>(find.byKey(const Key('coupon-claim'))).onPressed,
        isNull,
      );
      expect(
        t
            .widget<OutlinedButton>(find.byKey(const Key('coupon-inspect')))
            .onPressed,
        isNotNull,
      );
      expect(
        t.widget<TextButton>(find.byKey(const Key('coupon-retire'))).onPressed,
        isNotNull,
      );
    },
  );

  test(
    'receipt projection rejects money, delivery, malformed source and wrong request correlation',
    () {
      final binding = couponRequestBinding(fixtureCode, 1, 'key');
      expect(
        CouponClaimRecord.parse(
          claimReceipt(binding),
          expectedBinding: binding,
        ).status,
        'pending',
      );
      for (final entry in {
        'cash_value': true,
        'points_cost': 1,
        'delivery_verified': true,
        'source': 'PAYMENT',
        'evidence': 'LIVE',
        'request_binding': 'bad',
        'id': -1,
        'status': 'paid',
        'claimed_at': '2026-11-21 12:00:00',
        'reward_name': null,
      }.entries) {
        expect(
          () => CouponClaimRecord.parse({
            ...claimReceipt(binding),
            entry.key: entry.value,
          }),
          throwsFormatException,
          reason: entry.key,
        );
      }
      expect(
        () => CouponClaimRecord.parse(
          claimReceipt(binding),
          expectedBinding: List.filled(64, '0').join(),
        ),
        throwsFormatException,
      );
    },
  );
  testWidgets(
    'only authenticated customer context enables coupon claims; no automatic mutation',
    (t) async {
      final api = CouponApi(role: 'vendor');
      await t.pumpWidget(view(api, MemoryOperations()));
      await t.pumpAndSettle();
      expect(api.methods, ['GET me']);
      expect(find.byKey(const Key('coupon-claim')), findsNothing);
      expect(api.posts, isEmpty);
    },
  );
  testWidgets(
    'claim persists exact code/key before dispatch and clears only bound confirmation',
    (t) async {
      final api = CouponApi(), store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      expect(api.posts, isEmpty);
      await enterClaim(t);
      expect(api.posts, hasLength(1));
      expect(api.posts.single['body']['code'], fixtureCode);
      expect(store.values, isEmpty);
      expect(find.text('Literal <reward>'), findsOneWidget);
      expect(find.textContaining('Claim recorded.'), findsOneWidget);
    },
  );
  testWidgets(
    'uncertain claim survives restart and never automatically replays or replaces key',
    (t) async {
      final api = CouponApi()..lose = true, store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      final original = api.posts.single['body'];
      expect(store.values, hasLength(1));
      expect(find.textContaining('PRIVATE'), findsNothing);
      await t.pumpWidget(const SizedBox());
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      expect(api.posts, hasLength(1));
      expect(
        t.widget<TextField>(find.byKey(const Key('coupon-code'))).enabled,
        isFalse,
      );
      api.lose = false;
      await click(t, 'coupon-claim');
      expect(api.posts, hasLength(2));
      expect(api.posts.last['body'], original);
      expect(store.values, isEmpty);
    },
  );
  testWidgets('wrong binding cannot acknowledge a saved claim', (t) async {
    final api = CouponApi()..forge = true, store = MemoryOperations();
    await t.pumpWidget(view(api, store));
    await t.pumpAndSettle();
    await enterClaim(t);
    expect(store.values, hasLength(1));
    expect(find.textContaining('Claim recorded.'), findsNothing);
    expect(find.text('Literal <reward>'), findsNothing);
  });
  testWidgets(
    'read-only receipt inspection confirms existing request without resending claim',
    (t) async {
      final api = CouponApi()..lose = true, store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      await click(t, 'coupon-restore');
      await click(t, 'coupon-inspect');
      expect(api.posts.map((p) => p['path']), [
        'commercial/coupons/claim',
        'commercial/coupons/claim-receipt',
      ]);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'null receipt does not clear uncertain request or create replacement',
    (t) async {
      final api = CouponApi()
            ..lose = true
            ..inspectAbsent = true,
          store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      await click(t, 'coupon-restore');
      await click(t, 'coupon-inspect');
      expect(store.values, hasLength(1));
      expect(find.textContaining('No receipt is recorded yet'), findsOneWidget);
      expect(
        t.widget<TextField>(find.byKey(const Key('coupon-code'))).enabled,
        isFalse,
      );
    },
  );
  testWidgets(
    'secure write failure blocks any claim dispatch until explicit restoration',
    (t) async {
      final api = CouponApi(), store = MemoryOperations()..fail = true;
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      expect(api.posts, isEmpty);
      expect(
        t.widget<FilledButton>(find.byKey(const Key('coupon-claim'))).onPressed,
        isNull,
      );
      expect(find.textContaining('secure write failed'), findsNothing);
    },
  );
  testWidgets(
    'durable cancellation fences unaccepted request before clearing its saved identity',
    (t) async {
      final api = CouponApi()
            ..lose = true
            ..neverAccepted = true,
          store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      final original = api.posts.single['body'];
      await click(t, 'coupon-restore');
      await t.ensureVisible(find.byKey(const Key('coupon-retire')));
      await t.tap(find.byKey(const Key('coupon-retire')));
      await t.pumpAndSettle();
      await click(t, 'coupon-retire-confirm');
      expect(api.posts.last['body'], original);
      expect(store.values, isEmpty);
      expect(find.textContaining('permanently retired'), findsOneWidget);
    },
  );
  testWidgets(
    'cancellation racing an already accepted claim recovers its receipt',
    (t) async {
      final api = CouponApi()..lose = true, store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await enterClaim(t);
      await click(t, 'coupon-restore');
      await t.ensureVisible(find.byKey(const Key('coupon-retire')));
      await t.tap(find.byKey(const Key('coupon-retire')));
      await t.pumpAndSettle();
      await click(t, 'coupon-retire-confirm');
      expect(find.text('Literal <reward>'), findsOneWidget);
      expect(find.textContaining('permanently retired'), findsNothing);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'background clears private draft and requires explicit restore without replay',
    (t) async {
      final api = CouponApi(), store = MemoryOperations();
      await t.pumpWidget(view(api, store));
      await t.pumpAndSettle();
      await t.enterText(find.byKey(const Key('coupon-code')), fixtureCode);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pump();
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(find.byKey(const Key('coupon-code')), findsNothing);
      expect(api.posts, isEmpty);
      await click(t, 'coupon-restore');
      expect(
        t
            .widget<TextField>(find.byKey(const Key('coupon-code')))
            .controller!
            .text,
        isEmpty,
      );
    },
  );
  testWidgets(
    'real client401 expires current coupon view after its own epoch clears',
    (t) async {
      final api = BufferedTransportApi(401);
      addTearDown(api.close);
      var expired = 0;
      await t.pumpWidget(
        view(api, MemoryOperations(), expired: () => expired++),
      );
      await t.runAsync(api.deliver);
      await t.pump();
      expect(expired, 1);
    },
  );
  testWidgets(
    'Arabic390px keeps private code LTR and manual fulfillment wording',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      await t.pumpWidget(view(CouponApi(), MemoryOperations(), ar: true));
      await t.pumpAndSettle();
      expect(find.text('قسائم المكافآت'), findsOneWidget);
      expect(
        t.widget<TextField>(find.byKey(const Key('coupon-code'))).textDirection,
        TextDirection.ltr,
      );
      expect(t.takeException(), isNull);
    },
  );
}
