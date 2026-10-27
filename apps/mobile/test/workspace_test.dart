import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:creabiz_ev/catalog.dart';
import 'test_support.dart';

Future<void> signIn(WidgetTester tester) async {
  await tester.enterText(
    find.byKey(const Key('auth-email')),
    'synthetic@example.test',
  );
  await tester.enterText(
    find.byKey(const Key('auth-password')),
    'synthetic-password',
  );
  await tester.tap(find.byKey(const Key('auth-submit')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'appearance and Arabic persist across app recreation, including signed-in customer UI',
    (tester) async {
      final store = TestStore();
      final preferences = AppPreferences(store);
      await preferences.load();
      final api = FixtureApi();
      addTearDown(api.close);
      await tester.pumpWidget(EvApp(preferences: preferences, api: api));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('settings')));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<ThemeMode>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Dark').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<Locale>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('العربية').last);
      await tester.pumpAndSettle();
      expect(
        tester.widget<MaterialApp>(find.byType(MaterialApp)).themeMode,
        ThemeMode.dark,
      );
      await tester.tap(find.text('إغلاق'));
      await tester.pumpAndSettle();
      expect(
        Directionality.of(tester.element(find.byType(Workspace))),
        TextDirection.rtl,
      );
      expect(
        Theme.of(tester.element(find.byType(Workspace))).brightness,
        Brightness.dark,
      );
      await tester.pumpWidget(const SizedBox());
      final restored = AppPreferences(store);
      await restored.load();
      await tester.pumpWidget(EvApp(preferences: restored, api: api));
      await tester.pumpAndSettle();
      expect(restored.locale.languageCode, 'ar');
      expect(restored.theme, ThemeMode.dark);
      await signIn(tester);
      expect(find.text('اكتشف'), findsWidgets);
      expect(
        Directionality.of(tester.element(find.byType(CatalogView))),
        TextDirection.rtl,
      );
      await restored.update(theme: ThemeMode.system);
      await tester.pumpAndSettle();
      tester.binding.platformDispatcher.platformBrightnessTestValue =
          Brightness.light;
      await tester.pumpAndSettle();
      expect(
        Theme.of(tester.element(find.byType(Workspace))).brightness,
        Brightness.light,
      );
      tester.binding.platformDispatcher.platformBrightnessTestValue =
          Brightness.dark;
      await tester.pumpAndSettle();
      expect(
        Theme.of(tester.element(find.byType(Workspace))).brightness,
        Brightness.dark,
      );
      tester.binding.platformDispatcher.clearPlatformBrightnessTestValue();
    },
  );
  testWidgets(
    'catalog UI applies all filters, retries failed next page and preserves state across tabs',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = FixtureApi();
      addTearDown(api.close);
      final prefs = AppPreferences(TestStore());
      await prefs.load();
      await tester.pumpWidget(EvApp(preferences: prefs, api: api));
      await tester.pumpAndSettle();
      await signIn(tester);
      await tester.enterText(
        find.byKey(const Key('catalog-search')),
        'Station & محطة',
      );
      await tester.enterText(find.byKey(const Key('catalog-country')), 'ae');
      await tester.enterText(find.byKey(const Key('catalog-power')), '-1');
      final beforeInvalid = api.queries.length;
      await tester.tap(find.text('Apply filters'));
      await tester.pumpAndSettle();
      expect(api.queries.length, beforeInvalid);
      expect(find.text('Enter a whole number from 1 to 1000.'), findsOneWidget);
      await tester.enterText(find.byKey(const Key('catalog-power')), '150');
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('CCS2').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Apply filters'));
      await tester.pumpAndSettle();
      expect(api.queries.last, {
        'q': 'Station & محطة',
        'country': 'AE',
        'connector_type': 'CCS2',
        'min_power_kw': '150',
        'page': '1',
      });
      api.failNext = true;
      await tester.ensureVisible(find.byKey(const Key('catalog-next')));
      await tester.tap(find.byKey(const Key('catalog-next')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('catalog-error')), findsOneWidget);
      await tester.ensureVisible(find.text('Retry'));
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(api.queries.last['page'], '2');
      expect(find.text('Contract station page 2'), findsOneWidget);
      await tester.tap(find.byIcon(Icons.event_outlined));
      await tester.pumpAndSettle();
      await tester.tap(find.byIcon(Icons.ev_station_outlined));
      await tester.pumpAndSettle();
      expect(find.text('Contract station page 2'), findsOneWidget);
      expect(
        tester
            .widget<TextFormField>(find.byKey(const Key('catalog-search')))
            .controller!
            .text,
        'Station & محطة',
      );
      await tester.ensureVisible(find.text('Clear filters'));
      await tester.tap(find.text('Clear filters'));
      await tester.pumpAndSettle();
      expect(api.queries.last, {'page': '1'});
      expect(
        tester
            .widget<TextFormField>(find.byKey(const Key('catalog-search')))
            .controller!
            .text,
        '',
      );
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'Arabic vendor layouts fit narrow and tablet screens and expired sessions clear private UI',
    (tester) async {
      final api = FixtureApi(role: 'vendor');
      addTearDown(api.close);
      final prefs = AppPreferences(TestStore());
      await prefs.load();
      await prefs.update(locale: const Locale('ar'));
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.view.resetPhysicalSize);
      for (final width in [320.0, 900.0]) {
        tester.view.physicalSize = Size(width, 1000);
        await tester.pumpWidget(
          EvApp(key: ValueKey(width), preferences: prefs, api: api),
        );
        await tester.pumpAndSettle();
        await signIn(tester);
        expect(find.byTooltip('إنشاء'), findsOneWidget);
        expect(tester.takeException(), isNull);
        if (width >= 800) expect(find.byType(NavigationRail), findsOneWidget);
      }
      api.expired = true;
      await tester.tap(find.byIcon(Icons.event_outlined));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('auth-email')), findsOneWidget);
      expect(find.byType(NavigationRail), findsNothing);
      expect(find.text('Contract station page 1'), findsNothing);
      expect(find.text('انتهت جلستك. سجّل الدخول مجدداً.'), findsOneWidget);
    },
  );
}
