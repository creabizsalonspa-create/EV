import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_finance.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'test_support.dart';

class VendorOperationStore implements FinancialOperationStore {
  final values = <String, String>{};
  @override
  Future<Map<String, dynamic>?> read(String k) async => values[k] == null
      ? null
      : Map<String, dynamic>.from(jsonDecode(values[k]!) as Map);
  @override
  Future<void> write(String k, Map<String, dynamic> v) async {
    values[k] = jsonEncode(v);
  }

  @override
  Future<void> remove(String k) async {
    values.remove(k);
  }
}

class VendorFinancialApi extends FixtureApi {
  bool lost = false, deny = false, untrusted = false;
  int posts = 0;
  final bodies = <Map<String, dynamic>>[];
  Map<String, dynamic> get account => {
    'id': 7,
    'vendor_id': 3,
    'provider': 'mock',
    'currency': 'KWD',
    'minor_units': 3,
    'status': 'pending_consent',
    'required_consent_version': 'published-version-2',
    'consent_version': null,
    'evidence': 'MOCK_PAYOUT_ACCOUNT',
    'real_money_moved': false,
    'bank_reference': 'private-bank-secret',
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (deny) throw const ApiException(401, 'private-bank-secret');
    if (path == 'me') return {'id': 3, 'role': 'vendor'};
    if (method == 'POST') {
      posts++;
      bodies.add({...body!});
      if (lost) throw const ApiException(0, 'private-bank-secret');
      if (path.endsWith('/onboarding'))
        return {
          'url': untrusted
              ? 'https://connect.stripe.com.evil.test/onboard'
              : 'https://connect.stripe.com/test-only',
          'expires_at': DateTime.now().millisecondsSinceEpoch ~/ 1000 + 300,
          'evidence': 'STRIPE_TEST_HOSTED_ONBOARDING',
          'real_money_moved': false,
        };
      return {
        'account': {
          ...account,
          'status': path.endsWith('/revoke')
              ? 'revoked'
              : 'pending_verification',
          'consent_version': body['consent_version'],
        },
      };
    }
    if (path == 'finance/report')
      return {
        'real_money_movement': false,
        'groups': [
          {
            'currency': 'KWD',
            'minor_units': 3,
            'evidence': 'SIMULATED',
            'invoiced_minor': 1250,
            'paid_minor': 1250,
            'refunded_minor': 0,
            'outstanding_minor': 0,
            'net_vendor_allocated_minor': -5,
            'net_commission_allocated_minor': 10,
          },
        ],
      };
    return {
      'data': [account],
      'current_page': 1,
      'last_page': 2,
      'total': 26,
    };
  }
}

Widget vendorApp(Widget child, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: child,
);
Future<void> recipientConfirm(WidgetTester t, String label) async {
  await t.tap(find.byType(CheckboxListTile));
  await t.pumpAndSettle();
  await t.ensureVisible(find.widgetWithText(FilledButton, label));
  await t.tap(find.widgetWithText(FilledButton, label));
  await t.pumpAndSettle();
}

class PayableApi extends FixtureApi {
  bool lost = false;
  int writes = 0;
  final bodies = <Map<String, dynamic>>[];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') return {'id': 3, 'role': 'vendor'};
    if (method == 'POST') {
      writes++;
      bodies.add({...body!});
      if (lost) throw const ApiException(0, 'private-payable-details');
      return {
        'id': 7,
        'vendor_id': 3,
        'currency': 'AED',
        'minor_units': 2,
        'amount_minor': 900,
        'evidence': 'SIMULATED',
        'state': 'pending',
      };
    }
    return {
      'vendor_id': 3,
      'currency': 'AED',
      'minor_units': 2,
      'available_minor': writes == 0 ? 900 : 0,
      'evidence': 'SIMULATED',
      'real_payout_enabled': false,
      'eligible_to_request': writes == 0,
    };
  }
}

