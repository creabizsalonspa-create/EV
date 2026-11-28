import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/vendor_campaigns.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'test_support.dart';

Map<String, dynamic> intent() => {
  'invoice_id': 11,
  'station_id': 22,
  'title': 'Private vendor creative',
  'title_ar': 'حملة خاصة',
  'description': 'Package creative',
  'description_ar': 'وصف الحملة',
  'country': 'AE',
  'region': null,
  'placement': 'discovery_banner',
  'budget_units': 3,
  'starts_at': '2026-11-15T10:00:00Z',
  'ends_at': '2026-11-16T10:00:00Z',
};
Map<String, dynamic> campaign({String status = 'draft'}) => {
  ...intent(),
  'id': 7,
  'vendor_id': 1,
  'control_revision': 9,
  'unit': 'PREPAID_PACKAGE_DELIVERY_UNIT',
  'additional_cash_charge': false,
  'human_impressions_verified': false,
  'status': status,
  'delivered_units': 0,
  'verified_server_clicks': 0,
};

class CampaignStore implements FinancialOperationStore {
  final values = <String, String>{};
  @override
  Future<Map<String, dynamic>?> read(String scope) async =>
      values[scope] == null
      ? null
      : Map<String, dynamic>.from(jsonDecode(values[scope]!) as Map);
  @override
  Future<void> write(String scope, Map<String, dynamic> value) async {
    values[scope] = jsonEncode(value);
  }

  @override
  Future<void> remove(String scope) async {
    values.remove(scope);
  }
}

class CampaignApi extends FixtureApi {
  String role = 'vendor';
  void Function(Map<String, dynamic>)? beforeCreate;
  bool live = true, returnAccepted = false, corruptDecision = false;
  int epoch = 0;
  final changes = StreamController<int>.broadcast(sync:true);
  @override
  Stream<int> get sessionEvents => changes.stream;
  void rotate() { epoch++; changes.add(epoch); }
  Map<String, dynamic> row = campaign();
  List<dynamic> deliveryRows = [];
  bool wrongPage = false;
  Completer<dynamic>? pendingList;
  final requests = <Map<String, dynamic>>[];
  @override
  bool Function() sessionFence() {
    final original = epoch;
    return () => live && epoch == original;
  }
  dynamic paged(List<dynamic> data) => {
    'data': data,
    'current_page': 1,
    'last_page': 1,
    'total': data.length,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add(path);
    requests.add({
      'path': path,
      'method': method,
      'body': body == null ? null : Map.of(body),
    });
    if (path == 'me') return {'id': role == 'admin' ? 9 : 1, 'role': role};
    if (path == 'commercial/campaigns' && method == 'GET')
      return pendingList?.future ??
          {
            ...paged([row]),
            if (wrongPage) 'current_page':2,
            'adapter_enabled': true,
          };
    if (path == 'commercial/campaigns/7')
      return {'campaign': row, 'events': [], 'currently_eligible': false,
        'deliveries':paged(deliveryRows)};
    if (path == 'commercial/campaign-packages')
      return paged([
        {
          'invoice_id': 11,
          'vendor_id': 1,
          'campaign_eligible': true,
          'capacity': {'remaining_slots': 1, 'remaining_units': 3},
          'period_start': '2026-11-01',
          'period_end': '2026-12-01',
        },
      ]);
    if (path == 'commercial/campaign-stations')
      return paged([
        {'id': 22, 'name': 'Owned source station', 'country': 'AE'},
      ]);
    if (path == 'commercial/campaign-intents/cancel')
      return {
        'cancelled': !returnAccepted,
        'campaign': returnAccepted ? row : null,
      };
    if (path.endsWith('/pause')) {
      row = {...row, 'status': 'paused', 'control_revision': 42};
      return {'campaign': corruptDecision ? {...row,'station_id':999} : row};
    }
    if (path == 'commercial/campaigns' && method == 'POST') {
      beforeCreate?.call(body!);
      row = {...campaign(), ...body!}..remove('idempotency_key');
      return {'campaign': row};
    }
    throw const ApiException(404, 'Unknown controlled route');
  }
}

