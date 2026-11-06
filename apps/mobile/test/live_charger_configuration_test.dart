import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final fixture = Platform.environment['EV_NATIVE_CONFIGURATION_FIXTURE'];
  test(
    'native HTTP operating read uses real correlated simulator reply without changing settings',
    () async {
      final f =
          jsonDecode(await File(fixture!).readAsString())
              as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'] as String, f['password'] as String);
      final path =
          'vendor-ops/companies/${f['company_id']}/chargers/${f['charger_id']}/configuration-reads';
      final body = {
        'idempotency_key': operationKey(),
        'reason': 'Inspect native device operating settings',
      };
      final created = await api.request(path, method: 'POST', body: body);
      expect(created['status'], 'transport_submitted');
      expect(created['settings_changed'], false);
      final replay = await api.request(path, method: 'POST', body: body);
      expect(replay['id'], created['id']);
      dynamic observed;
      for (var attempt = 0; attempt < 20; attempt++) {
        observed = await api.request(
          '$path/${created['id']}/observe',
          method: 'POST',
          body: {},
        );
        if (observed['status'] == 'source_observed') break;
        await Future<void>.delayed(const Duration(milliseconds: 750));
      }
      expect(observed['status'], 'source_observed');
      expect((observed['snapshot'] as List).length, 3);
      expect(
        (observed['snapshot'] as List).every(
          (row) => row['status'] == 'observed',
        ),
        true,
      );
      expect(observed['physical_charging_verified'], false);
      final history = await api.request(path);
      expect(history['settings_writes_enabled'], false);
      expect((history['data'] as List).first.containsKey('reason'), false);
      await api.signOut();
      await api.signIn(f['customer'] as String, f['password'] as String);
      await expectLater(
        api.request(path),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: fixture == null
        ? 'Requires explicit owned local CitrineOS simulator fixture; no physical device acceptance.'
        : false,
  );
}