void main() {
  testWidgets(
    'uncertain settlement request survives refresh and restart with the original identity even after eligibility changes',
    (tester) async {
      final api = PayableApi()..lost = true, store = VendorOperationStore();
      Widget screen() => MaterialApp(
        home: VendorPayableView(
          api: api,
          onExpired: () {},
          currency: 'AED',
          store: store,
        ),
      );
      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();
      expect(api.writes, 0);
      Future<void> send() async {
        await tester.ensureVisible(find.byType(CheckboxListTile));
        await tester.tap(find.byType(CheckboxListTile));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await tester.pumpAndSettle();
      }

      await send();
      final first = api.bodies.single;
      expect(store.values.length, 1);
      await tester.pumpWidget(const SizedBox());
      await tester.pumpWidget(screen());
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.byType(CheckboxListTile), findsOneWidget);
      api.lost = false;
      await send();
      expect(api.bodies.last, first);
      expect(store.values, isEmpty);
      expect(find.textContaining('private-payable-details'), findsNothing);
    },
  );

  testWidgets(
    'vendor earnings keep three-decimal and signed allocations distinct from bank proof',
    (t) async {
      await t.pumpWidget(
        vendorApp(
          VendorFinanceView(api: VendorFinancialApi(), onExpired: () {}),
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('Invoiced: 1.250 KWD'), findsOneWidget);
      expect(
        find.text('Net vendor invoice allocation: -0.005 KWD'),
        findsOneWidget,
      );
      expect(find.textContaining('private-bank-secret'), findsNothing);
    },
  );
  testWidgets(
    'versioned consent resumes one saved identity and never configures or verifies a bank',
    (t) async {
      final api = VendorFinancialApi()..lost = true;
      final store = VendorOperationStore();
      Widget view() => vendorApp(
        PayoutRecipientActionView(
          api: api,
          onExpired: () {},
          account: api.account,
          action: 'consent',
          store: store,
        ),
      );
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      expect(api.posts, 0);
      await recipientConfirm(t, 'Review recipient consent');
      expect(api.bodies.single['consent_version'], 'published-version-2');
      await t.pumpWidget(const MaterialApp(home: Text('closed')));
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      expect(api.posts, 1);
      api.lost = false;
      await recipientConfirm(t, 'Review recipient consent');
      expect(api.bodies[1], api.bodies[0]);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'hosted onboarding does not open automatically and refuses deceptive provider hosts',
    (t) async {
      final api = VendorFinancialApi()..untrusted = true;
      final store = VendorOperationStore();
      var opened = 0;
      await t.pumpWidget(
        vendorApp(
          PayoutRecipientActionView(
            api: api,
            onExpired: () {},
            account: {...api.account, 'provider': 'stripe_test'},
            action: 'onboarding',
            store: store,
            launch: (uri) async {
              opened++;
              return true;
            },
          ),
        ),
      );
      await t.pumpAndSettle();
      await recipientConfirm(t, 'Hosted recipient enrollment');
      expect(opened, 0);
      expect(find.text('Open Stripe TEST enrollment'), findsNothing);
      expect(store.values.length, 1);
    },
  );
  testWidgets(
    'trusted TEST onboarding requires a second explicit external-navigation action',
    (t) async {
      final api = VendorFinancialApi();
      var opened = 0;
      await t.pumpWidget(
        vendorApp(
          PayoutRecipientActionView(
            api: api,
            onExpired: () {},
            account: {...api.account, 'provider': 'stripe_test'},
            action: 'onboarding',
            store: VendorOperationStore(),
            launch: (uri) async {
              expect(uri.host, 'connect.stripe.com');
              opened++;
              return true;
            },
          ),
        ),
      );
      await t.pumpAndSettle();
      await recipientConfirm(t, 'Hosted recipient enrollment');
      expect(opened, 0);
      await t.tap(find.text('Open Stripe TEST enrollment'));
      await t.pumpAndSettle();
      expect(opened, 1);
    },
  );
  testWidgets(
    'Arabic earnings preserve exact precision at390px without raw bank fields',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      await t.pumpWidget(
        vendorApp(
          VendorFinanceView(api: VendorFinancialApi(), onExpired: () {}),
          ar: true,
        ),
      );
      await t.pumpAndSettle();
      expect(find.textContaining('١٫٢٥٠ KWD'), findsWidgets);
      expect(t.takeException(), isNull);
    },
  );
  testWidgets(
    'expired vendor status removes private earnings and expires only current session',
    (t) async {
      final api = VendorFinancialApi();
      var expired = 0;
      await t.pumpWidget(
        vendorApp(VendorFinanceView(api: api, onExpired: () => expired++)),
      );
      await t.pumpAndSettle();
      api.deny = true;
      await t.tap(find.text('Refresh'));
      await t.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Invoiced: 1.250 KWD'), findsNothing);
    },
  );
}
