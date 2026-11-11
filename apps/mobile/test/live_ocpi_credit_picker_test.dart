import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_OCPI_CREDIT_FIXTURE'];
  test(
    'native invoice-scoped partner credit selection uses canonical reviewed mock funds exactly once',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final base = Uri.parse(f['base']);
      expect(base.host, '127.0.0.1');
      expect(base.scheme, 'http');
      final driver = EvApi(base), vendor = EvApi(base), other = EvApi(base);
      addTearDown(driver.close);
      addTearDown(vendor.close);
      addTearDown(other.close);
      await driver.signIn(f['driver'], f['password']);
      final invoice = 'finance/invoices/${f['invoice_id']}';
      final options = await driver.request('$invoice/source-credits');
      expect(options['invoice_id'], f['invoice_id']);
      expect(options['financial_authority'], false);
      expect(options['automatic_refund'], false);
      expect(options['data'], hasLength(1));
      final credit = options['data'][0];
      expect(credit['id'], f['credit_id']);
      expect(credit['available_for_review'], true);
      expect(credit['evidence']['status'], 'source_reference_matched');
      expect(credit['evidence']['reported_cost']['excl_vat'], '0.25');
      final body = {
        'idempotency_key': operationKey(),
        'ocpi_credit_record_id': credit['id'],
        'amount_minor': 25,
        'reason': 'Native synthetic partner credit review',
      };
      final request = await driver.request(
        '$invoice/refund-requests',
        method: 'POST',
        body: body,
      );
      expect(request['ocpi_credit_record_id'], f['credit_id']);
      expect(request['status'], 'pending');
      final replay = await driver.request(
        '$invoice/refund-requests',
        method: 'POST',
        body: body,
      );
      expect(replay['id'], request['id']);
      final linked = await driver.request('$invoice/source-credits');
      expect(linked['data'][0]['available_for_review'], false);
      expect(linked['data'][0]['existing_refund_request_id'], request['id']);
      expect((await driver.request(invoice))['refunds'], isEmpty);
      await vendor.signIn(f['owner'], f['password']);
      final review = 'finance/refund-requests/${request['id']}/review';
      const decision = {
        'decision': 'approve',
        'note':
            'Reviewed synthetic captured funds and matching source reference',
      };
      await vendor.request(review, method: 'POST', body: decision);
      await vendor.request(review, method: 'POST', body: decision);
      final summary = await driver.request(invoice);
      expect(summary['refunds'], hasLength(1));
      expect(summary['refunded_minor'], 25);
      expect(summary['ledger_balanced'], true);
      expect(summary['real_payment_processed'], false);
      await other.signIn(f['other'], f['password']);
      await expectLater(
        other.request('$invoice/source-credits'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await driver.signOut();
      await vendor.signOut();
      await other.signOut();
    },
    skip: path == null
        ? 'Requires owned local synthetic CPO and mock funds; no real partner, merchant or hardware acceptance.'
        : false,
  );
}
