// Opt-in actual Laravel client contract test. No fixtures in shipped source.
import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final fixturePath = Platform.environment['EV_NATIVE_INVENTORY_FIXTURE'];
  test(
    'real Laravel native inventory hierarchy, owner maintenance and read-only staff enforcement',
    () async {
      final fixture =
          jsonDecode(await File(fixturePath!).readAsString())
              as Map<String, dynamic>;
      expect(fixture['fixture_only'], isTrue);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(fixture['owner'], fixture['password']);
      final companies = await api.request(
        'vendor-ops/companies',
        query: {'page': '1'},
      );
      final company = (companies['data'] as List).singleWhere(
        (c) => c['id'] == fixture['company'],
      );
      expect(company['capabilities']['inventory_write'], isTrue);
      final base = 'vendor-ops/companies/${fixture['company']}';
      final sites = await api.request('$base/sites', query: {'page': '1'});
      expect(sites['data'][0]['id'], fixture['site']);
      final chargers = await api.request(
        '$base/sites/${fixture['site']}/chargers',
        query: {'page': '1'},
      );
      expect(chargers['data'][0]['id'], fixture['charger']);
      final connectors = '$base/chargers/${fixture['charger']}/connectors';
      final initial = await api.request(connectors, query: {'page': '1'});
      expect(initial['data'][0]['remote_control_enabled'], isFalse);
      expect(initial['data'][0]['availability'], 'unknown');
      final mutation = '$connectors/${fixture['connector']}/maintenance';
      final update = {
        'maintenance_blocked': true,
        'maintenance_reason': 'Synthetic native maintenance',
      };
      await api.request(mutation, method: 'PATCH', body: update);
      final blocked = await api.request(connectors);
      expect(blocked['data'][0]['maintenance_blocked'], isTrue);
      await api.signOut();
      final account = await api.signIn(fixture['reader'], fixture['password']);
      expect(account.role, 'customer');
      final shared = await api.request('vendor-ops/companies');
      expect(shared['data'][0]['capabilities']['inventory_read'], isTrue);
      expect(shared['data'][0]['capabilities']['inventory_write'], isFalse);
      expect(
        (await api.request(connectors))['data'][0]['maintenance_blocked'],
        isTrue,
      );
      await expectLater(
        api.request(
          mutation,
          method: 'PATCH',
          body: {
            'maintenance_blocked': false,
            'maintenance_reason': 'Unauthorized release',
          },
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await api.signIn(fixture['owner'], fixture['password']);
      await api.request(
        mutation,
        method: 'PATCH',
        body: {
          'maintenance_blocked': false,
          'maintenance_reason': 'Synthetic native recovery',
        },
      );
      expect(
        (await api.request(connectors))['data'][0]['maintenance_blocked'],
        isFalse,
      );
      await api.signOut();
    },
    skip: fixturePath == null
        ? 'Requires an isolated local native inventory fixture; run node tests/native-inventory.cjs.'
        : false,
  );
}
