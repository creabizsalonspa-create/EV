import 'dart:async';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/navigation_links.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
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

  testWidgets('invalid native channel value cannot become a route',
      (tester) async {
    final messenger =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    messenger.setMockMethodCallHandler(
      NativeNavigationBridge.channel,
      (_) async => {'record_id': 7, 'status': 'paid'},
    );
    addTearDown(() => messenger.setMockMethodCallHandler(
          NativeNavigationBridge.channel,
          null,
        ));
    expect(await NativeNavigationBridge.consume(), isNull);
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
    // Widget tests can remain in a detached lifecycle state even though the
    // fixture has a visible route. Model an actual foreground app-link event.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));
    expect(workspace.account?.role, 'customer');
    expect(workspace.busy, isFalse);

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


  testWidgets('inactive app defers a link until an authenticated foreground frame',
      (tester) async {
    final messenger =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    String? nextTarget;
    messenger.setMockMethodCallHandler(
      NativeNavigationBridge.channel,
      (_) async {
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
    expect(workspace.account?.role, 'customer');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();

    nextTarget = 'support';
    NativeNavigationBridge.pending.value++;
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(nextTarget, 'support');

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(workspace.tab, 4);
    expect(nextTarget, isNull);
    expect(tester.takeException(), isNull);
  });

  testWidgets('late link response after account reset cannot revive a private route',
      (tester) async {
    final messenger =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    Completer<String?>? blocked;
    messenger.setMockMethodCallHandler(
      NativeNavigationBridge.channel,
      (_) async => blocked == null ? null : await blocked.future,
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
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));
    expect(workspace.account?.role, 'customer');

    blocked = Completer<String?>();
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pump();
    workspace.expiredSession();
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);

    blocked.complete('fleet');
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('link cannot route after app backgrounds during native read',
      (tester) async {
    final messenger =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    Completer<String?>? pending;
    bool consumed = false;
    messenger.setMockMethodCallHandler(
      NativeNavigationBridge.channel,
      (_) async {
        if (pending == null || consumed) return null;
        consumed = true;
        return await pending.future;
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
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));
    expect(workspace.account?.role, 'customer');

    pending = Completer<String?>();
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pump();
    expect(consumed, isTrue);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    pending.complete('support');
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
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
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    nextTarget = 'garage';
    NativeNavigationBridge.pending.value++;
    await tester.pump();
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(tester.takeException(), isNull);
  });
}
