import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/refund_requests.dart';
import 'package:creabiz_ev/payment_cases.dart';
import 'test_support.dart';

/// A private, canonical-API fixture whose responses can arrive after an
/// identity change or after the application is no longer in the foreground.
class PrivateListApi extends FixtureApi {
  PrivateListApi(this.label);

  final String label;
  int identity = 0;
  int reads = 0;
  bool deny = false;
  bool malformedRefund = false, malformedCase = false, malformedAmount = false;
  Completer<dynamic>? delayed;

  final sessionChanges = StreamController<int>.broadcast(sync: true);
  @override
  Stream<int> get sessionEvents => sessionChanges.stream;
  void rotateIdentity() { identity++; sessionChanges.add(identity); }

  @override
  bool Function() sessionFence() {
    final owner = identity;
    return () => owner == identity;
  }

  Map<String, dynamic> response(String path) => {
    'data': [
      if (path == 'finance/refund-requests')
        {
          'id': 8,
          'invoice_id': 3,
          'amount_minor': 125,
          'reason': label,
          'status': 'pending',
        }
      else
        {
          'id': 7,
          'invoice_id': 3,
          'amount_minor': 125,
          'reason': label,
          'status': 'open',
          'evidence': 'MOCK_PROVIDER',
          'real_money_moved': false,
        },
    ],
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
    if (path != 'finance/refund-requests' && path != 'commercial/cases') {
      throw StateError('Unexpected private list request');
    }
    reads++;
    final waiting = delayed;
    delayed = null;
    if (waiting != null) return waiting.future;
    if (deny) throw const ApiException(401, 'Private diagnostic');
    final data = response(path);
    final records = data['data'] as List;
    if (path == 'finance/refund-requests' && malformedRefund) {
      (records.single as Map<String, dynamic>)['id'] = 'forged-id';
    }
    if (path == 'commercial/cases' && malformedCase) {
      (records.single as Map<String, dynamic>)['status'] = 'invented_paid';
    }
    if (path == 'commercial/cases' && malformedAmount) {
      (records.single as Map<String, dynamic>)['amount_minor'] = '125.00';
    }
    return data;
  }
}

Widget screen(Widget page) => MaterialApp(home: page);

