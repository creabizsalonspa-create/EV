import 'dart:io';
import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_TOPOLOGY_FIXTURE'];
  test(
    'actual native client maps inventory and reserves independent EVSEs with shared-connector capacity',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], isTrue);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'], f['password']);
      final base =
          'vendor-ops/companies/${f['company_id']}/chargers/${f['charger_id']}';
      final evses = <dynamic>[];
      for (final n in [1, 2])
        evses.add(
          await api.request(
            '$base/evses',
            method: 'POST',
            body: {
              'number': n,
              'mode': 'application',
              'binding_id': null,
              'source_evse_number': 0,
            },
          ),
        );
      final maps = <dynamic>[];
      for (var i = 0; i < 3; i++)
        maps.add(
          await api.request(
            '$base/evses/${evses[i == 2 ? 1 : 0]['id']}/connectors/${f['connectors'][i]}/mapping',
            method: 'POST',
            body: {'source_connector_number': null},
          ),
        );
      await api.signOut();
      await api.signIn(f['customer'], f['password']);
      final discovery = await api.request('discovery/capabilities');
      expect(discovery['provider'], 'geoapify');
      expect(discovery['geocoding'], false);
      expect(discovery['routing'], false);
      expect(jsonEncode(discovery).contains('apiKey'), false);
      await expectLater(
        api.request(
          'discovery/route',
          method: 'POST',
          body: {
            'consent': true,
            'language': 'en',
            'points': [
              {'latitude': 25.2, 'longitude': 55.3},
              {'latitude': 25.3, 'longitude': 55.4},
            ],
          },
        ),
        throwsA(
          isA<ApiException>().having(
            (e) => e.status,
            'unconfigured provider',
            503,
          ),
        ),
      );
      await expectLater(
        api.request(
          'discovery/addresses',
          method: 'POST',
          body: {
            'consent': false,
            'language': 'en',
            'query': 'Synthetic address',
          },
        ),
        throwsA(
          isA<ApiException>().having((e) => e.status, 'consent required', 422),
        ),
      );

      expect(await api.setFavorite(f['station_id'] as int, true), isTrue);
      expect(await api.setFavorite(f['station_id'] as int, true), isTrue);
      final saved = await api.favorites();
      expect(saved.total, 1);
      expect(saved.items.single.id, f['station_id']);
      expect(saved.items.single.reservable, isFalse);
      final personalized = await api.catalog(
        filter: CatalogFilter(search: f['name']),
        includeFavorites: true,
      );
      expect(personalized.items.single.favorite, isTrue);
      final public = await api.catalog(
        filter: CatalogFilter(search: f['name']),
      );
      expect(public.items.single.favorite, isFalse);
      expect(await api.setFavorite(f['station_id'] as int, false), isFalse);
      expect((await api.favorites()).total, 0);
      final choices = await api.request(
        'stations/${f['station_id']}/connectors',
      );
      expect(choices['data'].length, 3);
      expect(choices['data'][0]['availability'], 'unknown');
      expect(choices['data'][0].containsKey('binding_id'), isFalse);
      final now = DateTime.now().toUtc().add(const Duration(hours: 2));
      final start = DateTime.utc(
        now.year,
        now.month,
        now.day,
        now.hour,
      ).toIso8601String().replaceFirst('.000Z', 'Z');
      Map<String, dynamic> body(int i, String key) => {
        'station_id': f['station_id'],
        'connector_mapping_id': maps[i]['id'],
        'starts_at': start,
        'idempotency_key': key,
      };
      final first = await api.request(
        'reservations',
        method: 'POST',
        body: body(0, 'native-first'),
      );
      final second = await api.request(
        'reservations',
        method: 'POST',
        body: body(2, 'native-second'),
      );
      expect(first['charging_evse_id'], isNot(second['charging_evse_id']));
      await expectLater(
        api.request(
          'reservations',
          method: 'POST',
          body: body(1, 'native-conflict'),
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      final replay = await api.request(
        'reservations',
        method: 'POST',
        body: body(0, 'native-first'),
      );
      expect(replay['id'], first['id']);
      await api.request('reservations/${first['id']}', method: 'DELETE');
      final reused = await api.request(
        'reservations',
        method: 'POST',
        body: body(1, 'native-reuse'),
      );
      expect(reused['charging_evse_id'], first['charging_evse_id']);
      await api.signOut();
    },
    skip: path == null
        ? 'Requires isolated local fixture; run node tests/native-topology.cjs.'
        : false,
  );
}
