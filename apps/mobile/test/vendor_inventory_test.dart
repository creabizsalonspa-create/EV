import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_inventory.dart';
import 'test_support.dart';

Map<String, dynamic> company({bool writer = true, bool operational = true, bool owner = true}) => {
  'id': 1,
  'legal_name': 'Literal <company>',
  'country': 'AE',
  'status': 'approved',
  'capabilities': {
    'inventory_read': true,
    'owner': owner,
    'inventory_write': writer,
    'inventory_operational': operational,
  },
};

class InventoryApi extends FixtureApi {
  final requests = <String>[];
  bool blocked = false, fail = false, loseAck = false, corruptAck = false, siteLinked = true;
  int epoch = 0;
  final changes = StreamController<int>.broadcast(sync:true);
  @override Stream<int> get sessionEvents => changes.stream;
  @override bool Function() sessionFence() { final captured = epoch; return () => captured == epoch; }
  void rotate() { epoch++; changes.add(epoch); }
  int writes = 0;
  Completer<dynamic>? pending;
  Map<String, dynamic> page(
    List<Map<String, dynamic>> items, [
    int current = 1,
    int last = 1,
  ]) => {
    'data': items,
    'current_page': current,
    'last_page': last,
    'total': items.length,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    requests.add('$method $path');
    if (expired) throw const ApiException(401, 'private diagnostic');
    if (pending != null) return pending!.future;
    if (fail) throw const ApiException(404, 'private diagnostic');
    if (path == 'stations/7/availability-blocks' && method == 'GET') {
      return {'station_id': 7, 'data': <dynamic>[]};
    }
    if (method == 'PATCH') {
      expect(
        path,
        'vendor-ops/companies/1/chargers/3/connectors/4/maintenance',
      );
      expect(body!['maintenance_reason'], 'Literal <reason>');
      blocked = body['maintenance_blocked'] as bool;
      writes++;
      if (loseAck) throw const ApiException(0, 'transport lost');
      return {'id':corruptAck?99:4,'charger_id':3,'maintenance_blocked':blocked,
        'maintenance_reason':body['maintenance_reason'],'availability':'unknown','remote_control_enabled':false};
    }
    if (path == 'vendor-ops/companies')
      return page([company()], int.parse(query['page'] ?? '1'), 2);
    if (path.endsWith('/sites'))
      return page([
        {
          'id': 2,
          'company_id':1,
          'station_id': siteLinked ? 7 : null,
          'name': 'Literal site',
          'address': 'Synthetic address',
          'country': 'AE',
          'timezone': 'Asia/Dubai',
          'latitude': 25,
          'longitude': 55,
        },
      ]);
    if (path.endsWith('/chargers'))
      return page([
        {
          'id': 3,
          'company_id':1,'site_id':2,
          'asset_reference': 'SIM-3',
          'manufacturer': 'Synthetic',
          'model': 'Fixture',
          'source': 'simulator',
          'ocpp_protocol': '1.6',
        },
      ]);
    expect(path, 'vendor-ops/companies/1/chargers/3/connectors');
    return page([
      {
        'id': 4,
        'charger_id':3,
        'connector_number': 1,
        'connector_type': 'CCS2',
        'max_power_kw': 50,
        'maintenance_blocked': blocked,
        'maintenance_reason': blocked ? 'Literal <reason>' : null,
      },
    ]);
  }
}

