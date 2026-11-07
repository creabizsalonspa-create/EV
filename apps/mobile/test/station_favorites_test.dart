import 'dart:async';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> stationJson(bool saved) => {
  'id': 1,
  'name': 'Private choice',
  'connector_type': 'CCS2',
  'evidence': 'DISCOVERY_ONLY',
  'power_kw': 50,
  'reservable': false,
  'is_favorite': saved,
};
ApiPage<Station> page(bool saved) => ApiPage(
  items: [Station.fromJson(stationJson(saved))],
  current: 1,
  last: 1,
  total: 1,
);

class FavoriteApi extends EvApi {
  final status = Completer<dynamic>();
  final paths = <String>[];
  FavoriteApi() : super(Uri.https('fixture.example'));
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    paths.add(path);
    if (path == 'stations')
      return {
        'data': [stationJson(false)..remove('is_favorite')],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    if (path == 'favorites/status') return status.future;
    throw StateError('Unexpected request $path');
  }
}

Widget app(Widget child, {bool arabic = false}) => MaterialApp(
  locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: Scaffold(body: child),
);

void main() {
  test(
    'private status augments public records without granting discovery control',
    () async {
      final api = FavoriteApi();
      addTearDown(api.close);
      api.status.complete({
        'favorite_station_ids': [1],
      });
      final result = await api.catalog(includeFavorites: true);
      expect(api.paths, ['stations', 'favorites/status']);
      expect(result.items.single.favorite, isTrue);
      expect(result.items.single.reservable, isFalse);
      expect(result.items.single.source, 'DISCOVERY_ONLY');
    },
  );
  test(
    'late private status after session change cannot personalize a public result',
    () async {
      final api = FavoriteApi();
      final result = api.catalog(includeFavorites: true);
      await Future<void>.delayed(Duration.zero);
      api.close();
      api.status.complete({
        'favorite_station_ids': [1],
      });
      await expectLater(
        result,
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
    },
  );
  test(
    'malformed private status fails closed instead of inventing saved state',
    () async {
      final api = FavoriteApi();
      addTearDown(api.close);
      api.status.complete({
        'favorite_station_ids': ['1'],
      });
      await expectLater(
        api.catalog(includeFavorites: true),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 502)),
      );
    },
  );
  testWidgets(
    'lost write response never replays and refresh reads authoritative state',
    (tester) async {
      var saved = false, writes = 0, failRead = false;
      final key = GlobalKey<CatalogViewState>();
      await tester.pumpWidget(
        app(
          CatalogView(
            key: key,
            loader: (_, __) async {
              if (failRead)
                throw const ApiException(503, 'Synthetic failed refresh');
              return page(saved);
            },
            customer: true,
            onReserve: (_) async {},
            onFavorite: (_, desired) async {
              writes++;
              saved = desired;
              throw const ApiException(0, 'Synthetic lost acknowledgement');
            },
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const Key('favorite-1')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('favorite-1')).hitTestable(), findsOneWidget);
      await tester.tap(find.byKey(const Key('favorite-1')));
      await tester.pumpAndSettle();
      expect(writes, 1);
      expect(find.text('Save station'), findsOneWidget);
      expect(
        find.textContaining('Preference could not be confirmed.'),
        findsOneWidget,
      );
      failRead = true;
      await key.currentState!.refresh();
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Preference could not be confirmed.'),
        findsOneWidget,
      );
      expect(writes, 1);
      failRead = false;
      await key.currentState!.refresh();
      await tester.pumpAndSettle();
      expect(find.text('Remove saved station'), findsOneWidget);
      expect(writes, 1);
      expect(
        find.textContaining('Preference could not be confirmed.'),
        findsNothing,
      );
    },
  );
  testWidgets(
    'saved-only removal refreshes empty state and hides catalog filters',
    (tester) async {
      var saved = true;
      await tester.pumpWidget(
        app(
          CatalogView(
            savedOnly: true,
            loader: (_, __) async => saved
                ? page(true)
                : const ApiPage<Station>(
                    items: [],
                    current: 1,
                    last: 1,
                    total: 0,
                  ),
            customer: true,
            onReserve: (_) async {},
            onFavorite: (_, desired) async => saved = desired,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('catalog-search')), findsNothing);
      await tester.tap(find.byKey(const Key('favorite-1')));
      await tester.pumpAndSettle();
      expect(find.text('No saved stations yet.'), findsOneWidget);
      expect(find.text('Private choice'), findsNothing);
    },
  );
  testWidgets(
    'protected read expiry clears records and informs session owner',
    (tester) async {
      var expired = 0;
      await tester.pumpWidget(
        app(
          CatalogView(
            savedOnly: true,
            loader: (_, __) async => throw const ApiException(401, 'Expired'),
            customer: true,
            onReserve: (_) async {},
            onExpired: () => expired++,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Private choice'), findsNothing);
    },
  );
  testWidgets('Arabic saved station controls stay RTL at mobile width', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(
      app(
        CatalogView(
          savedOnly: true,
          loader: (_, __) async => page(true),
          customer: true,
          onReserve: (_) async {},
          onFavorite: (_, desired) async => desired,
        ),
        arabic: true,
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('المحطات المحفوظة'), findsOneWidget);
    expect(find.text('إزالة المحطة المحفوظة'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byType(CatalogView))),
      TextDirection.rtl,
    );
    expect(tester.takeException(), isNull);
  });
}
