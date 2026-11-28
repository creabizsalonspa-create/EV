// Actual local authenticated-source metadata/bytes. Optional explicit local file revocation; no device commands.
import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_logs.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_AUTHENTICATED_LOGS_FIXTURE'];
  test(
    'actual local native private authenticated-source diagnostics preserve both protocol evidence and byte integrity',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], isTrue);
      final base = Uri.parse(f['base']);
      expect(base.scheme, 'http');
      expect(['localhost', '127.0.0.1'], contains(base.host));
      final api = EvApi(base);
      addTearDown(api.close);
      final owner = await api.signIn(f['owner'], f['password']);
      expect(owner.role, 'vendor');
      final protocols = <String>{};
      for (final charger in f['chargers'] as List) {
        protocols.add(charger['protocol']);
        final endpoint =
            'vendor-ops/companies/${f['company_id']}/chargers/${charger['id']}/diagnostic-logs';
        final raw = await api.request(endpoint, query: {'page': '1'}),
            page = parseDiagnosticLogPage(raw);
        expect(page['evidence'], 'AUTHENTICATED_SOURCE');
        expect(page['charging_capacity_changed'], isFalse);
        final record = (page['data'] as List).singleWhere(
          (v) => v['id'] == charger['operation_id'],
        );
        expect(record['evidence'], 'AUTHENTICATED_SOURCE');
        expect(record['protocol'], charger['protocol']);
        expect(record['physical_device_verified'], isFalse);
        expect(record['can_download'], isTrue);
        final doc = await api.downloadDiagnosticLog(
          '$endpoint/${record['id']}/download',
        );
        expect(doc.mime, 'application/octet-stream');
        expect(doc.bytes.length, charger['file_size']);
        expect(doc.bytes.length, lessThanOrEqualTo(524288));
        expect(sha256.convert(doc.bytes).toString(), charger['file_digest']);
        expect(record['file_sha256'], charger['file_digest']);
        if (f['verify_native_revocation'] == true) {
          expect(page['can_manage'], isTrue);
          final cancelPath = '$endpoint/${record['id']}';
          final receipt = await api.request(cancelPath, method: 'DELETE');
          expect(receipt['cancelled'], isTrue);
          expect(receipt['source_command_sent'], isFalse);
          expect(receipt['charging_capacity_changed'], isFalse);
          final retry = await api.request(cancelPath, method: 'DELETE');
          expect(retry, receipt);
          final history = parseDiagnosticLogPage(
            await api.request(endpoint, query: {'page': '1'}),
          );
          final cancelled = (history['data'] as List).singleWhere(
            (v) => v['id'] == record['id'],
          );
          expect(cancelled['cancelled_at'], isNotNull);
          expect(cancelled['content_removed_at'], isNotNull);
          expect(cancelled['can_download'], isFalse);
          expect(cancelled['file_sha256'], charger['file_digest']);
          await expectLater(
            api.downloadDiagnosticLog('$cancelPath/download'),
            throwsA(isA<ApiException>().having(
              (e) => e.status, 'revoked file', 404,
            )),
          );
        }
      }
      expect(protocols, {'1.6', '2.0.1'});
      final revoked = f['verify_native_revocation'] == true;
      await api.signOut();
      f.clear();
      await File('${File(path).parent.path}/native.done').writeAsString(
        revoked
            ? 'Passed native both-protocol private-byte integrity and explicit local revocation; no device command.\n'
            : 'Passed native read-only both-protocol metadata and private-byte integrity; no management request or source write.\n',
      );
    },
    skip: path == null
        ? 'Requires owned local authenticated TLS/Citrine diagnostics fixture.'
        : false,
  );
}
