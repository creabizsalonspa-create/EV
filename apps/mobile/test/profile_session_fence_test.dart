import 'dart:async';

import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredProfileApi extends FixtureApi {
  final profileRequested = Completer<void>();
  final profileResponse = Completer<Map<String, dynamic>>();

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (path == 'profile' && method == 'PATCH') {
      if (!profileRequested.isCompleted) profileRequested.complete();
      return profileResponse.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

Future<void> loginFixture(WidgetTester tester) async {
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

Future<void> submitProfileEdit(
  WidgetTester tester,
  DeferredProfileApi api,
) async {
  await tester.pumpAndSettle();
  expect(find.byType(EntryDialog), findsOneWidget);
  await tester.enterText(find.byType(TextFormField).last, 'Old account name');
  await tester.tap(find.widgetWithText(FilledButton, 'Save'));
  await tester.pump();
  await api.profileRequested.future;
}

void main() {
  testWidgets('profile PATCH can update the current authenticated account',
      (tester) async {
    final api = DeferredProfileApi();
    addTearDown(api.close);
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();
    await loginFixture(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    final Future<void> update = workspace.profile();
    await submitProfileEdit(tester, api);
    api.profileResponse.complete({
      'id': 1,
      'name': 'Updated account name',
      'role': 'customer',
    });
    await update;
    await tester.pumpAndSettle();
    expect(workspace.account?.name, 'Updated account name');
    expect(find.byKey(const Key('auth-email')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('delayed old profile cannot restore a revoked session',
      (tester) async {
    final api = DeferredProfileApi();
    addTearDown(api.close);
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();
    await loginFixture(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    final Future<void> update = workspace.profile();
    await submitProfileEdit(tester, api);
    workspace.expiredSession();
    await tester.pumpAndSettle();
    expect(workspace.account, isNull);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);

    api.profileResponse.complete({
      'id': 1,
      'name': 'Stale profile from revoked session',
      'role': 'customer',
    });
    await update;
    await tester.pumpAndSettle();
    expect(workspace.account, isNull);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('delayed profile cannot overwrite a new login of same user',
      (tester) async {
    final api = DeferredProfileApi();
    addTearDown(api.close);
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();
    await loginFixture(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    final Future<void> oldUpdate = workspace.profile();
    await submitProfileEdit(tester, api);
    workspace.expiredSession();
    await tester.pumpAndSettle();
    await loginFixture(tester);
    expect(workspace.account?.name, 'Synthetic test account');

    api.profileResponse.complete({
      'id': 1,
      'name': 'Old session profile',
      'role': 'customer',
    });
    await oldUpdate;
    await tester.pumpAndSettle();
    expect(workspace.account?.name, 'Synthetic test account');
    expect(tester.takeException(), isNull);
  });
}
