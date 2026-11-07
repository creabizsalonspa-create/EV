import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_documents.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_FINANCIAL_FIXTURE'];
  test(
    'native actual Laravel private Arabic PDF and canonical refund submission review enforce ownership and replay identity',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['customer'], f['password']);
      final generated = await api.request(
        'financial/documents',
        method: 'POST',
        body: {
          'source_kind': 'charging_invoice',
          'source_id': f['invoice'],
          'locale': 'ar',
        },
      );
      final doc = FinancialDocumentRecord.fromJson(
        Map<String, dynamic>.from(generated as Map),
      );
      final bytes = await api.downloadDocument(
        'financial/documents/${doc.id}/download/pdf',
      );
      doc.verify(bytes);
      expect(bytes.bytes.length, greaterThan(1000));
      final replay = await api.request(
        'financial/documents',
        method: 'POST',
        body: {
          'source_kind': 'charging_invoice',
          'source_id': f['invoice'],
          'locale': 'ar',
        },
      );
      expect(replay['id'], doc.id);
      final body = {
        'amount_minor': 125,
        'reason': 'Synthetic native refund request',
        'idempotency_key': 'native-request-${f['invoice']}',
      };
      final request = await api.request(
        'finance/invoices/${f['invoice']}/refund-requests',
        method: 'POST',
        body: body,
      );
      expect(request['status'], 'pending');
      final again = await api.request(
        'finance/invoices/${f['invoice']}/refund-requests',
        method: 'POST',
        body: body,
      );
      expect(again['id'], request['id']);
      await expectLater(
        api.request(
          'finance/invoices/${f['invoice']}/refund-requests',
          method: 'POST',
          body: {...body, 'amount_minor': 126},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      expect(
        (await api.downloadDocument(
          'financial/documents/${doc.id}/download/pdf',
        )).bytes,
        bytes.bytes,
      );
      final reviewed = await api.request(
        'finance/refund-requests/${request['id']}/review',
        method: 'POST',
        body: {'decision': 'reject', 'note': 'Synthetic vendor rejection'},
      );
      expect(reviewed['status'], 'rejected');
      final repeated = await api.request(
        'finance/refund-requests/${request['id']}/review',
        method: 'POST',
        body: {'decision': 'reject', 'note': 'Synthetic vendor rejection'},
      );
      expect(repeated['id'], request['id']);
      await api.signOut();
      await api.signIn(f['customer'], f['password']);
      final approvalBody = {
        'amount_minor': 50,
        'reason': 'Synthetic approved refund',
        'idempotency_key': 'native-approve-${f['invoice']}',
      };
      final approval = await api.request(
        'finance/invoices/${f['invoice']}/refund-requests',
        method: 'POST',
        body: approvalBody,
      );
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      final decision = {
        'decision': 'approve',
        'note': 'Synthetic mock approval',
      };
      final paid = await api.request(
        'finance/refund-requests/${approval['id']}/review',
        method: 'POST',
        body: decision,
      );
      expect(paid['status'], 'approved');
      expect(paid['refund_id'], isA<int>());
      await api.request(
        'finance/refund-requests/${approval['id']}/review',
        method: 'POST',
        body: decision,
      );
      final invoice = await api.request('finance/invoices/${f['invoice']}');
      expect(invoice['refunded_minor'], 50);
      expect((invoice['refunds'] as List).length, 1);
      await api.signOut();
      await api.signIn(f['foreign'], f['password']);
      expect((await api.request('finance/refund-requests'))['total'], 0);
      await expectLater(
        api.downloadDocument('financial/documents/${doc.id}/download/pdf'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.request(
          'finance/invoices/${f['invoice']}/refund-requests',
          method: 'POST',
          body: body,
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Run node tests/native-financial-operations.cjs with local guarded synthetic fixtures'
        : false,
  );
}
