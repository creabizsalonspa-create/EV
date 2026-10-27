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

void main() {
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
      expect(find.text('Ledger balanced'), findsOneWidget);
      expect(find.byType(TextField), findsNothing);
      api.fail = true;
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