Widget app(Widget home, {bool arabic = false}) => MaterialApp(
  locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: home,
);
VendorInventoryView connectors(
  InventoryApi api, {
  bool writer = true,
  bool operational = true,
  VoidCallback? onExpired,
}) => VendorInventoryView(
  api: api,
  onExpired: onExpired ?? () {},
  company: company(writer: writer, operational: operational),
  site: const {'id': 2},
  charger: const {'id': 3},
);
void main() {
  testWidgets(
    'linked station site offers an owner-only policy editor with current source GET',
    (tester) async {
      final api = InventoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        app(VendorInventoryView(
          api: api,
          onExpired: () {},
          company: company(),
          metadataEditor: true,
        )),
      );
      await tester.pumpAndSettle();
      final open = find.text('Manage station availability blocks');
      expect(open, findsOneWidget);
      await tester.ensureVisible(open);
      await tester.tap(open);
      await tester.pumpAndSettle();
      expect(find.text('Station availability blocks'), findsOneWidget);
      expect(api.requests, contains('GET stations/7/availability-blocks'));
      expect(api.requests.every((v) => v.startsWith('GET ')), isTrue);
    },
  );
  testWidgets(
    'invited staff and unlinked sites never expose station-wide policy writes',
    (tester) async {
      final api = InventoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        app(VendorInventoryView(
          api: api,
          onExpired: () {},
          company: company(owner: false),
          metadataEditor: true,
        )),
      );
      await tester.pumpAndSettle();
      expect(find.text('Manage station availability blocks'), findsNothing);
      api.siteLinked = false;
      await tester.tap(find.byTooltip('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Manage station availability blocks'), findsNothing);
      expect(api.requests.where((v) => v.contains('/availability-blocks')), isEmpty);
    },
  );
  testWidgets(
    'linked station management is localized and does not auto-publish',
    (tester) async {
      final api = InventoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        app(VendorInventoryView(
          api: api,
          onExpired: () {},
          company: company(),
          metadataEditor: true,
        ), arabic: true),
      );
      await tester.pumpAndSettle();
      final open = find.text('إدارة فترات حجب المحطة');
      expect(open, findsOneWidget);
      await tester.ensureVisible(open);
      await tester.tap(open);
      await tester.pumpAndSettle();
      expect(find.text('فترات حجب المحطة'), findsOneWidget);
      expect(api.requests, contains('GET stations/7/availability-blocks'));
      expect(api.requests.every((v) => v.startsWith('GET ')), isTrue);
    },
  );
  test('maintenance receipt must match exact connector, charger, state and reason', () {
    final row={'id':4,'charger_id':3,'maintenance_blocked':true,
      'maintenance_reason':'Original reason','availability':'unknown','remote_control_enabled':false};
    bool matches(dynamic value)=>inventoryMaintenanceReceipt(value,4,3,true,'Original reason');
    expect(matches(row),isTrue);
    for (final change in [
      {'id':5},{'charger_id':6},{'maintenance_blocked':false},
      {'maintenance_reason':'Other'},{'availability':'available'},{'remote_control_enabled':true},
    ]) { expect(matches({...row,...change}),isFalse); }
  });
  testWidgets('inventory background invalidates maintenance consent and hides private rows',(tester) async {
    final api=InventoryApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(connectors(api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Block new starts'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField),'Literal <reason>');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.writes,0);
    expect(find.text('Connector 1'),findsNothing);
    await tester.tap(find.byTooltip('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Connector 1'),findsOneWidget);
  });
  testWidgets('revoked inventory erases rows and denies retained navigation or refresh',(tester) async {
    final api=InventoryApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(VendorInventoryView(api:api,onExpired:(){})));
    await tester.pumpAndSettle();
    final navigate=tester.widget<FilledButton>(find.widgetWithText(FilledButton,'Sites')).onPressed!;
    final refresh=tester.widget<IconButton>(find.byWidgetPredicate((w) => w is IconButton && w.tooltip == 'Refresh')).onPressed!;
    final count=api.requests.length;
    api.rotate();
    await tester.pumpAndSettle();
    expect(find.text('Literal <company>'),findsNothing);
    navigate(); refresh();
    await tester.pumpAndSettle();
    expect(api.requests.length,count);
  });
  testWidgets('wrong maintenance acknowledgement requires fresh inventory without replay',(tester) async {
    final api=InventoryApi()..corruptAck=true;
    addTearDown(api.close);
    await tester.pumpWidget(app(connectors(api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Block new starts'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField),'Literal <reason>');
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.writes,1);
    expect(find.text('Connector 1'),findsNothing);
    expect(find.text(inventoryEnglish['uncertain']!),findsOneWidget);
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(api.writes,1);
    expect(find.text('Maintenance block active'),findsOneWidget);
  });
  test('inventory translation contracts match', () {
    expect(inventoryEnglish.keys.toSet(), inventoryArabic.keys.toSet());
  });
  testWidgets(
    'company pagination and hierarchy use scoped APIs without hardware commands',
    (tester) async {
      final api = InventoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        app(VendorInventoryView(api: api, onExpired: () {})),
      );
      await tester.pumpAndSettle();
      expect(find.text('Literal <company>'), findsOneWidget);
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(find.text('Page 2 of 2 · 1 records'), findsOneWidget);
      for (final label in ['Sites', 'Chargers', 'Connectors']) {
        await tester.tap(find.widgetWithText(FilledButton, label));
        await tester.pumpAndSettle();
      }
      expect(find.text('Connector 1'), findsOneWidget);
      expect(
        api.requests,
        contains('GET vendor-ops/companies/1/sites/2/chargers'),
      );
      expect(api.requests.every((v) => v.startsWith('GET ')), isTrue);
    },
  );
  testWidgets(
    'maintenance requires a reason and confirmation, then refreshes authoritative state',
    (tester) async {
      final api = InventoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(app(connectors(api)));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Block new starts'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, 0);
      await tester.enterText(find.byType(TextFormField), 'Literal <reason>');
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.blocked, isTrue);
      expect(find.text('Maintenance block active'), findsOneWidget);
      expect(find.text('Literal <reason>'), findsOneWidget);
      expect(find.text('Remove maintenance block'), findsOneWidget);
    },
  );
  testWidgets(
    'lost acknowledgement never replays a maintenance update and requires refresh',
    (tester) async {
      final api = InventoryApi()..loseAck = true;
      addTearDown(api.close);
      await tester.pumpWidget(app(connectors(api)));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Block new starts'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField), 'Literal <reason>');
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text(inventoryEnglish['uncertain']!), findsOneWidget);
      expect(find.text('Connector 1'), findsNothing);
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('Maintenance block active'), findsOneWidget);
    },
  );
  testWidgets(
    'read-only staff cannot change maintenance and revoked scope clears private rows',
    (tester) async {
      final api = InventoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(app(connectors(api, writer: false)));
      await tester.pumpAndSettle();
      expect(find.text('Block new starts'), findsNothing);
      api.fail = true;
      await tester.tap(find.byTooltip('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Connector 1'), findsNothing);
      expect(find.text('private diagnostic'), findsNothing);
    },
  );
  testWidgets(
    'Arabic narrow layout keeps literal data and blocks unapproved release',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = InventoryApi()..blocked = true;
      addTearDown(api.close);
      await tester.pumpWidget(
        app(connectors(api, operational: false), arabic: true),
      );
      await tester.pumpAndSettle();
      expect(find.text('Literal <reason>'), findsOneWidget);
      final button = tester.widget<OutlinedButton>(
        find.widgetWithText(OutlinedButton, inventoryArabic['release']!),
      );
      expect(button.onPressed, isNull);
      expect(
        Directionality.of(tester.element(find.text('Literal <reason>'))),
        TextDirection.rtl,
      );
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets('disposed scope ignores a pending response', (tester) async {
    final api = InventoryApi()..pending = Completer<dynamic>();
    addTearDown(api.close);
    await tester.pumpWidget(app(connectors(api)));
    await tester.pump();
    await tester.pumpWidget(const MaterialApp(home: Text('Public')));
    api.pending!.complete(api.page([]));
    await tester.pumpAndSettle();
    expect(find.text('Public'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
  testWidgets(
    'expired authentication clears inventory and calls session handler',
    (tester) async {
      var expired = false;
      final api = InventoryApi()..expired = true;
      addTearDown(api.close);
      await tester.pumpWidget(
        app(connectors(api, onExpired: () => expired = true)),
      );
      await tester.pumpAndSettle();
      expect(expired, isTrue);
      expect(find.text('Connector 1'), findsNothing);
    },
  );
  testWidgets(
    'nested authentication expiry returns to the public root without reloading removed scopes',
    (tester) async {
      final api = InventoryApi();
      addTearDown(api.close);
      var expiredCalls = 0;
      await tester.pumpWidget(
        app(
          Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => VendorInventoryView(
                      api: api,
                      onExpired: () => expiredCalls++,
                    ),
                  ),
                ),
                child: const Text('Public root'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Public root'));
      await tester.pumpAndSettle();
      for (final label in ['Sites', 'Chargers', 'Connectors']) {
        await tester.tap(find.widgetWithText(FilledButton, label));
        await tester.pumpAndSettle();
      }
      final prior = api.requests.length;
      api.expired = true;
      await tester.tap(find.byTooltip('Refresh'));
      await tester.pumpAndSettle();
      expect(expiredCalls, 1);
      expect(api.requests.length, prior + 1);
      expect(find.text('Public root'), findsOneWidget);
      expect(find.text('Literal <company>'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
