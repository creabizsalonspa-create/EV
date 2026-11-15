import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'test_support.dart';

class DeferredRecordApi extends FixtureApi {
  final pending = <Completer<dynamic>>[];

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (path == 'reservations') {
      final response = Completer<dynamic>();
      pending.add(response);
      return response.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

Map<String, dynamic> recordPage(int id, int page) => {
  'data': [
    {
      'id': id,
      'station_id': 1,
      'status': 'confirmed',
      'starts_at': '2026-11-16T12:00:00Z',
    },
  ],
  'current_page': page,
  'last_page': 3,
  'total': 3,
};

void main() {
  testWidgets('latest reservation response wins over an earlier slow page',
      (tester) async {
    final api = DeferredRecordApi();
    addTearDown(api.close);
    final preferences = AppPreferences(TestStore());
    await preferences.load();
    await tester.pumpWidget(EvApp(preferences: preferences, api: api));
    await tester.pumpAndSettle();

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

    // Exercise the Workspace's shared loader directly: multiple completion
    // callbacks can request pages despite the normal busy UI button gate.
    final dynamic state = tester.state(find.byType(Workspace));
    state.setState(() => state.tab = 1);
    final Future<void> oldPage = state.load(page: 1);
    final Future<void> newPage = state.load(page: 2);
    expect(api.pending.length, 2);

    api.pending[1].complete(recordPage(202, 2));
    await newPage;
    await tester.pump();
    expect(state.records.first['id'], 202);
    expect(state.recordPage, 2);

    api.pending[0].complete(recordPage(101, 1));
    await oldPage;
    await tester.pump();
    expect(state.records.first['id'], 202);
    expect(state.recordPage, 2);

    // Feature-specific expiry callbacks may arrive while a GET is pending.
    final Future<void> revokedPage = state.load(page: 3);
    expect(api.pending.length, 3);
    state.expiredSession();
    api.pending[2].complete(recordPage(303, 3));
    await revokedPage;
    await tester.pump();
    expect(state.records, isEmpty);
    expect(find.byKey(const Key('auth-email')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
