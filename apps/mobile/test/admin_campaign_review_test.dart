import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/admin_campaign_review.dart';
import 'package:creabiz_ev/api.dart';
import 'test_support.dart';

Map<String, dynamic> campaign({String status = 'submitted', String revision = '11'}) => {
  'id': 7, 'vendor_id': 9, 'invoice_id': 12, 'station_id': 4,
  'control_revision': revision, 'budget_units': 30,
  'delivered_units': 0, 'verified_server_clicks': 0,
  'title': 'Synthetic campaign', 'title_ar': 'حملة تجريبية',
  'description': 'Synthetic paid package', 'description_ar': 'باقة مدفوعة تجريبية',
  'country': 'AE', 'region': null, 'placement': 'station_detail',
  'starts_at': '2026-11-01 00:00:00', 'ends_at': '2026-12-01 00:00:00',
  'status': status, 'sponsored': true, 'unit': 'PREPAID_PACKAGE_DELIVERY_UNIT',
  'additional_cash_charge': false, 'human_impressions_verified': false,
};

class AdminCampaignApi extends FixtureApi {
  AdminCampaignApi({super.role = 'admin'});
  final stream = StreamController<int>.broadcast(sync: true);
  int epoch = 0;
  bool enabled = true, forged = false, demoted = false, expire = false;
  bool forgedScope = false;
  final queries = <Map<String, String>>[];
  String status = 'submitted', revision = '11';
  final posts = <Map<String, dynamic>>[];
  Completer<dynamic>? delay;
  @override
  Stream<int> get sessionEvents => stream.stream;
  @override
  bool Function() sessionFence() {
    final original = epoch;
    return () => epoch == original;
  }
  void revoke() { epoch++; stream.add(epoch); }
  @override
  void close() { stream.close(); super.close(); }
  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    if (path == 'me') return {'id': 1, 'role': demoted ? 'vendor' : role};
    if (path == 'commercial/campaigns') {
      queries.add(Map.of(query));
      if (delay != null) return delay!.future;
      if (expire) {
        revoke();
        final invalidated = epoch;
        throw ApiException(401, 'Expired', expirationIsCurrent: () => epoch == invalidated);
      }
      final matches = (query['status'] == null || query['status'] == status) &&
          (query['country'] == null || query['country'] == 'AE') &&
          (query['vendor_id'] == null || query['vendor_id'] == '9') &&
          (query['station_id'] == null || query['station_id'] == '4') &&
          (query['invoice_id'] == null || query['invoice_id'] == '12');
      return {
        'data': [if (matches) campaign(status: status, revision: revision)],
        'current_page': 1, 'last_page': 1, 'per_page': 25, 'total': matches ? 1 : 0,
        'adapter_enabled': enabled,
        'scope': {for (final key in ['status', 'country', 'vendor_id', 'station_id', 'invoice_id'])
          key: key == 'country' && forgedScope ? 'US' : key.endsWith('_id') ? int.tryParse(query[key] ?? '') : query[key]},
      };
    }
    if (path == 'commercial/campaigns/7') return {
      'campaign': campaign(status: status, revision: revision),
      'currently_eligible': false,
      'events': [
        {'id': 10, 'campaign_id': 7, 'actor_id': 9, 'action': 'created',
          'reason': 'Paid units reserved', 'created_at': '2026-11-01 00:00:00'},
        {'id': 11, 'campaign_id': 7, 'actor_id': 9, 'action': 'submit',
          'reason': 'Ready for review', 'created_at': '2026-11-01 00:01:00'},
        if (status == 'approved')
          {'id': 12, 'campaign_id': 7, 'actor_id': 1, 'action': 'approve',
            'reason': 'Reviewed synthetic creative', 'created_at': '2026-11-01 00:02:00'},
      ],
    };
    expect(method, 'POST');
    expect(path, 'commercial/campaigns/7/approve');
    posts.add(Map.of(body!));
    status = 'approved';
    revision = '12';
    return {'campaign': campaign(status: status, revision: revision)
      ..['vendor_id'] = forged ? 10 : 9};
  }
}

Widget app(AdminCampaignApi api, {bool ar = false, VoidCallback? onExpired}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: AdminCampaignReviewView(api: api, onExpired: onExpired ?? () {}),
);

Future<void> openDetail(WidgetTester t, AdminCampaignApi api) async {
  await t.pumpWidget(app(api));
  await t.pumpAndSettle();
  await t.tap(find.text('Synthetic campaign'));
  await t.pumpAndSettle();
}
Future<void> consent(WidgetTester t) async {
  final input = find.byType(TextField);
  await t.ensureVisible(input);
  await t.enterText(input, 'Reviewed synthetic creative');
  FocusManager.instance.primaryFocus?.unfocus();
  await t.pumpAndSettle();
  final button = find.text('Approve campaign');
  await t.ensureVisible(button);
  await t.tap(button);
  await t.pumpAndSettle();
}

