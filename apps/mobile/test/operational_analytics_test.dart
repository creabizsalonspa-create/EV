import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/operational_analytics.dart';
import 'test_support.dart';

class OpsApi extends FixtureApi {
  bool unsafe = false, fail = false, unsafeFeedback = false, unknownDemand = false;
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
    return {
      'company_id': 2,
      'evidence': 'SIMULATED_SOURCE',
      'physical_uptime_verified': unsafe,
      'physical_site_demand_verified': false,
      'billing_authority': false,
      'financial_visible': false,
      'from': '2026-11-01',
      'to': '2026-11-06',
      'as_of': '2026-11-06T12:00:00Z',
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
          for (final key in operationalLabels.keys)
            key: key == 'maximum_observed_site_interval_average_w' && unknownDemand
                ? null
                : key == 'completed_energy_wh' ? 1250 : 0,
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
}
