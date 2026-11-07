import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/hosted_checkout.dart';
import 'package:creabiz_ev/corporate_billing.dart';
import 'test_support.dart';
import 'hosted_checkout_test.dart' show hostedFixture;
import 'vendor_finance_test.dart' show VendorOperationStore, PausedVendorStore;

final corporateFingerprint = List.filled(64, 'a').join();
Map<String, dynamic> corporateHostedFixture({
  String status = 'open',
  bool owner = true,
  int id = 71,
}) => {
  ...hostedFixture(status: status),
  'id': id,
  'canonical_checkout_id': id,
  'scope': 'corporate',
  'invoice_id': null,
  'subscription_id': null,
  'statement_id': 31,
  'payer_id': 1,
  'user_id': 1,
  'line_fingerprint': corporateFingerprint,
  'owner': owner,
  'amount_minor': 2100,
  'currency': 'AED',
  'minor_units': 2,
  'allowed_actions': owner
      ? [
          'inspect',
          if (status == 'open' || status == 'authorized') 'cancel',
          if (status == 'authorized') 'capture',
        ]
      : [],
  'can_simulate': owner && status == 'open',
  'can_retry': owner && status == 'cancelled',
};

class CorporateHostedApi extends FixtureApi {
  Map<String, dynamic>? row;
  bool lost = false, currentOwner = true, deny = false, badSource = false;
  final requests = <Map<String, dynamic>>[];
  Completer<dynamic>? pausedSource;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    requests.add({
      'path': path,
      'method': method,
      'body': body == null ? null : {...body},
      'query': {...query},
    });
    if (deny) throw const ApiException(401, 'private-corporate-diagnostic');
    if (path == 'me') return {'id': 1, 'role': 'customer'};
    if (path == 'commercial/corporate/statements/31') {
      if (pausedSource != null) return pausedSource!.future;
      return quote();
    }
    if (method == 'POST') {
      if (path == 'commercial/corporate/hosted-checkouts') {
        if (lost) throw const ApiException(0, 'private-corporate-diagnostic');
        row = corporateHostedFixture();
      } else if (path.endsWith('/simulate')) {
        row = corporateHostedFixture(
          status: body!['outcome'] == 'authorized' ? 'authorized' : 'cancelled',
        );
      } else if (path.endsWith('/capture')) {
        row = corporateHostedFixture(status: 'captured');
      } else if (path.endsWith('/retry-preparation')) {
        row = corporateHostedFixture();
      } else if (path.endsWith('/cancel')) {
        row = corporateHostedFixture(status: 'cancelled');
      }
      return {'checkout': row};
    }
    if (path == 'commercial/corporate/hosted-checkouts')
      return {
        'data': row == null ? [] : [row],
        'current_page': 1,
        'last_page': 1,
        'total': row == null ? 0 : 1,
      };
    return {'checkout': row};
  }

  Map<String, dynamic> quote() => {
    'statement': {
      'id': 31,
      'payer_id': 1,
      'fleet_id': 8,
      'number': 'CB-CORP-TEST',
      'total_minor': badSource ? 2000 : 2100,
      'minor_units': 2,
      'currency': 'AED',
      'due_at': '2026-11-30T00:00:00Z',
    },
    'lines': [
      {
        'invoice_id': 13,
        'invoice_number': 'SIM-CORP-13',
        'cost_center_name': 'Test operations',
      },
    ],
    'can_collect_hosted': currentOwner,
    'line_fingerprint': corporateFingerprint,
    'real_payment_processed': false,
    'receipt': null,
    'state': 'issued',
  };
}

