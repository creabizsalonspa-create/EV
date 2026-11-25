import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/finance_approval_queue.dart';
import 'test_support.dart';

Map<String, dynamic> row(int id) => {
  'kind': 'adjustment', 'record_id': id, 'vendor_id': 9,
  'invoice_id': null, 'currency': 'KWD', 'minor_units': 3,
  'amount_minor': -1250, 'status': 'awaiting_approval',
  'created_at': '2026-11-24T00:00:00Z',
};

Map<String, dynamic> page({int number = 1, int total = 1}) {
  final first = (number - 1) * 20;
  return {
    'admin_only': true, 'read_only': true, 'mutations_performed': false,
    'real_money_moved': false, 'not_a_payment_or_payout_authority': true,
    'evidence': 'SIMULATED_FINANCIAL_APPROVAL_REVIEW',
    'data': [for (var i = first; i < total && i < first + 20; i++) row(i + 1)],
    'current_page': number, 'last_page': total == 0 ? 1 : ((total - 1) ~/ 20) + 1,
    'total': total, 'per_page': 20,
    'summary': {
      'scope': 'ALL_CURRENT_FILTERED_PENDING_RECORDS_NOT_PAGE',
      'currency_and_precision_isolated': true,
      'not_a_payment_or_payout_authority': true,
      'groups': [
        if (total > 0) {
          'kind': 'adjustment', 'currency': 'KWD', 'minor_units': 3,
          'review_count': total, 'signed_amount_minor': -1250 * total,
          'basis': 'PENDING_RECORDS_NOT_FINANCIAL_BALANCE',
        },
      ],
    },
  };
}

