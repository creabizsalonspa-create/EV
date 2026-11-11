import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/source_credit_picker.dart';
import 'test_support.dart';

class CreditOptionsApi extends FixtureApi {
  final calls = <String>[];
  bool active = true, wrongInvoice = false;
  Completer<dynamic>? wait;
  @override
  bool Function() sessionFence() =>
      () => active;
  Map<String, dynamic> result(int page) => {
    'schema_version': 1,
    'invoice_id': wrongInvoice ? 999 : 3,
    'financial_authority': false,
    'automatic_refund': false,
    'current_page': page,
    'last_page': 2,
    'total': 26,
    'data': [
      for (final id in [7, 8])
        {
          'id': id,
          'external_id': 'private-ref-$id',
          'available_for_review': id == 7,
          'existing_refund_state': id == 8 ? 'pending' : null,
          'evidence': {
            'schema_version': 1,
            'invoice_id': 3,
            'credit_record_id': id,
            'status': 'source_reference_matched',
            'financial_authority': false,
            'automatic_refund': false,
            'currency': 'EUR',
            'reported_cost': {'excl_vat': '0.250000'},
          },
        },
    ],
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(path, 'finance/invoices/3/source-credits');
    calls.add(query['page']!);
    if (wait != null) return wait!.future;
    return result(int.parse(query['page']!));
  }
}

void main() {
  testWidgets(
    'invoice-scoped source list paginates and prevents selecting an already reviewed reference',
    (tester) async {
      final api = CreditOptionsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: SourceCreditPickerView(
            api: api,
            invoiceId: 3,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('private-ref-7'), findsOneWidget);
      expect(
        tester
            .widget<OutlinedButton>(
              find
                  .widgetWithText(OutlinedButton, 'Select credit reference')
                  .at(0),
            )
            .onPressed,
        isNotNull,
      );
      expect(
        tester
            .widget<OutlinedButton>(
              find
                  .widgetWithText(OutlinedButton, 'Select credit reference')
                  .at(1),
            )
            .onPressed,
        isNull,
      );
      await tester.ensureVisible(find.text('Next'));
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(api.calls, ['1', '2']);
    },
  );
  testWidgets(
    'a wrong-invoice result is rejected and backgrounding clears private source references',
    (tester) async {
      final api = CreditOptionsApi()..wrongInvoice = true;
      await tester.pumpWidget(
        MaterialApp(
          home: SourceCreditPickerView(
            api: api,
            invoiceId: 3,
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('private-ref-7'), findsNothing);
      api.wrongInvoice = false;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('private-ref-7'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.text('private-ref-7'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.calls, hasLength(2));
    },
  );
  testWidgets(
    'a late response from a different authenticated context stays hidden',
    (tester) async {
      final api = CreditOptionsApi()..wait = Completer<dynamic>();
      await tester.pumpWidget(
        MaterialApp(
          home: SourceCreditPickerView(
            api: api,
            invoiceId: 3,
            onExpired: () {},
          ),
        ),
      );
      await tester.pump();
      api.active = false;
      api.wait!.complete(api.result(1));
      await tester.pumpAndSettle();
      expect(find.text('private-ref-7'), findsNothing);
    },
  );
}
