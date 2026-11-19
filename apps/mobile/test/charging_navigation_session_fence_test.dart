import 'dart:async';

import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredChargingStartApi extends FixtureApi {
  final started = Completer<void>();
  final commandResult = Completer<Map<String, dynamic>>();

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (path == 'reservations' && method == 'GET') {
      return Future.value({
        'data': [
          {
            'id': 3,
            'status': 'confirmed',
            'station_id': 1,
            'starts_at': '2026-11-17T12:00:00Z',
            'csms_bound': false,
          }
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      });
    }
    if (path == 'reservations/3/simulate-start' && method == 'POST') {
      if (!started.isCompleted) started.complete();
      return commandResult.future;
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
  testWidgets('delayed simulated start cannot switch a new login to sessions',
      (tester) async {
    final api = DeferredChargingStartApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    await loginFixture(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    await tester.tap(find.byIcon(Icons.event_outlined));
    await tester.pumpAndSettle();
    expect(workspace.tab, 1);
    await tester.ensureVisible(find.text('Start simulator'));
    await tester.tap(find.text('Start simulator'));
    await tester.pump();
    await api.started.future;
    expect(workspace.busy, isTrue);

    workspace.expiredSession();
    await tester.pumpAndSettle();
    await loginFixture(tester);
    expect(workspace.account?.role, 'customer');
    expect(workspace.tab, 0);

    api.commandResult.complete({'status': 'started'});
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(workspace.message, isNull);
    expect(tester.takeException(), isNull);
  });
}