void main() {
  test('strict typed record rejects foreign or promoted evidence', () {
    final source = campaign();
    final record = AdminCampaignRecord.parse(source);
    source['title'] = 'changed';
    expect(record.title, 'Synthetic campaign');
    expect(record.actions, ['approve', 'reject']);
    for (final change in <void Function(Map<String, dynamic>)>[
      (r) => r['id'] = 9007199254740992,
      (r) => r['control_revision'] = '9007199254740992',
      (r) => r['additional_cash_charge'] = true,
      (r) => r['human_impressions_verified'] = true,
      (r) => r['request_key'] = 'private',
      (r) => r['budget_units'] = 1.5,
      (r) => r['delivered_units'] = 31,
      (r) => r['verified_server_clicks'] = 1,
    ]) {
      final raw = campaign(); change(raw);
      expect(() => AdminCampaignRecord.parse(raw), throwsFormatException);
    }
    final history = [
      {'id': 10, 'campaign_id': 7, 'actor_id': 9, 'action': 'created',
        'reason': 'Paid units reserved', 'created_at': '2026-11-01 00:00:00'},
      {'id': 11, 'campaign_id': 7, 'actor_id': 9, 'action': 'submit',
        'reason': 'Ready for review', 'created_at': '2026-11-01 00:01:00'},
    ];
    expect(AdminCampaignEvent.parseHistory(history, record).last.id, 11);
    expect(() => AdminCampaignEvent.parseHistory(history.take(1).toList(), record), throwsFormatException);
    expect(() => AdminCampaignEvent.parseHistory([
      history.first, {...history.last, 'campaign_id': 8},
    ], record), throwsFormatException);
    // Valid-looking final revision must not hide an impossible state path.
    for (final bad in [
      [history.first, {...history.last, 'action': 'pause'}, {...history.last, 'id': 12, 'action': 'submit'}],
      [history.first, {...history.last, 'actor_id': 8}],
      [{...history.first, 'actor_id': 8}, history.last],
      [history.first, {...history.last, 'action': 'created'}, {...history.last, 'id': 12}],
    ]) {
      final finalRevision = bad.length == 3 ? '12' : '11';
      expect(() => AdminCampaignEvent.parseHistory(bad,
        AdminCampaignRecord.parse(campaign(revision: finalRevision))), throwsFormatException);
    }
    final accepted = AdminCampaignRecord.parse(campaign(status: 'approved', revision: '12'));
    record.verifyDecision(accepted, 'approve');
    final delivered = AdminCampaignRecord.parse(
      campaign(status: 'approved', revision: '12')
        ..['delivered_units'] = 2
        ..['verified_server_clicks'] = 1);
    final regressed = AdminCampaignRecord.parse(
      campaign(status: 'suspended', revision: '13')..['delivered_units'] = 2);
    expect(() => delivered.verifyDecision(regressed, 'suspend'), throwsFormatException);
    expect(() => record.verifyDecision(AdminCampaignRecord.parse(
      campaign(status: 'approved', revision: '12')..['invoice_id'] = 13), 'approve'), throwsFormatException);
    expect(() => record.verifyDecision(AdminCampaignRecord.parse(
      campaign(status: 'approved')), 'approve'), throwsFormatException);
  });

  testWidgets('current Admin explicitly moderates exact source revision, no serving inference', (t) async {
    final api = AdminCampaignApi();
    addTearDown(api.close);
    await openDetail(t, api);
    expect(api.posts, isEmpty);
    expect(find.text('Not currently eligible for serving'), findsOneWidget);
    await consent(t);
    expect(api.posts, isEmpty);
    await t.tap(find.text('Confirm review'));
    await t.pumpAndSettle();
    expect(api.posts.single, {'reason': 'Reviewed synthetic creative', 'expected_control_revision': 11});
    expect(find.text('Decision confirmed. Refresh authoritative history.'), findsOneWidget);
    expect(find.text('Currently eligible for serving'), findsNothing);
    expect(find.text('Approve campaign'), findsNothing);
  });

  testWidgets('native filters require exact server scope and clear stale rows', (t) async {
    final api = AdminCampaignApi();
    addTearDown(api.close);
    await t.pumpWidget(app(api));
    await t.pumpAndSettle();
    await t.tap(find.text('Campaign filters'));
    await t.pumpAndSettle();
    final country = find.byKey(const Key('campaign-filter-country'));
    await t.ensureVisible(country);
    await t.enterText(country, 'AE');
    FocusManager.instance.primaryFocus?.unfocus();
    await t.pumpAndSettle();
    expect(find.text('Synthetic campaign'), findsNothing);
    final apply = find.text('Apply campaign filters');
    await t.scrollUntilVisible(apply, 150, scrollable: find.byType(Scrollable).first);
    await t.tap(apply);
    await t.pumpAndSettle();
    expect(api.queries.last, {'page': '1', 'country': 'AE'});
    expect(find.text('Synthetic campaign'), findsOneWidget);
    api.forgedScope = true;
    await t.tap(find.text('Refresh campaign history'));
    await t.pumpAndSettle();
    expect(find.text('Synthetic campaign'), findsNothing);
    expect(find.textContaining('Review not confirmed.'), findsOneWidget);
    expect(api.posts, isEmpty);
  });

  testWidgets('vendor cannot access Admin campaign reads', (t) async {
    final api = AdminCampaignApi(role: 'vendor');
    addTearDown(api.close);
    await t.pumpWidget(app(api));
    await t.pumpAndSettle();
    expect(api.calls, ['GET me']);
    expect(find.text('Synthetic campaign'), findsNothing);
  });

  testWidgets('demotion before consent dispatch prevents moderation', (t) async {
    final api = AdminCampaignApi();
    addTearDown(api.close);
    await openDetail(t, api);
    await consent(t);
    api.demoted = true;
    await t.tap(find.text('Confirm review'));
    await t.pumpAndSettle();
    expect(api.posts, isEmpty);
    expect(find.text('Synthetic campaign'), findsNothing);
  });

  testWidgets('forged receipt removes decision controls without false confirmation', (t) async {
    final api = AdminCampaignApi()..forged = true;
    addTearDown(api.close);
    await openDetail(t, api);
    await consent(t);
    await t.tap(find.text('Confirm review'));
    await t.pumpAndSettle();
    expect(api.posts.length, 1);
    expect(find.text('Decision confirmed. Refresh authoritative history.'), findsNothing);
    expect(find.textContaining('Review not confirmed.'), findsOneWidget);
    expect(find.text('Approve campaign'), findsNothing);
  });

  testWidgets('revocation during consent cannot dispatch previous Admin decision', (t) async {
    final api = AdminCampaignApi();
    addTearDown(api.close);
    await openDetail(t, api);
    await consent(t);
    api.revoke();
    await t.pumpAndSettle();
    await t.tap(find.text('Confirm review'));
    await t.pumpAndSettle();
    expect(api.posts, isEmpty);
    expect(find.text('Synthetic campaign'), findsNothing);
  });

  testWidgets('late response after session invalidation never displays private campaign', (t) async {
    final api = AdminCampaignApi()..delay = Completer<dynamic>();
    addTearDown(api.close);
    await t.pumpWidget(app(api));
    await t.pump();
    api.revoke();
    api.delay!.complete({
      'data': [campaign()], 'current_page': 1, 'last_page': 1,
      'per_page': 25, 'total': 1, 'adapter_enabled': true,
    });
    await t.pumpAndSettle();
    expect(find.text('Synthetic campaign'), findsNothing);
    expect(api.posts, isEmpty);
  });

  testWidgets('real current expiry clears review and invokes sign-in callback', (t) async {
    final api = AdminCampaignApi()..expire = true;
    addTearDown(api.close);
    var expirations = 0;
    await t.pumpWidget(app(api, onExpired: () { expirations++; }));
    await t.pumpAndSettle();
    expect(expirations, 1);
    expect(find.text('Synthetic campaign'), findsNothing);
  });

  testWidgets('expired old generation cannot sign out a subsequent account', (t) async {
    final api = AdminCampaignApi()..delay = Completer<dynamic>();
    addTearDown(api.close);
    var expirations = 0;
    await t.pumpWidget(app(api, onExpired: () { expirations++; }));
    await t.pump();
    api.revoke();
    final expiredGeneration = api.epoch;
    api.revoke();
    api.delay!.completeError(ApiException(401, 'Old expiry',
      expirationIsCurrent: () => api.epoch == expiredGeneration));
    await t.pumpAndSettle();
    expect(expirations, 0);
    expect(find.text('Synthetic campaign'), findsNothing);
  });

  testWidgets('Arabic disabled adapter and foreground privacy', (t) async {
    final api = AdminCampaignApi()..enabled = false;
    addTearDown(api.close);
    await t.pumpWidget(app(api, ar: true));
    await t.pumpAndSettle();
    expect(find.text('ضوابط الحملات معطلة'), findsOneWidget);
    await t.tap(find.text('حملة تجريبية'));
    await t.pumpAndSettle();
    expect(find.text('اعتماد الحملة'), findsNothing);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pump();
    expect(find.text('حملة تجريبية'), findsNothing);
    expect(api.posts, isEmpty);
    expect(t.takeException(), isNull);
  });
}
