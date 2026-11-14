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
  bool live = true, returnAccepted = false;
  Map<String, dynamic> row = campaign();
  Completer<dynamic>? pendingList;
  final requests = <Map<String, dynamic>>[];
  @override
  bool Function() sessionFence() =>
      () => live;
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
            'adapter_enabled': true,
          };
    if (path == 'commercial/campaigns/7')
      return {'campaign': row, 'events': [], 'currently_eligible': false};
    if (path == 'commercial/campaign-packages')
      return paged([
        {
          'invoice_id': 11,
          'vendor_id': 1,
          'campaign_eligible': true,
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
      row = {...row, 'status': 'paused', 'control_revision': 10};
      return {'campaign': row};
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
    await tester.ensureVisible(find.text('I reviewed this campaign decision.'));
    await tester.tap(find.text('I reviewed this campaign decision.'));
    await tester.pump();
    await tester.ensureVisible(find.text('Pause'));
    await tester.tap(find.text('Pause'));
    await tester.pumpAndSettle();
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
      await tester.ensureVisible(find.text('Create draft'));
      await tester.tap(find.text('Create draft'));
      await tester.pumpAndSettle();
      final creation = api.requests.singleWhere(
        (request) =>
            request['path'] == 'commercial/campaigns' &&
            request['method'] == 'POST',
      );
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
}
