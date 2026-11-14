import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/source_status_durations.dart';

Map<String, dynamic> report() => {
  'basis': 'BOUNDED_SIMULATED_STATUS_OBSERVATIONS',
  'physical_uptime_verified': false,
  'window_seconds': 86400,
  'max_observation_hold_seconds': 300,
  'state': 'observed',
  'window_started_at': '2026-11-27T12:00:00Z',
  'window_ended_at': '2026-11-28T12:00:00Z',
  'components': [
    {
      'evse_number': 1,
      'connector_number': 2,
      'observed_status_seconds': {'Faulted': 120},
      'unknown_seconds': 86280,
    },
  ],
};
void main() {
  test(
    'duration accounting rejects overlapping totals and physical uptime claims',
    () {
      final valid = report();
      expect(sourceDurationComponents(valid).single['unknown_seconds'], 86280);
      expect(
        () => sourceDurationComponents({
          ...valid,
          'physical_uptime_verified': true,
        }),
        throwsFormatException,
      );
      (valid['components'] as List).first['unknown_seconds'] = 86400;
      expect(() => sourceDurationComponents(valid), throwsFormatException);
    },
  );
  testWidgets(
    'renders observed seconds and unknown gaps without uptime percentage',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(body: SourceStatusDurationsView(report: report())),
        ),
      );
      expect(find.text('Faulted: 120'), findsOneWidget);
      expect(find.text('Unknown seconds: 86280'), findsOneWidget);
      expect(find.textContaining('not physical uptime'), findsOneWidget);
    },
  );
}
