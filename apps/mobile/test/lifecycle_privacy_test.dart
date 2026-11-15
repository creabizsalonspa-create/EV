import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:creabiz_ev/strings.dart';
import 'test_support.dart';

void main() {
  testWidgets('inactive app masks signed-in nested routes without logging out',
      (tester) async {
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    final api = FixtureApi();
    addTearDown(api.close);
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();

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
    expect(find.byKey(const Key('auth-email')), findsNothing);

    // Keep a navigator overlay open: privacy protection must not only
    // obscure the root workspace behind dialogs and bottom sheets.
    await tester.tap(find.byKey(const Key('settings')));
    await tester.pumpAndSettle();
    expect(find.byType(BottomSheet), findsOneWidget);

    for (final state in [
      AppLifecycleState.inactive,
      AppLifecycleState.paused,
      AppLifecycleState.hidden,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
      await tester.pump();
      expect(find.byKey(const Key('privacy-shield')), findsOneWidget);
      expect(
        tester
            .widget<ExcludeSemantics>(
              find.byKey(const Key('privacy-content-semantics')),
            )
            .excluding,
        isTrue,
      );
      expect(
        tester
            .widget<IgnorePointer>(
              find.byKey(const Key('privacy-content-input')),
            )
            .ignoring,
        isTrue,
      );
    }

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('privacy-shield')), findsNothing);
    expect(
      tester
          .widget<IgnorePointer>(
            find.byKey(const Key('privacy-content-input')),
          )
          .ignoring,
      isFalse,
    );
    expect(find.byType(BottomSheet), findsOneWidget);
    expect(find.byKey(const Key('auth-email')), findsNothing);
  });

  test('privacy shield announcement is available in English and Arabic', () {
    expect(
      EvStrings(const Locale('en')).t('privacyScreenHidden'),
      'App content is hidden while the app is inactive.',
    );
    expect(
      EvStrings(const Locale('ar')).t('privacyScreenHidden'),
      'يتم إخفاء محتوى التطبيق عندما لا يكون نشطاً.',
    );
  });
}