void main() {
  testWidgets('refund records disappear when inactive and late reads are discarded', (
    tester,
  ) async {
    final api = PrivateListApi('Old private refund');
    final waiting = Completer<dynamic>();
    api.delayed = waiting;
    addTearDown(() =>
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));

    await tester.pumpWidget(screen(RefundRequestsView(
      api: api,
      role: 'customer',
      onExpired: () {},
    )));
    await tester.pump();
    expect(api.reads, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    waiting.complete(api.response('finance/refund-requests'));
    await tester.pumpAndSettle();
    expect(find.text('Old private refund'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.reads, 1);
    expect(find.text('Old private refund'), findsNothing);

    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.reads, 2);
    expect(find.text('Old private refund'), findsOneWidget);

    // A same-instance logout must hide already-rendered private records.
    api.rotateIdentity();
    await tester.pumpWidget(screen(RefundRequestsView(
      api: api,
      role: 'customer',
      onExpired: () {},
    )));
    expect(find.text('Old private refund'), findsNothing);
    final refresh = find.widgetWithText(OutlinedButton, 'Refresh');
    if (refresh.evaluate().isNotEmpty) {
      expect(tester.widget<OutlinedButton>(refresh).onPressed, isNull);
    }
    expect(api.reads, 2);
  });

  testWidgets('refund list discards an expired response from a former identity', (
    tester,
  ) async {
    final api = PrivateListApi('Private refund');
    final waiting = Completer<dynamic>();
    api.delayed = waiting;
    var expired = 0;
    await tester.pumpWidget(screen(RefundRequestsView(
      api: api,
      role: 'customer',
      onExpired: () => expired++,
    )));
    await tester.pump();
    api.rotateIdentity();
    waiting.completeError(const ApiException(401, 'Old identity expired'));
    // The stale request may leave a spinner until the current account
    // replaces the widget. Do not wait for a spinner to settle.
    await tester.pump();
    expect(expired, 0);
    expect(find.text('Old identity expired'), findsNothing);
  });

  testWidgets('case records and retained evidence actions cannot cross identity changes', (
    tester,
  ) async {
    final old = PrivateListApi('Old private dispute');
    await tester.pumpWidget(screen(PaymentCasesView(
      api: old,
      onExpired: () {},
    )));
    await tester.pumpAndSettle();
    expect(find.text('Old private dispute'), findsOneWidget);
    final oldAction = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Private evidence'),
    ).onPressed!;
    old.rotateIdentity();
    oldAction();
    await tester.pumpAndSettle();
    expect(find.byType(CaseEvidenceView), findsNothing);

    // Switching Api objects also resets the previous owner's cached list.
    final current = PrivateListApi('New private dispute');
    await tester.pumpWidget(screen(PaymentCasesView(
      api: current,
      onExpired: () {},
    )));
    await tester.pumpAndSettle();
    expect(find.text('Old private dispute'), findsNothing);
    expect(find.text('New private dispute'), findsNothing);
    expect(current.reads, 0);
    expect(tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Refresh'),
    ).onPressed, isNull);
    // An explicitly reopened route, rather than a revoked route reusing
    // another API, establishes the new owner's independent read authority.
    await tester.pumpWidget(screen(PaymentCasesView(
      key: const ValueKey('new-owner-case-route'),
      api: current,
      onExpired: () {},
    )));
    await tester.pumpAndSettle();
    expect(find.text('New private dispute'), findsOneWidget);
    expect(find.text('Old private dispute'), findsNothing);
    expect(current.reads, 1);
  });

  testWidgets('case history is hidden while backgrounded; valid expiry is reported once', (
    tester,
  ) async {
    final api = PrivateListApi('Sensitive case evidence');
    var expired = 0;
    addTearDown(() =>
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(screen(PaymentCasesView(
      api: api,
      onExpired: () => expired++,
    )));
    await tester.pumpAndSettle();
    expect(find.text('Sensitive case evidence'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.text('Sensitive case evidence'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.reads, 1);
    api.deny = true;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(expired, 1);
    expect(find.text('Private diagnostic'), findsNothing);
  });
  testWidgets('malformed financial history fails closed until explicit valid refresh', (
    tester,
  ) async {
    final api = PrivateListApi('Valid refund')..malformedRefund = true;
    await tester.pumpWidget(screen(RefundRequestsView(
      api: api,
      role: 'customer',
      onExpired: () {},
    )));
    await tester.pumpAndSettle();
    expect(find.text('Valid refund'), findsNothing);
    expect(api.reads, 1);
    api.malformedRefund = false;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Valid refund'), findsOneWidget);
    expect(api.reads, 2);
  });

  testWidgets('forged payment case outcome cannot appear or open private evidence', (
    tester,
  ) async {
    final api = PrivateListApi('Untrusted case')..malformedCase = true;
    await tester.pumpWidget(screen(PaymentCasesView(
      api: api,
      onExpired: () {},
    )));
    await tester.pumpAndSettle();
    expect(find.text('Untrusted case'), findsNothing);
    expect(find.text('Private evidence'), findsNothing);
    api.malformedCase = false;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Untrusted case'), findsOneWidget);
  });

  testWidgets('case history refuses a textual money amount until a validated refresh', (
    tester,
  ) async {
    final api = PrivateListApi('Amount must remain exact')..malformedAmount = true;
    await tester.pumpWidget(screen(PaymentCasesView(
      api: api,
      onExpired: () {},
    )));
    await tester.pumpAndSettle();
    expect(find.text('Amount must remain exact'), findsNothing);
    expect(find.textContaining('Disputed amount (minor units):'), findsNothing);
    api.malformedAmount = false;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Amount must remain exact'), findsOneWidget);
    expect(find.text('Disputed amount (minor units): 125'), findsOneWidget);
  });

}
