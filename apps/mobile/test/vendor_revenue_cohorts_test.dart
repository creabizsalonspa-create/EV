import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
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
  bool tamperCsv = false;
  bool expireCsv = false;
  Completer<PrivateDocument>? pendingCsv;
  Completer<void>? expiryDelay;
  @override
  Future<PrivateDocument> downloadVendorRevenueCohort(
    int vendorId, {required String currency, required String from, required String to}
  ) async {
    expect(vendorId, 1);
    expect(queries.last, {'currency':currency,'from':from,'to':to});
    if (expireCsv) {
      await forgetSession();
      final invalidated = sessionFence();
      if (expiryDelay != null) await expiryDelay!.future;
      throw ApiException(401, 'Expired export', expirationIsCurrent: invalidated);
    }
    if (pendingCsv != null) return pendingCsv!.future;
    final data = cohort(from:from,to:to,currency:currency);
    if (tamperCsv) data['data'][0]['paid_minor'] = 1;
    return cohortDocument(data);
  }
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
    if (expired) {
      await forgetSession();
      final invalidated = sessionFence();
      throw ApiException(401, 'expired-private-provider-details',
        expirationIsCurrent: invalidated);
    }
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

PrivateDocument cohortDocument(Map<String,dynamic> source) {
  final headers = VendorRevenueCohortCsv.headers;
  final text = [
    headers.join(','),
    for (final row in source['data'])
      headers.map((field) => '${row[field]}').join(','),
  ].join('\n');
  return PrivateDocument(Uint8List.fromList(utf8.encode(text)), 'text/csv');
}

