import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/catalog.dart';

ApiPage<Station> stationPage(int page, String name) => ApiPage(
  items: [
    Station.fromJson({
      'id': page,
      'name': name,
      'connector_type': 'CCS2',
      'evidence': 'DISCOVERY_ONLY',
      'power_kw': 50,
      'reservable': false,
    }),
  ],
  current: page,
  last: 2,
  total: 26,
);
void main() {
  test(
    'query parameters are encoded and pagination stays on the configured service origin',
    () async {
      final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      final requests = <Uri>[];
      final subscription = server.listen((req) async {
        requests.add(req.uri);
        req.response.headers.contentType = ContentType.json;
        req.response.write(
          jsonEncode({
            'data': [],
            'current_page': int.parse(req.uri.queryParameters['page']!),
            'last_page': 2,
            'total': 26,
            'next_page_url': 'https://untrusted.example/api/stations?page=2',
          }),
        );
        await req.response.close();
      });
      final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
      try {
        const filter = CatalogFilter(
          search: 'A&B محطة',
          connector: 'Type2',
          country: 'ae',
          minimumPower: 22,
          latitude: 25.2,
          longitude: 55.3,
          radiusKm: 10,
        );
        final page = await api.catalog(filter: filter, page: 2);
        expect(page.current, 2);
        expect(requests.single.path, '/api/stations');
        expect(requests.single.queryParameters, {
          'q': 'A&B محطة',
          'connector_type': 'Type2',
          'country': 'AE',
          'min_power_kw': '22',
          'latitude': '25.2',
          'longitude': '55.3',
          'radius_km': '10.0',
          'page': '2',
        });
      } finally {
        api.close();
        await subscription.cancel();
        await server.close(force: true);
      }
    },
  );
  test(
    'late responses cannot replace a newer search and disposal ignores late completion',
    () async {
      final first = Completer<ApiPage<Station>>(),
          second = Completer<ApiPage<Station>>();
      var calls = 0;
      final controller = CatalogController(
        (filter, page) => ++calls == 1 ? first.future : second.future,
      );
      final old = controller.load(filter: const CatalogFilter(search: 'old'));
      final latest = controller.load(
        filter: const CatalogFilter(search: 'new'),
      );
      second.complete(stationPage(1, 'new'));
      await latest;
      first.complete(stationPage(1, 'old'));
      await old;
      expect(controller.data!.items.single.name, 'new');
      final pending = Completer<ApiPage<Station>>();
      final disposed = CatalogController((filter, page) => pending.future);
      final loading = disposed.load();
      disposed.dispose();
      pending.complete(stationPage(1, 'gone'));
      await loading;
      controller.dispose();
    },
  );
  test(
    'failed next page keeps current records and retries exactly the requested filtered page',
    () async {
      var fail = true;
      final queries = <String>[];
      final controller = CatalogController((filter, page) async {
        queries.add('${filter.search}:$page');
        if (page == 2 && fail) throw const ApiException(503, 'temporary');
        return stationPage(page, 'page$page');
      });
      await controller.load(filter: const CatalogFilter(search: 'retained'));
      await controller.load(page: 2);
      expect(controller.data!.current, 1);
      expect(controller.error, isA<ApiException>());
      expect(controller.loading, isFalse);
      fail = false;
      await controller.retry();
      expect(controller.data!.current, 2);
      expect(controller.error, isNull);
      expect(queries, ['retained:1', 'retained:2', 'retained:2']);
      controller.dispose();
    },
  );
}
