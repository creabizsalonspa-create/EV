import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/connector_choices.dart';
import 'package:creabiz_ev/vendor_topology.dart';
import 'package:creabiz_ev/topology_strings.dart';
import 'test_support.dart';

final parent = Station.fromJson({
  'id': 10,
  'name': 'Literal canonical site',
  'connector_type': 'CCS2',
  'power_kw': 50,
  'evidence': 'SIMULATED',
  'reservable': false,
  'reservation_policy': 'canonical',
});
Map<String, dynamic> option(int id, {bool ready = true}) => {
  'id': id,
  'station_id': 10,
  'evse_number': id,
  'connector_number': 7,
  'connector_type': 'Type2',
  'max_power_kw': 22,
  'reservable': ready,
};
Widget app(Widget page, {bool arabic = false}) => MaterialApp(
  locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: page,
);

class TopologyApi extends FixtureApi {
  int writes = 0;
  bool created = false, loseAck = false, mapped = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'POST') {
      writes++;
      if (path.endsWith('/mapping')) {
        expect(body, {'source_connector_number': null});
        mapped = true;
      } else {
        expect(body, {
          'number': 2,
          'mode': 'application',
          'binding_id': null,
          'source_evse_number': 0,
        });
        created = true;
      }
      if (loseAck) throw const ApiException(0, 'private diagnostic');
      return {};
    }
    if (path.endsWith('source-options'))
      return {
        'data': <dynamic>[],
        'current_page': 1,
        'last_page': 1,
        'total': 0,
      };
    if (path.endsWith('connectors'))
      return {
        'data': [
          {
            'id': 9,
            'connector_number': 7,
            'connector_type': 'CCS2',
            'canonical_mapping_id': mapped ? 5 : null,
          },
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    return {
      'data': created
          ? [
              {'id': 2, 'number': 2, 'mode': 'application'},
            ]
          : <dynamic>[],
      'current_page': 1,
      'last_page': 1,
      'total': created ? 1 : 0,
    };
  }
}

VendorTopologyView vendor(
  TopologyApi api, {
  bool write = true,
  Map<String, dynamic>? evse,
}) => VendorTopologyView(
  api: api,
  company: {
    'id': 1,
    'capabilities': {'inventory_write': write, 'inventory_operational': true},
  },
  charger: const {'id': 3},
  onExpired: () {},
  evse: evse,
);
void main() {
  test(
    'canonical resource localization contracts match',
    () => expect(topologyEn.keys.toSet(), topologyAr.keys.toSet()),
  );
  testWidgets(
    'customer selects an explicit mapped connector with actual capabilities',
    (tester) async {
      Station? selected;
      await tester.pumpWidget(
        app(
          ConnectorChoicesView(
            station: parent,
            loader: (_) async => ApiPage(
              items: [option(2), option(3, ready: false)],
              current: 1,
              last: 1,
              total: 2,
            ),
            onReserve: (station) async => selected = station,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Reserve simulator slot'), findsOneWidget);
      await tester.tap(find.text('Reserve simulator slot'));
      await tester.pumpAndSettle();
      expect(selected!.id, 10);
      expect(selected!.mappingId, 2);
      expect(selected!.connector, 'Type2');
      expect(selected!.powerKw, 22);
      expect(selected!.evseNumber, 2);
    },
  );
  testWidgets(
    'Arabic pagination failure clears stale choices and retries the failed page',
    (tester) async {
      var fail = true;
      final pages = <int>[];
      await tester.pumpWidget(
        app(
          ConnectorChoicesView(
            station: parent,
            loader: (page) async {
              pages.add(page);
              if (page == 2 && fail)
                throw const ApiException(503, 'private detail');
              return ApiPage(
                items: [option(page)],
                current: page,
                last: 2,
                total: 26,
              );
            },
            onReserve: (_) async {},
          ),
          arabic: true,
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('التالي'));
      await tester.pumpAndSettle();
      expect(find.textContaining('EVSE 1'), findsNothing);
      expect(find.text('private detail'), findsNothing);
      fail = false;
      await tester.tap(find.text('إعادة المحاولة'));
      await tester.pumpAndSettle();
      expect(pages, [1, 2, 2]);
      expect(
        Directionality.of(tester.element(find.text('Literal canonical site'))),
        TextDirection.rtl,
      );
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets('disposed connector page ignores a late response', (
    tester,
  ) async {
    final pending = Completer<ApiPage<Map<String, dynamic>>>();
    await tester.pumpWidget(
      app(
        ConnectorChoicesView(
          station: parent,
          loader: (_) => pending.future,
          onReserve: (_) async {},
        ),
      ),
    );
    await tester.pump();
    await tester.pumpWidget(const MaterialApp(home: Text('Public')));
    pending.complete(
      ApiPage(items: [option(1)], current: 1, last: 1, total: 1),
    );
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
  testWidgets(
    'vendor creates a real payload with Arabic digits and refreshed immutable identity',
    (tester) async {
      final api = TopologyApi();
      addTearDown(api.close);
      await tester.pumpWidget(app(vendor(api)));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create EVSE'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField), '٢');
      await tester.tap(find.text('Confirm mapping'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('EVSE 2 · #2'), findsOneWidget);
    },
  );
  testWidgets(
    'mapping an application connector requires confirmation and has no source target',
    (tester) async {
      final api = TopologyApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        app(vendor(api, evse: const {'id': 2, 'mode': 'application'})),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Map connector'));
      await tester.pumpAndSettle();
      expect(api.writes, 0);
      await tester.tap(find.text('Confirm mapping'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('Map connector'), findsNothing);
      expect(find.text('#5'), findsOneWidget);
    },
  );
  testWidgets('read-only staff cannot create or map identities', (
    tester,
  ) async {
    final api = TopologyApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(vendor(api, write: false)));
    await tester.pumpAndSettle();
    expect(find.text('Create EVSE'), findsNothing);
    expect(api.writes, 0);
  });
  testWidgets(
    'uncertain EVSE creation cannot replay without authoritative refresh',
    (tester) async {
      final api = TopologyApi()..loseAck = true;
      addTearDown(api.close);
      await tester.pumpWidget(app(vendor(api)));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create EVSE'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField), '2');
      await tester.tap(find.text('Confirm mapping'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text(topologyEn['uncertain']!), findsOneWidget);
      expect(find.text('Create EVSE'), findsNothing);
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('EVSE 2 · #2'), findsOneWidget);
    },
  );
}
