import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_revenue_cohorts.dart';
import 'test_support.dart';

Map<String, dynamic> cohort({
  String from = '2026-11-01',
  String to = '2026-11-03',
  String currency = 'AED',
  int vendor = 1,
}) {
  final first = VendorRevenueCohortSnapshot.dateDay(from);
  final last = VendorRevenueCohortSnapshot.dateDay(to);
  final dates = <Map<String, dynamic>>[];
  for (var i = first; i <= last; i++) {
    final date = DateTime.fromMillisecondsSinceEpoch(
      i * Duration.millisecondsPerDay, isUtc: true,
    ).toIso8601String().substring(0, 10);
    final hasInvoice = i == first;
    dates.add({
      'date_utc': date,
      'invoice_count': hasInvoice ? 1 : 0,
      'invoiced_minor': hasInvoice ? 1099 : 0,
      'vendor_allocated_minor': hasInvoice ? 999 : 0,
      'commission_allocated_minor': hasInvoice ? 100 : 0,
      'paid_minor': hasInvoice ? 1099 : 0,
      'refunded_minor': hasInvoice ? 200 : 0,
      'vendor_refunded_minor': hasInvoice ? 200 : 0,
      'unpaid_credited_minor': 0,
      'vendor_credited_minor': 0,
      'net_collected_minor': hasInvoice ? 899 : 0,
      'net_vendor_allocated_minor': hasInvoice ? 799 : 0,
    });
  }
  return {
    'vendor_id': vendor,
    'currency': currency,
    'minor_units': 2,
    'from': from,
    'to': to,
    'basis': 'INVOICE_ISSUANCE_DATE_UTC_COHORT',
    'payment_and_refund_totals':
        'LIFETIME_OF_SELECTED_INVOICE_COHORT_AS_OF_QUERY',
    'evidence': 'SIMULATED_CANONICAL_FINANCE',
    'not_cashflow_or_payout_eligibility': true,
    'external_psp_or_bank_verified': false,
    'real_money_movement': false,
    'data': dates,
    'totals': {
      'invoice_count': 1,
      'invoiced_minor': 1099,
      'vendor_allocated_minor': 999,
      'commission_allocated_minor': 100,
      'paid_minor': 1099,
      'refunded_minor': 200,
      'vendor_refunded_minor': 200,
      'unpaid_credited_minor': 0,
      'vendor_credited_minor': 0,
      'net_collected_minor': 899,
      'net_vendor_allocated_minor': 799,
    },
  };
}

class RevenueApi extends FixtureApi {
  RevenueApi({super.role = 'vendor'});
  final queries = <Map<String, String>>[];
  dynamic malformed;
  Completer<dynamic>? stalled;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    expect(method, 'GET');
    if (expired) throw const ApiException(401, 'expired-private-provider-details');
    if (path == 'me') {
      return {'id': 1, 'role': role, 'name': 'Synthetic vendor'};
    }
    expect(path, 'commercial/vendors/1/revenue-cohorts');
    queries.add({...query});
    if (stalled != null) return stalled!.future;
    return malformed ?? cohort(
      from: query['from']!,
      to: query['to']!,
      currency: query['currency']!,
    );
  }
}

