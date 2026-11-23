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
  Completer<dynamic>? pending;
  final pages = <String>[];

  @override
  bool Function() sessionFence() =>
      () => current;

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
        'created_at': '2026-11-23 04:30:00',
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

Widget checkoutActivity(CheckoutEventsApi api, {bool ar = false}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: HostedCheckoutEventsView(
        api: api,
        checkoutId: 4,
        corporate: api.corporate,
        onExpired: () {},
      ),
    );

Future<void> press(WidgetTester tester, String label) async {
  await tester.tap(find.text(label));
  await tester.pumpAndSettle();
}

void main() {
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
