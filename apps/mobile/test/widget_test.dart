import 'dart:async';
import 'package:flutter/material.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:creabiz_ev/strings.dart';
import 'test_support.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:creabiz_ev/main.dart';

class PendingRestoreApi extends FixtureApi {
  final pending = Completer<Account?>();
  @override
  bool get canRemember => true;
  @override
  Future<Account?> restoreSession() => pending.future;
}

class PendingPreferences implements PreferenceStore {
  final pending = Completer<String?>();
  @override
  Future<String?> read() => pending.future;
  @override
  Future<void> write(String value) async {}
}

void main() {
  testWidgets('initial preferences loading has an accessible live progress label', (tester) async {
    final store = PendingPreferences();
    final preferences = AppPreferences(store);
    final semantics = tester.ensureSemantics();
    addTearDown(semantics.dispose);
    addTearDown(preferences.dispose);
    await tester.pumpWidget(EvApp(preferences: preferences));
    final indicator = find.byType(CircularProgressIndicator);
    expect(tester.widget<CircularProgressIndicator>(indicator).semanticsLabel, EvStrings.en['loading']);
    expect(find.bySemanticsLabel(EvStrings.en['loading']!), findsOneWidget);
    store.pending.complete(null);
    await tester.pumpAndSettle();
    expect(indicator, findsNothing);
    expect(find.bySemanticsLabel(EvStrings.en['loading']!), findsNothing);
  });
  for (final ar in [false, true]) {
    testWidgets('workspace restoration announces localized progress and clears it ($ar)', (tester) async {
      final api = PendingRestoreApi();
      addTearDown(api.close);
      final preferences = AppPreferences(TestStore());
      await preferences.load();
      await preferences.update(locale: Locale(ar ? 'ar' : 'en'));
      final semantics = tester.ensureSemantics();
      addTearDown(semantics.dispose);
      addTearDown(preferences.dispose);
      await tester.pumpWidget(EvApp(preferences: preferences, api: api));
      final labels = ar ? EvStrings.ar : EvStrings.en;
      final progress = find.byType(LinearProgressIndicator);
      expect(tester.widget<LinearProgressIndicator>(progress).semanticsLabel, labels['restoringSession']);
      expect(find.bySemanticsLabel(labels['restoringSession']!), findsOneWidget);
      final live = find.byWidgetPredicate((widget) =>
          widget is Semantics &&
          widget.properties.liveRegion == true &&
          widget.child is LinearProgressIndicator);
      expect(live, findsOneWidget);
      expect(find.text(labels['restoringSession']!), findsOneWidget);
      api.pending.complete(null);
      await tester.pumpAndSettle();
      expect(progress, findsNothing);
      expect(find.bySemanticsLabel(labels['restoringSession']!), findsNothing);
      expect(find.byKey(const Key('auth-submit')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  setUp(() => SharedPreferences.setMockInitialValues({}));
  testWidgets('missing service configuration never displays mock inventory', (
    tester,
  ) async {
    await tester.pumpWidget(const EvApp());
    await tester.pumpAndSettle();
    expect(
      find.text('The service is unavailable. Please contact support.'),
      findsOneWidget,
    );
    expect(find.text('Sign in'), findsOneWidget);
    final button = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Sign in'),
    );
    expect(button.onPressed, isNull);
  });
}
