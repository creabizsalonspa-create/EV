import 'package:creabiz_ev/api.dart';
import 'package:flutter_test/flutter_test.dart';
import '../lib/connector_freshness.dart';
void main() {
  test('source filter statuses and Arabic labels share the detail contract', () {
    expect(reportedConnectorStatuses.length,10);
    expect(connectorStatusLabel('Available',true),'متاح بحسب المصدر');
    expect(const CatalogFilter(recentSourceStatus:'Available',connector:'CCS2').query,containsPair('recent_source_status','Available'));
  });

  final value = <String,dynamic>{'state':'recent','reported_status':'Available','evidence':'SIMULATED_SOURCE','physical_availability_verified':false,'max_age_seconds':300,'observed_at':'2026-11-14T05:00:00Z','checked_at':'2026-11-14T05:04:00Z','valid_until':'2026-11-14T05:05:00Z'};
  test('source freshness expires inclusively using monotonic elapsed time', () {
    expect(connectorReportedState(value,const Duration(milliseconds:59999)),'Available');
    expect(connectorReportedState(value,const Duration(seconds:60)),'stale');
    expect(connectorFreshnessText(value,Duration.zero,true),contains('متاح بحسب المصدر'));
    expect(connectorFreshnessText(value,const Duration(seconds:60),true),contains('قديم'));
    expect(connectorFreshnessText(value,Duration.zero,false),contains('Physical availability is unverified'));
    expect(connectorFreshnessText(value,Duration.zero,true),contains('2026-11-14T05:00:00Z'));
  });
  test('unsupported or malformed source claims remain unknown', () {
    for(final update in <Map<String,dynamic>>[{'physical_availability_verified':true},{'evidence':'LIVE'},{'max_age_seconds':301},{'observed_at':'2026-11-14T05:05:00Z'},{'valid_until':'garbage'},{'reported_status':'anything'},{'state':'unknown'}]) {
      expect(connectorReportedState({...value,...update},Duration.zero),'unknown');
    }
    expect(connectorReportedState(null,Duration.zero),'unknown');
    expect(connectorReportedState(value,const Duration(seconds:-1)),'unknown');
  });

  test('unresolved evidence stays unknown past expiry in either language', () {
    final unresolved = {...value, 'state': 'unknown'};
    const elapsed = Duration(minutes: 8);
    expect(connectorReportedState(unresolved, elapsed), 'unknown');
    expect(
      connectorFreshnessText(unresolved, elapsed, false),
      contains('Observed: unknown'),
    );
    expect(
      connectorFreshnessText(unresolved, elapsed, true),
      contains('وقت الرصد: غير معروف'),
    );
    expect(
      connectorReportedState({...value, 'state': 'stale'}, Duration.zero),
      'unknown',
    );
    expect(
      connectorReportedState({
        ...value,
        'state': 'stale',
        'reported_status': null,
        'checked_at': value['valid_until'],
      }, Duration.zero),
      'stale',
    );
  });

  test(
    'invalid calendar dates and future microsecond observations fail closed',
    () {
      expect(
        connectorReportedState({
          ...value,
          'observed_at': '2026-11-31T05:00:00Z',
          'checked_at': '2026-12-01T05:04:00Z',
          'valid_until': '2026-12-01T05:05:00Z',
        }, Duration.zero),
        'unknown',
      );
      final fractional = {
        ...value,
        'observed_at': '2026-11-14T05:00:00.000005Z',
        'checked_at': '2026-11-14T05:04:00.000010Z',
        'valid_until': '2026-11-14T05:05:00.000005Z',
      };
      expect(
        connectorReportedState(
          fractional,
          const Duration(microseconds: 59999994),
        ),
        'Available',
      );
      expect(
        connectorReportedState(
          fractional,
          const Duration(microseconds: 59999995),
        ),
        'stale',
      );
      expect(
        connectorReportedState({
          ...fractional,
          'checked_at': '2026-11-14T05:00:00.000004Z',
        }, Duration.zero),
        'unknown',
      );
    },
  );
}
