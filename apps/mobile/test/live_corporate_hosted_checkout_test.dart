import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/hosted_checkout.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_CORPORATE_HOSTED_FIXTURE'];
  test(
    'actual Laravel corporate hosted immutable collection, replay and tenant controls',
    () async {
      final f = jsonDecode(await File(path!).readAsString());
      expect(f['fixture_only'], true);
      const base = 'commercial/corporate/hosted-checkouts';
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'], f['password']);
      final sourcePath = 'commercial/corporate/statements/${f['statement_id']}';
      final source = await api.request(sourcePath);
      expect(source['can_collect_hosted'], true);
      expect(source['receipt'], null);
      final body = <String, dynamic>{
        'statement_id': f['statement_id'],
        'consent': true,
        'idempotency_key': 'native-corporate-${f['statement_id']}',
      };
      Future<HostedCheckoutRecord> action(
        String suffix, {
        Map<String, dynamic>? value,
      }) async => HostedCheckoutRecord(
        (await api.request(
          '$base$suffix',
          method: 'POST',
          body: value ?? {},
        ))['checkout'],
        corporate: true,
      );
      final first = await action('', value: body);
      expect(first.source['amount_minor'], f['amount_minor']);
      expect(first.source['currency'], f['currency']);
      expect(first.source['minor_units'], f['minor_units']);
      expect(first.source['line_fingerprint'], source['line_fingerprint']);
      expect(first.source['owner'], true);
      expect(first.source['evidence'], 'MOCK_HOSTED');
      expect((await action('', value: body)).id, first.id);
      await expectLater(
        action('', value: {...body, 'amount_minor': 1}),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)),
      );
      expect((await action('/${first.id}/inspect')).status, 'open');
      expect(
        (await action(
          '/${first.id}/simulate',
          value: {'consent': true, 'outcome': 'authorized'},
        )).status,
        'authorized',
      );
      expect((await api.request(sourcePath))['receipt'], null);
      expect((await action('/${first.id}/capture')).status, 'captured');
      expect((await action('/${first.id}/capture')).status, 'captured');
      final paid = await api.request(sourcePath);
      expect(paid['real_payment_processed'], false);
      expect(paid['receipt']['amount_minor'], f['amount_minor']);
      final receiptId = paid['receipt']['id'];
      await action('/${first.id}/inspect');
      expect((await api.request(sourcePath))['receipt']['id'], receiptId);
      expect(
        (await api.request(
          '$base/by-reference/${first.source['reference']}',
        ))['checkout']['id'],
        first.id,
      );
      await api.signIn(f['driver'], f['password']);
      var allocated = 0;
      for (final id in f['invoice_ids']) {
        final invoice = await api.request('finance/invoices/$id');
        expect(invoice['amount_due_minor'], 0);
        expect(invoice['ledger_balanced'], true);
        allocated += invoice['paid_minor'] as int;
      }
      expect(allocated, f['amount_minor']);
      await expectLater(
        api.request('$base/${first.id}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signIn(f['vendor'], f['password']);
      await expectLater(
        api.request('$base/by-reference/${first.source['reference']}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        action('/${first.id}/capture'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Requires explicit local Laravel corporate fixture.'
        : false,
  );
}
