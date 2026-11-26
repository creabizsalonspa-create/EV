import 'dart:async';
import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/subscriptions.dart';
import 'test_support.dart';
import 'vendor_finance_test.dart' show VendorOperationStore, PausedVendorStore;

class SubscriptionApi extends FixtureApi {
  bool lost = false, wrongOwner = false;
  int? forgedPlanId, forgedCurrentPage, forgedTotal, renewalCycle;
  Map<String, dynamic>? historyOverride, writeReceiptOverride, operationReceiptOverride;
  int writes = 0;
  Completer<dynamic>? meWait, detailWait, eventsWait, postWait;
  final bodies = <Map<String, dynamic>>[];
  final plan = <String, dynamic>{
    'id': 7,
    'name': 'Published <literal> plan',
    'audience': 'customer',
    'available': true,
    'currency': 'KWD',
    'minor_units': 3,
    'price_minor': 1250,
    'interval': 'month',
    'entitlements': {'priority': true},
  };
  Map<String, dynamic> get summary => {
    'subscription': {
      'id': 4,
      'user_id': wrongOwner ? 9 : 1,
      'plan_id': 7,
      'status': renewalCycle == null ? 'pending' : 'active',
      'cycle_number': renewalCycle ?? 0,
      'period_end': renewalCycle == null ? null : '2026-11-28T00:00:00Z',
      'cancel_at_period_end': false,
    },
    'plan': plan,
    'real_payment_processed': false,
    'ledger_balanced': true,
    'entitlements_active': false,
    'entitlements': [],
    'invoices': [
      {
        'id': 11,
        'subscription_id': 4,
        'currency': 'KWD',
        'minor_units': 3,
        'total_minor': 1313,
        'paid': false,
        'period_start': '2026-10-28',
        'period_end': '2026-11-28',
      },
    ],
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    queries.add(query);
    if (expired)
      throw const ApiException(401, 'private-subscription-diagnostic');
    if (path == 'me') {
      return meWait?.future ?? {'id': 1, 'role': 'customer'};
    }
    if (method == 'POST') {
      writes++;
      bodies.add({...body!});
      if (lost) throw const ApiException(0, 'private-subscription-diagnostic');
      if (postWait != null) return postWait!.future;
      final result = writeReceiptOverride ?? summary;
      if (path.endsWith('/renew')) return result;
      final saved = bodies.last;
      final action = saved['action'] ?? 'enroll';
      final planId = saved['plan_id'];
      final key = saved['idempotency_key'];
      return {
        ...result,
        'operation_receipt': operationReceiptOverride ?? {
          'event_id': 19, 'actor_id': 1, 'subscription_id': 4,
          'action': action, 'plan_id': planId,
          'request_receipt': sha256.convert(utf8.encode(
            'subscription-operation-v1:1:4:19:$action:${planId ?? 0}:$key',
          )).toString(),
        },
      };
    }
    if (path == 'commercial/plans')
      return {
        'data': [
          if (forgedPlanId != null) {...plan, 'id': forgedPlanId} else plan,
        ],
        'current_page': forgedCurrentPage ?? int.parse(query['page'] ?? '1'),
        'last_page': 2,
        'total': forgedTotal ?? 26,
      };
    if (path == 'commercial/subscriptions/4') {
      return detailWait?.future ?? summary;
    }
    if (path == 'commercial/subscriptions/4/events') {
      return eventsWait?.future ?? historyOverride ??
          {'data': [], 'current_page': 1, 'last_page': 1, 'total': 0};
    }
    return {'data': [], 'current_page': 1, 'last_page': 1, 'total': 0};
  }
}

Widget host(Widget view, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: view,
);
Future<void> confirm(WidgetTester t) async {
  await t.ensureVisible(find.byType(CheckboxListTile));
  await t.tap(find.byType(CheckboxListTile));
  await t.pumpAndSettle();
  await t.ensureVisible(find.byType(FilledButton));
  await t.tap(find.byType(FilledButton));
  await t.pumpAndSettle();
}

