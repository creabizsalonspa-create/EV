import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/hosted_checkout.dart';
import 'test_support.dart';
import 'vendor_finance_test.dart' show VendorOperationStore, PausedVendorStore;

Map<String, dynamic> hostedFixture({String status = 'open', bool own = true}) =>
    {
      'id': 7,
      'reference': 'b67c7ce6-e750-4a6f-bb40-a4016af90a43',
      'user_id': own ? 1 : 2,
      'scope': 'charging',
      'invoice_id': 3,
      'subscription_id': null,
      'canonical_checkout_id': 9,
      'status': status,
      'canonical_status': status == 'open' ? 'pending' : status,
      'amount_minor': 1234,
      'currency': 'KWD',
      'minor_units': 3,
      'expires_at': DateTime.now()
          .toUtc()
          .add(const Duration(hours: 1))
          .toIso8601String(),
      'evidence': 'MOCK_HOSTED',
      'real_money_moved': false,
      'external_provider_verified': false,
      'hosted_url': null,
      'allowed_actions': own
          ? [
              'inspect',
              if (status == 'open' || status == 'authorized') 'cancel',
              if (status == 'authorized') 'capture',
            ]
          : [],
      'can_simulate': own && status == 'open',
      'can_retry': own && status == 'cancelled',
    };

class HostedApi extends FixtureApi {
  Map<String, dynamic>? row;
  bool lose = false, deny = false;
  final requests = <Map<String, dynamic>>[];
  Completer<dynamic>? paused;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    requests.add({
      'path': path,
      'method': method,
      'body': body == null ? null : {...body},
      'query': {...query},
    });
    if (deny) throw const ApiException(401, 'private-payment-diagnostic');
    if (path == 'me') return {'id': 1, 'role': 'customer'};
    if (method == 'POST') {
      if (path == 'commercial/hosted-checkouts') {
        if (lose) throw const ApiException(0, 'private-payment-diagnostic');
        row = hostedFixture();
      } else if (path.endsWith('/retry-preparation')) {
        row = hostedFixture();
      } else if (path.endsWith('/simulate')) {
        row = hostedFixture(
          status: body!['outcome'] == 'authorized' ? 'authorized' : 'cancelled',
        );
      } else if (path.endsWith('/capture')) {
        row = hostedFixture(status: 'captured');
      } else if (path.endsWith('/cancel')) {
        row = hostedFixture(status: 'cancelled');
      }
      return {'checkout': row};
    }
    if (path == 'commercial/hosted-checkouts')
      return {
        'data': row == null ? [] : [row],
        'current_page': int.parse(query['page'] ?? '1'),
        'last_page': 1,
        'total': row == null ? 0 : 1,
      };
    if (paused != null) return paused!.future;
    return {'checkout': row};
  }
}