Future<void> mountCorporate(
  WidgetTester tester,
  CorporateHostedApi api, {
  VendorOperationStore? store,
  bool ar = false,
  VoidCallback? expired,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: ThemeData(brightness: ar ? Brightness.dark : Brightness.light),
      home: HostedCheckoutView(
        api: api,
        onExpired: expired ?? () {},
        scope: 'corporate',
        statementId: 31,
        store: store ?? VendorOperationStore(),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> tapKey(WidgetTester tester, String key) async {
  final f = find.byKey(Key(key));
  await tester.scrollUntilVisible(
    f,
    150,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.pumpAndSettle();
  expect(f.hitTestable(), findsOneWidget);
  await tester.tap(f);
  await tester.pumpAndSettle();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUp(() {
    HostedPaymentReturnBridge.started = false;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
          HostedPaymentReturnBridge.channel,
          (call) async => false,
        );
  });
  test(
    'corporate opt-in is strict and default invoice parser remains unchanged',
    () {
      final row = corporateHostedFixture();
      expect(() => HostedCheckoutRecord(row), throwsFormatException);
      expect(
        HostedCheckoutRecord(row, corporate: true).source['statement_id'],
        31,
      );
      for (final change in <void Function(Map<String, dynamic>)>[
        (r) => r['invoice_id'] = 3,
        (r) => r['subscription_id'] = 4,
        (r) => r['payer_id'] = 2,
        (r) => r['line_fingerprint'] = 'invalid',
        (r) => r['canonical_checkout_id'] = 4,
        (r) => r['scope'] = 'charging',
        (r) => r['owner'] = 'true',
      ]) {
        final bad = corporateHostedFixture();
        change(bad);
        expect(
          () => HostedCheckoutRecord(bad, corporate: true),
          throwsFormatException,
        );
      }
    },
  );
  testWidgets(
    'statement amount and durable intent precede any corporate POST',
    (tester) async {
      final api = CorporateHostedApi(), store = PausedVendorStore();
      await mountCorporate(tester, api, store: store);
      expect(find.textContaining('21.00 AED'), findsOneWidget);
      await tapKey(tester, 'hosted-consent');
      await tester.ensureVisible(find.byKey(const Key('hosted-create')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('hosted-create')));
      await tester.pump();
      await store.entered.future;
      expect(api.requests.where((r) => r['method'] == 'POST'), isEmpty);
      store.release.complete();
      await tester.pumpAndSettle();
      final body = api.requests.singleWhere(
        (r) => r['method'] == 'POST',
      )['body'];
      expect(body.keys.toSet(), {'statement_id', 'consent', 'idempotency_key'});
      expect(body['statement_id'], 31);
      expect(
        store.values.keys.single,
        contains(':corporate:create:statement:31'),
      );
    },
  );
  testWidgets(
    'restart recovers exact original corporate intent without automatic write',
    (tester) async {
      final api = CorporateHostedApi()..lost = true,
          store = VendorOperationStore();
      await mountCorporate(tester, api, store: store);
      await tapKey(tester, 'hosted-consent');
      await tapKey(tester, 'hosted-create');
      final original = api.requests.singleWhere(
        (r) => r['method'] == 'POST',
      )['body'];
      await tester.pumpWidget(const SizedBox());
      await tester.pumpAndSettle();
      api.lost = false;
      await mountCorporate(tester, api, store: store);
      expect(api.requests.where((r) => r['method'] == 'POST').length, 1);
      await tapKey(tester, 'hosted-consent');
      await tapKey(tester, 'hosted-create');
      expect(
        api.requests.where((r) => r['method'] == 'POST').last['body'],
        original,
      );
    },
  );
  testWidgets(
    'past payer without current ownership receives no creation or financial controls',
    (tester) async {
      final api = CorporateHostedApi()..currentOwner = false;
      await mountCorporate(tester, api);
      expect(find.byKey(const Key('hosted-create')), findsNothing);
      await tester.pumpWidget(const SizedBox());
      await tester.pumpAndSettle();
      api.row = corporateHostedFixture(status: 'authorized', owner: false);
      await mountCorporate(tester, api);
      expect(find.byKey(const Key('hosted-capture')), findsNothing);
      expect(find.byKey(const Key('hosted-cancel')), findsNothing);
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
    },
  );
  testWidgets('conflicting statement amount or line snapshot clears controls', (
    tester,
  ) async {
    final api = CorporateHostedApi()
      ..row = corporateHostedFixture()
      ..badSource = true;
    await mountCorporate(tester, api);
    expect(find.byKey(const Key('hosted-error')), findsOneWidget);
    expect(find.byKey(const Key('hosted-create')), findsNothing);
    expect(api.requests.every((r) => r['method'] == 'GET'), true);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpAndSettle();
    api.badSource = false;
    api.row!['line_fingerprint'] = List.filled(64, 'b').join();
    await mountCorporate(tester, api);
    expect(find.byKey(const Key('hosted-error')), findsOneWidget);
  });
  testWidgets(
    'mock authorization and capture use corporate endpoint and original ID',
    (tester) async {
      final api = CorporateHostedApi()..row = corporateHostedFixture();
      await mountCorporate(tester, api);
      await tester.scrollUntilVisible(
        find.text('Simulate authorization'),
        150,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Simulate authorization'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.row!['status'], 'authorized');
      await tapKey(tester, 'hosted-capture');
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.row!['status'], 'captured');
      expect(
        api.requests.where((r) => r['method'] == 'POST').map((r) => r['path']),
        [
          'commercial/corporate/hosted-checkouts/71/simulate',
          'commercial/corporate/hosted-checkouts/71/capture',
        ],
      );
    },
  );
  testWidgets(
    'corporate capture intent cannot erase an unrelated invoice capture identity',
    (tester) async {
      final api = CorporateHostedApi()
        ..row = corporateHostedFixture(status: 'authorized');
      final store = VendorOperationStore();
      const invoiceScope = 'http://127.0.0.1:8000:1:hosted:71:capture';
      await store.write(invoiceScope, {
        'key': 'original-uncertain-invoice',
        'body': {},
      });
      await mountCorporate(tester, api, store: store);
      await tapKey(tester, 'hosted-capture');
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.row!['status'], 'captured');
      expect(
        (await store.read(invoiceScope))?['key'],
        'original-uncertain-invoice',
      );
    },
  );
  testWidgets(
    'foreground and native return signal read source only without capture',
    (tester) async {
      final api = CorporateHostedApi()..row = corporateHostedFixture();
      await mountCorporate(tester, api);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.textContaining('AED'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      HostedPaymentReturnBridge.changes.signal();
      await tester.pumpAndSettle();
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
      expect(api.row!['status'], 'open');
    },
  );
  testWidgets('late source quote cannot reappear after background', (
    tester,
  ) async {
    final api = CorporateHostedApi();
    await mountCorporate(tester, api);
    api.pausedSource = Completer<dynamic>();
    await tester.tap(find.byKey(const Key('hosted-refresh')));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    api.pausedSource!.complete(api.quote());
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('hosted-create')), findsNothing);
    expect(find.textContaining('AED'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
  });
  testWidgets(
    'corporate source page exposes statement and private document navigation',
    (tester) async {
      final api = CorporateHostedApi();
      await tester.pumpWidget(
        MaterialApp(
          home: CorporateStatementView(api: api, id: 31, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('corporate-hosted-checkout')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('corporate-statement-document')),
        findsOneWidget,
      );
    },
  );
  testWidgets('Arabic dark corporate collection fits390px and remains RTL', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final api = CorporateHostedApi()
      ..row = corporateHostedFixture(status: 'authorized');
    await mountCorporate(tester, api, ar: true);
    expect(
      Directionality.of(tester.element(find.text('التحصيل المستضاف للشركة'))),
      TextDirection.rtl,
    );
    await tester.scrollUntilVisible(
      find.byKey(const Key('hosted-capture')),
      150,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
}
