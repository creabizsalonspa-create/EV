import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_OCPI_INVOICE_FIXTURE'];
  test(
    'native actual source TEST invoice consent uses canonical ledger, immutable replay and owned history',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final base = Uri.parse(f['base']);
      expect(base.host, '127.0.0.1');
      expect(base.scheme, 'http');
      final api = EvApi(base);
      addTearDown(api.close);
      await api.signIn(f['driver'], f['password']);
      final root = 'roaming/sandbox-orders/${f['order_id']}';
      final before = await api.request(root);
      expect(before['state'], 'source_completed');
      expect(before['local_invoice_created'], false);
      expect(before['can_preview_test_invoice'], true);
      final p = await api.request('$root/test-invoice-preview');
      expect(p['automatic_charge'], false);
      expect(p['total_minor'], 25);
      expect(p['currency'], 'EUR');
      final body = {
        'request_key': operationKey(),
        'preview_token': p['preview_token'],
        'terms_version': p['terms_version'],
        'consent': true,
        'expected_total_minor': p['total_minor'],
        'expected_currency': p['currency'],
        'expected_minor_units': p['minor_units'],
        'expected_energy_wh': p['energy_wh'],
      };
      await expectLater(
        api.request(
          '$root/test-invoice',
          method: 'POST',
          body: {...body, 'expected_total_minor': 26},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      final result = await api.request(
        '$root/test-invoice',
        method: 'POST',
        body: body,
      );
      expect(result['billing_origin'], 'OCPI_TEST_EXPLICIT_CONSENT');
      expect(result['ledger_balanced'], true);
      expect(result['real_payment_processed'], false);
      final id = result['invoice']['id'];
      expect(result['invoice']['total_minor'], 25);
      expect(result['invoice']['evidence'], 'SIMULATED');
      expect(
        (await api.request(
          '$root/test-invoice',
          method: 'POST',
          body: body,
        ))['invoice']['id'],
        id,
      );
      final resolve = await api.request(
        '$root/test-invoice-resolve',
        method: 'POST',
        body: {'request_key': body['request_key'], 'cancel_unissued': true},
      );
      expect(resolve['state'], 'issued');
      expect(resolve['invoice_id'], id);
      expect(resolve['financial_records_changed'], false);
      final order = await api.request(root);
      expect(order['test_invoice_id'], id);
      expect(order['local_invoice_created'], true);
      expect(order['physical_charging_verified'], false);
      final summary = await api.request('finance/invoices/$id');
      expect(summary['invoice']['energy_wh'], 1000);
      expect(summary['payments'], isEmpty);
      expect((await api.request('simulations'))['data'], isEmpty);
      await api.signOut();
      await api.signIn(f['other'], f['password']);
      await expectLater(
        api.request('finance/invoices/$id'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.request('$root/test-invoice-preview'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
    },
    skip: path == null
        ? 'Requires private approved local CPO TEST billing fixture; no real collection or physical acceptance.'
        : false,
  );
}
