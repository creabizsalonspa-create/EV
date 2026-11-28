import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/partner_catalog_policy.dart';
import 'package:creabiz_ev/roaming.dart';

void main() {
  final base = <String,dynamic>{'state':'within_policy','max_age_seconds':60,'checked_at':'2026-11-15T12:00:00.000000Z','evidence':'PARTNER_CATALOG_TIMESTAMPS_ONLY','physical_availability_verified':false,'remote_control':false,'billing_authority':false};
  for (final ar in [false,true]) {
    test('Catalog policy stays qualified and unknown on malformed authority $ar', () {
      for (final entry in [('within_policy','within limit','ضمن الحد'),('stale','limit exceeded','تم تجاوز الحد'),('unknown','age unknown','العمر غير معروف')]) {
        expect(partnerCatalogPolicyText({...base,'state':entry.$1},ar), contains(ar?entry.$3:entry.$2));
      }
      for (final invalid in [null,{...base,'state':'unsupported'},{...base,'physical_availability_verified':true},{...base,'remote_control':true},{...base,'billing_authority':true},{...base,'max_age_seconds':59},{...base,'max_age_seconds':'60'},{...base,'checked_at':'<img src=x>'}]) {
        expect(partnerCatalogPolicyText(invalid,ar), contains(ar?'غير متاحة':'policy unavailable'));
      }
    });
  }
  test('policy status agrees with source and ingestion clocks at exact cutoff', () {
    final row=<String,dynamic>{
      'evidence':'PARTNER_REPORTED_UNVERIFIED',
      'source_timestamp_evidence':'PARTNER_REPORTED_ONLY',
      'source_clock_evidence':'PARTNER_REPORTED_TIME',
      'source_updated_at':'2026-11-15T11:59:00.000001Z',
      'catalog_checked_at':'2026-11-15T12:00:00.000000Z',
      'reported_source_age_minutes':0,
      'received_at':'2026-11-15T11:59:30Z',
      'received_timestamp_evidence':'LOCAL_INGESTION_TIME',
      'received_age_minutes':0,
      'catalog_age_policy':base,
    };
    expect(verifiedPartnerCatalogPolicy(row)?['state'],'within_policy');
    final sourceStale={...row,'source_updated_at':'2026-11-15T11:59:00Z',
      'reported_source_age_minutes':1};
    final receiptStale={...row,'received_at':'2026-11-15T11:59:00Z',
      'received_age_minutes':1};
    for(final stale in [sourceStale,receiptStale]){
      expect(verifiedPartnerCatalogPolicy(stale),isNull);
      expect(verifiedPartnerCatalogPolicy({...stale,'catalog_age_policy':{...base,'state':'stale'}})?['state'],'stale');
    }
    for(final change in <Map<String,dynamic>>[
      {'received_at':'2026-11-15T12:00:00.000001Z'},
      {'received_age_minutes':1},
      {'received_timestamp_evidence':'PARTNER_REPORTED_ONLY'},
      {'source_updated_at':'2026-02-30T11:59:00Z'},
      {'catalog_age_policy':{...base,'checked_at':'2026-11-15T12:00:01Z'}},
    ]){
      expect(verifiedPartnerCatalogPolicy({...row,...change}),isNull,reason:'$change');
    }
    final unknown={...row,'source_updated_at':null,'reported_source_age_minutes':null,
      'source_clock_evidence':'SOURCE_TIMESTAMP_UNKNOWN',
      'catalog_age_policy':{...base,'state':'unknown'}};
    expect(verifiedPartnerCatalogPolicy(unknown)?['state'],'unknown');
    for(final ar in [false,true]){
      expect(partnerCatalogPolicyText({...base,'checked_at':'2026-02-30T12:00:00Z'},ar),
        contains(ar?'غير متاحة':'policy unavailable'));
    }
  });
  test('Unavailable saved-partner models discard catalog policy metadata', () {
    final row=RoamingLocation.fromJson({'id':1,'location_available':false,'is_favorite':true,'location':null,'received_at':null,'catalog_age_policy':base,'evidence':'PARTNER_REPORTED_UNVERIFIED','availability':'unknown','remote_control':false,'reservable':false});
    expect(row.catalogAgePolicy,isNull);
  });
}
