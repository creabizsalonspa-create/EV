import 'dart:async';

import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredVehiclePreferencesApi extends FixtureApi {
  final requested = Completer<void>();
  final response = Completer<Map<String, dynamic>>();

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (path == 'vehicles/42/battery-preferences') {
      if (!requested.isCompleted) requested.complete();
      return response.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

Future<void> loginFixture(WidgetTester tester) async {
  await tester.enterText(
      find.byKey(const Key('auth-email')), 'synthetic@example.test');
  await tester.enterText(
      find.byKey(const Key('auth-password')), 'synthetic-password');
  await tester.tap(find.byKey(const Key('auth-submit')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('vehicle preference from revoked session cannot alter new catalog',
      (tester) async {
    final api = DeferredVehiclePreferencesApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    await loginFixture(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    final Future<void> oldVehicle = workspace.discoverBatteryVehicle(42);
    await tester.pump();
    await api.requested.future;
    workspace.expiredSession();
    await tester.pumpAndSettle();
    await loginFixture(tester);
    expect(workspace.account?.role, 'customer');

    api.response.complete({'connector_type': 'CHAdeMO'});
    await oldVehicle;
    await tester.pumpAndSettle();

    expect(workspace.tab, 0);
    expect(
      api.queries.where((query) => query['connector_type'] == 'CHAdeMO'),
      isEmpty,
    );
    expect(tester.takeException(), isNull);
  });
}
