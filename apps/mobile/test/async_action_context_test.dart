import 'dart:async';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

Future<void> authenticate(WidgetTester tester) async {
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
  testWidgets('old action auth failure cannot sign out a newer session',
      (tester) async {
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    final api = FixtureApi();
    addTearDown(api.close);
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();
    await authenticate(tester);

    final dynamic workspace = tester.state(find.byType(Workspace));
    final oldRequest = Completer<void>();
    final Future<void> oldAction = workspace.perform(() async {
      await oldRequest.future;
      throw const ApiException(401, 'Old account expired');
    });
    await tester.pump();
    expect(workspace.busy, isTrue);

    workspace.expiredSession();
    await tester.pumpAndSettle();
    expect(workspace.busy, isFalse);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);
    expect(workspace.error, 'expired');

    await authenticate(tester);
    expect(find.byKey(const Key('auth-email')), findsNothing);
    expect(workspace.error, isNull);

    oldRequest.complete();
    await oldAction;
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('auth-email')), findsNothing);
    expect(workspace.error, isNull);
    expect(workspace.busy, isFalse);
    expect(tester.takeException(), isNull);
  });
}
