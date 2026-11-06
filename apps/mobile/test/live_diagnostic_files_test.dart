import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final fixture = Platform.environment['EV_NATIVE_DIAGNOSTIC_FIXTURE'];
  test(
    'native diagnostic download validates bounded private bytes and revocation without source replay',
    () async {
      final f =
          jsonDecode(await File(fixture!).readAsString())
              as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'] as String, f['password'] as String);
      final path =
          'vendor-ops/companies/${f['company_id']}/chargers/${f['charger_id']}/diagnostic-logs';
      final report = await api.request(path);
      expect(report['charging_capacity_changed'], false);
      final row = (report['data'] as List).singleWhere(
        (x) => x['id'] == f['operation'],
      );
      expect(row['can_download'], true);
      expect(row['repair_order_id'], f['repair_order_id']);
      final bytes = await api.downloadDiagnosticLog(
        '$path/${f['operation']}/download',
      );
      expect(bytes.mime, 'application/octet-stream');
      expect(sha256.convert(bytes.bytes).toString(), f['sha256']);
      expect(bytes.bytes, base64Decode(f['bytes'] as String));
      await api.request('$path/${f['operation']}', method: 'DELETE');
      await expectLater(
        api.downloadDiagnosticLog('$path/${f['operation']}/download'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await api.signIn(f['customer'] as String, f['password'] as String);
      await expectLater(
        api.request(path),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: fixture == null
        ? 'Requires explicit private owned local diagnostic source fixture; no hardware acceptance.'
        : false,
  );
}
