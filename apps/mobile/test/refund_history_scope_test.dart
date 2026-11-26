import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/refund_requests.dart';
import 'test_support.dart';

class RefundHistoryApi extends FixtureApi {
  int identity = 0, reads = 0, writes = 0;
  int returnedPage = 1;
  bool duplicate = false;
  final events = StreamController<int>.broadcast(sync: true);
  Completer<dynamic>? waiting;
  Map<String, dynamic> row = {
    'id': 8,
    'invoice_id': 3,
    'amount_minor': 125,
    'reason': 'Private refund fixture',
    'status': 'pending',
  };

  @override
  Stream<int> get sessionEvents => events.stream;

  @override
  bool Function() sessionFence() {
    final epoch = identity;
    return () => epoch == identity;
  }

  void revoke() {
    identity++;
    events.add(identity);
  }

  Map<String, dynamic> response() => {
    'data': List.generate(duplicate ? 2 : 1, (_) => Map<String, dynamic>.from(row)),
    'current_page': returnedPage,
    'last_page': 1,
    'total': duplicate ? 2 : 1,
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') {
      writes++;
      throw StateError('Unexpected refund mutation');
    }
    if (path != 'finance/refund-requests') {
      throw StateError('Unexpected private read');
    }
    reads++;
    final pending = waiting;
    waiting = null;
    if (pending != null) return pending.future;
    return response();
  }
}

Widget screen(RefundHistoryApi api) => MaterialApp(
  home: RefundRequestsView(api: api, onExpired: () {}, role: 'vendor'),
);

void main() {
  testWidgets('wrong page and duplicate refund identities never grant review navigation', (tester) async {
    final api = RefundHistoryApi()..returnedPage = 2;
    addTearDown(() { api.events.close(); });
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(find.text('Review request'), findsNothing);
    api.returnedPage = 1;
    api.duplicate = true;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Review request'), findsNothing);
    api.duplicate = false;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Review request'), findsOneWidget);
    expect(api.reads, 3);
    expect(api.writes, 0);
  });

  test('strict refund history denies malformed canonical values', () {
    final row = RefundHistoryApi().row;
    expect(nativeRefundHistoryRecord(row), same(row));
    for (final entry in [
      {'id': '8'}, {'id': 0}, {'invoice_id': -1},
      {'amount_minor': '125'}, {'amount_minor': 0},
      {'amount_minor': 2000000001}, {'reason': ' '},
      {'reason': List.filled(501, 'x').join()},
      {'status': 'paid'}, {'ocpi_credit_record_id': '7'},
      {'ocpi_credit_record_id': 0},
    ]) {
      expect(
        () => nativeRefundHistoryRecord({...row, ...entry}),
        throwsFormatException,
      );
    }
  });

  testWidgets('first inactive mount waits for explicit refresh', (tester) async {
    final api = RefundHistoryApi();
    addTearDown(() {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      api.events.close();
    });
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(api.reads, 0);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.reads, 0);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.reads, 1);
    expect(find.text('Private refund fixture'), findsOneWidget);
    expect(api.writes, 0);
  });

  testWidgets('malformed history cannot create review controls', (tester) async {
    final api = RefundHistoryApi();
    addTearDown(() { api.events.close(); });
    api.row['amount_minor'] = '125';
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(find.text('Private refund fixture'), findsNothing);
    expect(find.text('Review request'), findsNothing);
    api.row['amount_minor'] = 125;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Review request'), findsOneWidget);
    expect(api.writes, 0);
  });

  testWidgets('background invalidates retained review action after resume', (tester) async {
    final api = RefundHistoryApi();
    addTearDown(() {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      api.events.close();
    });
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    final action = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Review request'),
    ).onPressed!;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    action();
    await tester.pumpAndSettle();
    expect(find.byType(RefundDecisionView), findsNothing);
    expect(find.text('Private refund fixture'), findsNothing);
    expect(api.reads, 1);
    expect(api.writes, 0);
  });

  testWidgets('late refund read cannot restore private history after revocation', (tester) async {
    final api = RefundHistoryApi();
    final pending = Completer<dynamic>();
    api.waiting = pending;
    addTearDown(() { api.events.close(); });
    await tester.pumpWidget(screen(api));
    await tester.pump();
    expect(api.reads, 1);
    api.revoke();
    pending.complete(api.response());
    await tester.pumpAndSettle();
    expect(find.text('Private refund fixture'), findsNothing);
    expect(find.text('Review request'), findsNothing);
    expect(api.writes, 0);
  });

  testWidgets('revoked session clears private data and rejects old actions', (tester) async {
    final api = RefundHistoryApi();
    addTearDown(() { api.events.close(); });
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    final action = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Review request'),
    ).onPressed!;
    api.revoke();
    action();
    await tester.pumpAndSettle();
    expect(find.byType(RefundDecisionView), findsNothing);
    expect(find.text('Private refund fixture'), findsNothing);
    expect(api.writes, 0);
    expect(api.reads, 1);
  });
}