void main() {
  test('paid package capacity is tenant-scoped, exact and bounded', () {
    final row = {
      'invoice_id': 11,
      'vendor_id': 1,
      'campaign_eligible': true,
      'capacity': {'remaining_slots': 1, 'remaining_units': 3},
    };
    expect(campaignPackage(row, 1)['available_units'], 3);
    expect(campaignPackage({
      ...row, 'capacity': {'remaining_slots': 0, 'remaining_units': 3},
    }, 1)['available_units'], 0);
    expect(campaignPackage({
      ...row, 'campaign_eligible': false, 'capacity': null,
    }, 1)['available_units'], 0);
    expect(() => campaignPackage(row, 2), throwsFormatException);
    for (final invalid in [
      null,
      {'remaining_slots': 1, 'remaining_units': 1.5},
      {'remaining_slots': -1, 'remaining_units': 2},
      {'remaining_slots': 1, 'remaining_units': 1000001},
    ]) {
      expect(
        () => campaignPackage({...row, 'capacity': invalid}, 1),
        throwsFormatException,
      );
    }
  });

  test('publisher receipt projection rejects foreign or invented source and excludes private publisher keys', () {
    final row=<String,dynamic>{'id':12,'campaign_id':7,'kind':'delivery',
      'parent_delivery_id':null,'occurred_at':'2026-11-15 10:00:00',
      'created_at':'2026-11-15 10:00:01','event_id':'PRIVATE-KEY','fingerprint':'PRIVATE-HASH'};
    final safe=campaignDelivery(row,7);
    expect(safe.keys.toSet(),{'id','kind','occurred_at','created_at'});
    expect(safe['occurred_at'],DateTime.utc(2026,11,15,10));
    for(final invalid in [
      {...row,'campaign_id':8},{...row,'kind':'impression'},
      {...row,'kind':'click','parent_delivery_id':null},
      {...row,'kind':'click','parent_delivery_id':12},
      {...row,'occurred_at':'2026-02-30 10:00:00'},
    ]) {
      expect(()=>campaignDelivery(invalid,7),throwsFormatException);
    }
    expect(campaignDelivery({...row,'kind':'click','parent_delivery_id':11},7)['kind'],'click');
  });
  test('campaign pages reject duplicates, wrong page and impossible total', () {
    final raw={'data':[campaign()],'current_page':1,'last_page':1,'total':1};
    final parsed=campaignPage(raw,1,'id',(row)=>row);
    expect(parsed.items.length,1);
    for(final invalid in [
      {...raw,'current_page':2},{...raw,'total':26},
      {...raw,'data':[campaign(),campaign()],'total':2},
      {...raw,'last_page':0},
    ]) {
      expect(()=>campaignPage(invalid,1,'id',(row)=>row),throwsFormatException);
    }
  });
  testWidgets('vendor review shows only approved publisher receipt data and rejects a foreign campaign receipt',(tester) async {
    final api=CampaignApi()..deliveryRows=[
      {'id':12,'campaign_id':7,'kind':'delivery','parent_delivery_id':null,
        'occurred_at':'2026-11-15 10:00:00','created_at':'2026-11-15 10:00:01',
        'event_id':'PRIVATE-PUBLISHER-KEY','fingerprint':'PRIVATE-PUBLISHER-HASH'},
    ];
    await tester.pumpWidget(MaterialApp(home:VendorCampaignsView(
      api:api,onExpired:(){},operationStore:CampaignStore(),
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Review'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.byKey(const ValueKey('campaign-delivery-12')),120,
      scrollable:find.byType(Scrollable).first);
    expect(find.text('Recorded delivery unit'),findsOneWidget);
    expect(find.textContaining('PRIVATE-PUBLISHER'),findsNothing);
    expect(api.requests.where((r)=>r['method']=='POST'),isEmpty);
    api.deliveryRows=[{...api.deliveryRows.single as Map,'campaign_id':8}];
    await tester.scrollUntilVisible(find.text('Refresh history'),-120,
      scrollable:find.byType(Scrollable).first);
    await tester.tap(find.text('Refresh history'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Review'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('campaign-delivery-12')),findsNothing);
    expect(find.textContaining('Request not confirmed'),findsOneWidget);
    expect(api.requests.where((r)=>r['method']=='POST'),isEmpty);
  });

  testWidgets('vendor campaign rejects wrong page then restores verified history on explicit refresh',(tester) async {
    final api=CampaignApi()..wrongPage=true;
    await tester.pumpWidget(MaterialApp(home:VendorCampaignsView(
      api:api,onExpired:(){},operationStore:CampaignStore(),
    )));
    await tester.pumpAndSettle();
    expect(find.text('Private vendor creative'),findsNothing);
    expect(find.textContaining('Request not confirmed'),findsOneWidget);
    api.wrongPage=false;
    await tester.tap(find.text('Refresh history'));
    await tester.pumpAndSettle();
    expect(find.text('Private vendor creative'),findsOneWidget);
    expect(api.requests.where((r)=>r['method']=='POST'),isEmpty);
  });

  test(
    'strict campaign receipts and saved intent preserve ownership, units and UTC',
    () {
      expect(nativeCampaign(campaign(), 1, 'vendor')['id'], 7);
      expect(
        nativeCampaign(
          {...campaign(), 'control_revision': '9007199254740993'},
          1,
          'vendor',
        )['control_revision'],
        9007199254740993,
      );
      expect(
        () => nativeCampaign(campaign(), 2, 'vendor'),
        throwsFormatException,
      );
      expect(
        () => nativeCampaign(
          {...campaign(), 'additional_cash_charge': true},
          1,
          'vendor',
        ),
        throwsFormatException,
      );
      expect(
        () => nativeCampaignIntent({...intent(), 'budget_units': 3.5}),
        throwsFormatException,
      );
      expect(
        () => nativeCampaignIntent({...intent(), 'secret': 'unexpected'}),
        throwsFormatException,
      );
      expect(() => campaignUtc('2026-02-30T10:00:00Z'), throwsFormatException);
      expect(
        campaignMatchesIntent({
          ...campaign(),
          'starts_at': '2026-11-15 10:00:00',
          'ends_at': '2026-11-16 10:00:00',
        }, intent()),
        isTrue,
      );
    },
  );
  test('campaign receipt time never normalizes invalid dates or assumes a device timezone', () {
    for (final value in [
      '2026-11-15T10:00:00',
      '2026-11-15T11:00:00+01:00',
      '2026-11-15T10:00:00.000001Z',
      '2026-11-15T10:00:00Z\n',
      '2026-11-15 10:00:00 ',
      '2026-11-14T34:00:00Z',
      '2026-10-46T10:00:00Z',
    ]) {
      expect(campaignMatchesIntent({...campaign(),'starts_at':value},intent()),isFalse,reason:value);
    }
    for(final value in [
      '2026-11-15T10:00:00Z',
      '2026-11-15T10:00:00.000000Z',
      '2026-11-15 10:00:00',
    ]) {
      expect(campaignMatchesIntent({...campaign(),'starts_at':value},intent()),isTrue,reason:value);
    }
  });
  test('campaign transition receipt must preserve intent and advance global event revision', () {
    final before=campaign(status:'approved');
    final after={...before,'status':'paused','control_revision':42};
    expect(campaignConfirmsDecision(before,after,'pause'),isTrue);
    for(final invalid in [
      {...after,'control_revision':9},
      {...after,'control_revision':8},
      {...after,'status':'approved'},
      {...after,'id':8},
      {...after,'vendor_id':2},
      {...after,'station_id':999},
      {...after,'budget_units':4},
      {...after,'starts_at':'2026-11-15T10:00:00'},
      {...after,'title':'different creative'},
    ]) {
      expect(campaignConfirmsDecision(before,invalid,'pause'),isFalse,reason:'$invalid');
    }
    expect(campaignConfirmsDecision(before,after,'approve'),isFalse);
  });
  testWidgets('vendor reviews a campaign and confirms a revision-bound pause', (
    tester,
  ) async {
    final api = CampaignApi()..row = campaign(status: 'approved');
    await tester.pumpWidget(
      MaterialApp(
        home: VendorCampaignsView(
          api: api,
          onExpired: () {},
          operationStore: CampaignStore(),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Review'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextField),
      'Pause this approved campaign',
    );
    // Editing invalidates the previously rendered consent callback. Let the
    // new reviewed-intent frame settle before the user can opt in again.
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('I reviewed this campaign decision.'));
    await tester.tap(find.text('I reviewed this campaign decision.'));
    await tester.pump();
    await tester.ensureVisible(find.text('Pause'));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value,isTrue);
    expect(tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton,'Pause')).onPressed,isNotNull);
    final retained = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton,'Pause')).onPressed!;
    await tester.tap(find.text('Pause'));
    await tester.pumpAndSettle();
    retained();
    retained();
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog),findsOneWidget);
    expect(api.requests.where((r) => r['method'] == 'POST'), isEmpty);
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    final write = api.requests.singleWhere((r) => r['method'] == 'POST');
    expect(write['path'], 'commercial/campaigns/7/pause');
    expect((write['body'] as Map)['expected_control_revision'], 9);
    expect((write['body'] as Map)['reason'], 'Pause this approved campaign');
  });
  testWidgets(
    'restored unused intent resolves without submitting a duplicate campaign',
    (tester) async {
      final api = CampaignApi(), store = CampaignStore();
      final scope = '${api.base.origin}:${api.base.path}:1:campaign-create';
      await store.write(scope, {'key': 'saved-explicit-key', 'body': intent()});
      await tester.pumpWidget(
        MaterialApp(
          home: VendorCampaignsView(
            api: api,
            onExpired: () {},
            operationStore: store,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create or recover campaign'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.text('I reviewed this exact campaign intent.'),
      );
      await tester.tap(find.text('I reviewed this exact campaign intent.'));
      await tester.pump();
      final resolve = find.text(
        'Resolve saved creation: recover receipt or cancel unused intent',
      );
      await tester.ensureVisible(resolve);
      await tester.tap(resolve);
      await tester.pumpAndSettle();
      expect(store.values, isEmpty);
      final writes = api.requests.where((r) => r['method'] == 'POST').toList();
      expect(writes.length, 1);
      expect(writes.single['path'], 'commercial/campaign-intents/cancel');
      expect(
        (writes.single['body'] as Map)['idempotency_key'],
        'saved-explicit-key',
      );
    },
  );
  testWidgets(
    'accepted lost creation recovers its exact receipt and never cancels the campaign',
    (tester) async {
      final api = CampaignApi()..returnAccepted = true, store = CampaignStore();
      await store.write(
        '${api.base.origin}:${api.base.path}:1:campaign-create',
        {'key': 'accepted-key', 'body': intent()},
      );
      await tester.pumpWidget(
        MaterialApp(
          home: VendorCampaignsView(
            api: api,
            onExpired: () {},
            operationStore: store,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create or recover campaign'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.text('I reviewed this exact campaign intent.'),
      );
      await tester.tap(find.text('I reviewed this exact campaign intent.'));
      await tester.pump();
      final resolve = find.text(
        'Resolve saved creation: recover receipt or cancel unused intent',
      );
      await tester.ensureVisible(resolve);
      await tester.tap(resolve);
      await tester.pumpAndSettle();
      expect(store.values, isEmpty);
      expect(api.row['status'], 'draft');
      expect(
        api.requests.where((r) => r['path'] == 'commercial/campaigns/7/cancel'),
        isEmpty,
      );
    },
  );
  testWidgets('late list response cannot reveal a revoked account', (
    tester,
  ) async {
    final api = CampaignApi()..pendingList = Completer<dynamic>();
    await tester.pumpWidget(
      MaterialApp(
        home: VendorCampaignsView(
          api: api,
          onExpired: () {},
          operationStore: CampaignStore(),
        ),
      ),
    );
    await tester.pump();
    api.live = false;
    api.pendingList!.complete({
      ...api.paged([campaign()]),
      'adapter_enabled': true,
    });
    await tester.pumpAndSettle();
    expect(find.text('Private vendor creative'), findsNothing);
  });
  testWidgets(
    'authors bilingual campaign and persists exact creation identity before dispatch',
    (tester) async {
      final api = CampaignApi(), store = CampaignStore();
      api.beforeCreate = (body) {
        expect(store.values.length, 1);
        expect(
          (jsonDecode(store.values.values.single) as Map)['key'],
          body['idempotency_key'],
        );
      };
      await tester.pumpWidget(
        MaterialApp(
          home: VendorCampaignsView(
            api: api,
            onExpired: () {},
            operationStore: store,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create or recover campaign'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Paid package invoice'));
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('#11 ·').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Owned station'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Owned source station').last);
      await tester.pumpAndSettle();
      for (final entry in {
        'Title (English)': 'Private vendor creative',
        'Title (Arabic)': 'حملة خاصة',
        'Description (English)': 'Package creative',
        'Description (Arabic)': 'وصف الحملة',
        'Whole delivery units': '٣',
        'Start UTC: YYYY-MM-DDTHH:mm:ssZ': '2026-11-15T10:00:00Z',
        'End UTC: YYYY-MM-DDTHH:mm:ssZ': '2026-11-16T10:00:00Z',
      }.entries) {
        final field = find.byWidgetPredicate(
          (widget) =>
              widget is TextField && widget.decoration?.labelText == entry.key,
        );
        await tester.scrollUntilVisible(
          field,
          150,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.enterText(field, entry.value);
      }
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('I reviewed this exact campaign intent.'),
        150,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('I reviewed this exact campaign intent.'));
      await tester.pump();
      // Editing a previously consented draft requires explicit re-review.
      // A detached approval callback must not reauthorize the modified draft.
      final oldConsent = tester.widget<CheckboxListTile>(
        find.byType(CheckboxListTile)).onChanged!;
      final title = find.byWidgetPredicate((w) => w is TextField &&
          w.decoration?.labelText == 'Title (English)');
      await tester.scrollUntilVisible(title,-150,scrollable:find.byType(Scrollable).first);
      await tester.enterText(title,'Updated private creative');
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(find.text('Create draft'),150,
        scrollable:find.byType(Scrollable).first);
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton,'Create draft')).onPressed,isNull);
      oldConsent(true);
      await tester.pumpAndSettle();
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton,'Create draft')).onPressed,isNull);
      expect(api.requests.where((r)=>r['method']=='POST'),isEmpty);
      final budget = find.byWidgetPredicate(
        (w) => w is TextField && w.decoration?.labelText == 'Whole delivery units',
      );
      await tester.ensureVisible(budget);
      expect(find.text('Available delivery units: 3'), findsOneWidget);
      await tester.enterText(budget, '٤');
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('I reviewed this exact campaign intent.'));
      await tester.tap(find.text('I reviewed this exact campaign intent.'));
      await tester.pump();
      await tester.ensureVisible(find.text('Create draft'));
      await tester.tap(find.text('Create draft'));
      await tester.pumpAndSettle();
      expect(api.requests.where((r) => r['method'] == 'POST'), isEmpty);
      expect(store.values, isEmpty);
      await tester.ensureVisible(budget);
      await tester.enterText(budget, '٣');
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('I reviewed this exact campaign intent.'));
      await tester.tap(find.text('I reviewed this exact campaign intent.'));
      await tester.pump();
      await tester.ensureVisible(find.text('Create draft'));
      await tester.tap(find.text('Create draft'));
      await tester.pumpAndSettle();
      final creation = api.requests.singleWhere(
        (request) =>
            request['path'] == 'commercial/campaigns' &&
            request['method'] == 'POST',
      );
      expect((creation['body'] as Map)['title'], 'Updated private creative');
      expect((creation['body'] as Map)['budget_units'], 3);
      expect((creation['body'] as Map)['invoice_id'], 11);
      expect((creation['body'] as Map)['station_id'], 22);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'Admin bearer policy is preserved and private campaign management is not offered',
    (tester) async {
      final api = CampaignApi()..role = 'admin';
      await tester.pumpWidget(
        MaterialApp(
          home: VendorCampaignsView(
            api: api,
            onExpired: () {},
            operationStore: CampaignStore(),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Private vendor creative'), findsNothing);
      expect(find.text('Approve'), findsNothing);
      expect(find.text('Create or recover campaign'), findsNothing);
      expect(api.calls, ['me']);
    },
  );
  testWidgets(
    'background hides private saved creation while retaining its recovery identity',
    (tester) async {
      final api = CampaignApi(), store = CampaignStore();
      await store.write(
        '${api.base.origin}:${api.base.path}:1:campaign-create',
        {'key': 'private-saved-key', 'body': intent()},
      );
      await tester.pumpWidget(
        MaterialApp(
          home: VendorCampaignsView(
            api: api,
            onExpired: () {},
            operationStore: store,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create or recover campaign'));
      await tester.pumpAndSettle();
      expect(find.text('title: Private vendor creative'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.textContaining('Private vendor creative'), findsNothing);
      expect(store.values.length, 1);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      expect(find.textContaining('Private vendor creative'), findsNothing);
      expect(
        api.requests.where((request) => request['method'] == 'POST'),
        isEmpty,
      );
    },
  );
  testWidgets('first inactive vendor campaign mount does not read private data',(tester) async {
    final api = CampaignApi();
    addTearDown(api.changes.close);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    addTearDown(()=>tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));
    await tester.pumpWidget(MaterialApp(home:VendorCampaignsView(
      api:api,onExpired:(){},operationStore:CampaignStore(),
    )));
    await tester.pumpAndSettle();
    expect(api.calls,isEmpty);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.calls,isEmpty);
    await tester.tap(find.text('Refresh history'));
    await tester.pumpAndSettle();
    expect(api.calls,['me','commercial/campaigns']);
  });

  testWidgets('same API epoch rotation invalidates private recovery and stale refresh',(tester) async {
    final api=CampaignApi(), store=CampaignStore();
    addTearDown(api.changes.close);
    await store.write('${api.base.origin}:${api.base.path}:1:campaign-create',
      {'key':'original-actor-only','body':intent()});
    Widget app()=>MaterialApp(home:VendorCampaignsView(
      api:api,onExpired:(){},operationStore:store,
    ));
    await tester.pumpWidget(app());
    await tester.pumpAndSettle();
    final oldRefresh=tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton,'Refresh history')).onPressed!;
    await tester.tap(find.text('Create or recover campaign'));
    await tester.pumpAndSettle();
    expect(find.text('title: Private vendor creative'),findsOneWidget);
    await tester.ensureVisible(find.text('I reviewed this exact campaign intent.'));
    await tester.tap(find.text('I reviewed this exact campaign intent.'));
    await tester.pump();
    final resolve=find.widgetWithText(OutlinedButton,
      'Resolve saved creation: recover receipt or cancel unused intent');
    await tester.ensureVisible(resolve);
    final stale=tester.widget<OutlinedButton>(resolve).onPressed!;
    final count=api.requests.length;
    api.rotate();
    await tester.pumpAndSettle();
    expect(find.textContaining('Private vendor creative'),findsNothing);
    stale();
    oldRefresh();
    await tester.pumpWidget(app());
    await tester.pumpAndSettle();
    expect(api.requests.length,count);
    expect(store.values.length,1);
    expect(find.textContaining('Private vendor creative'),findsNothing);
  });

  testWidgets('account rotation during decision dialog prevents campaign transition',(tester) async {
    final api=CampaignApi()..row=campaign(status:'approved');
    addTearDown(api.changes.close);
    await tester.pumpWidget(MaterialApp(home:VendorCampaignsView(
      api:api,onExpired:(){},operationStore:CampaignStore(),
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Review'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'Pause the reviewed campaign');
    // Editing invalidates the previously rendered consent callback. Let the
    // new reviewed-intent frame settle before the user can opt in again.
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('I reviewed this campaign decision.'));
    await tester.tap(find.text('I reviewed this campaign decision.'));
    await tester.pump();
    await tester.ensureVisible(find.text('Pause'));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value,isTrue);
    expect(tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton,'Pause')).onPressed,isNotNull);
    await tester.tap(find.text('Pause'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog),findsOneWidget);
    api.rotate();
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.requests.where((r)=>r['method']=='POST'),isEmpty);
    expect(find.text('Private vendor creative'),findsNothing);
  });
  testWidgets('mismatched transition receipt blocks further action until source refresh',(tester) async {
    final api=CampaignApi()..row=campaign(status:'approved')..corruptDecision=true;
    addTearDown(api.changes.close);
    await tester.pumpWidget(MaterialApp(home:VendorCampaignsView(
      api:api,onExpired:(){},operationStore:CampaignStore(),
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Review'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField),'Pause this reviewed campaign');
    // Editing invalidates the previously rendered consent callback. Let the
    // new reviewed-intent frame settle before the user can opt in again.
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('I reviewed this campaign decision.'));
    await tester.tap(find.text('I reviewed this campaign decision.'));
    await tester.pump();
    await tester.ensureVisible(find.text('Pause'));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(find.byType(CheckboxListTile)).value,isTrue);
    expect(tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton,'Pause')).onPressed,isNotNull);
    await tester.tap(find.text('Pause'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog),findsOneWidget);
    await tester.tap(find.text('Confirm'));
    await tester.pumpAndSettle();
    expect(api.requests.where((r)=>r['method']=='POST').length,1);
    expect(find.text('Pause'),findsNothing);
    expect(find.text('Resume'),findsNothing);
    expect(find.textContaining('Request not confirmed'),findsOneWidget);
    await tester.ensureVisible(find.text('Refresh history'));
    await tester.tap(find.text('Refresh history'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Review'));
    await tester.pumpAndSettle();
    expect(find.text('Resume'),findsOneWidget);
    expect(api.requests.where((r)=>r['method']=='POST').length,1);
  });
}
