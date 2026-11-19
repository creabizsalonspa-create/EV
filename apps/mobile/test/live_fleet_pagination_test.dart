import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_FLEET_FIXTURE'];
  test(
    'actual Laravel native fleet and invitation pages preserve owner/driver/foreign boundaries',
    () async {
      final fixture = jsonDecode(await File(path!).readAsString());
      expect(fixture['fixture_only'], true);
      expect(fixture['financial_mutations'], false);
      final base = Uri.parse(fixture['base']);
      expect(base.scheme, 'http');
      expect(['127.0.0.1', 'localhost'], contains(base.host));
      final apis = <EvApi>[];
      addTearDown(() {
        for (final api in apis) {
          api.close();
        }
      });
      Future<EvApi> login(String role) async {
        final api = EvApi(base);
        apis.add(api);
        await api.signIn(fixture[role], fixture['password']);
        return api;
      }

      Future<List<int>> pages(EvApi api, String route, int total) async {
        final ids = <int>[];
        for (var page = 1; page <= 2; page++) {
          final data = await api.request(route, query: {'page': '$page'});
          expect(data['total'], total);
          expect(data['current_page'], page);
          expect(data['last_page'], 2);
          expect(data['data'].length, page == 1 ? 25 : total - 25);
          ids.addAll((data['data'] as List).map((row) => row['id'] as int));
        }
        expect(ids.toSet().length, total);
        return ids;
      }

      final owner = await login('owner');
      expect(await pages(owner, 'fleets', 27), fixture['fleets']);
      for (final route in ['fleets', 'fleet-invitations']) {
        for (final page in ['0', '100001', '1.5']) {
          await expectLater(
            owner.request(route, query: {'page': page}),
            throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)),
          );
        }
      }
      final driver = await login('driver');
      expect(
        await pages(driver, 'fleet-invitations', 26),
        fixture['invitations'],
      );
      final active = await driver.request('fleets');
      expect(active['total'], 1);
      expect(active['data'].single['id'], fixture['active_fleet']);
      final foreign = await login('foreign');
      for (final route in ['fleets', 'fleet-invitations']) {
        expect((await foreign.request(route))['total'], 0);
      }
      await expectLater(
        foreign.request('fleets/${fixture['active_fleet']}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Requires owned local Laravel fleet fixture; run tests/native-fleet-pagination.cjs.'
        : false,
  );
}
