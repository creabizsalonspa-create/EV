import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/rider_history.dart';
import 'test_support.dart';

class HistoryApi extends FixtureApi {
  final paths = <String>[];
  bool current = true;
  Completer<dynamic>? delayed;
  @override
  bool Function() sessionFence() =>
      () => current;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    paths.add(path);
    if (delayed != null) return delayed!.future;
    return page(query['kind'] ?? 'events');
  }

  Map<String, dynamic> page(String kind) => {
    'read_only': true,
    'hardware_verified': false,
    'kind': kind,
    'current_page': 1,
    'last_page': 1,
    'total': 1,
    'data': [
      kind == 'events'
          ? {'id': 1, 'action': 'token.revoked', 'created_at': '2026-11-07'}
          : {
              'id': 2,
              'state': 'source_ended',
              'source_start_observed': true,
              'source_end_observed': false,
              'protocol': '2.0.1',
              'charging_session_id': 8,
              'created_at': '2026-11-07',
            },
    ],
  };
}

void main() {
  testWidgets(
    'customer history refuses to label incomplete source evidence as ended',
    (tester) async {
      final api = HistoryApi();
      await tester.pumpWidget(
        MaterialApp(
          home: RiderHistoryView(api: api, onExpired: () {}, tokenId: 4),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Token revoked'), findsOneWidget);
      expect(api.paths.single, 'rider-access/tokens/4/activity');
      await tester.tap(find.text('Card charging attempts'));
      await tester.pumpAndSettle();
      expect(find.text('Source evidence requires review'), findsOneWidget);
      expect(find.text('Source start and end observed'), findsNothing);
      expect(find.text('Token revoked'), findsNothing);
    },
  );
  testWidgets('operator route clears on background until explicit refresh', (
    tester,
  ) async {
    final api = HistoryApi();
    await tester.pumpWidget(
      MaterialApp(
        home: RiderHistoryView(
          api: api,
          onExpired: () {},
          companyId: 5,
          grantId: 9,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(api.paths.single, 'vendor-ops/companies/5/rider-grants/9/activity');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('Token revoked'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.paths, hasLength(1));
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Token revoked'), findsOneWidget);
  });
  testWidgets(
    'delayed result cannot repopulate a changed authenticated context',
    (tester) async {
      final api = HistoryApi()..delayed = Completer<dynamic>();
      await tester.pumpWidget(
        MaterialApp(
          home: RiderHistoryView(api: api, onExpired: () {}, tokenId: 4),
        ),
      );
      await tester.pump();
      api.current = false;
      api.delayed!.complete(api.page('events'));
      await tester.pumpAndSettle();
      expect(find.text('Token revoked'), findsNothing);
    },
  );
}
