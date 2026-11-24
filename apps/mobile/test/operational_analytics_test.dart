import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/operational_analytics.dart';
import 'test_support.dart';

class OpsApi extends FixtureApi {
  bool unsafe = false, fail = false, unsafeFeedback = false, unknownDemand = false;
  bool wrongPeriod = false, inconsistentCoverage = false;
  bool forgedUnavailable = false;
  Completer<dynamic>? held;
  final applied = <Map<String, String>>[];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    applied.add(Map.from(query));
    if (fail) throw const ApiException(401, 'PRIVATE source failure');
    if (held != null) return held!.future;
    return {
      'company_id': 2,
      'evidence': 'SIMULATED_SOURCE',
      'physical_uptime_verified': unsafe,
      'physical_site_demand_verified': false,
      'billing_authority': false,
      'financial_visible': false,
      'from': wrongPeriod ? '2026-11-01' : query['from'],
      'to': query['to'],
      'as_of': DateTime.now().toUtc().toIso8601String(),
      'until': DateTime.now().toUtc().toIso8601String(),
      'window_seconds': 3600,
      'status_freshness_seconds': 300,
      'demand_scope':
        'sum_of_floored_accepted_session_interval_averages_within_window_excluding_same_evse_overlaps',
      'invoice_facts': [],
      'feedback_scope': 'currently_published_revisions_created_in_window',
      'feedback_physical_charging_verified': unsafeFeedback,
      'feedback_groups': [
        {
          'site_id': 1,
          'evidence': 'SIMULATED_SOURCE_OBSERVED',
          'ratings': 2,
          'rating_sum': 9,
        },
      ],
      'sites': [
        {
          'name': 'Synthetic site',
          'site_id': 1,
          // The canonical metric map contains both EVSE and connector
          // exposure denominators; their values must remain distinct.
          for (final key in operationalLabels.keys)
            key: key == 'maximum_observed_site_interval_average_w' && unknownDemand
                ? null
                : key == 'reported_non_fault_unavailable_seconds' && forgedUnavailable
                    ? 1
                    : key == 'completed_energy_wh'
                    ? 1250
                    : key == 'status_known_seconds' && inconsistentCoverage
                        ? 10
                        : 0,
        },
      ],
    };
  }
}

