import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_finance.dart';
import 'package:creabiz_ev/vendor_earnings_csv.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';

Map<String, dynamic> snapshot({int vendor = 3, int page = 1}) => {
  'vendor_id': vendor,
  'currency': 'KWD',
  'minor_units': 3,
  'overall_available_minor': -5,
  'hold_days': 7,
  'overall_balance_scope': 'ALL_VENDOR_INVOICES_AND_APPROVED_ADJUSTMENTS',
  'evidence': 'SIMULATED_CANONICAL_SETTLEMENT',
  'real_payout_enabled': false,
  'external_psp_or_bank_verified': false,
  'current_page': page,
  'last_page': 2,
  'per_page': 1,
  'total': 2,
  'data': [
    {
      'invoice_id': page,
      'invoice_number': 'Owner $vendor invoice $page <literal>',
      'currency': 'KWD',
      'minor_units': 3,
      'evidence': 'PARTNER_SANDBOX_SOURCE',
      for (final field in VendorEarningsSnapshot.amounts.keys)
        field: field == 'available_minor' ? -5 : 1005,
    },
  ],
};

PrivateDocument pageCsv({int vendor = 3, int page = 1,
    bool changedAmount = false}) {
  final report = snapshot(vendor: vendor, page: page);
  final row = Map<String, dynamic>.from((report['data'] as List).single as Map);
  if (changedAmount) row['paid_minor'] = 9999;
  final body = <String>[
    VendorEarningsCsv.headers.join(','),
    VendorEarningsCsv.headers.map((field) => field == 'invoice_number'
        ? VendorEarningsCsv.literalNumber(row[field] as String)
        : '${row[field]}').join(','),
  ].join('\n') + '\n';
  return PrivateDocument(Uint8List.fromList(utf8.encode(body)), 'text/csv');
}

class EarningsApi extends FixtureApi {
  int stamp = 0, vendor = 3;
  String accountRole = 'vendor';
  bool unavailable = false;
  Completer<dynamic>? meWait, reportWait;
  Completer<PrivateDocument>? csvWait;
  bool invalidCsvAmount = false;
  final csvQueries = <Map<String, dynamic>>[];
  @override
  bool Function() sessionFence() {
    final n = stamp;
    return () => n == stamp;
  }

  @override
  Future<PrivateDocument> downloadVendorEarningsPage(
    int vendorId, {
    required String currency,
    required int page,
    int perPage = 20,
  }) async {
    csvQueries.add({
      'vendor_id': vendorId, 'currency': currency,
      'page': page, 'per_page': perPage,
    });
    if (csvWait != null) return csvWait!.future;
    return pageCsv(
      vendor: vendorId, page: page, changedAmount: invalidCsvAmount,
    );
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    queries.add(Map.of(query));
    if (path == 'finance/report')
      return {
        'real_money_movement': false,
        'groups': [
          {
            'currency': 'KWD',
            'minor_units': 3,
            'evidence': 'SIMULATED',
            for (final field in [
              'invoiced_minor',
              'paid_minor',
              'refunded_minor',
              'outstanding_minor',
              'net_vendor_allocated_minor',
              'net_commission_allocated_minor',
            ])
              field: 1005,
          },
        ],
      };
    if (path == 'me')
      return meWait == null
          ? {'id': vendor, 'role': accountRole}
          : meWait!.future;
    if (method != 'GET') throw StateError('Earnings must never mutate source.');
    if (unavailable) throw const ApiException(503, 'private server details');
    return reportWait == null
        ? snapshot(vendor: vendor, page: int.parse(query['page']!))
        : reportWait!.future;
  }
}

