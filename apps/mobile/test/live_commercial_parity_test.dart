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
      // The same production-shape Laravel catalog that the native plan
      // picker reads must identify the current customer's published plan.
      final plans = await api.request('commercial/plans');
      final available = (plans['data'] as List).firstWhere(
        (p) => p['id'] == f['plan_id'],
      ) as Map;
      expect(available['audience'], 'customer');
      expect(available['currency'], 'AED');
      expect(available['minor_units'], 2);
      expect(available['available'], true);
      expect(available['entitlements']['priority'], true);

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
      expect(subscriptionOperationAcknowledged(summary, {
        'plan_id': f['plan_id'],
        'idempotency_key': 'native-enroll-${f['driver_id']}',
      }, owner: f['driver_id'] as int, action: 'enroll'), true);
      expect(summary['entitlements_active'], false);
      expect((summary['invoices'] as List).single['total_minor'], 1050);
      // A parsed financial snapshot cannot be retroactively relabelled
      // by a mutable transport map after its original role/owner check.
      (subscribed['subscription'] as Map)['user_id'] = -1;
      (subscribed['invoices'] as List).single['total_minor'] = 1;
      expect(summary['subscription']['user_id'], f['driver_id']);
      expect((summary['invoices'] as List).single['total_minor'], 1050);
      final owned = await api.request('commercial/subscriptions');
      expect(
        (owned['data'] as List).any((entry) =>
            entry['id'] == id && entry['user_id'] == f['driver_id']),
        true,
      );
      final persisted = subscriptionSummary(
        await api.request('commercial/subscriptions/$id'),
        f['driver_id'] as int,
        id: id as int,
      );
      expect((persisted['invoices'] as List).single['total_minor'], 1050);
      expect(persisted['entitlements_active'], false);
      final replay = await api.request(
        'commercial/subscriptions',
        method: 'POST',
        body: {
          'plan_id': f['plan_id'],
          'idempotency_key': 'native-enroll-${f['driver_id']}',
        },
      );
      expect(replay['subscription']['id'], id);
      expect(replay['operation_receipt'], summary['operation_receipt']);
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
      expect(subscriptionOperationAcknowledged(
        subscriptionSummary(changed, f['driver_id'] as int, id: id),
        {'action': 'cancel_at_period_end', 'idempotency_key': 'native-cancel-$id'},
        owner: f['driver_id'] as int, action: 'cancel_at_period_end',
      ), true);
      final resumed = await api.request(
        'commercial/subscriptions/$id/change',
        method: 'POST',
        body: {'action': 'resume', 'idempotency_key': 'native-resume-$id'},
      );
      expect(resumed['subscription']['cancel_at_period_end'], false);
      expect(subscriptionOperationAcknowledged(
        subscriptionSummary(resumed, f['driver_id'] as int, id: id),
        {'action': 'resume', 'idempotency_key': 'native-resume-$id'},
        owner: f['driver_id'] as int, action: 'resume',
      ), true);
      final renewal = subscriptionSummary(await api.request(
        'commercial/subscriptions/$id/renew', method: 'POST',
        body: {'expected_cycle_number': resumed['subscription']['cycle_number']},
      ), f['driver_id'] as int, id: id);
      expect(renewal['subscription']['cycle_number'], resumed['subscription']['cycle_number']);
      expect(renewal['invoices'], hasLength(1));
      expect(renewal['real_payment_processed'], false);
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
