import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/loyalty.dart';
import 'test_support.dart';

class LoyaltyApi extends FixtureApi {
  bool forged = false, deny = false;
  Completer<dynamic>? pending;
  final calls = <String>[];
  Map<String, dynamic> get account => {
    'user_id': 1,
    'balance_points': -10,
    'pending_reconciliation_points': 5,
    'spendable_points': 0,
    'qualified_lifetime_points': 100,
    'redemption_hold': true,
    'unit': 'NON_MONETARY_POINTS',
    'cash_value': forged,
    'transferable': false,
    'evidence': 'TEST_PROMOTIONAL_ECONOMICS',
    'tier_by_currency': {
      'AED': {'tier': 'Gold', 'enabled': true},
    },
    'private_reference': 'sensitive-provider-secret',
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(body, isNull);
    calls.add('$path:${query['page'] ?? ''}');
    if (pending != null) return pending!.future;
    if (deny) throw const ApiException(401, 'sensitive-provider-secret');
    if (path.endsWith('/account')) return account;
    if (path.endsWith('/redemptions/9'))
      return {
        'redemption': {'id': 9, 'points_cost': 30, 'status': 'pending'},
        'reward': {'name': 'Synthetic reward', 'name_ar': 'مكافأة تجريبية'},
        'events': [
          {
            'action': 'requested',
            'reason': '<script>literal review</script>',
            'created_at': '2026-10-28T00:00:00Z',
            'private_reference': 'sensitive-provider-secret',
          },
        ],
        'private_reference': 'sensitive-provider-secret',
      };
    final number = int.parse(query['page'] ?? '1');
    return {
      'current_page': number,
      'last_page': 2,
      'total': 26,
      'data': [
        if (path.endsWith('/ledger'))
          {
            'id': number,
            'points': -30,
            'kind': number == 1 ? 'redemption' : 'untrusted-provider-kind',
            'created_at': '2026-10-28T00:00:00Z',
            'private_reference': 'sensitive-provider-secret',
          }
        else if (path.endsWith('/rewards'))
          {
            'id': 7,
            'points_cost': 30,
            'available_stock': 2,
            'name': 'Synthetic reward',
            'name_ar': 'مكافأة تجريبية',
            'description': 'Manual test benefit',
            'description_ar': 'منفعة اختبار يدوية',
            'cash_value': false,
            'fulfillment': 'MANUAL_NON_MONETARY',
            'private_reference': 'sensitive-provider-secret',
          }
        else
          {
            'id': 9,
            'reward_id': 7,
            'points_cost': 30,
            'status': 'pending',
            'private_reference': 'sensitive-provider-secret',
          },
      ],
    };
  }
}

Widget app(LoyaltyApi api, {bool ar = false, VoidCallback? expired}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: LoyaltyView(api: api, onExpired: expired ?? () {}),
    );
Future<void> section(WidgetTester t, String label) async {
  await t.tap(find.byType(DropdownButtonFormField<String>));
  await t.pumpAndSettle();
  await t.tap(find.text(label).last);
  await t.pumpAndSettle();
}

void main() {
  testWidgets(
    'canonical noncash debt, hold and tier are displayed without raw provider fields or mutations',
    (t) async {
      final api = LoyaltyApi();
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      expect(find.text('Posted points: -10'), findsOneWidget);
      expect(find.text('AED: Gold'), findsOneWidget);
      expect(find.textContaining('Redemption is on hold'), findsOneWidget);
      expect(find.textContaining('sensitive-provider-secret'), findsNothing);
      await t.tap(find.text('Refresh'));
      await t.pumpAndSettle();
      expect(api.calls, [
        'commercial/loyalty/account:',
        'commercial/loyalty/account:',
      ]);
    },
  );
  testWidgets(
    'unexpected monetary semantics fail closed and never display a cash balance',
    (t) async {
      await t.pumpWidget(app(LoyaltyApi()..forged = true));
      await t.pumpAndSettle();
      expect(find.textContaining('Posted points:'), findsNothing);
      expect(find.text('AED: Gold'), findsNothing);
      expect(t.takeException(), isNull);
    },
  );
  testWidgets(
    'points history uses server pages and safe kind labels without currency math',
    (t) async {
      final api = LoyaltyApi();
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      await section(t, 'Points history');
      expect(find.text('Reward reservation: -30'), findsOneWidget);
      await t.ensureVisible(find.text('Next'));
      await t.tap(find.text('Next'));
      await t.pumpAndSettle();
      expect(api.calls.last, 'commercial/loyalty/ledger:2');
      expect(find.text('Points adjustment: -30'), findsOneWidget);
      expect(find.textContaining('untrusted-provider-kind'), findsNothing);
    },
  );
  testWidgets(
    'Arabic reward catalog and pending history fit390px with no invented fulfillment',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      await t.pumpWidget(app(LoyaltyApi(), ar: true));
      await t.pumpAndSettle();
      await section(t, 'كتالوج المكافآت');
      expect(find.text('مكافأة تجريبية'), findsOneWidget);
      expect(find.textContaining('النقاط المطلوبة'), findsOneWidget);
      expect(
        Directionality.of(t.element(find.text('مكافأة تجريبية'))),
        TextDirection.rtl,
      );
      expect(t.takeException(), isNull);
      await section(t, 'طلبات مكافآتي');
      expect(find.textContaining('بانتظار التسليم اليدوي'), findsOneWidget);
      expect(t.takeException(), isNull);
    },
  );
  testWidgets(
    'own redemption audit keeps pending status and literal reasons while hiding arbitrary fields',
    (t) async {
      final api = LoyaltyApi();
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      await section(t, 'My reward requests');
      await t.tap(find.textContaining('#9'));
      await t.pumpAndSettle();
      expect(api.calls.last, 'commercial/loyalty/redemptions/9:');
      expect(find.text('Pending manual fulfillment'), findsOneWidget);
      expect(
        find.textContaining('<script>literal review</script>'),
        findsOneWidget,
      );
      expect(find.textContaining('sensitive-provider-secret'), findsNothing);
    },
  );
  testWidgets('expiry clears private account and current owning session', (
    t,
  ) async {
    final api = LoyaltyApi();
    int expired = 0;
    await t.pumpWidget(app(api, expired: () => expired++));
    await t.pumpAndSettle();
    api.deny = true;
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(expired, 1);
    expect(find.textContaining('Posted points:'), findsNothing);
    expect(find.textContaining('sensitive-provider-secret'), findsNothing);
  });
  testWidgets(
    'disposed private reads cannot expire or repopulate another screen',
    (t) async {
      final api = LoyaltyApi()..pending = Completer<dynamic>();
      int expired = 0;
      await t.pumpWidget(app(api, expired: () => expired++));
      await t.pump();
      await t.pumpWidget(const MaterialApp(home: Text('new identity')));
      api.pending!.completeError(
        const ApiException(401, 'sensitive-provider-secret'),
      );
      await t.pumpAndSettle();
      expect(expired, 0);
      expect(find.text('new identity'), findsOneWidget);
      expect(t.takeException(), isNull);
    },
  );
}