Future<void> mount(
  WidgetTester tester,
  HostedApi api, {
  VendorOperationStore? store,
  bool ar = false,
  int? checkoutId,
  int? sourceOwnerId,
  Future<bool> Function(Uri)? launcher,
  VoidCallback? onExpired,
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
        onExpired: onExpired ?? () {},
        scope: checkoutId == null ? 'charging' : null,
        invoiceId: checkoutId == null ? 3 : null,
        checkoutId: checkoutId,
        sourceOwnerId: sourceOwnerId,
        store: store ?? VendorOperationStore(),
        openProvider: launcher,
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> tap(WidgetTester tester, String key) async {
  final finder = find.byKey(Key(key));
  await tester.scrollUntilVisible(
    finder,
    150,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.pumpAndSettle();
  expect(finder.hitTestable(), findsOneWidget);
  await tester.tap(finder);
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
    'Stripe URL accepts actual opaque fragment but rejects confused destinations and query authority',
    () {
      expect(
        approvedHostedPaymentUrl(
          Uri.parse(
            'https://checkout.stripe.com/c/pay/cs_test_Ab123#fidkdWxOpaque',
          ),
        ),
        true,
      );
      for (final u in [
        'http://checkout.stripe.com/c/pay/cs_test_Ab123',
        'https://checkout.stripe.com.evil.test/c/pay/cs_test_Ab123',
        'https://user@checkout.stripe.com/c/pay/cs_test_Ab123',
        'https://checkout.stripe.com:444/c/pay/cs_test_Ab123',
        'https://checkout.stripe.com/c/pay/cs_live_Ab123',
        'https://checkout.stripe.com/c/pay/cs_test_Ab123?success=1',
      ])
        expect(approvedHostedPaymentUrl(Uri.parse(u)), false, reason: u);
    },
  );
  test(
    'source contract rejects live claims, inexact amounts, wrong scope and foreign PSP redirect',
    () {
      for (final change in <void Function(Map<String, dynamic>)>[
        (r) => r['real_money_moved'] = true,
        (r) => r['external_provider_verified'] = true,
        (r) => r['status'] = 'captured',
        (r) => r['amount_minor'] = 1.5,
        (r) => r['minor_units'] = 4,
        (r) => r['scope'] = 'corporate',
        (r) => r['allowed_actions'] = ['withdraw'],
        (r) => r['hosted_url'] = 'https://evil.test',
      ]) {
        final row = hostedFixture();
        change(row);
        expect(() => HostedCheckoutRecord(row), throwsFormatException);
      }
    },
  );
  testWidgets(
    'creation requires explicit consent and privately stores exact intent before any POST',
    (tester) async {
      final api = HostedApi(), store = PausedVendorStore();
      await mount(tester, api, store: store);
      expect(
        tester
            .widget<FilledButton>(find.byKey(const Key('hosted-create')))
            .onPressed,
        isNull,
      );
      await tap(tester, 'hosted-consent');
      await tester.ensureVisible(find.byKey(const Key('hosted-create')));
      await tester.tap(find.byKey(const Key('hosted-create')));
      await tester.pump();
      await store.entered.future;
      expect(api.requests.where((r) => r['method'] == 'POST'), isEmpty);
      store.release.complete();
      await tester.pumpAndSettle();
      final post = api.requests.singleWhere((r) => r['method'] == 'POST');
      expect(post['body']['invoice_id'], 3);
      expect(post['body']['consent'], true);
      expect(post['body'].containsKey('amount_minor'), false);
      expect(store.values, isNotEmpty);
    },
  );
  testWidgets(
    'lost creation response survives restart using original payload/key without automatic replay',
    (tester) async {
      final api = HostedApi()..lose = true, store = VendorOperationStore();
      await mount(tester, api, store: store);
      await tap(tester, 'hosted-consent');
      await tap(tester, 'hosted-create');
      final first = api.requests.singleWhere(
        (r) => r['method'] == 'POST',
      )['body'];
      expect(find.textContaining('private-payment-diagnostic'), findsNothing);
      await tester.pumpWidget(const SizedBox());
      await tester.pumpAndSettle();
      api.lose = false;
      await mount(tester, api, store: store);
      expect(api.requests.where((r) => r['method'] == 'POST').length, 1);
      await tap(tester, 'hosted-consent');
      await tap(tester, 'hosted-create');
      final posts = api.requests.where((r) => r['method'] == 'POST').toList();
      expect(posts[1]['body'], first);
    },
  );
  testWidgets(
    'explicit mock authorization stays unpaid until original capture confirmed',
    (tester) async {
      final api = HostedApi()..row = hostedFixture();
      await mount(tester, api);
      await tester.scrollUntilVisible(
        find.text('Simulate authorization'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(find.text('Simulate authorization').hitTestable(), findsOneWidget);
      await tester.tap(find.text('Simulate authorization'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.row!['status'], 'authorized');
      expect(api.requests.any((r) => r['path'].endsWith('/capture')), false);
      await tap(tester, 'hosted-capture');
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.row!['status'], 'captured');
    },
  );
  testWidgets(
    'foreground return performs GET only and never confirms payment from wake-up',
    (tester) async {
      final api = HostedApi()..row = hostedFixture();
      await mount(tester, api);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.textContaining('KWD'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
      expect(api.row!['status'], 'open');
      HostedPaymentReturnBridge.changes.signal();
      await tester.pumpAndSettle();
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
    },
  );
  testWidgets(
    'untrusted return method arguments cannot select a payment or confirm success',
    (tester) async {
      final api = HostedApi()..row = hostedFixture();
      await mount(tester, api);
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(
            HostedPaymentReturnBridge.channel,
            (call) async => true,
          );
      final done = Completer<void>();
      tester.binding.channelBuffers.push(
        'com.creabiz.ev/payment_return',
        const StandardMethodCodec().encodeMethodCall(
          const MethodCall('hostedReturn', {
            'invoice_id': 999,
            'status': 'succeeded',
          }),
        ),
        (_) => done.complete(),
      );
      await tester.pumpAndSettle();
      await tester.runAsync(
        () => done.future.timeout(const Duration(seconds: 5)),
      );
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
      expect(api.requests.any((r) => r['path'].contains('999')), false);
      expect(api.row!['status'], 'open');
    },
  );
  testWidgets(
    'read-only associated vendor/admin record cannot expose financial action controls',
    (tester) async {
      final api = HostedApi()
        ..row = hostedFixture(status: 'authorized', own: false);
      await mount(tester, api, checkoutId: 7);
      expect(find.byKey(const Key('hosted-capture')), findsNothing);
      expect(find.byKey(const Key('hosted-cancel')), findsNothing);
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
      await tester.pumpWidget(const SizedBox());
      await tester.pumpAndSettle();
      api.row = null;
      await mount(tester, api, sourceOwnerId: 2);
      expect(find.byKey(const Key('hosted-create')), findsNothing);
    },
  );
  testWidgets(
    'confirmed terminal retry is explicit and refreshes proof before releasing old intent',
    (tester) async {
      final api = HostedApi()..row = hostedFixture(status: 'cancelled');
      await mount(tester, api);
      await tap(tester, 'hosted-retry');
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
      expect(find.byKey(const Key('hosted-create')), findsOneWidget);
      await tap(tester, 'hosted-consent');
      await tap(tester, 'hosted-create');
      expect(api.requests.where((r) => r['method'] == 'POST').length, 1);
    },
  );
  testWidgets(
    'unknown preparation resumes only on explicit same-record action after foreground GET',
    (tester) async {
      final api = HostedApi()
        ..row = (hostedFixture(status: 'needs_review')
          ..['allowed_actions'] = ['inspect', 'retry_preparation']
          ..['can_retry_preparation'] = true);
      await mount(tester, api);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
      await tap(tester, 'hosted-retry-preparation');
      final post = api.requests.singleWhere((r) => r['method'] == 'POST');
      expect(post['path'], 'commercial/hosted-checkouts/7/retry-preparation');
      expect(post['body'], isEmpty);
      expect(api.row!['id'], 7);
      expect(api.row!['status'], 'open');
      expect(
        api.requests.where(
          (r) =>
              r['path'] == 'commercial/hosted-checkouts' &&
              r['method'] == 'POST',
        ),
        isEmpty,
      );
    },
  );
  testWidgets(
    'mismatched invoice source is rejected without financial actions',
    (tester) async {
      final api = HostedApi()..row = (hostedFixture()..['invoice_id'] = 999);
      await mount(tester, api);
      expect(find.byKey(const Key('hosted-error')), findsOneWidget);
      expect(find.byKey(const Key('hosted-create')), findsNothing);
      expect(api.requests.every((r) => r['method'] == 'GET'), true);
    },
  );
  testWidgets('expired session clears private rows and uses localized errors', (
    tester,
  ) async {
    var expired = 0;
    final api = HostedApi()..deny = true;
    await mount(tester, api, onExpired: () => expired++);
    expect(expired, 1);
    expect(find.textContaining('private-payment-diagnostic'), findsNothing);
  });
  testWidgets('Arabic dark payment state is responsive and RTL at390px', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final api = HostedApi()..row = hostedFixture(status: 'authorized');
    await mount(tester, api, ar: true);
    expect(
      Directionality.of(tester.element(find.text('الدفع عبر موقع المزود'))),
      TextDirection.rtl,
    );
    await tester.scrollUntilVisible(
      find.byKey(const Key('hosted-capture')),
      150,
      scrollable: find.byType(Scrollable).first,
    );
    expect(tester.takeException(), isNull);
  });
}