Future<void> open(
  WidgetTester tester,
  RevenueApi api, {
  bool arabic = false,
  VoidCallback? onExpired,
}) async {
  await tester.pumpWidget(MaterialApp(
    locale: Locale(arabic ? 'ar' : 'en'),
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    home: VendorRevenueCohortView(
      api: api, onExpired: onExpired ?? () {}, currency: 'AED',
    ),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('already painted vendor cohort income is instantly cleared on account logout',
      (tester) async {
    final api = RevenueApi();
    addTearDown(api.close);
    await open(tester, api);
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsOneWidget);
    final before = api.calls.length;
    await api.forgetSession();
    await tester.pump();
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsNothing);
    expect(find.textContaining('Issued: AED 10.99'), findsNothing);
    expect(api.calls.length, before);
  });

  test('valid issuance source is immutable and retains non-cashflow semantics', () {
    final raw = cohort();
    final c = VendorRevenueCohortSnapshot.parse(raw,
      vendorId: 1, currency: 'AED',
      from: '2026-11-01', to: '2026-11-03');
    expect(c.days.length, 3);
    expect(c.money('invoiced_minor'), 'AED 10.99');
    expect(c.money('net_vendor_allocated_minor'), 'AED 7.99');
    expect(c.source['not_cashflow_or_payout_eligibility'], true);
    expect(() => c.totals['paid_minor'] = 1, throwsUnsupportedError);
    raw['data'][0]['paid_minor'] = 2;
    raw['totals']['paid_minor'] = 2;
    expect(c.source['data'][0]['paid_minor'], 1099);
    expect(c.totals['paid_minor'], 1099);
    expect(() => c.source['data'][0]['paid_minor'] = 3,
        throwsUnsupportedError);
    expect(() => c.source['totals']['paid_minor'] = 3,
        throwsUnsupportedError);
  });

  test('rejects forged live evidence tenant periods totals and unsafe amounts', () {
    final corruptions = <void Function(Map<String, dynamic>)>[
      (c) => c['vendor_id'] = 2,
      (c) => c['currency'] = 'USD',
      (c) => c['evidence'] = 'BANK_VERIFIED',
      (c) => c['external_psp_or_bank_verified'] = true,
      (c) => c['real_money_movement'] = true,
      (c) => c['not_cashflow_or_payout_eligibility'] = false,
      (c) => c['basis'] = 'PAYMENT_DATE',
      (c) => c['payment_and_refund_totals'] = 'RECOGNIZED_REVENUE',
      (c) => c['data'][0]['invoiced_minor'] = 9007199254740992,
      (c) => c['data'][0]['paid_minor'] = 1.5,
      (c) => c['data'][1]['date_utc'] = '2026-11-01',
      (c) => c['data'][0]['net_collected_minor'] = 998,
      (c) => c['totals']['refunded_minor'] = 999,
      (c) => c['minor_units'] = 4,
    ];
    for (final corrupt in corruptions) {
      final data = cohort();
      corrupt(data);
      expect(
        () => VendorRevenueCohortSnapshot.parse(data,
          vendorId: 1, currency: 'AED',
          from: '2026-11-01', to: '2026-11-03'),
        throwsFormatException,
      );
    }
    expect(() => VendorRevenueCohortSnapshot.dateDay('2026-02-30'),
        throwsFormatException);
    expect(() => VendorRevenueCohortSnapshot.parse(
      cohort(from: '2026-11-01', to: '2026-11-03'),
      vendorId: 1, currency: 'AED',
      from: '2026-11-01', to: '2026-11-04'),
      throwsFormatException);
  });

  testWidgets('vendor sees exact bilingual source totals without payment mutation', (
    tester,
  ) async {
    final api = RevenueApi();
    await open(tester, api);
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsOneWidget);
    expect(
      tester.widget<Text>(
        find.byKey(const Key('revenue-cohort-total-issued')),
      ).data,
      'Issued: AED 10.99',
    );
    expect(api.queries.single['currency'], 'AED');
    expect(api.calls.where((c) => c.startsWith('POST ')), isEmpty);
    expect(find.textContaining('No bank or PSP verification'), findsOneWidget);
  });

  testWidgets('editing invalidates previously loaded private report before reload', (
    tester,
  ) async {
    final api = RevenueApi();
    await open(tester, api);
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsOneWidget);
    await tester.enterText(find.byKey(const Key('revenue-cohort-from')), '2026-02-30');
    await tester.pump();
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsNothing);
    final reads = api.queries.length;
    await tester.tap(find.byKey(const Key('revenue-cohort-refresh')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('revenue-cohort-error')), findsOneWidget);
    expect(api.queries.length, reads);
  });

  testWidgets('Arabic labels and wrong account access stay separated', (
    tester,
  ) async {
    final api = RevenueApi();
    await open(tester, api, arabic: true);
    expect(find.text('الإيرادات حسب تاريخ الفاتورة'), findsOneWidget);
    expect(find.byKey(const Key('revenue-cohort-total-vendor')), findsOneWidget);
    expect(api.calls.where((c) => c.startsWith('POST ')), isEmpty);
    final forbidden = RevenueApi(role: 'customer');
    await open(tester, forbidden);
    expect(forbidden.queries, isEmpty);
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsNothing);
    expect(find.byKey(const Key('revenue-cohort-error')), findsOneWidget);
  });

  testWidgets('session expiry clears private state and triggers logout callback', (
    tester,
  ) async {
    final api = RevenueApi();
    var expires = 0;
    await open(tester, api, onExpired: () => expires++);
    api.expired = true;
    await tester.tap(find.byKey(const Key('revenue-cohort-refresh')));
    await tester.pumpAndSettle();
    expect(expires, 1);
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsNothing);
  });

  testWidgets('late response after app background cannot restore old finance', (
    tester,
  ) async {
    final api = RevenueApi()..stalled = Completer<dynamic>();
    await tester.pumpWidget(MaterialApp(
      home: VendorRevenueCohortView(
        api: api, onExpired: () {}, currency: 'AED',
      ),
    ));
    await tester.pump();
    expect(api.queries, isNotEmpty);
    final oldQuery = api.queries.single;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    api.stalled!.complete(cohort(
      from: oldQuery['from']!, to: oldQuery['to']!,
      currency: oldQuery['currency']!,
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('revenue-cohort-total-issued')), findsNothing);
    expect(api.calls.where((c) => c.startsWith('POST ')), isEmpty);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
  });
}
