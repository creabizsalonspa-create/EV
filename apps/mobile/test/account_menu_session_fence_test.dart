import 'dart:async';

import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredMenuApi extends FixtureApi {
  final logoutStarted = Completer<void>();
  final logoutResult = Completer<void>();
  final verificationStarted = Completer<void>();
  final verificationResult = Completer<Map<String, dynamic>>();
  final forgetStarted = Completer<void>();
  final forgetResult = Completer<void>();
  bool pretendRemembered = false;

  @override
  bool get remembered => pretendRemembered;

  @override
  Future<void> signOut() async {
    if (!logoutStarted.isCompleted) logoutStarted.complete();
    await logoutResult.future;
  }

  @override
  Future<void> forgetSession() async {
    if (!forgetStarted.isCompleted) forgetStarted.complete();
    await forgetResult.future;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (path == 'email/verification-notification' && method == 'POST') {
      if (!verificationStarted.isCompleted) verificationStarted.complete();
      return verificationResult.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

Future<void> signIn(WidgetTester tester) async {
  await tester.enterText(
      find.byKey(const Key('auth-email')), 'synthetic@example.test');
  await tester.enterText(
      find.byKey(const Key('auth-password')), 'synthetic-password');
  await tester.tap(find.byKey(const Key('auth-submit')));
  await tester.pumpAndSettle();
}

Future<void> selectAccountMenu(WidgetTester tester, String label) async {
  await tester.tap(find.byTooltip('Account'));
  await tester.pumpAndSettle();
  await tester.tap(find.text(label).last);
  await tester.pump();
}

void main() {
  testWidgets('old logout completion cannot log out a fresh account',
      (tester) async {
    final api = DeferredMenuApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    await signIn(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    await selectAccountMenu(tester, 'Sign out');
    await api.logoutStarted.future;
    workspace.expiredSession();
    await tester.pumpAndSettle();
    await signIn(tester);
    expect(workspace.account?.role, 'customer');

    api.logoutResult.complete();
    await tester.pumpAndSettle();
    expect(workspace.account?.role, 'customer');
    expect(find.byKey(const Key('auth-email')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('old verification cannot replace a newer login profile',
      (tester) async {
    final api = DeferredMenuApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    await signIn(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    await selectAccountMenu(tester, 'Send verification email');
    await api.verificationStarted.future;
    workspace.expiredSession();
    await tester.pumpAndSettle();
    await signIn(tester);
    final meBefore = api.calls.where((call) => call == 'GET me').length;

    api.verificationResult.complete({'accepted': true});
    await tester.pumpAndSettle();
    expect(api.calls.where((call) => call == 'GET me').length, meBefore);
    expect(workspace.account?.name, 'Synthetic test account');
    expect(workspace.message, isNull);
    expect(tester.takeException(), isNull);
  });

  testWidgets('old encrypted-session deletion cannot show success on next login',
      (tester) async {
    final api = DeferredMenuApi()..pretendRemembered = true;
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    await signIn(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    await selectAccountMenu(tester, 'Forget sign-in');
    await api.forgetStarted.future;
    workspace.expiredSession();
    await tester.pumpAndSettle();
    await signIn(tester);

    api.forgetResult.complete();
    await tester.pumpAndSettle();
    expect(workspace.account?.role, 'customer');
    expect(workspace.message, isNull);
    expect(tester.takeException(), isNull);
  });
}
