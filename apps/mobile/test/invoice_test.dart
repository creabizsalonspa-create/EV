import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/invoice.dart';
import 'test_support.dart';

class InvoiceFixture extends FixtureApi {
  bool fail = false, invalidEvidence = false;
  int requests = 0;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'finance/sessions/7/invoice');
    expect(method, 'POST');
    requests++;
    if (fail) throw const ApiException(401, 'expired');
    return {
      'invoice': {
        'number': 'SIM-FIXTURE',
        'evidence': 'SIMULATED',
        'subtotal_minor': 313,
        'tax_minor': 16,
        'total_minor': 329,
        'currency': 'AED',
      },
      'paid_minor': 329,
      'refunded_minor': 329,
      'amount_due_minor': 0,
      'ledger_balanced': true,
      'payment_provider': 'SIMULATOR_ONLY',
      'real_payment_processed': invalidEvidence,
    };
  }
}

class DeferredInvoiceApi extends InvoiceFixture {
  final pending = Completer<dynamic>();
  int actorEpoch = 0;
  @override
  bool Function() sessionFence() {
    final captured = actorEpoch;
    return () => captured == actorEpoch;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'finance/sessions/7/invoice');
    expect(method, 'POST');
    requests++;
    return pending.future;
  }
}

void main() {
  testWidgets('late old-account invoice result cannot repopulate financial actions', (
    tester,
  ) async {
    final api = DeferredInvoiceApi();
    addTearDown(api.close);
    var expired = false;
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () => expired = true),
    ));
    await tester.pump();
    api.actorEpoch++;
    api.pending.complete({
      'invoice': {
        'number': 'PRIVATE-OLD-INVOICE',
        'evidence': 'SIMULATED',
        'id': 7,
        'user_id': 123,
        'minor_units': 2,
        'currency': 'AED',
      },
      'payment_provider': 'SIMULATOR_ONLY',
      'real_payment_processed': false,
    });
    await tester.pump();
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () => expired = true),
    ));
    await tester.pump();
    expect(find.text('PRIVATE-OLD-INVOICE'), findsNothing);
    expect(find.byType(OutlinedButton), findsNothing);
    expect(expired, isFalse);
    expect(api.requests, 1);
  });

  testWidgets('background discards invoice and never automatically replays POST', (
    tester,
  ) async {
    final api = InvoiceFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
    ));
    await tester.pumpAndSettle();
    expect(find.text('SIM-FIXTURE'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('SIM-FIXTURE'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(api.requests, 1);
    expect(find.text('SIM-FIXTURE'), findsNothing);
    await tester.ensureVisible(find.text('Refresh'));
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.requests, 2);
    expect(find.text('SIM-FIXTURE'), findsOneWidget);
  });
  testWidgets(
    'read-only simulated invoice displays actual minor-unit totals and clears private data on expiry',
    (tester) async {
      final api = InvoiceFixture();
      addTearDown(api.close);
      var expired = false;
      await tester.pumpWidget(
        MaterialApp(
          home: InvoicePage(
            api: api,
            sessionId: 7,
            onExpired: () => expired = true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('SIM-FIXTURE'), findsOneWidget);
      expect(find.text('Tax: 16 minor units AED'), findsOneWidget);
      expect(find.text('Amount due: 0 minor units AED'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Ledger balanced'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Ledger balanced'), findsOneWidget);
      expect(find.byType(TextField), findsNothing);
      api.fail = true;
      await tester.scrollUntilVisible(
        find.text('Refresh'),
        -200,
        scrollable: find.byType(Scrollable).first,
      );
      await Scrollable.ensureVisible(
        tester.element(find.text('Refresh')),
        alignment: 0.5,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(expired, isTrue);
      expect(find.text('SIM-FIXTURE'), findsNothing);
      expect(api.requests, 2);
    },
  );
  testWidgets(
    'unsupported finance evidence is not presented as a simulation document',
    (tester) async {
      final api = InvoiceFixture()..invalidEvidence = true;
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('SIM-FIXTURE'), findsNothing);
      expect(find.text('Ledger balanced'), findsNothing);
    },
  );
}
