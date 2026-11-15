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
  bool Function() sessionFence() =>
      () => active;
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
    'fleet': {'name': 'Private company'},
    'period': {
      'from': '2026-11-01',
      'through': '2026-11-15',
      'timezone': 'Asia/Dubai',
    },
    'current_inventory': {
      'active_members': 2,
      'invited_members': 1,
      'assigned_vehicles': 3,
      'cost_centers': 1,
    },
    'approved_claims': 1,
    'groups': [
      {
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
