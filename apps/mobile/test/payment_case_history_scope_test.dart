import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/payment_cases.dart';
import 'payment_cases_test.dart' show CaseApi, app;

class ScopedCaseApi extends CaseApi {
  int epoch = 0, reads = 0;
  final events = StreamController<int>.broadcast(sync: true);
  Completer<dynamic>? waiting;
  dynamic amount = 1250;

  @override
  Stream<int> get sessionEvents => events.stream;

  @override
  bool Function() sessionFence() {
    final original = epoch;
    return () => epoch == original;
  }

  void revoke() {
    epoch++;
    events.add(epoch);
  }

  Map<String, dynamic> response() => {
    'data': [{
      'id': 7,
      'invoice_id': 3,
      'amount_minor': amount,
      'reason': 'Private case history fixture',
      'status': 'open',
      'evidence': 'MOCK_PROVIDER',
      'real_money_moved': false,
    }],
    'current_page': 1,
    'last_page': 1,
    'total': 1,
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path != 'commercial/cases' || method != 'GET') {
      throw StateError('Unexpected case operation');
    }
    reads++;
    final pending = waiting;
    waiting = null;
    if (pending != null) return pending.future;
    return response();
  }
}

Widget screen(ScopedCaseApi api) => app(
  PaymentCasesView(api: api, onExpired: () {}),
);

void main() {
  testWidgets('case history mounts inactive without private reads', (t) async {
    final api = ScopedCaseApi();
    addTearDown(() {
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      api.events.close();
    });
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    expect(api.reads, 0);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pumpAndSettle();
    expect(api.reads, 0);
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(api.reads, 1);
    expect(find.text('Disputed amount (minor units): 1250'), findsOneWidget);
  });

  testWidgets('invalid exact amount cannot create evidence action', (t) async {
    final api = ScopedCaseApi()..amount = '1250';
    addTearDown(() { api.events.close(); });
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    expect(find.text('Private case history fixture'), findsNothing);
    expect(find.text('Private evidence'), findsNothing);
    api.amount = 1250;
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(find.text('Private evidence'), findsOneWidget);
  });

  testWidgets('revocation removes rows and invalidates retained evidence action', (t) async {
    final api = ScopedCaseApi();
    addTearDown(() { api.events.close(); });
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    final action = t.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Private evidence'),
    ).onPressed!;
    api.revoke();
    action();
    await t.pumpAndSettle();
    expect(find.text('Private case history fixture'), findsNothing);
    expect(find.byType(CaseEvidenceView), findsNothing);
    expect(api.reads, 1);
  });

  testWidgets('background invalidates old evidence actions even after resume', (t) async {
    final api = ScopedCaseApi();
    addTearDown(() {
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      api.events.close();
    });
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    final action = t.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Private evidence'),
    ).onPressed!;
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pumpAndSettle();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pumpAndSettle();
    action();
    await t.pumpAndSettle();
    expect(find.byType(CaseEvidenceView), findsNothing);
    expect(find.text('Private case history fixture'), findsNothing);
    expect(api.reads, 1);
  });

  testWidgets('delayed case history cannot restore revoked session rows', (t) async {
    final api = ScopedCaseApi();
    final pending = Completer<dynamic>();
    api.waiting = pending;
    addTearDown(() { api.events.close(); });
    await t.pumpWidget(screen(api));
    await t.pump();
    expect(api.reads, 1);
    api.revoke();
    pending.complete(api.response());
    await t.pumpAndSettle();
    expect(find.text('Private case history fixture'), findsNothing);
    expect(find.text('Private evidence'), findsNothing);
  });
}
