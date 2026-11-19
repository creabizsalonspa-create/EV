import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/navigation_links.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:creabiz_ev/strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

void main() {
  testWidgets('Arabic deep-link navigation remains usable on phone and tablet',
      (tester) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(360, 800);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final messenger =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    String? nextLink;
    messenger.setMockMethodCallHandler(
      NativeNavigationBridge.channel,
      (_) async {
        final result = nextLink;
        nextLink = null;
        return result;
      },
    );
    addTearDown(() => messenger.setMockMethodCallHandler(
          NativeNavigationBridge.channel,
          null,
        ));

    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await prefs.update(locale: const Locale('ar'));
    final api = FixtureApi();
    addTearDown(api.close);
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();

    await tester.enterText(
        find.byKey(const Key('auth-email')), 'synthetic@example.test');
    await tester.enterText(
        find.byKey(const Key('auth-password')), 'synthetic-password');
    await tester.tap(find.byKey(const Key('auth-submit')));
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(NavigationRail), findsNothing);
    expect(Directionality.of(tester.element(find.byType(Workspace))),
        TextDirection.rtl);

    nextLink = 'support';
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));
    expect(workspace.tab, 4);
    expect(find.text(EvStrings(const Locale('ar')).t('support')), findsWidgets);
    expect(tester.takeException(), isNull);

    // A live layout change preserves the selected tab and its RTL labels.
    tester.view.physicalSize = const Size(900, 1100);
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsNothing);
    expect(find.byType(NavigationRail), findsOneWidget);
    expect(workspace.tab, 4);
    expect(Directionality.of(tester.element(find.byType(Workspace))),
        TextDirection.rtl);
    expect(tester.takeException(), isNull);

    // Rotate back to a compact viewport after tablet navigation. The
    // authenticated destination and Arabic direction must survive without
    // rebuilding the account session or exposing a stale destination.
    tester.view.physicalSize = const Size(700, 360);
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(NavigationRail), findsNothing);
    expect(workspace.tab, 4);
    expect(Directionality.of(tester.element(find.byType(Workspace))),
        TextDirection.rtl);
    expect(tester.takeException(), isNull);

    nextLink = 'reservations';
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pumpAndSettle();
    expect(workspace.tab, 1);
    expect(tester.takeException(), isNull);
  });
}
