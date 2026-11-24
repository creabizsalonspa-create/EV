import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/catalog.dart';
import 'package:creabiz_ev/strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  for (final language in ['en', 'ar']) {
    testWidgets('independent advanced filters, localized power and complete reset ($language)', (tester) async {
      final requests = <Map<String, String>>[];
      final strings = EvStrings(Locale(language));
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: Scaffold(body: CatalogView(
          customer: true,
          loader: (filter, page) async {
            requests.add({...filter.query, 'page': '$page'});
            return const ApiPage<Station>(items: [], current: 1, last: 1, total: 0);
          },
          onReserve: (_) async {},
        )),
      ));
      await tester.pumpAndSettle();
      expect(requests, [{'page': '1'}]);

      Future<void> tap(Finder target) async {
        await tester.ensureVisible(target);
        await tester.pumpAndSettle();
        await tester.tap(target);
        await tester.pumpAndSettle();
      }
      Future<void> enter(String key, String value) async {
        final field = find.byKey(Key(key));
        await tester.ensureVisible(field);
        await tester.enterText(field, value);
        await tester.pumpAndSettle();
      }

      // Advanced address/price filters do not require geographic coordinates.
      await tap(find.text(language == 'ar' ? 'العنوان والمشغل والسعر' : 'Address, operator and price'));
      await enter('catalog-search', 'Station');
      await enter('catalog-country', 'ae');
      await enter('catalog-power', language == 'ar' ? '١٥٠' : '150');
      await enter('catalog-addressSearch', 'Main street');
      await enter('catalog-operatorName', 'Operator');
      await enter('catalog-accessSearch', 'Public');
      await enter('catalog-maximum-rate', language == 'ar' ? '١٫٥٠' : '1.50');
      // Missing paired currency must not cause an API request.
      await tap(find.text(strings.t('applyFilters')));
      expect(requests.length, 1);
      expect(find.byKey(const Key('catalog-invalid-filter-summary')), findsOneWidget);
      await enter('catalog-price-currency', 'aed');
      await tap(find.byKey(const ValueKey('catalog-source-status-')));
      await tester.tap(find.text(language == 'ar' ? 'متاح' : 'Available').last);
      await tester.pumpAndSettle();
      await tap(find.text(strings.t('applyFilters')));
      expect(requests.last, {
        'q': 'Station', 'country': 'AE', 'min_power_kw': '150',
        'address': 'Main street', 'operator': 'Operator', 'access': 'Public',
        'max_rate_per_kwh': '1.50', 'price_currency': 'AED',
        'recent_source_status': 'Available', 'page': '1',
      });
      expect(find.byKey(const Key('catalog-invalid-filter-summary')), findsNothing);
      await tap(find.text(strings.t('clearFilters')));
      expect(requests.last, {'page': '1'});
      for (final key in ['catalog-search','catalog-country','catalog-power',
        'catalog-addressSearch','catalog-operatorName','catalog-accessSearch',
        'catalog-maximum-rate','catalog-price-currency']) {
        final field = tester.widget<TextFormField>(find.byKey(Key(key)));
        expect(field.controller!.text, isEmpty, reason: key);
      }
      expect(find.byKey(const ValueKey('catalog-source-status-')), findsOneWidget);
      await tap(find.text(strings.t('applyFilters')));
      expect(requests.last, {'page': '1'});
      expect(tester.takeException(), isNull);
    });
  }
}
