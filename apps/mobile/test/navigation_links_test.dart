import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/navigation_links.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

Future<void> login(WidgetTester tester) async {
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
  test('only exact read-only navigation tab names are accepted', () {
    for (var index = 0; index < navigationTabs.length; index++) {
      expect(navigationTabIndex(navigationTabs[index]), index);
    }
    for (final invalid in [
      null,
      '',
      'Support',
      'support ',
      'support?token=private',
      'support/42',
      'payments',
      'admin',
      'charge/start',
      'reservations/7',
      '../fleet',
      'https://example.invalid/support',
    ]) {
      expect(navigationTabIndex(invalid), isNull);
    }
  });

  testWidgets('signed-in customer can open sections without a write',
      (tester) async {
    final messenger =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    String? nextTarget;
    messenger.setMockMethodCallHandler(
      NativeNavigationBridge.channel,
      (call) async {
        expect(call.method, 'consumeNavigation');
        final value = nextTarget;
        nextTarget = null;
        return value;
      },
    );
    addTearDown(() => messenger.setMockMethodCallHandler(
          NativeNavigationBridge.channel,
          null,
        ));
    final api = FixtureApi();
    addTearDown(api.close);
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();
    await login(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    final writesBefore = api.calls.where((value) => value.startsWith('POST ')).length;
    nextTarget = 'support';
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pumpAndSettle();
    expect(workspace.tab, 4);
    expect(find.byType(Workspace), findsOneWidget);

    nextTarget = 'garage';
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pumpAndSettle();
    expect(workspace.tab, 3);
    expect(
      api.calls.where((value) => value.startsWith('POST ')).length,
      writesBefore,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('signed-out and vendor-incompatible links cannot open private garage',
      (tester) async {
    final messenger =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    String? nextTarget;
    messenger.setMockMethodCallHandler(
      NativeNavigationBridge.channel,
      (call) async {
        final value = nextTarget;
        nextTarget = null;
        return value;
      },
    );
    addTearDown(() => messenger.setMockMethodCallHandler(
          NativeNavigationBridge.channel,
          null,
        ));
    final api = FixtureApi(role: 'vendor');
    addTearDown(api.close);
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));
    nextTarget = 'support';
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);

    await login(tester);
    expect(workspace.tab, 0);
    nextTarget = 'garage';
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(tester.takeException(), isNull);
  });
}
