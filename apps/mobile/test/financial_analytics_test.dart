import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_analytics.dart';
import 'test_support.dart';

Map<String, dynamic> fixture([Map<String, String> q = const {}]) => {
  'scope': 'own_charging_and_own_billing',
  'real_money_verified': false,
  'period': {
    'from': q['from'] ?? '2026-10-01',
    'to': q['to'] ?? '2026-10-30',
    'previous_from': '2026-09-01',
    'previous_to': '2026-09-30',
    'timezone': 'UTC',
  },
  'as_of': '2026-10-30T00:00:00Z',
  'category_labels': {
    'charging_collected': ['Charging receipts posted', 'مقبوضات الشحن'],
  },
  'state_labels': {
    'posted': ['Posted', 'مسجل'],
  },
  'groups': q['currency'] == 'USD'
      ? <dynamic>[]
      : [
          {
            'period': 'current',
            'category': 'charging_collected',
            'currency': 'KWD',
            'minor_units': 3,
            'state': 'posted',
            'evidence': 'MOCK_PROVIDER',
            'record_count': 1,
            'amount_minor': 1234,
          },
        ],
  'points': {
    'unit': 'NON_MONETARY_POINTS',
    'cash_value': false,
    'groups': [
      {'period': 'current', 'kind': 'reversal', 'entries': 1, 'points': -10},
    ],
  },
  'recurring_run_rates': <dynamic>[],
};

class AnalyticsApi extends FixtureApi {
  bool deny = false;
  dynamic malformed;
  Completer<dynamic>? response;
  final pdfQueries = <Map<String, String>>[];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(path, 'financial/analytics');
    calls.add('$method $path');
    queries.add({...query});
    if (deny) throw const ApiException(401, 'secret-source-diagnostic');
    return response == null ? (malformed ?? fixture(query)) : response!.future;
  }

  @override
  Future<PrivateDocument> downloadFinancialAnalytics(
    Map<String, String> query,
  ) async {
    pdfQueries.add({...query});
    throw const ApiException(503, 'secret-provider-diagnostic');
  }
}

Future<void> open(
  WidgetTester tester,
  AnalyticsApi api, {
  bool ar = false,
  VoidCallback? expired,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: ThemeData(brightness: ar ? Brightness.dark : Brightness.light),
      home: FinancialAnalyticsView(api: api, onExpired: expired ?? () {}),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  test('source preserves three-decimal money and signed noncash points', () {
    final r = FinancialReport(fixture());
    expect(r.groups.single['amount_minor'], 1234);
    expect(r.points.single['points'], -10);
  });
  test(
    'reject inexact money false live claims wrong scope and cash points',
    () {
      for (final change in <void Function(Map<String, dynamic>)>[
        (r) => r['groups'][0]['amount_minor'] = 9007199254740992,
        (r) => r['groups'][0]['amount_minor'] = 1.5,
        (r) => r['groups'][0]['minor_units'] = 4,
        (r) => r['groups'][0]['currency'] = 'POINTS',
        (r) => r['scope'] = 'platform',
        (r) => r['real_money_verified'] = true,
        (r) => r['points']['cash_value'] = true,
        (r) => r['points']['groups'][0]['kind'] = 'wallet',
        (r) => r['period']['from'] = '2026-02-30',
      ]) {
        final r = fixture();
        change(r);
        expect(() => FinancialReport(r), throwsFormatException);
      }
    },
  );
  testWidgets('source filters render exact amounts with no money mutation', (
    tester,
  ) async {
    final api = AnalyticsApi();
    await open(tester, api);
    await tester.scrollUntilVisible(
      find.text('KWD 1.234'),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('KWD 1.234'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.byKey(const Key('analytics-currency')),
      -200,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.enterText(find.byKey(const Key('analytics-currency')), 'USD');
    await tester.tap(find.byKey(const Key('analytics-refresh')));
    await tester.pumpAndSettle();
    expect(api.queries.last['currency'], 'USD');
    expect(find.text('KWD 1.234'), findsNothing);
    expect(find.text('No source records in this period.'), findsOneWidget);
  });
  testWidgets('invalid period blocked locally and old source cleared', (
    tester,
  ) async {
    final api = AnalyticsApi();
    await open(tester, api);
    await tester.enterText(
      find.byKey(const Key('analytics-from')),
      '2026-02-30',
    );
    await tester.tap(find.byKey(const Key('analytics-refresh')));
    await tester.pumpAndSettle();
    expect(api.calls.length, 1);
    expect(find.byKey(const Key('analytics-error')), findsOneWidget);
    expect(find.text('KWD 1.234'), findsNothing);
  });
  testWidgets('Arabic dark PDF uses loaded filters rather than edited inputs', (
    tester,
  ) async {
    final api = AnalyticsApi();
    await open(tester, api, ar: true);
    expect(find.text('الرؤى المالية'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('الرؤى المالية'))),
      TextDirection.rtl,
    );
    await tester.enterText(find.byKey(const Key('analytics-currency')), 'USD');
    await tester.ensureVisible(find.byKey(const Key('analytics-pdf')));
    await tester.tap(find.byKey(const Key('analytics-pdf')));
    await tester.pumpAndSettle();
    expect(api.pdfQueries.single, {...api.queries.single, 'locale': 'ar'});
    expect(find.textContaining('secret-provider'), findsNothing);
    expect(tester.takeException(), isNull);
  });
  testWidgets('background erases source and suppresses late response', (
    tester,
  ) async {
    final api = AnalyticsApi();
    await open(tester, api);
    api.response = Completer<dynamic>();
    await tester.tap(find.byKey(const Key('analytics-refresh')));
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    api.response!.complete(fixture(api.queries.last));
    await tester.pumpAndSettle();
    expect(find.text('KWD 1.234'), findsNothing);
    expect(find.byKey(const Key('analytics-pdf')), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(api.calls.length, 2);
  });
  testWidgets('expired and platform-scoped responses never expose records', (
    tester,
  ) async {
    var expired = 0;
    final api = AnalyticsApi()..deny = true;
    await open(tester, api, expired: () => expired++);
    expect(expired, 1);
    expect(find.textContaining('secret-source'), findsNothing);
    api.deny = false;
    api.malformed = fixture()..['scope'] = 'platform';
    await tester.tap(find.byKey(const Key('analytics-refresh')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('analytics-pdf')), findsNothing);
  });
}
