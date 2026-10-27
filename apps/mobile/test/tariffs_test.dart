import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/money_input.dart';
import 'package:creabiz_ev/tariffs.dart';
import 'test_support.dart';

class PricingApi extends FixtureApi {
  final publications = <Map<String, dynamic>>[];
  bool uncertain = false, expired = false;
  int price = 125, version = 1;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (expired) throw const ApiException(401, 'expired');
    expect(path, 'stations/8/tariffs');
    if (method == 'POST') {
      publications.add({...body!});
      price = body['rate_minor_per_kwh'] as int;
      version++;
      if (uncertain) {
        uncertain = false;
        throw const ApiException(0, 'lost acknowledgement');
      }
      return {'id': version};
    }
    return {
      'data': [
        {
          'id': version,
          'rate_minor_per_kwh': price,
          'tax_bps': 500,
          'commission_bps': 1250,
          'minor_units': 2,
          'currency': 'AED',
          'effective_from': '2026-10-27T00:00:00Z',
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
      'pricing_context': {
        'currency': 'AED',
        'minor_units': 2,
        'evidence': 'SIMULATED',
      },
    };
  }
}

Widget app(PricingApi api, VoidCallback expired) => MaterialApp(
  locale: const Locale('ar'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: TariffWorkspaceView(
    api: api,
    onExpired: expired,
    station: const {
      'id': 8,
      'name': 'Literal محطة',
      'currency': 'AED',
      'minor_units': 2,
    },
  ),
);
void main() {
  test(
    'exact prices support Arabic/Persian digits and reject rounding, exponent, overflow and ambiguous separators',
    () {
      expect(decimalMinor('٢٫٥٠', 2), 250);
      expect(decimalMinor('۲٫۵۰', 2), 250);
      expect(decimalMinor('100.00', 2, maximum: 10000), 10000);
      for (final value in [
        '-1',
        '1.001',
        '1e2',
        '2,50',
        '٢٬٥٠٠',
        '10000.01',
        '999999999999999999999',
      ])
        expect(decimalMinor(value, 2), isNull, reason: value);
      expect(decimalMinor('2.0', 0), isNull);
      expect(decimalMinor('0', 0), 0);
      expect(decimalMinor('100.01', 2, maximum: 10000), isNull);
      for (final digits in [0, 1, 2, 3])
        for (final amount in [0, 1, 99, 100, 999999, 1000000])
          expect(decimalMinor(minorDecimal(amount, digits), digits), amount);
    },
  );
  testWidgets(
    'vendor publishes exact Arabic prices with an idempotency identity and zero commission sentinel',
    (tester) async {
      final api = PricingApi();
      addTearDown(api.close);
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      expect(find.byType(TextFormField), findsNWidgets(2));
      await tester.enterText(find.byType(TextFormField).at(0), '٢٫٥٠');
      await tester.enterText(find.byType(TextFormField).at(1), '٥');
      await tester.ensureVisible(find.text('نشر إصدار التسعير'));
      await tester.tap(find.text('نشر إصدار التسعير'));
      await tester.pumpAndSettle();
      expect(api.publications.single['rate_minor_per_kwh'], 250);
      expect(api.publications.single['tax_bps'], 500);
      expect(api.publications.single['commission_bps'], 0);
      expect(api.publications.single['expected_currency'], 'AED');
      expect(api.publications.single['expected_minor_units'], 2);
      expect(
        api.publications.single['idempotency_key'],
        matches(RegExp(r'^[a-f0-9]{32}$')),
      );
      expect(find.text('2.50 AED / kWh'), findsOneWidget);
      expect(find.text('الضريبة: 5.00% · العمولة: 12.50%'), findsOneWidget);
    },
  );
  testWidgets(
    'lost publish acknowledgement never retries and requires history refresh; expiry removes private pricing',
    (tester) async {
      final api = PricingApi()..uncertain = true;
      addTearDown(api.close);
      var expired = false;
      await tester.pumpWidget(app(api, () => expired = true));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).at(0), '2.75');
      await tester.ensureVisible(find.text('نشر إصدار التسعير'));
      await tester.tap(find.text('نشر إصدار التسعير'));
      await tester.pumpAndSettle();
      expect(api.publications.length, 1);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'نشر إصدار التسعير'),
            )
            .onPressed,
        isNull,
      );
      await tester.ensureVisible(find.text('تحديث سجل التسعير'));
      await tester.tap(find.text('تحديث سجل التسعير'));
      await tester.pumpAndSettle();
      expect(find.text('2.75 AED / kWh'), findsOneWidget);
      expect(api.publications.length, 1);
      api.expired = true;
      await tester.tap(find.text('تحديث سجل التسعير'));
      await tester.pumpAndSettle();
      expect(expired, isTrue);
      expect(find.text('2.75 AED / kWh'), findsNothing);
    },
  );
}
