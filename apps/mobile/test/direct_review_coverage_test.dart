import 'package:creabiz_ev/direct_review_coverage.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> reply() => {
  'source_online': true,
  'source_topology_complete': true,
  'connectors': [{'state': 'recent'}, {'state': 'recent'}],
  'review_coverage': <String, dynamic>{
    'evidence': 'DIRECT_CONNECTOR_REVIEW_COVERAGE_ONLY',
    'state': 'all_identities_reviewed',
    'inventory_count': 2,
    'source_target_count': 2,
    'current_review_count': 2,
    'charging_mapping_created': false,
    'physical_availability_verified': false,
    'remote_control_authorized': false,
  },
};

void main() {
  test('missing coverage is unknown and partial coverage is not complete', () {
    final missing = reply()..remove('review_coverage');
    expect(validateDirectReviewCoverage(missing), isNull);
    final partial = reply();
    partial['review_coverage']['state'] = 'requires_review';
    partial['review_coverage']['current_review_count'] = 1;
    expect(validateDirectReviewCoverage(partial)!['state'], 'requires_review');
  });

  test('malformed coverage, invented authority and stale complete state are rejected', () {
    for (final bad in [
      {'evidence': 'SIMULATED'},
      {'charging_mapping_created': true},
      {'physical_availability_verified': true},
      {'remote_control_authorized': true},
      {'inventory_count': '2'},
      {'current_review_count': 1},
      {'source_target_count': 1},
      {'current_review_count': 3},
    ]) {
      final value = reply();
      (value['review_coverage'] as Map).addAll(bad);
      expect(() => validateDirectReviewCoverage(value), throwsFormatException);
    }
    final stale = reply()..['connectors'] = [{'state': 'recent'}, {'state': 'stale'}];
    expect(() => validateDirectReviewCoverage(stale), throwsFormatException);
    final offline = reply()..['source_online'] = false;
    expect(() => validateDirectReviewCoverage(offline), throwsFormatException);
  });

  testWidgets('complete review explicitly disclaims charging authority', (tester) async {
    await tester.pumpWidget(MaterialApp(home: Scaffold(
      body: DirectReviewCoverage(reply: reply()),
    )));
    expect(find.text('All connector identities reviewed; charging not authorized'), findsOneWidget);
    expect(find.text('2/2'), findsOneWidget);
    final partial = reply();
    partial['review_coverage']['state'] = 'requires_review';
    partial['review_coverage']['current_review_count'] = 1;
    await tester.pumpWidget(MaterialApp(home: Scaffold(
      body: DirectReviewCoverage(reply: partial),
    )));
    expect(find.text('Connector identity review incomplete'), findsOneWidget);
    expect(find.text('1/2'), findsOneWidget);
    await tester.pumpWidget(MaterialApp(home: Scaffold(
      body: DirectReviewCoverage(reply: reply()..remove('review_coverage')),
    )));
    expect(find.text('Connector review coverage unknown'), findsOneWidget);
    expect(find.text('All connector identities reviewed; charging not authorized'), findsNothing);
  });
}
