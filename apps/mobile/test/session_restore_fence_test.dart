import 'dart:async';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredSessionRestoreApi extends FixtureApi {
  final response = Completer<Account>();

  @override
  bool get canRemember => true;

  @override
  Future<Account> restoreSession() => response.future;
}

Future<void> authenticateFixture(WidgetTester tester) async {
  await tester.enterText(
      find.byKey(const Key('auth-email')), 'synthetic@example.test');
  await tester.enterText(
      find.byKey(const Key('auth-password')), 'synthetic-password');
  await tester.tap(find.byKey(const Key('auth-submit')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('cancelled restore exposes sign-in instead of endless spinner',
      (tester) async {
    final api = DeferredSessionRestoreApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pump();
    final dynamic workspace = tester.state(find.byType(Workspace));
    expect(workspace.restoring, isTrue);

    workspace.expiredSession();
    await tester.pumpAndSettle();
    expect(workspace.restoring, isFalse);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('old native cookie restore cannot replace a fresh signed-in user',
      (tester) async {
    final api = DeferredSessionRestoreApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pump();
    final dynamic workspace = tester.state(find.byType(Workspace));

    workspace.expiredSession();
    await tester.pumpAndSettle();
    await authenticateFixture(tester);
    expect(workspace.account?.role, 'customer');
    expect(workspace.account?.name, 'Synthetic test account');

    api.response.complete(Account.fromJson({
      'id': 51,
      'name': 'Stale vendor cookie session',
      'role': 'vendor',
    }));
    await tester.pumpAndSettle();
    expect(workspace.account?.role, 'customer');
    expect(workspace.account?.name, 'Synthetic test account');
    expect(workspace.restoring, isFalse);
    expect(tester.takeException(), isNull);
  });
}
