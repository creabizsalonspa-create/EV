import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_CASE_FIXTURE'];
  test(
    'actual Laravel native private case evidence preserves encryption contract replay fingerprint tenant privacy and canonical finances',
    () async {
      final f = jsonDecode(await File(path!).readAsString());
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['customer'], f['password']);
      final before = await api.request('finance/invoices/${f['invoice']}');
      final bytes = Uint8List.fromList(
        '%PDF-1.4\nSynthetic private native evidence'.codeUnits,
      );
      final key = 'native-evidence-${f['case_id']}';
      final row = await api.uploadCaseEvidence(
        f['case_id'],
        kind: 'service_record',
        bytes: bytes,
        mime: 'application/pdf',
        key: key,
      );
      expect(row['provider_submitted'], false);
      expect(row['evidence_status'], 'PRIVATE_REVIEW_ONLY');
      expect(row.containsKey('storage_path'), false);
      expect(row['sha256'], sha256.convert(bytes).toString());
      expect(
        (await api.uploadCaseEvidence(
          f['case_id'],
          kind: 'service_record',
          bytes: bytes,
          mime: 'application/pdf',
          key: key,
        ))['id'],
        row['id'],
      );
      await expectLater(
        api.uploadCaseEvidence(
          f['case_id'],
          kind: 'receipt',
          bytes: bytes,
          mime: 'application/pdf',
          key: key,
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      expect(
        (await api.request(
          'commercial/cases/${f['case_id']}/evidence',
        ))['total'],
        1,
      );
      final doc = await api.downloadDocument(
        'commercial/cases/${f['case_id']}/evidence/${row['id']}',
      );
      expect(doc.mime, 'application/pdf');
      expect(doc.bytes, bytes);
      final reply = await api.uploadCaseEvidence(
        f['case_id'],
        kind: 'receipt',
        bytes: bytes,
        mime: 'application/pdf',
        key: 'vendor-$key',
      );
      expect(reply['id'], isNot(row['id']));
      final after = await api.request('finance/invoices/${f['invoice']}');
      expect(after['paid_minor'], before['paid_minor']);
      expect(after['refunded_minor'], before['refunded_minor']);
      expect(after['ledger_balanced'], true);
      await api.signOut();
      await api.signIn(f['foreign'], f['password']);
      expect((await api.request('commercial/cases'))['total'], 0);
      await expectLater(
        api.request('commercial/cases/${f['case_id']}/evidence'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.downloadDocument(
          'commercial/cases/${f['case_id']}/evidence/${row['id']}',
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Run tests/native-case-evidence.cjs with guarded local synthetic fixtures'
        : false,
  );
}
