import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/roaming.dart';
import 'test_support.dart';

Map<String, dynamic> row({bool available = true}) => {
  'id': 1,
  'location_available': available,
  if (!available) 'is_favorite': true,
  'location': available
      ? {
          'id': 'source-1',
          'name': 'Partner location',
          'address': 'Public address',
          'city': 'Dubai',
          'country': 'ARE',
          'latitude': '25.2',
          'longitude': '55.3',
          'last_updated': '2026-11-05T00:00:00Z',
        }
      : null,
  'received_at': available ? '2026-11-05T00:01:00Z' : null,
  'evidence': 'PARTNER_REPORTED_UNVERIFIED',
  'availability': 'unknown',
  'remote_control': false,
  'reservable': false,
};
Map<String, dynamic> page(List<dynamic> rows) => {
  'data': rows,
  'current_page': 1,
  'last_page': 1,
  'total': rows.length,
};

class SavedApi extends FixtureApi {
  bool saved = false, lose = false, unavailable = false;
  int writes = 0;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'roaming/favorites/status')
      return {
        'favorite_record_ids': saved ? [1] : [],
      };
    if (path == 'roaming/favorites')
      return page(saved ? [row(available: !unavailable)] : []);
    expect(path, 'roaming/favorites/1');
    writes++;
    saved = method == 'PUT';
    if (lose) {
      lose = false;
      throw const ApiException(503, 'PRIVATE SERVER DETAIL');
    }
    return {'record_id': 1, 'is_favorite': saved};
  }
}

Widget app(SavedApi api, {int account = 1}) => MaterialApp(
  home: RoamingView(
    api: api,
    accountId: account,
    loader: (_) async => ApiPage<RoamingLocation>.fromJson(
      page([row()]),
      RoamingLocation.fromJson,
    ),
  ),
);
void main() {
  testWidgets(
    'partner search applies country and connector filters only on explicit submission',
    (tester) async {
      final queries = <Map<String, String>>[];
      await tester.pumpWidget(
        MaterialApp(
          home: RoamingView(
            loader: (_) async => ApiPage<RoamingLocation>.fromJson(
              page([row()]),
              RoamingLocation.fromJson,
            ),
            queryLoader: (filters, _) async {
              queries.add(Map.of(filters));
              return ApiPage<RoamingLocation>.fromJson(
                page([row()]),
                RoamingLocation.fromJson,
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Search partner locations'),
        'Harbour',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Country code (e.g. ARE)'),
        'are',
      );
      expect(queries.length, 1);
      await tester.tap(find.text('All connectors'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('CCS2').last);
      await tester.pumpAndSettle();
      final search = find.widgetWithText(
        TextButton,
        'Search partner locations',
      );
      // Search is below the fold after adding the source filters.
      // Bring the real ListView button fully into the viewport.
      await tester.scrollUntilVisible(
        search,
        220,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(search);
      await tester.pumpAndSettle();
      expect(queries.last, {
        'q': 'Harbour',
        'country': 'ARE',
        'connector_standard': 'IEC_62196_T2_COMBO',
      });
      expect(find.text('Start charging'), findsNothing);
    },
  );

  test('redaction cannot weaken the public partner location contract', () {
    expect(
      () => RoamingLocation.fromJson({...row(), 'location': null}),
      throwsFormatException,
    );
    expect(
      () => RoamingLocation.fromJson({
        ...row(available: false),
        'is_favorite': false,
      }),
      throwsFormatException,
    );
  });

  testWidgets(
    'lost save acknowledgement holds writes until authoritative preference refresh',
    (tester) async {
      final api = SavedApi()..lose = true;
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Save partner location'));
      await tester.tap(find.text('Save partner location'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(
        find.textContaining('Saved location update uncertain.'),
        findsOneWidget,
      );
      expect(find.textContaining('PRIVATE SERVER DETAIL'), findsNothing);
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(find.text('Remove saved partner location'), findsOneWidget);
      expect(api.writes, 1);
      expect(find.text('Start charging'), findsNothing);
    },
  );
  testWidgets('unavailable saved source is redacted and still removable', (
    tester,
  ) async {
    final api = SavedApi()
      ..saved = true
      ..unavailable = true;
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Saved partner locations'));
    await tester.pumpAndSettle();
    expect(find.text('Saved partner location is unavailable'), findsOneWidget);
    expect(find.text('Public address'), findsNothing);
    await tester.tap(find.text('Remove saved partner location'));
    await tester.pumpAndSettle();
    expect(find.text('No saved partner locations'), findsOneWidget);
    expect(api.writes, 1);
  });
}