Widget app(OpsApi api, VoidCallback expired) => MaterialApp(
  home: OperationalAnalyticsView(api: api, companyId: 2, onExpired: expired),
);
void main() {
  testWidgets('missing demand remains unknown without hiding the report', (tester) async {
    final api = OpsApi()..unknownDemand = true;
    await tester.pumpWidget(app(api, () {}));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Maximum sum of observed interval averages (W), not grid peak: Unknown'),
      250,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Maximum sum of observed interval averages (W), not grid peak: Unknown'), findsOneWidget);
    expect(find.textContaining('Inexact source metric'), findsNothing);
  });
  testWidgets('separate EVSE and connector coverage is visible in bilingual-safe source metrics',
    (tester) async {
      final api = OpsApi();
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Current mapped connector window seconds: 0'),
        180,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Current mapped connector window seconds: 0'),
          findsOneWidget);
      expect(find.text('Mapped connectors: 0'), findsOneWidget);
      expect(find.textContaining('Physical availability confirmed'),
          findsNothing);
    },
  );
  testWidgets(
    'reported unavailable seconds remain separate from fault evidence',
    (tester) async {
      await tester.pumpWidget(app(OpsApi(), () {}));
      await tester.pumpAndSettle();
      final metric = find.text(
        'Reported unavailable seconds without a fault: 0',
      );
      await tester.scrollUntilVisible(
        metric,
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(metric, findsOneWidget);
    },
  );
  testWidgets('unavailable seconds cannot exceed remaining known coverage',
    (tester) async {
      await tester.pumpWidget(app(OpsApi()..forgedUnavailable = true, () {}));
      await tester.pumpAndSettle();
      expect(find.text('View private report PDF'), findsNothing);
      expect(find.textContaining('Completed energy (Wh): 1250'), findsNothing);
    },
  );
  testWidgets(
    'source metrics render without authority promotion and stale accounts clear report',
    (tester) async {
      final api = OpsApi();
      var expired = 0;
      await tester.pumpWidget(app(api, () => expired++));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Completed energy (Wh): 1250'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Completed energy (Wh): 1250'), findsOneWidget);
      api.fail = true;
      await tester.scrollUntilVisible(
        find.text('Apply and refresh'),
        -200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Apply and refresh'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.textContaining('1250'), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
    },
  );
  testWidgets(
    'unknown capability fails closed; inactive app removes private results without reloading',
    (tester) async {
      final api = OpsApi()..unsafe = true;
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      expect(find.textContaining('1250'), findsNothing);
      expect(find.text('View private report PDF'), findsNothing);
      api.unsafe = false;
      await tester.scrollUntilVisible(
        find.text('Apply and refresh'),
        -200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Apply and refresh'));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Completed energy (Wh): 1250'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Completed energy (Wh): 1250'), findsOneWidget);
      final calls = api.applied.length;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.textContaining('1250'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.applied.length, calls);
    },
  );
  testWidgets(
    'anonymous source feedback renders but physical verification promotion fails closed',
    (tester) async {
      final api = OpsApi();
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Observed simulator feedback'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(find.text('Ratings: 2 · Rating sum: 9 · Site: 1'), findsOneWidget);
      api.unsafeFeedback = true;
      await tester.scrollUntilVisible(
        find.text('Apply and refresh'),
        -250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Apply and refresh'),
        -200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Apply and refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Observed simulator feedback'), findsNothing);
      expect(find.text('View private report PDF'), findsNothing);
    },
  );

  testWidgets('source interval mismatch and impossible uptime coverage fail closed',
    (tester) async {
      final api = OpsApi()..wrongPeriod = true;
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      expect(find.textContaining('Completed energy (Wh): 1250'), findsNothing);
      expect(find.text('View private report PDF'), findsNothing);
      api.wrongPeriod = false;
      api.inconsistentCoverage = true;
      await tester.scrollUntilVisible(
        find.text('Apply and refresh'), -200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Apply and refresh'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Completed energy (Wh): 1250'), findsNothing);
      expect(find.text('View private report PDF'), findsNothing);
    },
  );
  testWidgets('filter edits erase previous private energy and export authority',
    (tester) async {
      final api = OpsApi();
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      expect(api.applied.length, 1);
      await tester.scrollUntilVisible(
        find.text('View private report PDF'), 200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('View private report PDF'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('From (UTC)'), -220,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.enterText(find.byType(TextField).first, '2026-02-30');
      await tester.pump();
      expect(find.textContaining('Completed energy (Wh): 1250'), findsNothing);
      expect(find.text('View private report PDF'), findsNothing);
      expect(api.applied.length, 1);
    },
  );
  testWidgets('revoked API generation cannot expose cached native finance or energy',
    (tester) async {
      final api = OpsApi();
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('View private report PDF'), 200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('View private report PDF'), findsOneWidget);
      await api.forgetSession();
      await tester.pump();
      expect(find.text('View private report PDF'), findsNothing);
      expect(find.textContaining('Completed energy (Wh): 1250'), findsNothing);
    },
  );
  testWidgets('late source response after session replacement never reappears',
    (tester) async {
      final api = OpsApi()..held = Completer<dynamic>();
      await tester.pumpWidget(app(api, () {}));
      await tester.pump();
      expect(api.applied.length, 1);
      await api.forgetSession();
      api.held!.complete({
        'company_id': 2, 'evidence': 'SIMULATED_SOURCE',
        'physical_uptime_verified': false,
        'physical_site_demand_verified': false,
        'billing_authority': false,
        'financial_visible': false,
        'sites': [], 'invoice_facts': [],
        'demand_scope':
          'sum_of_floored_accepted_session_interval_averages_within_window_excluding_same_evse_overlaps',
        'status_freshness_seconds': 300,
        'window_seconds': 3600,
        'from': api.applied.single['from'], 'to': api.applied.single['to'],
        'as_of': DateTime.now().toUtc().toIso8601String(),
        'until': DateTime.now().toUtc().toIso8601String(),
      });
      await tester.pump();
      expect(find.text('View private report PDF'), findsNothing);
      expect(find.textContaining('Completed energy'), findsNothing);
      expect(api.applied.length, 1);
    },
  );
}