void main() {
  test('accepts exact optional UTC cutoff without fabricating old-source metadata', () {
    final raw = cohort();
    VendorRevenueCohortSnapshot parse() => VendorRevenueCohortSnapshot.parse(
      raw, vendorId: 1, currency: 'AED', from: '2026-11-01', to: '2026-11-03',
    );
    expect(parse().source.containsKey('as_of'), false);
    raw['as_of'] = '2026-11-03T09:10:11+00:00';
    expect(parse().source['as_of'], raw['as_of']);
    for (final invalid in [
      null, '2026-02-30T00:00:00Z', '2026-11-03T13:10:11+04:00',
      '2026-11-03T09:10:11', '2026-11-03', 123,
    ]) {
      raw['as_of'] = invalid;
      expect(parse, throwsFormatException);
    }
  });

  testWidgets('expanded vendor report exposes all canonical amounts and daily net collection', (tester) async {
    final api = RevenueApi();
    addTearDown(api.close);
    tester.view.physicalSize = const Size(1000, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await open(tester, api);
    final total = find.byKey(const Key('revenue-cohort-total-breakdown'));
    await tester.ensureVisible(total);
    await tester.tap(total);
    await tester.pumpAndSettle();
    for (final field in VendorRevenueCohortCsv.headers.skip(1)) {
      expect(find.byKey(ValueKey('revenue-breakdown-total-$field')), findsOneWidget);
    }
    ListTile tile(String scope, String field) => tester.widget<ListTile>(
      find.byKey(ValueKey('revenue-breakdown-$scope-$field')),
    );
    expect((tile('total', 'net_collected_minor').subtitle as Text).data, 'AED 8.99');
    expect((tile('total', 'commission_allocated_minor').subtitle as Text).data, 'AED 1.00');
    expect((tile('total', 'vendor_refunded_minor').subtitle as Text).data, 'AED 2.00');
    await tester.tap(total);
    await tester.pumpAndSettle();
    final day = api.queries.single['from']!;
    final daily = find.byKey(ValueKey('revenue-cohort-breakdown-$day'));
    await tester.scrollUntilVisible(daily, 300, scrollable: find.byType(Scrollable).first);
    await tester.tap(daily);
    await tester.pumpAndSettle();
    expect((tile(day, 'net_collected_minor').subtitle as Text).data, 'AED 8.99');
    expect((tile(day, 'vendor_credited_minor').subtitle as Text).data, 'AED 0.00');
    expect(api.queries.length, 1);
    expect(api.calls.where((c) => c.startsWith('POST ')), isEmpty);
    await api.forgetSession();
    await tester.pump();
    expect(find.byKey(ValueKey('revenue-breakdown-$day-net_collected_minor')), findsNothing);
    expect(total, findsNothing);
  });

  test('verified cohort CSV accepts 93 days but rejects altered cells and MIME', () {
    final raw = cohort(from:'2026-08-01',to:'2026-11-01');
    final snapshot = VendorRevenueCohortSnapshot.parse(raw,
      vendorId:1,currency:'AED',from:'2026-08-01',to:'2026-11-01');
    expect(snapshot.days.length,93);
    final document = cohortDocument(raw);
    expect(VendorRevenueCohortCsv.verifiedText(document,snapshot), contains('2026-11-01'));
    raw['data'][0]['paid_minor'] = 1;
    expect(() => VendorRevenueCohortCsv.verifiedText(cohortDocument(raw),snapshot), throwsFormatException);
    expect(() => VendorRevenueCohortCsv.verifiedText(
      PrivateDocument(document.bytes,'application/pdf'),snapshot), throwsFormatException);
  });

  testWidgets('verified cohort CSV is cleared on filter edit', (tester) async {
    final api = RevenueApi();
    await open(tester,api);
    final button = find.byKey(const Key('revenue-cohort-export'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('revenue-cohort-csv')),findsOneWidget);
    final from = find.byKey(const Key('revenue-cohort-from'));
    // The private CSV extends the lazy ListView, so the input may not be
    // built until we scroll back. Exercise the real scroll interaction.
    await tester.scrollUntilVisible(from, -250,
      scrollable: find.descendant(of: find.byType(ListView),
        matching: find.byType(Scrollable)).first);
    await tester.enterText(from,'2026-11-01');
    await tester.pump();
    expect(find.byKey(const Key('revenue-cohort-csv')),findsNothing);
    expect(button,findsNothing);
  });

  testWidgets('mismatching cohort CSV is never displayed', (tester) async {
    final api = RevenueApi()..tamperCsv = true;
    await open(tester,api);
    final button = find.byKey(const Key('revenue-cohort-export'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('revenue-cohort-csv')),findsNothing);
    expect(find.byKey(const Key('revenue-cohort-error')),findsOneWidget);
    expect(find.byKey(const Key('revenue-cohort-total-issued')),findsNothing);
    expect(button,findsNothing);
  });

  test('self-consistent totals cannot conceal an economically impossible day', () {
    for (final changed in <Map<String,int>>[
      {'paid_minor': 1100, 'net_collected_minor': 900},
      {'refunded_minor': 1100, 'net_collected_minor': -1},
      {'unpaid_credited_minor': 1},
      {'vendor_refunded_minor': 201, 'net_vendor_allocated_minor': 798},
      {'invoice_count': 0},
    ]) {
      final raw = cohort();
      (raw['data'][0] as Map).addAll(changed);
      (raw['totals'] as Map).addAll(changed);
      expect(() => VendorRevenueCohortSnapshot.parse(raw,
        vendorId:1,currency:'AED',from:'2026-11-01',to:'2026-11-03'),
        throwsFormatException);
    }
  });

  testWidgets('late CSV cannot restore a backgrounded revenue report', (tester) async {
    final api = RevenueApi()..pendingCsv = Completer<PrivateDocument>();
    addTearDown(api.close);
    await open(tester,api);
    final button = find.byKey(const Key('revenue-cohort-export'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pump();
    final q = api.queries.last;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    api.pendingCsv!.complete(cohortDocument(cohort(from:q['from']!,to:q['to']!)));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('revenue-cohort-csv')),findsNothing);
    expect(button,findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
  });

  testWidgets('current CSV expiry signals logout after session invalidation', (tester) async {
    final api = RevenueApi()..expireCsv = true;
    addTearDown(api.close);
    var expires = 0;
    await open(tester,api,onExpired:()=>expires++);
    final button = find.byKey(const Key('revenue-cohort-export'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(expires,1);
    expect(find.byKey(const Key('revenue-cohort-csv')),findsNothing);
    expect(button,findsNothing);
  });

  testWidgets('superseded CSV expiry cannot sign out the replacement session', (tester) async {
    final api = RevenueApi()..expireCsv = true..expiryDelay = Completer<void>();
    addTearDown(api.close);
    var expires = 0;
    await open(tester,api,onExpired:()=>expires++);
    final button = find.byKey(const Key('revenue-cohort-export'));
    await tester.ensureVisible(button);
    await tester.tap(button);
    await tester.pump();
    await api.forgetSession();
    api.expiryDelay!.complete();
    await tester.pumpAndSettle();
    expect(expires,0);
    expect(find.byKey(const Key('revenue-cohort-csv')),findsNothing);
  });

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
