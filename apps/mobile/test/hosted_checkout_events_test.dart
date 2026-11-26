import 'dart:async';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/hosted_checkout_events.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class CheckoutEventsApi extends FixtureApi {
  CheckoutEventsApi({this.corporate = false});

  final bool corporate;
  bool offline = false;
  bool wrongCheckout = false;
  bool current = true;
  int stamp = 0;
  String wire = '2026-11-23 04:30:00';
  final changes = StreamController<int>.broadcast(sync:true);
  @override Stream<int> get sessionEvents => changes.stream;
  void rotate() { current = false; stamp++; changes.add(stamp); }
  Completer<dynamic>? pending;
  final pages = <String>[];

  @override
  bool Function() sessionFence() { final epoch = stamp; return () => current && epoch == stamp; }

  Map<String, dynamic> page(String number) => {
    'current_page': int.parse(number),
    'last_page': 2,
    'total': 26,
    'data': [
      {
        'id': 7,
        (corporate ? 'checkout_id' : 'hosted_checkout_id'): wrongCheckout
            ? 5
            : 4,
        'kind': number == '1'
            ? 'provider_creation_uncertain'
            : 'untrusted_provider_kind',
        'created_at': wire,
        'provider_event_id': 'provider-secret-not-for-display',
      },
    ],
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(
      path,
      corporate
          ? 'commercial/corporate/hosted-checkouts/4/events'
          : 'commercial/hosted-checkouts/4/events',
    );
    expect(method, 'GET');
    expect(body, isNull);
    final number = query['page']!;
    pages.add(number);
    if (offline)
      throw const ApiException(503, 'provider-secret-not-for-display');
    if (pending case final wait?) return wait.future;
    return page(number);
  }
}

Widget checkoutActivity(CheckoutEventsApi api, {bool ar = false,int id = 4}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: HostedCheckoutEventsView(
        api: api,
        checkoutId: id,
        corporate: api.corporate,
        onExpired: () {},
      ),
    );

Future<void> press(WidgetTester tester, String label) async {
  await tester.tap(find.text(label));
  await tester.pumpAndSettle();
}

void main() {
  for (final ar in [false,true]) {
    testWidgets('visible checkout activity and retained pagination retire on account revocation ($ar)', (tester) async {
      final api = CheckoutEventsApi();
      addTearDown(api.close);
      addTearDown(api.changes.close);
      await tester.pumpWidget(checkoutActivity(api,ar:ar));
      await tester.pumpAndSettle();
      final label = ar ? 'نتيجة الإعداد لدى المزود غير مؤكدة' : 'Provider setup outcome uncertain';
      expect(find.text(label),findsOneWidget);
      final stale = tester.widget<TextButton>(find.widgetWithText(TextButton, ar ? 'التالي' : 'Next')).onPressed!;
      api.rotate();
      await tester.pumpAndSettle();
      expect(find.text(label),findsNothing);
      stale();
      await tester.pumpAndSettle();
      expect(api.pages,['1']);
    });
  }
  testWidgets('reused checkout route cannot display late previous target or use old callback', (tester) async {
    final api = CheckoutEventsApi();
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(checkoutActivity(api));
    await tester.pumpAndSettle();
    final stale = tester.widget<TextButton>(find.widgetWithText(TextButton,'Next')).onPressed!;
    api.pending = Completer<dynamic>();
    await tester.tap(find.text('Refresh activity'));
    await tester.pump();
    await tester.pumpWidget(checkoutActivity(api,id:5));
    api.pending!.complete(api.page('1'));
    await tester.pumpAndSettle();
    stale();
    await tester.pumpAndSettle();
    expect(find.text('Provider setup outcome uncertain'),findsNothing);
    expect(api.pages,['1','1']);
  });
  testWidgets('invalid calendar timestamp does not become a different UTC event', (tester) async {
    final api = CheckoutEventsApi()..wire='2026-02-30T04:30:00Z';
    addTearDown(api.close);
    addTearDown(api.changes.close);
    await tester.pumpWidget(checkoutActivity(api));
    await tester.pumpAndSettle();
    expect(find.text('Provider setup outcome uncertain'),findsNothing);
    expect(find.text('Could not load checkout activity.'),findsOneWidget);
    api.wire='2026-02-28T04:30:00.123456Z';
    await press(tester,'Refresh activity');
    expect(find.text('Provider setup outcome uncertain'),findsOneWidget);
  });

  testWidgets(
    'read-only pagination does not display provider details or stale events on offline failure',
    (tester) async {
      final api = CheckoutEventsApi();
      await tester.pumpWidget(checkoutActivity(api));
      await tester.pumpAndSettle();
      expect(find.text('Provider setup outcome uncertain'), findsOneWidget);
      await press(tester, 'Next');
      expect(find.text('Checkout event recorded'), findsOneWidget);
      expect(find.textContaining('untrusted_provider_kind'), findsNothing);
      api.offline = true;
      await press(tester, 'Refresh activity');
      expect(find.text('Checkout event recorded'), findsNothing);
      expect(
        find.textContaining('provider-secret-not-for-display'),
        findsNothing,
      );
      expect(find.text('Could not load checkout activity.'), findsOneWidget);
      api.offline = false;
      await press(tester, 'Refresh activity');
      expect(find.text('Checkout event recorded'), findsOneWidget);
      expect(api.pages, ['1', '2', '2', '2']);
    },
  );

  testWidgets('Arabic corporate events reject a row from another checkout', (
    tester,
  ) async {
    final api = CheckoutEventsApi(corporate: true);
    await tester.pumpWidget(checkoutActivity(api, ar: true));
    await tester.pumpAndSettle();
    final label = find.text('نتيجة الإعداد لدى المزود غير مؤكدة');
    expect(label, findsOneWidget);
    expect(Directionality.of(tester.element(label)), TextDirection.rtl);
    api.wrongCheckout = true;
    await press(tester, 'تحديث النشاط');
    expect(label, findsNothing);
    expect(find.text('تعذر تحميل نشاط عملية الدفع.'), findsOneWidget);
  });

  testWidgets(
    'a stale checkout activity reply is ignored after a session change',
    (tester) async {
      final api = CheckoutEventsApi()..pending = Completer<dynamic>();
      await tester.pumpWidget(checkoutActivity(api));
      await tester.pump();
      expect(api.pages, ['1']);
      api.current = false;
      api.pending!.complete(api.page('1'));
      await tester.pump();
      expect(find.text('Provider setup outcome uncertain'), findsNothing);
    },
  );
}
