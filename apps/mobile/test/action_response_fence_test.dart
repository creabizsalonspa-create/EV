import 'dart:async';

import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/account_recovery.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredFormApi extends FixtureApi {
  final vehicleWriteStarted = Completer<void>();
  final vehicleWrite = Completer<Map<String, dynamic>>();
  final recoveryStarted = Completer<void>();
  final recoveryWrite = Completer<Map<String, dynamic>>();

  @override
  Future<void> csrf() async {}

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (method == 'POST' && path == 'vehicles') {
      calls.add('POST vehicles');
      if (!vehicleWriteStarted.isCompleted) vehicleWriteStarted.complete();
      return vehicleWrite.future;
    }
    if (method == 'POST' && path == 'forgot-password') {
      calls.add('POST forgot-password');
      if (!recoveryStarted.isCompleted) recoveryStarted.complete();
      return recoveryWrite.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

Future<void> signInFixture(WidgetTester tester) async {
  await tester.enterText(
    find.byKey(const Key('auth-email')), 'synthetic@example.test');
  await tester.enterText(
    find.byKey(const Key('auth-password')), 'synthetic-password');
  await tester.tap(find.byKey(const Key('auth-submit')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('old vehicle write cannot refresh data or notify a new account',
      (tester) async {
    final api = DeferredFormApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    await signInFixture(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));
    await tester.tap(find.byIcon(Icons.directions_car_outlined));
    await tester.pumpAndSettle();
    expect(workspace.tab, 3);

    final Future<void> oldCreate = workspace.create();
    await tester.pumpAndSettle();
    expect(find.byType(EntryDialog), findsOneWidget);
    await tester.enterText(
      find.byType(TextFormField).last, 'Vehicle from old account');
    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pump();
    await api.vehicleWriteStarted.future;
    final readsBefore =
        api.calls.where((call) => call == 'GET vehicles').length;

    workspace.expiredSession();
    await tester.pumpAndSettle();
    await signInFixture(tester);
    expect(workspace.account?.role, 'customer');
    api.vehicleWrite.complete({'id': 44, 'name': 'Old vehicle'});
    await oldCreate;
    await tester.pumpAndSettle();

    expect(workspace.tab, 0);
    expect(workspace.message, isNull);
    expect(api.calls.where((call) => call == 'GET vehicles').length,
        readsBefore);
    expect(tester.takeException(), isNull);
  });

  testWidgets('old password recovery cannot post success into new login',
      (tester) async {
    final api = DeferredFormApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    final dynamic workspace = tester.state(find.byType(Workspace));

    final Future<void> oldRecovery = workspace.recovery();
    await tester.pumpAndSettle();
    expect(find.byType(AccountRecoveryView), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('recovery-email')), 'synthetic@example.test');
    await tester.tap(find.byKey(const Key('recovery-send')));
    await tester.pump();
    await api.recoveryStarted.future;

    workspace.expiredSession();
    await tester.pumpAndSettle();
    await signInFixture(tester);
    api.recoveryWrite.complete({'accepted': true});
    await oldRecovery;
    await tester.pumpAndSettle();

    expect(workspace.account?.role, 'customer');
    expect(workspace.message, isNull);
    expect(tester.takeException(), isNull);
  });
}
