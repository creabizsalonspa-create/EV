import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/fleet_report.dart';
import 'test_support.dart';

class ReportApi extends FixtureApi {
  bool active = true;
  Completer<dynamic>? pending;
  final reads = <Map<String, String>>[];
  @override
  bool Function() sessionFence() {
    final original = super.sessionFence();
    return () => active && original();
  }
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(method, 'GET');
    expect(path, 'fleets/7/report');
    reads.add(Map.of(query));
    return pending?.future ?? fixture();
  }

  Map<String, dynamic> fixture() => {
    'as_of': '2026-11-15T10:00:00Z',
    'fleet': {'id': 7, 'name': 'Private company'},
    'basis': 'approved_claims_submitted_in_period',
    'physical_charging_verified': false,
    'period': {
      'from': '2026-11-01',
      'through': '2026-11-15',
      'timezone': 'Asia/Dubai',
      'cost_center_id': 9,
    },
    'current_inventory': <String, dynamic>{
      'active_members': 2,
      'invited_members': 1,
      'assigned_vehicles': 3,
      'cost_centers': 1,
    },
    'approved_claims': 1,
    'groups': [
      {
        'cost_center_id': 9,
        'code': 'OPS',
        'name': '=PRIVATE()',
        'currency': 'AED',
        'minor_units': 2,
        'amount_minor': '9007199254740993',
        'claims': 1,
      },
    ],
  };
}

Widget screen(ReportApi api, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: FleetReportView(api: api, fleetId: 7, onExpired: () {}),
);
void main() {
  test('fleet report freezes exact validated fields and discards unrelated transport data', () {
    final api = ReportApi();
    addTearDown(api.close);
    final raw = api.fixture();
    final filters = {'from':'2026-11-01','through':'2026-11-15','cost_center_id':'9'};
    raw['current_inventory']['private_customer_email'] = 'unrelated@example.test';
    raw['groups'][0]['internal_reference'] = 'private-source';
    final report = verifyFleetReport(raw, 7, filters);
    raw['fleet']['name'] = 'Replacement';
    raw['groups'][0]['amount_minor'] = '1';
    raw['current_inventory']['active_members'] = 999;
    expect(report['fleet']['name'], 'Private company');
    expect(report['groups'][0]['amount_minor'], '9007199254740993');
    expect(report['current_inventory']['active_members'], 2);
    expect(report['current_inventory'].containsKey('private_customer_email'), false);
    expect(report['groups'][0].containsKey('internal_reference'), false);
    expect(() => report['groups'][0]['amount_minor'] = '2', throwsUnsupportedError);
    expect(() => report['groups'].clear(), throwsUnsupportedError);
    expect(() => report['fleet']['name'] = 'Other', throwsUnsupportedError);
    for (final corrupt in [
      {...api.fixture(), 'fleet': {'id': 7.0, 'name': 'Private company'}},
      {...api.fixture(), 'period': {...api.fixture()['period'], 'cost_center_id': 9.0}},
      {...api.fixture(), 'groups': [{...api.fixture()['groups'][0], 'claims': 9007199254740991}]},
    ]) {
      expect(() => verifyFleetReport(corrupt, 7, filters), throwsFormatException);
    }
  });

  testWidgets('already displayed fleet report and CSV clear on actual session generation change', (tester) async {
    final api = ReportApi();
    addTearDown(api.close);
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).at(0), '2026-11-01');
    await tester.enterText(find.byType(TextField).at(1), '2026-11-15');
    await tester.enterText(find.byType(TextField).at(2), '9');
    await tester.ensureVisible(find.byType(FilledButton));
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.byType(TextButton), 200,
      scrollable: find.descendant(of: find.byType(ListView),
        matching: find.byType(Scrollable)).first);
    await tester.tap(find.byType(TextButton));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('fleet-report-csv')), findsOneWidget);
    final callback = tester.widget<TextButton>(find.byType(TextButton)).onPressed!;
    await api.forgetSession();
    await tester.pumpAndSettle();
    callback();
    await tester.pump();
    expect(find.byKey(const Key('fleet-report-csv')), findsNothing);
    expect(find.textContaining('Private company'), findsNothing);
    expect(find.textContaining('90071992547409.93'), findsNothing);
    expect(api.reads.length, 1);
  });

  test('fleet report rejects incorrect scope, precision, totals and evidence', () {
    final api = ReportApi();
    final filters = {'from':'2026-11-01','through':'2026-11-15','cost_center_id':'9'};
    expect(verifyFleetReport(api.fixture(),7,filters)['approved_claims'],1);
    for (final raw in [
      {...api.fixture(), 'physical_charging_verified': true},
      {...api.fixture(), 'approved_claims': 2},
      {...api.fixture(), 'fleet': {'id': 8, 'name': 'Foreign'}},
      {...api.fixture(), 'groups': [{...api.fixture()['groups'][0], 'minor_units': 4}]},
    ]) {
      expect(() => verifyFleetReport(raw,7,filters),throwsFormatException);
    }
    expect(() => verifyFleetReport(api.fixture(),7,{...filters,'cost_center_id':'10'}),throwsFormatException);
  });
  for (final ar in [false, true]) {
    testWidgets(
      'fleet report explicit filtered read, exact money and private CSV ($ar)',
      (tester) async {
        final api = ReportApi();
        await tester.pumpWidget(screen(api, ar: ar));
        await tester.pumpAndSettle();
        expect(api.reads, isEmpty);
        await tester.enterText(find.byType(TextField).at(0), '2026-11-01');
        await tester.enterText(find.byType(TextField).at(1), '2026-11-15');
        await tester.enterText(find.byType(TextField).at(2), '9');
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await tester.pumpAndSettle();
        expect(api.reads.single, {
          'from': '2026-11-01',
          'through': '2026-11-15',
          'cost_center_id': '9',
        });
        await tester.scrollUntilVisible(
          find.byType(TextButton),
          200,
          scrollable: find
              .descendant(
                of: find.byType(ListView),
                matching: find.byType(Scrollable),
              )
              .first,
        );
        expect(find.textContaining('90071992547409.93'), findsOneWidget);
        await tester.tap(find.byType(TextButton));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.byKey(const Key('fleet-report-csv')));
        expect(
          tester
              .widget<SelectableText>(find.byKey(const Key('fleet-report-csv')))
              .data,
          contains("'=PRIVATE()"),
        );
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
        await tester.pump();
        expect(find.byKey(const Key('fleet-report-csv')), findsNothing);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        await tester.pump();
        expect(api.reads.length, 1);
        expect(find.text('Private company'), findsNothing);
      },
    );
  }
  testWidgets('late fleet report cannot paint after account change', (
    tester,
  ) async {
    final api = ReportApi()..pending = Completer<dynamic>();
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byType(FilledButton));
    await tester.tap(find.byType(FilledButton));
    await tester.pump();
    api.active = false;
    api.pending!.complete(api.fixture());
    await tester.pumpAndSettle();
    expect(find.text('Private company'), findsNothing);
  });
}