class ReviewApi extends FixtureApi {
  ReviewApi({super.role = 'admin'});
  final events = StreamController<int>.broadcast(sync: true);
  bool current = true;
  int total = 1;
  Completer<dynamic>? pending;
  @override
  Stream<int> get sessionEvents => events.stream;
  @override
  bool Function() sessionFence() => () => current;
  void revoke() {
    current = false;
    events.add(1);
  }
  @override
  void close() {
    events.close();
    super.close();
  }
  @override
  Future<dynamic> request(String path, {
    String method = 'GET', Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(body, isNull);
    calls.add(path);
    if (path == 'me') return {'id': 1, 'role': role};
    expect(path, 'finance/approval-queue');
    queries.add(Map.of(query));
    return pending == null ? page(number: int.parse(query['page']!), total: total) : pending!.future;
  }
}

Future<void> open(WidgetTester t, ReviewApi api, {bool ar = false}) async {
  await t.pumpWidget(MaterialApp(
    locale: Locale(ar ? 'ar' : 'en'),
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: ThemeData(brightness: ar ? Brightness.dark : Brightness.light),
    home: FinanceApprovalQueueView(api: api, onExpired: () {}),
  ));
  await t.pumpAndSettle();
}

void main() {
  const query = {'page': '1', 'per_page': '20', 'kind': 'all'};
  test('exact private review parses negative three-decimal source without mutable maps', () {
    final raw = page();
    final accepted = FinanceReviewPage.parse(raw, query);
    raw['data'][0]['amount_minor'] = 2;
    expect(accepted.rows.single.amountLabel, 'KWD -1.250');
    expect(() => accepted.rows.clear(), throwsUnsupportedError);
    expect(accepted.total, 1);
    expect(accepted.groups.single.amountLabel, 'KWD -1.250');
    expect(() => accepted.groups.clear(), throwsUnsupportedError);
    final missingPrecision = row(1)..['minor_units'] = null;
    expect(FinanceReviewRow.parse(missingPrecision).precision, isNull);
  });
  test('forged pages and source authority, unsafe numbers and private fields fail closed', () {
    for (final change in <void Function(Map<String, dynamic>)>[
      (r) => r['real_money_moved'] = true,
      (r) => r['admin_only'] = false,
      (r) => r['current_page'] = 2,
      (r) => r['last_page'] = 2,
      (r) => r['per_page'] = 25,
      (r) => r['total'] = 2,
      (r) => r['data'][0]['amount_minor'] = 1.5,
      (r) => r['data'][0]['record_id'] = 9007199254740992,
      (r) => r['data'][0]['request_key'] = 'private-request',
      (r) => r['data'][0]['invoice_id'] = 7,
      (r) => r['data'][0]['minor_units'] = 4,
      (r) => r['data'][0]['currency'] = 'AED ',
    ]) {
      final raw = page();
      change(raw);
      expect(() => FinanceReviewPage.parse(raw, query), throwsFormatException);
    }
    expect(() => FinanceReviewPage.parse(page(), {...query, 'vendor_id': '10'}), throwsFormatException);
    expect(() => FinanceReviewPage.parse(page(), {...query, 'kind': 'refund'}), throwsFormatException);
  });
  test('all filtered summary is independently scoped and never a page balance', () {
    final raw = page(number: 2, total: 21);
    final accepted = FinanceReviewPage.parse(raw, {...query, 'page': '2'});
    expect(accepted.rows.length, 1);
    expect(accepted.groups.single.count, 21);
    expect(accepted.groups.single.amountLabel, 'KWD -26.250');
    for (final change in <void Function(Map<String, dynamic>)>[
      (r) => r['summary']['scope'] = 'CURRENT_PAGE',
      (r) => r['summary']['currency_and_precision_isolated'] = false,
      (r) => r['summary']['groups'][0]['review_count'] = 1,
      (r) => r['summary']['groups'][0]['currency'] = 'AED',
      (r) => r['summary']['groups'][0]['signed_amount_minor'] = 9007199254740992,
      (r) => r['summary']['groups'][0]['minor_units'] = 4,
      (r) => r['summary']['groups'].add(Map<String, Object>.from(r['summary']['groups'][0] as Map)),
    ]) {
      final invalid = page(number: 2, total: 21);
      change(invalid);
      expect(() => FinanceReviewPage.parse(invalid, {...query, 'page': '2'}), throwsFormatException);
    }
    final legacy = page();
    legacy['data'][0]['minor_units'] = null;
    expect(FinanceReviewPage.parse(legacy, query).groups, isEmpty);
  });
  testWidgets('Admin can review whole filtered scope without financial action authority', (t) async {
    final api = ReviewApi()..total = 21;
    addTearDown(api.close);
    await open(t, api);
    final tile = find.text('Filtered pending records by scope');
    await t.ensureVisible(tile);
    await t.tap(tile);
    await t.pumpAndSettle();
    expect(find.text('Not a balance or payout amount'), findsOneWidget);
    await t.ensureVisible(find.text('KWD -26.250'));
    expect(find.text('KWD -26.250'), findsOneWidget);
    expect(find.text('Records: 21'), findsOneWidget);
    expect(api.calls, ['me', 'finance/approval-queue']);
  });
  testWidgets('Admin sees signed money in Arabic dark mode and no action authority', (t) async {
    final api = ReviewApi();
    addTearDown(api.close);
    await open(t, api, ar: true);
    await t.scrollUntilVisible(find.text('KWD -1.250'), 150,
      scrollable: find.byType(Scrollable).first);
    expect(find.text('KWD -1.250'), findsOneWidget);
    expect(find.text('المراجعات المالية المعلقة'), findsOneWidget);
    expect(Directionality.of(t.element(find.text('المراجعات المالية المعلقة'))), TextDirection.rtl);
    expect(api.calls, ['me', 'finance/approval-queue']);
    expect(find.text('Approve'), findsNothing);
    expect(t.takeException(), isNull);
  });
  testWidgets('pagination reuses exact applied vendor and currency filter', (t) async {
    final api = ReviewApi()..total = 21;
    addTearDown(api.close);
    await open(t, api);
    await t.enterText(find.byKey(const Key('finance-review-currency')), 'KWD');
    await t.enterText(find.byKey(const Key('finance-review-vendor')), '9');
    FocusManager.instance.primaryFocus?.unfocus();
    await t.pumpAndSettle();
    await t.ensureVisible(find.byKey(const Key('finance-review-refresh')));
    await t.tap(find.byKey(const Key('finance-review-refresh')));
    await t.pumpAndSettle();
    expect(api.queries.last, {
      'kind': 'all', 'page': '1', 'per_page': '20',
      'currency': 'KWD', 'vendor_id': '9',
    });
    await t.scrollUntilVisible(find.byKey(const Key('finance-review-next')), 500,
      scrollable: find.byType(Scrollable).first, maxScrolls: 50);
    final next = find.byKey(const Key('finance-review-next'));
    // scrollUntilVisible may stop as soon as a lazily built button exists,
    // even if its center is still beyond the viewport. Settle and expose
    // the actual hit target before dispatching pagination.
    await t.pumpAndSettle();
    await t.ensureVisible(next);
    await t.pumpAndSettle();
    expect(next.hitTestable(), findsOneWidget);
    expect(t.widget<OutlinedButton>(next).onPressed, isNotNull);
    await t.tap(next);
    await t.pumpAndSettle();
    expect(api.queries.last, {
      'kind': 'all', 'page': '2', 'per_page': '20',
      'currency': 'KWD', 'vendor_id': '9',
    });
    await t.scrollUntilVisible(find.text('Adjustment #21'), 150,
      scrollable: find.byType(Scrollable).first);
    expect(find.text('Adjustment #21').hitTestable(), findsOneWidget);
    expect(api.queries.length, 3);
  });
  testWidgets('non-Admin cannot dispatch review queue read', (t) async {
    final api = ReviewApi(role: 'vendor');
    addTearDown(api.close);
    await open(t, api);
    expect(api.calls, ['me']);
    expect(find.text('KWD -1.250'), findsNothing);
  });
  testWidgets('session event clears displayed source and drops late read', (t) async {
    final api = ReviewApi();
    addTearDown(api.close);
    await open(t, api);
    await t.scrollUntilVisible(find.text('KWD -1.250'), 150,
      scrollable: find.byType(Scrollable).first);
    expect(find.text('KWD -1.250'), findsOneWidget);
    await t.scrollUntilVisible(find.byKey(const Key('finance-review-refresh')), -150,
      scrollable: find.byType(Scrollable).first);
    api.pending = Completer<dynamic>();
    await t.tap(find.byKey(const Key('finance-review-refresh')));
    await t.pump();
    api.revoke();
    api.pending!.complete(page());
    await t.pumpAndSettle();
    expect(find.text('KWD -1.250'), findsNothing);
    expect(find.byKey(const Key('finance-review-refresh')), findsNothing);
  });
  testWidgets('background clears queue and requires explicit new read', (t) async {
    final api = ReviewApi();
    addTearDown(api.close);
    await open(t, api);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pump();
    expect(api.calls.length, 2);
    expect(find.text('KWD -1.250'), findsNothing);
    await t.tap(find.byKey(const Key('finance-review-refresh')));
    await t.pumpAndSettle();
    expect(api.calls.length, 4);
  });
}