Widget app(EarningsApi api, {bool ar = false, VoidCallback? expired}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: ThemeData(brightness: ar ? Brightness.dark : Brightness.light),
      home: VendorEarningsView(
        api: api,
        currency: 'KWD',
        onExpired: expired ?? () {},
      ),
    );
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  testWidgets('already displayed owner income and CSV controls vanish on explicit account invalidation',
      (tester) async {
    final api = EarningsApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(api));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('vendor-earnings-overall')), findsOneWidget);
    expect(find.byKey(const Key('vendor-earnings-export')), findsOneWidget);
    final before = api.calls.length;
    await api.forgetSession();
    await tester.pump();
    expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
    expect(find.byKey(const Key('vendor-earnings-export')), findsNothing);
    expect(find.byKey(const Key('vendor-earnings-csv-preview')), findsNothing);
    expect(find.textContaining('Private records cleared'), findsOneWidget);
    expect(api.calls.length, before);
  });

  for (final ar in [false, true]) {
    testWidgets(
      'itemized source keeps signed precision, distinct global scope and pagination ${ar ? 'AR' : 'EN'}',
      (t) async {
        await t.binding.setSurfaceSize(const Size(390, 844));
        addTearDown(() => t.binding.setSurfaceSize(null));
        final api = EarningsApi();
        addTearDown(api.close);
        await t.pumpWidget(app(api, ar: ar));
        await t.pumpAndSettle();
        expect(find.text('-${ar ? '٠٫٠٠٥' : '0.005'} KWD'), findsOneWidget);
        expect(
          find.textContaining(
            ar ? 'ليس مجموع الصفحة' : 'not the total of the displayed page',
          ),
          findsOneWidget,
        );
        final first = find.byKey(const ValueKey('vendor-earnings-invoice-1'));
        await t.ensureVisible(first);
        await t.pumpAndSettle();
        expect(find.text('Owner 3 invoice 1 <literal>'), findsOneWidget);
        expect(find.textContaining('PARTNER_SANDBOX_SOURCE'), findsOneWidget);
        final next = find.byKey(const Key('vendor-earnings-next'));
        await t.scrollUntilVisible(next, 350);
        await t.tap(next);
        await t.pumpAndSettle();
        expect(api.calls, [
          'GET me',
          'GET commercial/vendors/3/earnings',
          'GET me',
          'GET commercial/vendors/3/earnings',
        ]);
        expect(api.queries.last, {
          'currency': 'KWD',
          'page': '2',
          'per_page': '20',
        });
        expect(find.text('Owner 3 invoice 1 <literal>'), findsNothing);
        expect(t.takeException(), isNull);
      },
    );
  }
  testWidgets('vendor invoice CSV is exact per-page copyable TEST data',
    (t) async {
      final api = EarningsApi();
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      final button = find.byKey(const Key('vendor-earnings-export'));
      await t.ensureVisible(button);
      await t.tap(button);
      await t.pumpAndSettle();
      expect(api.csvQueries.single, {
        'vendor_id': 3, 'currency': 'KWD',
        'page': 1, 'per_page': 1,
      });
      expect(find.byKey(const Key('vendor-earnings-csv-preview')),
          findsOneWidget);
      expect(find.textContaining('bank payment'), findsNothing);
      // Copyable CSV may grow a lazily built ListView: scroll its owner
      // back to the top before looking up the refresh button.
      await t.scrollUntilVisible(
        find.byKey(const Key('vendor-earnings-refresh')),
        -250,
        scrollable: find.byType(Scrollable).first,
      );
      await t.tap(find.byKey(const Key('vendor-earnings-refresh')));
      await t.pumpAndSettle();
      expect(find.byKey(const Key('vendor-earnings-csv-preview')),
          findsNothing);
    },
  );
  testWidgets('source-mismatched CSV can never appear in a native vendor view',
    (t) async {
      final api = EarningsApi()..invalidCsvAmount = true;
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      final button = find.byKey(const Key('vendor-earnings-export'));
      await t.ensureVisible(button);
      await t.tap(button);
      await t.pumpAndSettle();
      expect(find.byKey(const Key('vendor-earnings-csv-preview')),
          findsNothing);
      expect(find.byKey(const Key('vendor-earnings-error')),
          findsOneWidget);
      expect(find.textContaining('9999'), findsNothing);
    },
  );
  testWidgets('old-user CSV response cannot appear after session rotation',
    (t) async {
      final api = EarningsApi()..csvWait = Completer<PrivateDocument>();
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      final button = find.byKey(const Key('vendor-earnings-export'));
      await t.ensureVisible(button);
      await t.tap(button);
      await t.pump();
      expect(api.csvQueries.single['vendor_id'], 3);
      api.stamp++;
      api.vendor = 4;
      api.csvWait!.complete(pageCsv(vendor: 3));
      await t.pumpAndSettle();
      expect(find.byKey(const Key('vendor-earnings-csv-preview')),
          findsNothing);
      expect(find.byKey(const Key('vendor-earnings-overall')),
          findsNothing);
    },
  );

  testWidgets('background during CSV download discards private bytes and does not reload',
    (t) async {
      final api = EarningsApi()..csvWait = Completer<PrivateDocument>();
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      final button = find.byKey(const Key('vendor-earnings-export'));
      await t.ensureVisible(button);
      await t.tap(button);
      await t.pump();
      final calls = api.calls.length;
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pump();
      api.csvWait!.complete(pageCsv());
      await t.pumpAndSettle();
      expect(find.byKey(const Key('vendor-earnings-csv-preview')),
          findsNothing);
      expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(api.calls.length, calls);
    },
  );
  testWidgets(
    'background clears private data and returning requires explicit refresh',
    (t) async {
      final api = EarningsApi();
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      expect(find.byKey(const Key('vendor-earnings-overall')), findsOneWidget);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await t.pump();
      expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
      final before = api.calls.length;
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pumpAndSettle();
      expect(api.calls.length, before);
      await t.tap(find.byKey(const Key('vendor-earnings-refresh')));
      await t.pumpAndSettle();
      expect(api.calls.length, before + 2);
      expect(find.byKey(const Key('vendor-earnings-overall')), findsOneWidget);
    },
  );
  testWidgets(
    'account transition during me prevents a private request using the next account',
    (t) async {
      final api = EarningsApi()..meWait = Completer<dynamic>();
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pump();
      api.stamp++;
      api.meWait!.complete({'id': 3, 'role': 'vendor'});
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      expect(api.calls, ['GET me']);
      expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
    },
  );
  testWidgets(
    'late expired report cannot sign out or populate the next account; explicit refresh recovers',
    (t) async {
      var expired = 0;
      final api = EarningsApi()..reportWait = Completer<dynamic>();
      addTearDown(api.close);
      await t.pumpWidget(app(api, expired: () => expired++));
      await t.pump();
      api.stamp++;
      api.vendor = 4;
      api.reportWait!.completeError(const ApiException(401, 'old account'));
      await t.pumpWidget(app(api, expired: () => expired++));
      await t.pumpAndSettle();
      expect(expired, 0);
      expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
      api.reportWait = null;
      await t.tap(find.byKey(const Key('vendor-earnings-refresh')));
      await t.pumpAndSettle();
      expect(api.calls.last, 'GET commercial/vendors/4/earnings');
      expect(find.byKey(const Key('vendor-earnings-overall')), findsOneWidget);
    },
  );
  testWidgets('customer role never issues a vendor earnings request', (
    t,
  ) async {
    final api = EarningsApi()..accountRole = 'customer';
    addTearDown(api.close);
    await t.pumpWidget(app(api));
    await t.pumpAndSettle();
    expect(api.calls, ['GET me']);
    expect(find.byKey(const Key('vendor-earnings-error')), findsOneWidget);
  });
  testWidgets(
    'failed refresh clears old amounts and never displays private transport errors',
    (t) async {
      final api = EarningsApi();
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      api.unavailable = true;
      await t.tap(find.byKey(const Key('vendor-earnings-refresh')));
      await t.pumpAndSettle();
      expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
      expect(find.textContaining('private server details'), findsNothing);
      expect(find.byKey(const Key('vendor-earnings-error')), findsOneWidget);
    },
  );
  testWidgets(
    'vendor overview navigates to owned itemized source instead of constructing settlement math',
    (t) async {
      final api = EarningsApi();
      addTearDown(api.close);
      await t.pumpWidget(
        MaterialApp(
          home: VendorFinanceView(api: api, onExpired: () {}),
        ),
      );
      await t.pumpAndSettle();
      final link = find.byKey(const ValueKey('vendor-itemized-KWD'));
      await t.ensureVisible(link);
      await t.tap(link);
      await t.pumpAndSettle();
      expect(find.byType(VendorEarningsView), findsOneWidget);
      expect(api.calls.last, 'GET commercial/vendors/3/earnings');
    },
  );
  testWidgets(
    'late successful private report cannot populate the next account',
    (t) async {
      final api = EarningsApi()..reportWait = Completer<dynamic>();
      addTearDown(api.close);
      await t.pumpWidget(app(api));
      await t.pump();
      api.stamp++;
      api.vendor = 4;
      api.reportWait!.complete(snapshot(vendor: 3));
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
      expect(find.textContaining('Owner 3 invoice'), findsNothing);
    },
  );
  testWidgets('current expired report expires only its current account', (
    t,
  ) async {
    var expired = 0;
    final api = EarningsApi()..reportWait = Completer<dynamic>();
    addTearDown(api.close);
    await t.pumpWidget(app(api, expired: () => expired++));
    await t.pump();
    api.reportWait!.completeError(
      const ApiException(401, 'private expired details'),
    );
    await t.pumpAndSettle();
    expect(expired, 1);
    expect(find.byKey(const Key('vendor-earnings-overall')), findsNothing);
  });
  test('signed refund-debt reservation remains exact in native JSON and CSV', () {
    final raw = snapshot();
    final row = (raw['data'] as List).single as Map<String, dynamic>;
    row['pending_reserved_minor'] = -450;
    row['completed_settlement_minor'] = 900;
    row['available_minor'] = 0;
    raw['overall_available_minor'] = 0;
    final report = VendorEarningsSnapshot.parse(
      raw, vendorId: 3, currency: 'KWD', page: 1,
    );
    expect(report.rows.single['pending_reserved_minor'], -450);
    expect(report.rows.single['completed_settlement_minor'], 900);
    PrivateDocument document(Map<String, dynamic> values) {
      final text = [
        VendorEarningsCsv.headers.join(','),
        VendorEarningsCsv.headers.map((field) => field == 'invoice_number'
            ? VendorEarningsCsv.literalNumber(values[field] as String)
            : '${values[field]}').join(','),
      ].join('\n') + '\n';
      return PrivateDocument(Uint8List.fromList(utf8.encode(text)), 'text/csv');
    }

    String verify(PrivateDocument csv) => VendorEarningsCsv.verifiedText(
      csv, vendorId: 3, currency: 'KWD', page: 1,
      source: report.source, rows: report.rows,
    );
    final accepted = verify(document(row));
    final cells = VendorEarningsCsv.parseBounded(accepted)[1];
    expect(cells[VendorEarningsCsv.headers.indexOf('pending_reserved_minor')],
        '-450');
    row['pending_reserved_minor'] = 450;
    expect(() => verify(document(row)), throwsFormatException);
    expect(report.rows.single['pending_reserved_minor'], -450);
  });
  final corruptions = <String, void Function(Map<String, dynamic>)>{
    'foreign vendor': (r) => r['vendor_id'] = 4,
    'wrong currency': (r) => r['currency'] = 'AED',
    'real payout claim': (r) => r['real_payout_enabled'] = true,
    'bank verification claim': (r) => r['external_psp_or_bank_verified'] = true,
    'forged overall scope': (r) => r['overall_balance_scope'] = 'PAGE_TOTAL',
    'fractional amount': (r) => r['data'][0]['paid_minor'] = 1.005,
    'unsupported precision': (r) => r['data'][0]['minor_units'] = 4,
    'inconsistent row count': (r) => r['data'] = [],
    'wrong page': (r) => r['current_page'] = 2,
    'unsafe money integer': (r) =>
        r['overall_available_minor'] = 9007199254740992,
  };
  for (final entry in corruptions.entries) {
    test('reject ${entry.key}', () {
      final data = jsonDecode(jsonEncode(snapshot())) as Map<String, dynamic>;
      entry.value(data);
      expect(
        () => VendorEarningsSnapshot.parse(
          data,
          vendorId: 3,
          currency: 'KWD',
          page: 1,
        ),
        throwsFormatException,
      );
    });
  }
  test(
    'snapshot preserves immutable original evidence and signed availability without recomputing money',
    () {
      final raw = snapshot();
      final report = VendorEarningsSnapshot.parse(
        raw,
        vendorId: 3,
        currency: 'KWD',
        page: 1,
      );
      (raw['data'] as List).first['paid_minor'] = 42;
      expect(report.source['data'][0]['paid_minor'], 1005);
      expect(report.rows.first['paid_minor'], 1005);
      expect(
        () => report.source['data'][0]['paid_minor'] = 42,
        throwsUnsupportedError,
      );
      expect(report.source['overall_available_minor'], -5);
      expect(report.rows.single['evidence'], 'PARTNER_SANDBOX_SOURCE');
      expect(report.rows.single['available_minor'], -5);
    },
  );
}
