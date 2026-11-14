import 'package:flutter_test/flutter_test.dart';
import '../lib/connector_freshness.dart';
void main() {
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
}
