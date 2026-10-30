import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/corporate_billing.dart';
import 'test_support.dart';
import 'vendor_finance_test.dart' show VendorOperationStore;

class CorporateApi extends FixtureApi {
  bool manager = true, lost = false, deny = false;
  int posts = 0;
  final bodies = <Map<String, dynamic>>[];
  Map<String, dynamic> get agreement => {
    'id': 6,
    'fleet_id': 4,
    'legal_name': 'Synthetic company',
    'terms': 'Literal <script> published terms',
    'currency': 'KWD',
    'minor_units': 3,
    'credit_limit_minor': 100000,
    'acceptance': null,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (deny) throw const ApiException(401, 'private-corporate-secret');
    if (path == 'me') return {'id': 1, 'role': 'customer'};
    if (method == 'POST') {
      posts++;
      bodies.add({...body!});
      if (lost) throw const ApiException(0, 'private-corporate-secret');
      if (path.endsWith('/accept')) return {'agreement': agreement};
      if (path.endsWith('/claims'))
        return {
          'claim': {
            'id': 7,
            'agreement_id': 6,
            'invoice_id': body['invoice_id'],
            'cost_center_id': body['cost_center_id'],
          },
        };
      if (path.endsWith('/review'))
        return {
          'decision': {
            'id': 10,
            'claim_id': 7,
            'decision': body['decision'],
            'reason': body['reason'],
          },
        };
      return {
        'statement': {'id': 11, 'agreement_id': 6},
      };
    }
    if (path == 'commercial/corporate')
      return {
        'agreements': [agreement],
        'can_manage': manager,
        'real_payment_enabled': false,
      };
    if (path == 'fleets/4')
      return {
        'cost_centers': [
          {'id': 3, 'code': 'ENG', 'name': 'Engineering'},
        ],
      };
    if (path == 'fleets')
      return {
        'data': [
          {'id': 4, 'name': 'Synthetic organization'},
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    if (path == 'finance/invoices')
      return {
        'data': [
          {'id': 9, 'user_id': 1, 'number': 'MY-OWN-INVOICE'},
        ],
        'current_page': 1,
        'last_page': 2,
        'total': 26,
      };
    return {
      'data': [
        {
          'id': 7,
          'agreement_id': 6,
          'currency': 'KWD',
          'minor_units': 3,
          'amount_minor': 1250,
          'cost_center_name': 'Engineering',
          'decision': null,
          'statement_id': null,
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

Widget corporateApp(Widget home, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: home,
);
Future<void> corporateConfirm(WidgetTester t) async {
  await t.ensureVisible(find.byType(CheckboxListTile).last);
  await t.tap(find.byType(CheckboxListTile).last);
  await t.pumpAndSettle();
  await t.ensureVisible(find.widgetWithText(FilledButton, 'Confirm'));
  await t.tap(find.widgetWithText(FilledButton, 'Confirm'));
  await t.pumpAndSettle();
}

void main() {
  testWidgets(
    'company membership hides owner acceptance and company-wide statements from drivers',
    (t) async {
      final api = CorporateApi()..manager = false;
      await t.pumpWidget(
        corporateApp(CorporateBillingView(api: api, onExpired: () {})),
      );
      await t.pumpAndSettle();
      await t.tap(find.text('Synthetic organization'));
      await t.pumpAndSettle();
      expect(
        find.text('Literal <script> published terms', findRichText: true),
        findsNothing,
      );
      expect(
        find.textContaining('Literal <script> published terms'),
        findsOneWidget,
      );
      expect(find.text('Accept published agreement'), findsNothing);
      await t.tap(find.text('Invoice claims'));
      await t.pumpAndSettle();
      expect(find.text('Review claim'), findsNothing);
      expect(find.text('Company statements'), findsNothing);
      expect(api.posts, 0);
    },
  );
  testWidgets('published company agreement is never accepted automatically', (
    t,
  ) async {
    final api = CorporateApi();
    await t.pumpWidget(
      corporateApp(
        CorporateActionView(
          api: api,
          onExpired: () {},
          action: 'accept',
          agreementId: 6,
          fleetId: 4,
          currency: 'KWD',
          store: VendorOperationStore(),
        ),
      ),
    );
    await t.pumpAndSettle();
    expect(api.posts, 0);
    await corporateConfirm(t);
    expect(api.bodies.single['consent'], true);
    expect(find.textContaining('Server confirmed'), findsOneWidget);
  });
  testWidgets(
    'own invoice and cost-center claim freezes exact scope across unknown results',
    (t) async {
      final api = CorporateApi()..lost = true;
      final store = VendorOperationStore();
      Widget view() => corporateApp(
        CorporateActionView(
          api: api,
          onExpired: () {},
          action: 'claim',
          agreementId: 6,
          fleetId: 4,
          currency: 'KWD',
          store: store,
        ),
      );
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      await t.tap(find.byType(DropdownButtonFormField<int>).first);
      await t.pumpAndSettle();
      await t.tap(find.text('MY-OWN-INVOICE').last);
      await t.pumpAndSettle();
      await t.tap(find.byType(DropdownButtonFormField<int>).last);
      await t.pumpAndSettle();
      await t.tap(find.text('ENG · Engineering').last);
      await t.pumpAndSettle();
      await corporateConfirm(t);
      expect(api.bodies.single['invoice_id'], 9);
      expect(api.bodies.single['cost_center_id'], 3);
      await t.pumpWidget(const MaterialApp(home: Text('closed')));
      await t.pumpWidget(view());
      await t.pumpAndSettle();
      expect(api.posts, 1);
      api.lost = false;
      await corporateConfirm(t);
      expect(api.bodies[1], api.bodies[0]);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'statement preparation sends selected source claims and exact Arabic billing month without client totals',
    (t) async {
      final api = CorporateApi();
      await t.pumpWidget(
        corporateApp(
          CorporateActionView(
            api: api,
            onExpired: () {},
            action: 'issue',
            agreementId: 6,
            fleetId: 4,
            currency: 'KWD',
            claimIds: [7],
            store: VendorOperationStore(),
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.enterText(find.byType(TextField), '٢٠٢٦-١٠');
      await corporateConfirm(t);
      expect(api.bodies.single['period'], '2026-10');
      expect(api.bodies.single['claim_ids'], [7]);
      expect(api.bodies.single.containsKey('total_minor'), false);
    },
  );
  testWidgets(
    'Arabic corporate agreement disclosures fit390px with no actual payment promise',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      await t.pumpWidget(
        corporateApp(
          CorporateBillingView(api: CorporateApi(), onExpired: () {}),
          ar: true,
        ),
      );
      await t.pumpAndSettle();
      await t.tap(find.text('Synthetic organization'));
      await t.pumpAndSettle();
      expect(find.textContaining('حد الائتمان المنشور'), findsOneWidget);
      expect(t.takeException(), isNull);
    },
  );
  testWidgets(
    'corporate access expiry clears company details and does not show raw diagnostics',
    (t) async {
      final api = CorporateApi();
      int expired = 0;
      await t.pumpWidget(
        corporateApp(
          CorporateBillingView(api: api, onExpired: () => expired++),
        ),
      );
      await t.pumpAndSettle();
      await t.tap(find.text('Synthetic organization'));
      await t.pumpAndSettle();
      api.deny = true;
      await t.tap(find.text('Refresh'));
      await t.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Synthetic company'), findsNothing);
      expect(find.textContaining('private-corporate-secret'), findsNothing);
    },
  );
}
