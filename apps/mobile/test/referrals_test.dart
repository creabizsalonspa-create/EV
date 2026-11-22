import 'package:creabiz_ev/financial_operation_store.dart';
import 'package:creabiz_ev/referrals.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

const invitation = 'REF-A0123456789ABCDE0123';

class ReferralStore implements FinancialOperationStore {
  final entries = <String, Map<String, dynamic>>{};

  @override
  Future<Map<String, dynamic>?> read(String scope) async => entries[scope];

  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    entries[scope] = value;
  }

  @override
  Future<void> remove(String scope) async {
    entries.remove(scope);
  }
}

class ReferralApi extends FixtureApi {
  ReferralApi(this.owner);

  final int owner;
  final version = List.filled(64, 'a').join();
  final writes = <Map<String, dynamic>>[];
  bool hideProgramme = false;
  Map<String, dynamic>? accepted;

  Map<String, dynamic> page(List<Map<String, dynamic>> data) => {
    'data': data,
    'total': data.length,
    'current_page': 1,
    'last_page': 1,
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
        'email_verified_at': '2026-01-01',
      };
    }
    if (path == 'commercial/referral-programs') {
      return page([
        if (!hideProgramme)
          {
            'id': 9,
            'name': 'Test programme',
            'name_ar': 'برنامج تجريبي',
            'terms': 'Exact published terms',
            'terms_ar': 'الشروط المنشورة',
            'terms_version': version,
            'active': true,
            'cash_value': false,
            'money_movement': false,
            'delivery_verified': false,
            'fulfillment': 'MANUAL_NON_MONETARY',
            'activation_mode': 'sandbox',
          },
      ]);
    }
    if (path == 'commercial/referrals/account') {
      if (query.isEmpty) return {'referral': accepted, 'money_movement': false};
      expect(query['program_id'], '9');
      return {
        'program_id': 9,
        'money_movement': false,
        'can_accept': accepted == null,
        'invited_count': 0,
        'qualified_count': 0,
        'own_code': null,
        'referral': accepted,
      };
    }
    if (path == 'commercial/referrals/rewards') {
      return page([
        if (accepted?['status'] == 'qualified')
          {
            'id': 12,
            'reward_name': 'Test reward',
            'reward_name_ar': 'مكافأة تجريبية',
            'points_cost': 0,
            'cash_value': false,
            'delivery_verified': false,
            'source': 'REFERRAL',
            'source_currently_eligible': false,
            'fulfillment': 'MANUAL_NON_MONETARY',
            'status': 'fulfilled',
          },
      ]);
    }
    expect(method, 'POST');
    final sent = body!;
    writes.add(sent);
    if (path.endsWith('/accept')) {
      expect(sent['terms_version'], version);
      expect(sent['consent'], true);
      expect(sent['code'], invitation);
      accepted = {
        'id': 4,
        'program_id': 9,
        'status': 'accepted',
        'cash_value': false,
        'money_movement': false,
        'delivery_verified': false,
      };
      if (writes.length == 1) throw StateError('Lost response after commit');
    } else if (path.endsWith('/qualify')) {
      expect(sent['invoice_id'], 18);
      accepted!['status'] = 'qualified';
    } else {
      expect(path, 'commercial/referrals/withdraw');
      expect(sent['reason'], 'Changed mind');
      accepted!['status'] = 'withdrawn';
    }
    return {'referral': accepted};
  }
}

Widget referralApp(
  ReferralApi api,
  ReferralStore store,
  int instance, {
  bool ar = false,
}) => MaterialApp(
  key: ValueKey(instance),
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: ReferralView(api: api, store: store, onExpired: () {}),
);

Future<void> tap(WidgetTester tester, String label) async {
  final target = find.text(label);
  await tester.ensureVisible(target);
  await tester.tap(target);
  await tester.pumpAndSettle();
}

Finder input(String label) => find.widgetWithText(TextField, label);

void main() {
  testWidgets(
    'explicit terms consent and exact uncertain retry belong to the original account',
    (tester) async {
      tester.view.physicalSize = const Size(390, 1100);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final owner = ReferralApi(1);
      final foreign = ReferralApi(2);
      final store = ReferralStore();
      await tester.pumpWidget(referralApp(owner, store, 1, ar: true));
      await tester.pumpAndSettle();
      await tap(tester, 'برنامج تجريبي');
      expect(find.text('الشروط المنشورة'), findsOneWidget);
      expect(
        Directionality.of(tester.element(find.text('الشروط المنشورة'))),
        TextDirection.rtl,
      );
      await tester.enterText(input('رمز الإحالة'), invitation.toLowerCase());
      await tester.pump();
      final accept = find.widgetWithText(FilledButton, 'قبول الإحالة');
      expect(tester.widget<FilledButton>(accept).onPressed, isNull);
      await tap(tester, 'أوافق على شروط البرنامج المعروضة أعلاه.');
      await tap(tester, 'قبول الإحالة');
      expect(owner.writes, hasLength(1));
      expect(store.entries, hasLength(1));

      await tester.pumpWidget(referralApp(foreign, store, 2));
      await tester.pumpAndSettle();
      expect(find.text('Retry saved request'), findsNothing);
      expect(find.text('My referral status'), findsNothing);
      expect(foreign.writes, isEmpty);

      await tester.pumpWidget(referralApp(owner, store, 3));
      await tester.pumpAndSettle();
      expect(find.text('Accepted'), findsOneWidget);
      expect(owner.writes, hasLength(1));
      await tap(tester, 'Retry saved request');
      expect(owner.writes, hasLength(2));
      expect(owner.writes.last, owner.writes.first);
      expect(store.entries, isEmpty);
      await tap(tester, 'Test programme');
      await tester.enterText(input('First paid charging invoice ID'), '18');
      await tester.pump();
      await tap(tester, 'Check invoice eligibility');
      expect(find.text('Qualified'), findsOneWidget);
      await tap(tester, 'My referral rewards');
      expect(find.text('Test reward'), findsOneWidget);
      expect(
        find.text('Manual fulfillment recorded · Source no longer eligible'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'owner can see their referral status when the catalogue no longer lists it',
    (tester) async {
      final api = ReferralApi(1)..hideProgramme = true;
      api.accepted = {
        'id': 4,
        'program_id': 9,
        'status': 'expired',
        'cash_value': false,
        'money_movement': false,
        'delivery_verified': false,
      };
      await tester.pumpWidget(referralApp(api, ReferralStore(), 1));
      await tester.pumpAndSettle();
      expect(find.text('No referral programmes.'), findsOneWidget);
      expect(find.text('My referral status'), findsOneWidget);
      expect(find.text('Expired'), findsOneWidget);
      expect(api.writes, isEmpty);
    },
  );
}
