import 'dart:async';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredFirstLoginApi extends FixtureApi {
  final firstResponse = Completer<Account>();
  int signIns = 0;

  @override
  Future<Account> signIn(String email, String password, {String? name}) {
    signIns++;
    if (signIns == 1) return firstResponse.future;
    return super.signIn(email, password, name: name);
  }
}

Future<void> enterCredentials(WidgetTester tester) async {
  await tester.enterText(
    find.byKey(const Key('auth-email')),
    'synthetic@example.test',
  );
  await tester.enterText(
    find.byKey(const Key('auth-password')),
    'synthetic-password',
  );
}

void main() {
  testWidgets('revoked asynchronous sign-in cannot repopulate a signed-out app',
      (tester) async {
    final api = DeferredFirstLoginApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));

    await enterCredentials(tester);
    final Future<void> staleLogin = workspace.login();
    await tester.pump();
    expect(workspace.busy, isTrue);
    expect(api.signIns, 1);

    workspace.expiredSession();
    await tester.pumpAndSettle();
    expect(workspace.account, isNull);

    api.firstResponse.complete(Account.fromJson({
      'id': 9,
      'name': 'Revoked vendor identity',
      'role': 'vendor',
    }));
    await staleLogin;
    await tester.pumpAndSettle();
    expect(workspace.account, isNull);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('old sign-in response cannot overwrite a newer account',
      (tester) async {
    final api = DeferredFirstLoginApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));

    await enterCredentials(tester);
    final Future<void> staleLogin = workspace.login();
    await tester.pump();
    workspace.expiredSession();
    await tester.pumpAndSettle();

    await enterCredentials(tester);
    await tester.tap(find.byKey(const Key('auth-submit')));
    await tester.pumpAndSettle();
    expect(api.signIns, 2);
    expect(workspace.account?.role, 'customer');
    expect(workspace.account?.name, 'Synthetic test account');

    api.firstResponse.complete(Account.fromJson({
      'id': 9,
      'name': 'Revoked vendor identity',
      'role': 'vendor',
    }));
    await staleLogin;
    await tester.pumpAndSettle();
    expect(workspace.account?.role, 'customer');
    expect(workspace.account?.name, 'Synthetic test account');
    expect(tester.takeException(), isNull);
  });
}
