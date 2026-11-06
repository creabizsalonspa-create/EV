import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final fixture = Platform.environment['EV_NATIVE_REPAIR_FIXTURE'];
  test(
    'native HTTP repair records preserve canonical recovery, version replay and customer privacy',
    () async {
      final f =
          jsonDecode(await File(fixture!).readAsString())
              as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'] as String, f['password'] as String);
      final path =
          'vendor-ops/companies/${f['company_id']}/chargers/${f['charger_id']}/repair-orders';
      final list = await api.request(path);
      final order = (list['data'] as List).singleWhere(
        (row) => row['alert_id'] == f['alert_id'],
      );
      expect(order['state'], 'closed');
      expect(order['recovery_observation_id'], isNotNull);
      var version = order['version'] as int;
      for (final state in ['open', 'in_progress', 'review', 'closed']) {
        final intent = {
          'request_key': operationKey(),
          'alert_id': f['alert_id'],
          'expected_version': version,
          'state': state,
          'note': 'Native synthetic repair review note',
        };
        final result = await api.request(path, method: 'POST', body: intent);
        expect(result['version'], ++version);
        expect(result['commands_sent'], false);
        expect(result['capacity_changed'], false);
        final replay = await api.request(path, method: 'POST', body: intent);
        expect(replay['replayed'], true);
        expect(replay['version'], version);
      }
      final history = await api.request('$path/${order['id']}/history');
      expect(
        (history['data'] as List).first['note'],
        'Native synthetic repair review note',
      );
      await api.signOut();
      await api.signIn(f['customer'] as String, f['password'] as String);
      await expectLater(
        api.request(path),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.request('$path/${order['id']}/history'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: fixture == null
        ? 'Requires explicit owned local source fault/recovery fixture; no physical repair acceptance.'
        : false,
  );
}
