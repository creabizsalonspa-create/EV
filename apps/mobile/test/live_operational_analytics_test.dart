import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final fixture = Platform.environment['EV_NATIVE_OPERATIONAL_FIXTURE'];
  test(
    'native operational source report and Arabic PDF stay scoped to the operator',
    () async {
      final f =
          jsonDecode(await File(fixture!).readAsString())
              as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(
        f['accounts']['vendor']['email'] as String,
        f['accounts']['vendor']['password'] as String,
      );
      final path =
          'vendor-ops/companies/${f['company_id']}/operational-analytics';
      final result = await api.request(path);
      expect(result['physical_uptime_verified'], false);
      expect(result['billing_authority'], false);
      expect((result['sites'] as List).length, 2);
      expect(
        (result['sites'] as List).every(
          (row) =>
              row['completed_energy_wh'] > 0 && row['source_completed'] == 1,
        ),
        true,
      );
      final doc = await api.downloadOperationalAnalytics('$path/pdf', {
        'from': result['from'] as String,
        'to': result['to'] as String,
        'locale': 'ar',
      });
      expect(doc.mime, 'application/pdf');
      expect(String.fromCharCodes(doc.bytes.take(5)), '%PDF-');
      await api.signOut();
      await api.signIn(
        f['accounts']['customer']['email'] as String,
        f['accounts']['customer']['password'] as String,
      );
      await expectLater(
        api.request(path),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.downloadOperationalAnalytics('$path/pdf', {}),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: fixture == null
        ? 'Requires explicit private dual-protocol source-backed charging fixture; no physical uptime acceptance.'
        : false,
  );
}
