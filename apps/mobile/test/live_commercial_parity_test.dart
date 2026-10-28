import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_documents.dart';
import 'package:creabiz_ev/subscriptions.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_COMMERCIAL_FIXTURE'];
  test(
    'actual Laravel company consent claim review statement privacy and native subscription lifecycle preserve canonical identity',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      final root = 'commercial/corporate', a = f['agreement_id'];
      await api.signIn(f['owner'], f['password']);
      final agreements = await api.request(
        root,
        query: {'fleet_id': '${f['fleet_id']}'},
      );
      expect(agreements['can_manage'], true);
      expect(agreements['real_payment_enabled'], false);
      expect(
        (await api.request(
          '$root/agreements/$a/accept',
          method: 'POST',
          body: {'consent': true},
        ))['agreement']['id'],
        a,
      );
      await api.signOut();
      await api.signIn(f['driver'], f['password']);
      expect(
        (await api.request(
          root,
          query: {'fleet_id': '${f['fleet_id']}'},
        ))['can_manage'],
        false,
      );
      final body = {
        'invoice_id': f['invoice_id'],
        'cost_center_id': f['cost_center_id'],
        'idempotency_key': 'native-claim-${f['invoice_id']}',
      };
      final claim = (await api.request(
        '$root/agreements/$a/claims',
        method: 'POST',
        body: body,
      ))['claim'];
      expect(
        (await api.request(
          '$root/agreements/$a/claims',
          method: 'POST',
          body: body,
        ))['claim']['id'],
        claim['id'],
      );
      await expectLater(
        api.request('$root/agreements/$a/statements'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      final subscribed = await api.request(
        'commercial/subscriptions',
        method: 'POST',
        body: {
          'plan_id': f['plan_id'],
          'idempotency_key': 'native-enroll-${f['driver_id']}',
        },
      );
      final summary = subscriptionSummary(subscribed, f['driver_id']);
      final id = summary['subscription']['id'];
      expect(summary['entitlements_active'], false);
      expect((summary['invoices'] as List).single['total_minor'], 1050);
      final replay = await api.request(
        'commercial/subscriptions',
        method: 'POST',
        body: {
          'plan_id': f['plan_id'],
          'idempotency_key': 'native-enroll-${f['driver_id']}',
        },
      );
      expect(replay['subscription']['id'], id);
      // Synthetic mock payment is test setup, never a native production action.
      await api.request(
        'commercial/subscriptions/$id/mock-payment',
        method: 'POST',
        body: {
          'invoice_id': (summary['invoices'] as List).single['id'],
          'outcome': 'succeeded',
          'idempotency_key': 'native-paid-$id',
        },
      );
      final changed = await api.request(
        'commercial/subscriptions/$id/change',
        method: 'POST',
        body: {
          'action': 'cancel_at_period_end',
          'idempotency_key': 'native-cancel-$id',
        },
      );
      expect(changed['subscription']['cancel_at_period_end'], true);
      final resumed = await api.request(
        'commercial/subscriptions/$id/change',
        method: 'POST',
        body: {'action': 'resume', 'idempotency_key': 'native-resume-$id'},
      );
      expect(resumed['subscription']['cancel_at_period_end'], false);
      expect(
        (await api.request('commercial/subscriptions/$id/events'))['total'],
        greaterThanOrEqualTo(3),
      );
      await api.signOut();
      await api.signIn(f['owner'], f['password']);
      await api.request(
        '$root/claims/${claim['id']}/review',
        method: 'POST',
        body: {
          'decision': 'approved',
          'reason': 'Synthetic native authorized business trip',
        },
      );
      final issue = {
        'period': f['period'],
        'claim_ids': [claim['id']],
        'idempotency_key': 'native-statement-$a',
      };
      final statement = (await api.request(
        '$root/agreements/$a/statements',
        method: 'POST',
        body: issue,
      ))['statement'];
      expect(statement['total_minor'], 1050);
      expect(
        (await api.request(
          '$root/agreements/$a/statements',
          method: 'POST',
          body: issue,
        ))['statement']['id'],
        statement['id'],
      );
      final generated = await api.request(
        'financial/documents',
        method: 'POST',
        body: {
          'source_kind': 'corporate_statement',
          'source_id': statement['id'],
          'locale': 'ar',
        },
      );
      final doc = FinancialDocumentRecord.fromJson(
        Map<String, dynamic>.from(generated as Map),
      );
      doc.verify(
        await api.downloadDocument(
          'financial/documents/${doc.id}/download/pdf',
        ),
      );
      await expectLater(
        api.request('commercial/subscriptions/$id'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await api.signIn(f['driver'], f['password']);
      await expectLater(
        api.downloadDocument('financial/documents/${doc.id}/download/pdf'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      final report = await api.request('finance/report');
      expect(report['real_money_movement'], false);
      expect((await api.request('commercial/payout-accounts'))['total'], 0);
      await expectLater(
        api.request('$root/agreements/$a/claims'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Run node tests/native-commercial-parity.cjs with guarded local synthetic fixtures'
        : false,
  );
}
