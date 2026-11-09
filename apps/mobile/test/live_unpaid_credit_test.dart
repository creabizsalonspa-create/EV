import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_UNPAID_CREDIT_FIXTURE'];
  test(
    'actual native unpaid credit uses one canonical noncash journal and preserves no-collection truth',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final base = Uri.parse(f['base']);
      expect(base.host, '127.0.0.1');
      expect(base.scheme, 'http');
      final owner = EvApi(base), driver = EvApi(base);
      addTearDown(owner.close);
      addTearDown(driver.close);
      await owner.signIn(f['owner'], f['password']);
      final root = 'finance/invoices/${f['invoice_id']}';
      final summary = await owner.request(root);
      expect(summary['can_issue_unpaid_credit'], true);
      final options = await owner.request(
        '$root/source-credits',
        query: {'full_reversal': '1'},
      );
      expect(options['data'][0]['full_reversal_source_match'], true);
      final invoice = summary['invoice'];
      final body = {
        'idempotency_key': operationKey(),
        'credit_record_id': f['credit_id'],
        'reason': 'Native full source correction without collection',
        'consent': true,
        'expected_actor_id': (await owner.request('me'))['id'],
        'expected_invoice_number': invoice['number'],
        'expected_total_minor': invoice['total_minor'],
        'expected_currency': invoice['currency'],
        'expected_minor_units': invoice['minor_units'],
      };
      final reportPath =
          'ocpi/providers/${f['provider_id']}/billing-reconciliation';
      final beforeReport = await owner.request(reportPath);
      expect(beforeReport['read_only'], true);
      expect(beforeReport['payment_execution'], false);
      expect(beforeReport['data'][0]['billing']['state'], 'unpaid');
      final credit = await owner.request(
        '$root/unpaid-credit',
        method: 'POST',
        body: body,
      );
      expect(credit['cash_moved'], false);
      expect(credit['refund_created'], false);
      final report = await owner.request(
        reportPath,
        query: {'kind': 'credits'},
      );
      expect(report['provider_id'], f['provider_id']);
      expect(report['data'].length, 1);
      expect(report['data'][0]['billing']['invoice']['id'], f['invoice_id']);
      expect(report['data'][0]['billing']['state'], 'credited');
      expect(report['data'][0]['billing']['paid_minor'], 0);
      expect(report['data'][0]['billing']['refunded_minor'], 0);
      expect(report['data'][0]['billing']['due_minor'], 0);
      final replay = await owner.request(
        '$root/unpaid-credit',
        method: 'POST',
        body: body,
      );
      expect(replay['credit_note']['id'], credit['credit_note']['id']);
      final resolved = await owner.request(
        '$root/unpaid-credit-resolve',
        method: 'POST',
        body: {
          'idempotency_key': body['idempotency_key'],
          'expected_actor_id': body['expected_actor_id'],
          'expected_invoice_number': invoice['number'],
          'cancel_unissued': true,
        },
      );
      expect(resolved['state'], 'issued');
      expect(resolved['credit_note']['id'], credit['credit_note']['id']);
      await driver.signIn(f['driver'], f['password']);
      await expectLater(
        driver.request(reportPath),
        throwsA(
          isA<ApiException>().having((error) => error.status, 'status', 404),
        ),
      );
      final finalInvoice = await driver.request(root);
      expect(finalInvoice['state'], 'credited');
      expect(finalInvoice['amount_due_minor'], 0);
      expect(finalInvoice['paid_minor'], 0);
      expect(finalInvoice['refunded_minor'], 0);
      expect(finalInvoice['payments'], isEmpty);
      expect(finalInvoice['refunds'], isEmpty);
      expect(finalInvoice['unpaid_credit_notes'], hasLength(1));
      expect(finalInvoice['ledger_balanced'], true);
      expect(finalInvoice['real_payment_processed'], false);
      await expectLater(
        driver.request(
          '$root/simulate-payment',
          method: 'POST',
          body: {'amount_minor': 25, 'idempotency_key': operationKey()},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await expectLater(
        driver.request('$root/unpaid-credit', method: 'POST', body: body),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await owner.signOut();
      await driver.signOut();
    },
    skip: path == null
        ? 'Requires isolated CPO TEST invoice with no collection; no live partner or physical acceptance.'
        : false,
  );
}
