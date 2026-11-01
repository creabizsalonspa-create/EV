import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/hosted_checkout.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_HOSTED_FIXTURE'];
  test(
    'actual Laravel native hosted charging/subscription source lifecycle replay recovery and tenant controls',
    () async {
      final f = jsonDecode(await File(path!).readAsString());
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['customer'], f['password']);
      final original = await api.request('finance/invoices/${f['invoice']}');
      expect(original['amount_due_minor'], 1050);
      expect(original['paid_minor'], 0);
      final body = <String, dynamic>{
        'scope': 'charging',
        'invoice_id': f['invoice'],
        'idempotency_key': 'native-hosted-${f['invoice']}',
        'consent': true,
      };
      final first = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: body,
        ))['checkout'],
      );
      expect(first.source['evidence'], 'MOCK_HOSTED');
      expect(first.source['amount_minor'], 1050);
      expect(first.status, 'open');
      final replay = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: body,
        ))['checkout'],
      );
      expect(replay.id, first.id);
      await expectLater(
        api.request(
          'commercial/checkouts',
          method: 'POST',
          body: {
            'invoice_id': f['invoice'],
            'amount_minor': 1050,
            'idempotency_key': 'forbidden-replacement',
          },
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await expectLater(
        api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: {...body, 'amount_minor': 1},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)),
      );
      final auth = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${first.id}/simulate',
          method: 'POST',
          body: {'outcome': 'authorized', 'consent': true},
        ))['checkout'],
      );
      expect(auth.status, 'authorized');
      expect(
        (await api.request('finance/invoices/${f['invoice']}'))['paid_minor'],
        0,
      );
      final paid = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${first.id}/capture',
          method: 'POST',
          body: {},
        ))['checkout'],
      );
      expect(paid.status, 'captured');
      await api.request(
        'commercial/hosted-checkouts/${first.id}/capture',
        method: 'POST',
        body: {},
      );
      final finalInvoice = await api.request(
        'finance/invoices/${f['invoice']}',
      );
      expect(finalInvoice['paid_minor'], 1050);
      expect(finalInvoice['amount_due_minor'], 0);
      expect(finalInvoice['ledger_balanced'], true);
      final subBody = <String, dynamic>{
        'scope': 'subscription',
        'invoice_id': f['subscription_invoice'],
        'subscription_id': f['subscription'],
        'idempotency_key': 'native-sub-${f['subscription']}',
        'consent': true,
      };
      final sub = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: subBody,
        ))['checkout'],
      );
      final closed = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${sub.id}/simulate',
          method: 'POST',
          body: {'outcome': 'abandoned', 'consent': true},
        ))['checkout'],
      );
      expect(closed.status, 'cancelled');
      expect(closed.permits('retry'), true);
      final old = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: subBody,
        ))['checkout'],
      );
      expect(old.id, sub.id);
      expect(old.status, 'cancelled');
      final newSub = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: {
            ...subBody,
            'idempotency_key': 'native-confirmed-next-${f['subscription']}',
          },
        ))['checkout'],
      );
      expect(newSub.id, isNot(sub.id));
      await api.request(
        'commercial/hosted-checkouts/${newSub.id}/simulate',
        method: 'POST',
        body: {'outcome': 'authorized', 'consent': true},
      );
      expect(
        HostedCheckoutRecord(
          (await api.request(
            'commercial/hosted-checkouts/${newSub.id}/capture',
            method: 'POST',
            body: {},
          ))['checkout'],
        ).status,
        'captured',
      );
      final subscription = await api.request(
        'commercial/subscriptions/${f['subscription']}',
      );
      expect(subscription['entitlements_active'], true);
      final reference = first.source['reference'] as String;
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      final vendor = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${first.id}',
        ))['checkout'],
      );
      expect(vendor.source['owner'], false);
      expect(vendor.source['allowed_actions'], isEmpty);
      expect(vendor.hosted, isNull);
      await expectLater(
        api.request(
          'commercial/hosted-checkouts/${first.id}/capture',
          method: 'POST',
          body: {},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      final ownVendor = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: {
            'scope': 'subscription',
            'subscription_id': f['vendor_subscription'],
            'invoice_id': f['vendor_subscription_invoice'],
            'consent': true,
            'idempotency_key':
                'native-vendor-package-${f['vendor_subscription']}',
          },
        ))['checkout'],
      );
      expect(ownVendor.source['owner'], true);
      await api.request(
        'commercial/hosted-checkouts/${ownVendor.id}/simulate',
        method: 'POST',
        body: {'consent': true, 'outcome': 'authorized'},
      );
      final vendorCaptured = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${ownVendor.id}/capture',
          method: 'POST',
          body: {},
        ))['checkout'],
      );
      expect(vendorCaptured.status, 'captured');
      expect(
        (await api.request(
          'commercial/subscriptions/${f['vendor_subscription']}',
        ))['entitlements_active'],
        true,
      );
      await api.signOut();
      await api.signIn(f['foreign'], f['password']);
      expect((await api.request('commercial/hosted-checkouts'))['total'], 0);
      await expectLater(
        api.request('commercial/hosted-checkouts/${first.id}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.request('commercial/hosted-checkouts/by-reference/$reference'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Requires isolated local/mock Laravel hosted fixture.'
        : false,
  );
}
