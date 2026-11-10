import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'package:creabiz_ev/ocpi_test_invoice.dart';
import 'test_support.dart';

class InvoiceMemoryStore implements FinancialOperationStore {
  final values = <String, String>{};
  bool fail = false;
  @override
  Future<Map<String, dynamic>?> read(String key) async => values[key] == null
      ? null
      : Map<String, dynamic>.from(jsonDecode(values[key]!) as Map);
  @override
  Future<void> write(String key, Map<String, dynamic> value) async {
    if (fail) throw StateError('PRIVATE secure storage failure');
    values[key] = jsonEncode(value);
  }

  @override
  Future<void> remove(String key) async {
    values.remove(key);
  }
}

class TestInvoiceApi extends FixtureApi {
  final InvoiceMemoryStore store;
  TestInvoiceApi(this.store);
  bool lost = false, commit = true, created = false, unsafe = false;
  int posts = 0, resolutions = 0, actor = 1;
  final bodies = <Map<String, dynamic>>[];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'me') return {'id': actor, 'role': 'customer'};
    if (path.endsWith('/test-invoice-resolve')) {
      resolutions++;
      return {
        'state': created ? 'issued' : 'cancelled_unissued',
        'order_id': 3,
        'invoice_id': created ? 5 : null,
        'real_payment_processed': false,
        'financial_records_changed': false,
      };
    }
    if (method == 'POST' && path.endsWith('/test-invoice')) {
      expect(
        store.values,
        isNotEmpty,
        reason: 'Private identity must be durable before dispatch',
      );
      posts++;
      bodies.add(Map<String, dynamic>.from(body!));
      if (commit) created = true;
      if (lost) {
        lost = false;
        throw const ApiException(503, 'PRIVATE source transport');
      }
      return {
        'billing_origin': 'OCPI_TEST_EXPLICIT_CONSENT',
        'roaming_order_id': 3,
        'invoice': {
          'id': 5,
          'evidence': 'SIMULATED',
          'total_minor': 25,
          'currency': 'EUR',
        },
        'real_payment_processed': false,
        'payment_provider': 'SIMULATOR_ONLY',
      };
    }
    if (path.endsWith('/test-invoice-preview'))
      return {
        'evidence': 'SIMULATED',
        'physical_charging_verified': false,
        'real_payment_processed': unsafe,
        'automatic_charge': false,
        'terms_version': 'ocpi-simulated-invoice-v1',
        'currency': 'EUR',
        'minor_units': 2,
        'preview_token': 'signed-private-test-preview',
        'provider_name': 'Approved synthetic CPO',
        'location_name': 'Private source site',
        'energy_wh': 1000,
        'rate_minor_per_kwh': 25,
        'tax_bps': 0,
        'subtotal_minor': 25,
        'tax_minor': 0,
        'total_minor': 25,
      };
    return {
      'id': 3,
      'evidence': 'SANDBOX_PARTNER_UNVERIFIED',
      'physical_charging_verified': false,
      'live_commands_enabled': false,
      'local_invoice_created': created,
      'test_invoice_id': created ? 5 : null,
      'test_invoice_evidence': created ? 'SIMULATED_CANONICAL_LEDGER' : null,
      'real_payment_processed': false,
    };
  }
}

Widget app(
  TestInvoiceApi api,
  InvoiceMemoryStore store, {
  VoidCallback? expired,
}) => MaterialApp(
  home: OcpiTestInvoiceView(
    api: api,
    store: store,
    orderId: 3,
    onExpired: expired ?? () {},
  ),
);
Future<void> consentAndSend(WidgetTester t) async {
  final check = find.byType(CheckboxListTile);
  await t.ensureVisible(check);
  await t.pumpAndSettle();
  await t.tap(check);
  await t.pumpAndSettle();
  final send = find.widgetWithText(FilledButton, 'Create simulated invoice');
  await t.ensureVisible(send);
  await t.pumpAndSettle();
  await t.tap(send);
  await t.pumpAndSettle();
}

void main() {
  testWidgets(
    'lost invoice survives restart with frozen private identity; reads/background never resend',
    (tester) async {
      final store = InvoiceMemoryStore();
      final actual = TestInvoiceApi(store)..lost = true;
      await tester.pumpWidget(app(actual, store));
      await tester.pumpAndSettle();
      final send = find.widgetWithText(
        FilledButton,
        'Create simulated invoice',
      );
      expect(tester.widget<FilledButton>(send).onPressed, isNull);
      await consentAndSend(tester);
      expect(actual.posts, 1);
      expect(store.values.length, 1);
      expect(find.textContaining('PRIVATE'), findsNothing);
      final first = actual.bodies.single;
      await tester.pumpWidget(const SizedBox());
      await tester.pumpAndSettle();
      await tester.pumpWidget(app(actual, store));
      await tester.pumpAndSettle();
      expect(find.text('Saved invoice total: 0.25 EUR'), findsOneWidget);
      expect(actual.posts, 1);
      await tester.tap(find.text('Refresh invoice preview'));
      await tester.pumpAndSettle();
      expect(actual.posts, 1);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.textContaining('0.25'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(actual.posts, 1);
      await tester.ensureVisible(
        find.text('Retry same simulated invoice request'),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Retry same simulated invoice request'));
      await tester.pumpAndSettle();
      expect(actual.posts, 2);
      expect(actual.bodies.last, first);
      expect(store.values, isEmpty);
      expect(find.text('View simulated invoice'), findsOneWidget);
    },
  );
  testWidgets(
    'explicit resolution cancels an unissued request and never creates or refunds an invoice',
    (tester) async {
      final store = InvoiceMemoryStore();
      final actual = TestInvoiceApi(store)
        ..lost = true
        ..commit = false;
      await tester.pumpWidget(app(actual, store));
      await tester.pumpAndSettle();
      await consentAndSend(tester);
      await tester.ensureVisible(find.text('Resolve saved invoice request'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Resolve saved invoice request'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Resolve'));
      await tester.pumpAndSettle();
      expect(actual.posts, 1);
      expect(actual.resolutions, 1);
      expect(actual.created, false);
      expect(store.values, isEmpty);
      expect(find.text('View simulated invoice'), findsNothing);
    },
  );
  testWidgets(
    'secure persistence failure prevents dispatch and changed accounts clear financial data',
    (tester) async {
      final store = InvoiceMemoryStore();
      final api = TestInvoiceApi(store);
      await tester.pumpWidget(app(api, store));
      await tester.pumpAndSettle();
      store.fail = true;
      await consentAndSend(tester);
      expect(api.posts, 0);
      expect(find.textContaining('PRIVATE'), findsNothing);
      store.fail = false;
      await tester.tap(find.text('Refresh invoice preview'));
      await tester.pumpAndSettle();
      api.lost = true;
      await consentAndSend(tester);
      expect(api.posts, 1);
      api.actor = 99;
      await tester.ensureVisible(find.text('Refresh invoice preview'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Refresh invoice preview'));
      await tester.pumpAndSettle();
      expect(find.textContaining('0.25'), findsNothing);
      expect(find.text('Retry same simulated invoice request'), findsNothing);
      expect(api.posts, 1);
    },
  );
  testWidgets('unexpected real payment capability hides invoice consent', (
    tester,
  ) async {
    final store = InvoiceMemoryStore();
    final api = TestInvoiceApi(store)..unsafe = true;
    await tester.pumpWidget(app(api, store));
    await tester.pumpAndSettle();
    expect(find.text('Create simulated invoice'), findsNothing);
    expect(api.posts, 0);
  });
}