void main() {
  testWidgets('due renewal requires explicit cycle-bound confirmation and never auto posts',
      (t) async {
    final api = SubscriptionApi()..renewalCycle = 1;
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionDetailView(
      api: api, onExpired: () {}, id: 4,
    )));
    await t.pumpAndSettle();
    final button = find.byKey(const Key('subscription-renew-check'));
    await t.ensureVisible(button);
    await t.tap(button);
    await t.pumpAndSettle();
    expect(api.writes, 0);
    expect(find.byType(CircularProgressIndicator), findsNothing);
    await t.tap(find.text('Not now'));
    await t.pumpAndSettle();
    expect(api.writes, 0);
    await t.tap(button);
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('subscription-renew-confirm')));
    await t.pumpAndSettle();
    expect(api.writes, 1);
    expect(api.bodies.single, {'expected_cycle_number': 1});
    expect(api.calls.where((c) => c == 'POST commercial/subscriptions/4/renew').length, 1);
    expect(find.text('Benefits are not active'), findsOneWidget);
    await t.ensureVisible(find.text('Refresh'));
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(api.writes, 1);
  });

  testWidgets('logout while renewal consent is open prevents a write',
      (t) async {
    final api = SubscriptionApi()..renewalCycle = 1;
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionDetailView(
      api: api, onExpired: () {}, id: 4,
    )));
    await t.pumpAndSettle();
    final button = find.byKey(const Key('subscription-renew-check'));
    await t.ensureVisible(button);
    await t.tap(button);
    await t.pumpAndSettle();
    await api.forgetSession();
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('subscription-renew-confirm')));
    await t.pumpAndSettle();
    expect(api.writes, 0);
    expect(button, findsNothing);
    expect(find.text(subscriptionEn['privateCleared']!), findsOneWidget);
  });

  testWidgets('wrong renewal cycle clears stale controls without replay',
      (t) async {
    final api = SubscriptionApi()..renewalCycle = 1;
    api.writeReceiptOverride = {
      ...api.summary,
      'subscription': {...(api.summary['subscription'] as Map), 'cycle_number': 2},
    };
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionDetailView(
      api: api, onExpired: () {}, id: 4,
    )));
    await t.pumpAndSettle();
    final button = find.byKey(const Key('subscription-renew-check'));
    await t.ensureVisible(button);
    await t.tap(button);
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('subscription-renew-confirm')));
    await t.pumpAndSettle();
    expect(api.writes, 1);
    expect(button, findsNothing);
    expect(find.text('Benefits are not active'), findsNothing);
    await t.ensureVisible(find.text('Refresh'));
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(button, findsOneWidget);
    expect(api.writes, 1);
  });

  test('all lifecycle acknowledgements bind the original immutable event and key', () {
    final api = SubscriptionApi();
    addTearDown(api.close);
    for (final action in ['enroll', 'change_plan', 'cancel_at_period_end', 'resume', 'cancel_now']) {
      final planId = ['enroll', 'change_plan'].contains(action) ? 7 : null;
      final saved = <String, dynamic>{
        if (action != 'enroll') 'action': action,
        if (planId != null) 'plan_id': planId,
        'idempotency_key': 'original-intent',
      };
      final receipt = <String, dynamic>{
        'event_id': 19, 'actor_id': 1, 'subscription_id': 4,
        'action': action, 'plan_id': planId,
        'request_receipt': sha256.convert(utf8.encode(
          'subscription-operation-v1:1:4:19:$action:${planId ?? 0}:original-intent',
        )).toString(),
      };
      final summary = {...api.summary, 'operation_receipt': receipt};
      expect(subscriptionOperationAcknowledged(summary, saved, owner: 1, action: action), isTrue);
      for (final bad in [
        {'event_id': 20}, {'event_id': 19.0}, {'actor_id': 2},
        {'subscription_id': 5}, {'action': 'other'}, {'plan_id': 8},
        {'request_receipt': 'unbound'}, {'request_key': 'secret'},
        {'fingerprint': 'private'},
      ]) {
        expect(subscriptionOperationAcknowledged({
          ...summary, 'operation_receipt': {...receipt, ...bad},
        }, saved, owner: 1, action: action), isFalse);
      }
      expect(subscriptionOperationAcknowledged(api.summary, saved, owner: 1, action: action), isFalse);
      expect(subscriptionOperationAcknowledged(summary, {
        ...saved, 'idempotency_key': 'replacement',
      }, owner: 1, action: action), isFalse);
    }
  });

  testWidgets('unbound lifecycle response preserves the original key until exact event acknowledgement',
      (t) async {
    final api = SubscriptionApi()..operationReceiptOverride = {'action': 'resume'};
    final store = VendorOperationStore();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'cancel_at_period_end',
      subscriptionId: 4, store: store,
    )));
    await t.pumpAndSettle();
    await confirm(t);
    expect(api.writes, 1);
    expect(store.values.length, 1);
    expect(find.text(subscriptionEn['done']!), findsNothing);
    final original = Map<String, dynamic>.from(api.bodies.single);
    api.operationReceiptOverride = null;
    // Explicit same-intent retry may resolve the original event even if a
    // later action has changed the mutable subscription summary.
    await t.ensureVisible(find.byType(FilledButton));
    await t.tap(find.byType(FilledButton));
    await t.pumpAndSettle();
    expect(api.writes, 2);
    expect(api.bodies.last, original);
    expect(store.values, isEmpty);
    expect(find.text(subscriptionEn['done']!), findsOneWidget);
  });

  testWidgets('catalog freezes accepted private plan and entitlement metadata',
      (t) async {
    final api = SubscriptionApi();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionsView(api: api, onExpired: () {})));
    await t.pumpAndSettle();

    final dynamic state = t.state(find.byType(SubscriptionsView));
    final accepted = state.page.items.single as Map<String, dynamic>;
    final frozen = accepted['entitlements'] as Map;
    api.plan['name'] = 'Stale changed plan after accepted response';
    (api.plan['entitlements'] as Map)['priority'] = false;

    expect(accepted['name'], 'Published <literal> plan');
    expect(frozen['priority'], true);
    expect(() => accepted['id'] = 800, throwsUnsupportedError);
    expect(() => frozen['priority'] = false, throwsUnsupportedError);
    state.setState(() {});
    await t.pump();
    expect(find.text('Published <literal> plan'), findsOneWidget);
    expect(find.text('Stale changed plan after accepted response'), findsNothing);
    expect(api.writes, 0);
  });

  testWidgets('catalog rejects fabricated page, source IDs and unsafe total',
      (t) async {
    for (final poison in [1, 2, 3]) {
      final api = SubscriptionApi();
      addTearDown(api.close);
      if (poison == 1) api.forgedCurrentPage = 2;
      if (poison == 2) api.forgedPlanId = 9007199254740992;
      if (poison == 3) api.forgedTotal = 9007199254740992;
      await t.pumpWidget(
        host(SubscriptionsView(key: ValueKey(poison), api: api,
          onExpired: () {})),
      );
      await t.pumpAndSettle();

      expect(find.text('Published <literal> plan'), findsNothing);
      expect(find.text('Private subscription source cleared. Refresh after signing in again.'), findsNothing);
      expect(find.textContaining('private-subscription-diagnostic'), findsNothing);
      expect(api.writes, 0);
      await t.pumpWidget(const SizedBox());
    }
  });

  testWidgets('initially inactive native plans require a foreground user refresh',
    (t) async {
      final api = SubscriptionApi();
      addTearDown(api.close);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pumpWidget(host(SubscriptionsView(api: api, onExpired: () {})));
      await t.pumpAndSettle();
      expect(api.calls, isEmpty);
      expect(find.text(subscriptionEn['privateCleared']!), findsOneWidget);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(api.calls, isEmpty);
      await t.tap(find.text('Refresh'));
      await t.pumpAndSettle();
      expect(api.calls, ['GET me', 'GET commercial/plans']);
    },
  );

  testWidgets('already loaded native plan and invoice source is erased on logout signal',
      (t) async {
    for (final detail in [false, true]) {
      final api = SubscriptionApi();
      await t.pumpWidget(host(detail
          ? SubscriptionDetailView(api: api, onExpired: () {}, id: 4)
          : SubscriptionsView(api: api, onExpired: () {})));
      await t.pumpAndSettle();
      final label = detail ? 'Benefits are not active' : 'Published <literal> plan';
      expect(find.text(label), findsOneWidget);
      final before = api.calls.length;
      await api.forgetSession();
      await t.pump();
      expect(find.text(label), findsNothing);
      expect(find.text(subscriptionEn['privateCleared']!), findsOneWidget);
      expect(api.calls.length, before);
      await t.pumpWidget(const SizedBox());
      api.close();
    }
  });

  testWidgets('native enrollment consent and stored request keep the original frozen plan',
      (t) async {
    final api = SubscriptionApi();
    final store = VendorOperationStore();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'enroll',
      plan: api.plan, store: store,
    )));
    await t.pumpAndSettle();
    expect(find.text('Published <literal> plan'), findsOneWidget);
    expect(find.textContaining('1.250 KWD'), findsOneWidget);
    api.writeReceiptOverride = {
      ...api.summary,
      'plan': Map<String, dynamic>.from(api.plan),
    };

    // A caller can retain the original transport Map and mutate it after
    // a user sees its price. Never silently switch the selected plan ID.
    api.plan['id'] = 8;
    api.plan['name'] = 'Forged plan';
    api.plan['price_minor'] = 99999;
    final dynamic state = t.state(find.byType(SubscriptionActionView));
    state.setState(() {});
    await t.pump();
    expect(find.text('Published <literal> plan'), findsOneWidget);
    expect(find.text('Forged plan'), findsNothing);
    expect(find.textContaining('1.250 KWD'), findsOneWidget);

    await confirm(t);
    expect(api.bodies.single['plan_id'], 7);
    expect(store.values, isEmpty);
    expect(api.writes, 1);
  });

  testWidgets('wrong source plan receipt never acknowledges a durable native enrollment',
      (t) async {
    final api = SubscriptionApi();
    final store = VendorOperationStore();
    api.writeReceiptOverride = {
      ...api.summary,
      'plan': {...api.plan, 'id': 8},
    };
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'enroll',
      plan: api.plan, store: store,
    )));
    await t.pumpAndSettle();
    await confirm(t);
    expect(api.writes, 1);
    expect(api.bodies.single['plan_id'], 7);
    expect(store.values.length, 1);
    expect(find.text(subscriptionEn['done']!), findsNothing);
  });

  testWidgets('open subscription billing action clears consent and replay UI on logout',
      (t) async {
    final api = SubscriptionApi();
    final store = VendorOperationStore();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'enroll',
      plan: api.plan, store: store,
    )));
    await t.pumpAndSettle();
    expect(find.byType(CheckboxListTile), findsOneWidget);
    final before = api.calls.length;
    await api.forgetSession();
    await t.pump();
    expect(find.byType(CheckboxListTile), findsNothing);
    expect(find.byType(FilledButton), findsNothing);
    expect(find.text(subscriptionEn['privateCleared']!), findsOneWidget);
    expect(api.calls.length, before);
    expect(store.values, isEmpty);
  });

  testWidgets('old identity cannot fetch plans after logout during me', (t) async {
    final api = SubscriptionApi()..meWait = Completer<dynamic>();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionsView(api: api, onExpired: () {})));
    await t.pump();
    expect(api.calls, contains('GET me'));
    await api.forgetSession();
    api.meWait!.complete({'id': 1, 'role': 'customer'});
    await t.pumpAndSettle();
    expect(api.calls.where((call) => call == 'GET commercial/plans'), isEmpty);
    expect(find.text('Published <literal> plan'), findsNothing);
    expect(find.text(subscriptionEn['privateCleared']!), findsOneWidget);
  });

  testWidgets('catalog clears source on background and needs explicit refresh', (t) async {
    final api = SubscriptionApi();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionsView(api: api, onExpired: () {})));
    await t.pumpAndSettle();
    expect(find.text('Published <literal> plan'), findsOneWidget);
    final before = api.calls.length;
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    expect(find.text('Published <literal> plan'), findsNothing);
    expect(find.text(subscriptionEn['privateCleared']!), findsOneWidget);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pumpAndSettle();
    expect(api.calls.length, before);
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(api.calls.length, before + 2);
    expect(find.text('Published <literal> plan'), findsOneWidget);
  });

  testWidgets('private subscription event history retains immutable source evidence',
      (t) async {
    final api = SubscriptionApi();
    addTearDown(api.close);
    final source = <String, dynamic>{
      'data': [
        {
          'id': 27,
          'kind': 'enrolled',
          'created_at': '2026-10-28T12:00:00Z',
          'data': {'detail': {'reviewed': true}},
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
    api.historyOverride = source;
    await t.pumpWidget(host(SubscriptionDetailView(
      api: api, onExpired: () {}, id: 4,
    )));
    await t.pumpAndSettle();

    final dynamic state = t.state(find.byType(SubscriptionDetailView));
    final accepted = state.events.items.single as Map<String, dynamic>;
    final detail = (accepted['data'] as Map)['detail'] as Map;
    (source['data'] as List).first['kind'] = 'forged';
    (source['data'] as List).first['data']['detail']['reviewed'] = false;
    expect(accepted['kind'], 'enrolled');
    expect(detail['reviewed'], true);
    expect(() => accepted['kind'] = 'forged', throwsUnsupportedError);
    expect(() => detail['reviewed'] = false, throwsUnsupportedError);
    expect(api.writes, 0);
  });

  testWidgets('private history rejects fabricated page, event and count metadata',
      (t) async {
    final wrong = <Map<String, dynamic>>[
      {'data': [], 'current_page': 2, 'last_page': 2, 'total': 0},
      {
        'data': [
          {'id': 9007199254740992, 'kind': 'enrolled',
            'created_at': '2026-10-28T12:00:00Z'},
        ],
        'current_page': 1, 'last_page': 1, 'total': 1,
      },
      {
        'data': [], 'current_page': 1, 'last_page': 1,
        'total': 9007199254740992,
      },
    ];
    for (var i = 0; i < wrong.length; i++) {
      final api = SubscriptionApi()..historyOverride = wrong[i];
      addTearDown(api.close);
      await t.pumpWidget(host(SubscriptionDetailView(
        key: ValueKey(i), api: api, onExpired: () {}, id: 4,
      )));
      await t.pumpAndSettle();
      expect(find.text('Benefits are not active'), findsNothing);
      expect(find.textContaining('private-subscription-diagnostic'), findsNothing);
      expect(api.writes, 0);
      await t.pumpWidget(const SizedBox());
    }
  });

  testWidgets('revoked user cannot initiate invoice history read after late detail', (t) async {
    final api = SubscriptionApi()..detailWait = Completer<dynamic>();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionDetailView(
      api: api, onExpired: () {}, id: 4,
    )));
    await t.pump();
    await api.forgetSession();
    api.detailWait!.complete(api.summary);
    await t.pumpAndSettle();
    expect(api.calls.where((call) => call.endsWith('/events')), isEmpty);
    expect(find.text('Benefits are not active'), findsNothing);
    expect(find.text(subscriptionEn['privateCleared']!), findsOneWidget);
  });

  testWidgets('native subscription invoice and history vanish while inactive', (t) async {
    final api = SubscriptionApi();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionDetailView(
      api: api, onExpired: () {}, id: 4,
    )));
    await t.pumpAndSettle();
    expect(find.text('Benefits are not active'), findsOneWidget);
    final before = api.calls.length;
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    expect(find.text('Benefits are not active'), findsNothing);
    expect(find.textContaining('1.313 KWD'), findsNothing);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pumpAndSettle();
    expect(api.calls.length, before);
    await t.tap(find.text('Refresh'));
    await t.pumpAndSettle();
    expect(find.text('Benefits are not active'), findsOneWidget);
  });

  testWidgets('saved native subscription nonce remains private in unknown-outcome UI', (t) async {
    final api = SubscriptionApi()..lost = true;
    final store = VendorOperationStore();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'enroll',
      plan: api.plan, store: store,
    )));
    await t.pumpAndSettle();
    await confirm(t);
    await t.pumpAndSettle();
    expect(store.values.length, 1);
    expect(find.text(subscriptionEn['pendingIntent']!), findsOneWidget);
    expect(find.textContaining('idempotency_key'), findsNothing);
    expect(find.textContaining('private-subscription-diagnostic'), findsNothing);
  });

  testWidgets('late old-account enrollment response cannot confirm saved intent', (t) async {
    final api = SubscriptionApi()..postWait = Completer<dynamic>();
    final store = VendorOperationStore();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'enroll',
      plan: api.plan, store: store,
    )));
    await t.pumpAndSettle();
    await t.ensureVisible(find.byType(CheckboxListTile));
    await t.tap(find.byType(CheckboxListTile));
    await t.pumpAndSettle();
    await t.ensureVisible(find.byType(FilledButton));
    await t.tap(find.byType(FilledButton));
    await t.pump();
    expect(api.writes, 1);
    await api.forgetSession();
    api.postWait!.complete(api.summary);
    await t.pumpAndSettle();
    expect(find.text(subscriptionEn['done']!), findsNothing);
    expect(find.textContaining('idempotency_key'), findsNothing);
    expect(store.values.length, 1);
  });

  testWidgets('backgrounded subscription receipt must retain its exact durable request for review',
    (t) async {
      final api = SubscriptionApi()..postWait = Completer<dynamic>();
      final store = VendorOperationStore();
      addTearDown(api.close);
      await t.pumpWidget(host(SubscriptionActionView(
        api: api, onExpired: () {}, action: 'enroll',
        plan: api.plan, store: store,
      )));
      await t.pumpAndSettle();
      await t.ensureVisible(find.byType(CheckboxListTile));
      await t.tap(find.byType(CheckboxListTile));
      await t.pumpAndSettle();
      await t.ensureVisible(find.byType(FilledButton));
      await t.tap(find.byType(FilledButton));
      await t.pump();
      expect(api.writes, 1);
      expect(store.values.length, 1);
      final exact = Map<String, dynamic>.from(api.bodies.single);

      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pump();
      api.postWait!.complete(api.summary);
      await t.pumpAndSettle();
      expect(store.values.length, 1);
      expect(find.text(subscriptionEn['done']!), findsNothing);
      expect(find.textContaining('idempotency_key'), findsNothing);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.bodies.single, exact);
    },
  );

  testWidgets(
    'account change during secure persistence blocks enrollment even before the route disposes',
    (t) async {
      final api = SubscriptionApi(), store = PausedVendorStore();
      await t.pumpWidget(
        host(
          SubscriptionActionView(
            api: api,
            onExpired: () {},
            action: 'enroll',
            plan: api.plan,
            store: store,
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.ensureVisible(find.byType(CheckboxListTile));
      await t.tap(find.byType(CheckboxListTile));
      await t.pumpAndSettle();
      await t.ensureVisible(find.byType(FilledButton));
      await t.tap(find.byType(FilledButton));
      await t.pump();
      await store.entered.future;
      await api.forgetSession();
      store.release.complete();
      await t.pumpAndSettle();
      expect(api.writes, 0);
      expect(store.values.length, 1);
    },
  );

  testWidgets(
    'leaving enrollment during secure persistence cannot dispatch under a later session',
    (t) async {
      final api = SubscriptionApi(), store = PausedVendorStore();
      await t.pumpWidget(
        host(
          SubscriptionActionView(
            api: api,
            onExpired: () {},
            action: 'enroll',
            plan: api.plan,
            store: store,
          ),
        ),
      );
      await t.pumpAndSettle();
      await t.ensureVisible(find.byType(CheckboxListTile));
      await t.tap(find.byType(CheckboxListTile));
      await t.pumpAndSettle();
      await t.ensureVisible(find.byType(FilledButton));
      await t.tap(find.byType(FilledButton));
      await t.pump();
      await store.entered.future;
      await t.pumpWidget(const SizedBox());
      store.release.complete();
      await t.pumpAndSettle();
      expect(api.writes, 0);
      expect(store.values.length, 1);
    },
  );

  testWidgets(
    'a different plan cannot relabel or dispatch a saved unknown enrollment',
    (t) async {
      final api = SubscriptionApi()..lost = true,
          store = VendorOperationStore();
      await t.pumpWidget(
        host(
          SubscriptionActionView(
            api: api,
            onExpired: () {},
            action: 'enroll',
            plan: api.plan,
            store: store,
          ),
        ),
      );
      await t.pumpAndSettle();
      await confirm(t);
      await t.pumpWidget(const SizedBox());
      await t.pumpWidget(
        host(
          SubscriptionActionView(
            api: api,
            onExpired: () {},
            action: 'enroll',
            plan: {...api.plan, 'id': 8, 'name': 'Wrong new plan'},
            store: store,
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('Wrong new plan'), findsNothing);
      expect(
        t.widget<CheckboxListTile>(find.byType(CheckboxListTile)).onChanged,
        isNull,
      );
      expect(store.values.length, 1);
    },
  );

  test(
    'lifecycle controls respect unpaid, paid, scheduled and cancelled states',
    () {
      final now = DateTime.utc(2026, 10, 28);
      expect(
        subscriptionActions({
          'status': 'pending',
          'cancel_at_period_end': false,
        }, now: now),
        ['cancel_now'],
      );
      final active = {
        'status': 'active',
        'period_end': '2026-11-28T00:00:00Z',
        'cancel_at_period_end': false,
      };
      expect(subscriptionActions(active, now: now), [
        'change_plan',
        'cancel_at_period_end',
        'cancel_now',
      ]);
      expect(
        subscriptionActions({
          ...active,
          'cancel_at_period_end': true,
        }, now: now),
        ['resume', 'cancel_now'],
      );
      expect(subscriptionActions({'status': 'cancelled'}, now: now), isEmpty);
    },
  );

  testWidgets(
    'catalog uses exact authoritative precision and pagination without enrolling on read',
    (t) async {
      final api = SubscriptionApi();
      await t.pumpWidget(host(SubscriptionsView(api: api, onExpired: () {})));
      await t.pumpAndSettle();
      expect(find.textContaining('1.250 KWD'), findsOneWidget);
      expect(api.writes, 0);
      await t.ensureVisible(find.text('Next'));
      await t.tap(find.text('Next'));
      await t.pumpAndSettle();
      expect(api.queries.any((q) => q['page'] == '2'), true);
      expect(api.writes, 0);
    },
  );
  testWidgets(
    'unknown enrollment retains exact plan and key after process recreation',
    (t) async {
      final api = SubscriptionApi()..lost = true,
          store = VendorOperationStore();
      Widget screen() => host(
        SubscriptionActionView(
          api: api,
          onExpired: () {},
          action: 'enroll',
          plan: api.plan,
          store: store,
        ),
      );
      await t.pumpWidget(screen());
      await t.pumpAndSettle();
      expect(api.writes, 0);
      await confirm(t);
      expect(api.writes, 1);
      expect(store.values.length, 1);
      expect(
        find.textContaining('private-subscription-diagnostic'),
        findsNothing,
      );
      final first = api.bodies.single;
      await t.pumpWidget(const SizedBox());
      await t.pumpWidget(screen());
      await t.pumpAndSettle();
      expect(api.writes, 1);
      api.lost = false;
      await confirm(t);
      expect(api.bodies.last, first);
      expect(store.values, isEmpty);
    },
  );
  testWidgets(
    'foreign receipt never acknowledges a pending subscription operation',
    (t) async {
      final api = SubscriptionApi()..wrongOwner = true,
          store = VendorOperationStore();
      await t.pumpWidget(
        host(
          SubscriptionActionView(
            api: api,
            onExpired: () {},
            action: 'enroll',
            plan: api.plan,
            store: store,
          ),
        ),
      );
      await t.pumpAndSettle();
      await confirm(t);
      expect(store.values.length, 1);
      expect(find.text(subscriptionEn['done']!), findsNothing);
    },
  );
  testWidgets(
    'subscription details do not infer benefits from unpaid invoice or capture on load',
    (t) async {
      final api = SubscriptionApi();
      await t.pumpWidget(
        host(SubscriptionDetailView(api: api, onExpired: () {}, id: 4)),
      );
      await t.pumpAndSettle();
      expect(find.text('Benefits are not active'), findsOneWidget);
      expect(api.writes, 0);
      await t.scrollUntilVisible(
        find.text('No receipt'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.textContaining('1.313 KWD'), findsOneWidget);
      expect(api.writes, 0);
    },
  );
  testWidgets(
    'Arabic lifecycle confirmation fits narrow screen and requires explicit consent',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      final api = SubscriptionApi();
      await t.pumpWidget(
        host(
          SubscriptionActionView(
            api: api,
            onExpired: () {},
            action: 'cancel_at_period_end',
            subscriptionId: 4,
            store: VendorOperationStore(),
          ),
          ar: true,
        ),
      );
      await t.pumpAndSettle();
      expect(
        Directionality.of(t.element(find.byType(CheckboxListTile))),
        TextDirection.rtl,
      );
      expect(api.writes, 0);
      expect(t.takeException(), isNull);
      await confirm(t);
      expect(api.bodies.single['action'], 'cancel_at_period_end');
      expect(api.bodies.single.containsKey('idempotency_key'), true);
    },
  );
  testWidgets(
    'expired subscription access hides details without exposing diagnostics',
    (t) async {
      final api = SubscriptionApi()..expired = true;
      var expired = 0;
      await t.pumpWidget(
        host(
          SubscriptionDetailView(api: api, onExpired: () => expired++, id: 4),
        ),
      );
      await t.pumpAndSettle();
      expect(expired, 1);
      expect(
        find.textContaining('private-subscription-diagnostic'),
        findsNothing,
      );
      expect(api.writes, 0);
    },
  );
  test('private subscription detail refuses source identities beyond exact JSON integers',
      () {
    final api = SubscriptionApi();
    for (final broken in [
      () {
        final j = api.summary;
        (j['subscription'] as Map)['id'] = 9007199254740992;
        return j;
      }(),
      () {
        final j = api.summary;
        (j['subscription'] as Map)['id'] = 4.0;
        return j;
      }(),
      () {
        final j = api.summary;
        (j['invoices'] as List).first['id'] = 9007199254740992;
        return j;
      }(),
      () {
        final j = api.summary;
        (j['invoices'] as List).first['id'] = true;
        return j;
      }(),
    ]) {
      expect(() => subscriptionSummary(broken, 1, id: 4),
        throwsFormatException);
    }
    expect(() => subscriptionSummary(api.summary, 9007199254740992),
      throwsFormatException);
  });

  testWidgets('inexact subscription action IDs never dispatch a durable mutation',
      (t) async {
    final api = SubscriptionApi();
    addTearDown(api.close);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'enroll',
      plan: {...api.plan, 'id': 9007199254740992},
      store: VendorOperationStore(),
    )));
    await t.pumpAndSettle();
    expect(find.byType(FilledButton), findsOneWidget);
    expect(t.widget<FilledButton>(find.byType(FilledButton)).onPressed, isNull);
    expect(api.writes, 0);
    await t.pumpWidget(host(SubscriptionActionView(
      api: api, onExpired: () {}, action: 'cancel_now',
      subscriptionId: 9007199254740992,
      store: VendorOperationStore(),
    )));
    await t.pumpAndSettle();
    expect(t.widget<FilledButton>(find.byType(FilledButton)).onPressed, isNull);
    expect(api.writes, 0);
    expect(api.calls.where((x) => x.endsWith('/change')), isEmpty);
  });

  test('subscription summary refuses mismatched immutable plan source', () {
    final api = SubscriptionApi();
    final wrong = {
      ...api.summary,
      'plan': {...api.plan, 'id': 8},
    };
    expect(() => subscriptionSummary(wrong, 1, id: 4),
      throwsFormatException);
  });

  test('accepted private subscription invoice source is deep immutable', () {
    final raw = SubscriptionApi().summary;
    final report = subscriptionSummary(raw, 1, id: 4);
    (raw['invoices'] as List).first['total_minor'] = 42;
    (raw['subscription'] as Map)['user_id'] = 9;
    (raw['plan'] as Map)['name'] = 'Stale account changed plan';
    expect((report['invoices'] as List).first['total_minor'], 1313);
    expect((report['subscription'] as Map)['user_id'], 1);
    expect((report['plan'] as Map)['name'], 'Published <literal> plan');
    expect(
      () => (report['invoices'] as List).first['total_minor'] = 2,
      throwsUnsupportedError,
    );
    expect(
      () => (report['subscription'] as Map)['user_id'] = 4,
      throwsUnsupportedError,
    );
  });

  test(
    'summary rejects unbalanced or live evidence and mismatched invoice source',
    () {
      final api = SubscriptionApi();
      for (final data in [
        api.summary..['ledger_balanced'] = false,
        api.summary..['real_payment_processed'] = true,
        api.summary
          ..['invoices'] = [
            {'id': 1, 'subscription_id': 99, 'paid': true},
          ],
      ]) {
        expect(
          () => subscriptionSummary(data, 1, id: 4),
          throwsFormatException,
        );
      }
    },
  );
}
