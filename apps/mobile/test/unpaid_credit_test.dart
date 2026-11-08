import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/unpaid_credit.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'test_support.dart';

class CreditStore implements FinancialOperationStore {
  final values = <String, String>{};
  @override
  Future<Map<String, dynamic>?> read(String scope) async =>
      values[scope] == null
      ? null
      : Map<String, dynamic>.from(jsonDecode(values[scope]!) as Map);
  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    values[scope] = jsonEncode(value);
  }

  @override
  Future<void> remove(String scope) async {
    values.remove(scope);
  }
}

class UnpaidApi extends FixtureApi {
  final posts = <Map<String, dynamic>>[];
  Map<String, dynamic>? issued;
  bool lose = true, commit = true;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') return {'id': 1, 'role': 'vendor'};
    if (path.endsWith('/source-credits')) {
      expect(query['full_reversal'], '1');
      return {
        'schema_version': 1,
        'invoice_id': 3,
        'financial_authority': false,
        'automatic_refund': false,
        'current_page': 1,
        'last_page': 1,
        'total': 1,
        'data': [
          {
            'id': 7,
            'external_id': 'FULL-PARTNER-CREDIT',
            'available_for_review': true,
            'full_reversal_source_match': true,
            'evidence': {
              'schema_version': 1,
              'invoice_id': 3,
              'credit_record_id': 7,
              'status': 'source_reference_matched',
              'financial_authority': false,
              'automatic_refund': false,
              'currency': 'EUR',
              'reported_cost': {'excl_vat': '0.25'},
            },
          },
        ],
      };
    }
    if (method == 'GET')
      return {
        'invoice': {
          'id': 3,
          'number': 'SIM-UNPAID-3',
          'total_minor': 25,
          'currency': 'EUR',
          'minor_units': 2,
        },
        'can_issue_unpaid_credit': issued == null,
        'unpaid_credit_notes': [if (issued != null) issued],
      };
    posts.add(Map<String, dynamic>.from(body!));
    if (path.endsWith('/unpaid-credit-resolve'))
      return {
        'invoice_id': 3,
        'state': issued == null ? 'cancelled_unissued' : 'issued',
        'cash_moved': false,
        if (issued != null) 'credit_note': issued,
      };
    expect(path, 'finance/invoices/3/unpaid-credit');
    if (commit)
      issued = {
        'id': 9,
        'invoice_id': 3,
        'actor_id': 1,
        'request_key': body['idempotency_key'],
        'credit_record_id': 7,
        'reason': body['reason'],
        'amount_minor': 25,
        'currency': 'EUR',
        'minor_units': 2,
        'evidence': 'SIMULATED_NONCASH',
        'credit_note_number': 'SIM-UCN-9',
      };
    if (lose) throw const ApiException(0, 'PRIVATE TRANSPORT');
    return {
      'invoice_id': 3,
      'credit_note': issued,
      'cash_moved': false,
      'refund_created': false,
    };
  }
}

Future<void> prepare(WidgetTester tester) async {
  await tester.tap(find.text('Choose full partner credit'));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Select credit reference'));
  await tester.pumpAndSettle();
  await tester.enterText(
    find.byType(TextField),
    'Source correction before collection',
  );
  await tester.tap(find.byType(CheckboxListTile));
  await tester.pumpAndSettle();
  await tester.ensureVisible(find.text('Issue full noncash TEST credit'));
  await tester.tap(find.text('Issue full noncash TEST credit'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'committed unknown credit resolves from durable identity even when eligibility has closed',
    (tester) async {
      final api = UnpaidApi(), store = CreditStore();
      Widget view() => MaterialApp(
        home: UnpaidCreditView(
          api: api,
          invoiceId: 3,
          onExpired: () {},
          store: store,
        ),
      );
      await tester.pumpWidget(view());
      await tester.pumpAndSettle();
      await prepare(tester);
      expect(api.posts, hasLength(1));
      expect(store.values, hasLength(1));
      expect(find.textContaining('PRIVATE TRANSPORT'), findsNothing);
      await tester.pumpWidget(const MaterialApp(home: Text('closed')));
      await tester.pumpWidget(view());
      await tester.pumpAndSettle();
      expect(api.posts, hasLength(1));
      await tester.ensureVisible(
        find.text('Resolve or cancel unissued request'),
      );
      await tester.tap(find.text('Resolve or cancel unissued request'));
      await tester.pumpAndSettle();
      expect(api.posts[1]['idempotency_key'], api.posts[0]['idempotency_key']);
      expect(store.values, isEmpty);
      expect(find.text('SIM-UCN-9'), findsOneWidget);
      expect(find.text('Issue full noncash TEST credit'), findsNothing);
    },
  );
  testWidgets(
    'explicit retry preserves body and key without a payment or refund request',
    (tester) async {
      final api = UnpaidApi(), store = CreditStore();
      await tester.pumpWidget(
        MaterialApp(
          home: UnpaidCreditView(
            api: api,
            invoiceId: 3,
            onExpired: () {},
            store: store,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await prepare(tester);
      api.lose = false;
      await tester.ensureVisible(find.text('Retry saved credit request'));
      await tester.tap(find.text('Retry saved credit request'));
      await tester.pumpAndSettle();
      expect(api.posts, hasLength(2));
      expect(api.posts[1], api.posts[0]);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'backgrounding wipes credit inputs and leaves recovery to explicit refresh',
    (tester) async {
      final api = UnpaidApi()..commit = false, store = CreditStore();
      await tester.pumpWidget(
        MaterialApp(
          home: UnpaidCreditView(
            api: api,
            invoiceId: 3,
            onExpired: () {},
            store: store,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await prepare(tester);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.byType(TextField), findsNothing);
      expect(store.values, hasLength(1));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.posts, hasLength(1));
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.text('Resolve or cancel unissued request'),
      );
      await tester.tap(find.text('Resolve or cancel unissued request'));
      await tester.pumpAndSettle();
      expect(store.values, isEmpty);
      expect(api.issued, isNull);
      expect(api.posts.last['cancel_unissued'], true);
    },
  );
}
