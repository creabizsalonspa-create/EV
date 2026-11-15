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
  test('Unavailable saved-partner models discard catalog policy metadata', () {
    final row=RoamingLocation.fromJson({'id':1,'location_available':false,'is_favorite':true,'location':null,'received_at':null,'catalog_age_policy':base,'evidence':'PARTNER_REPORTED_UNVERIFIED','availability':'unknown','remote_control':false,'reservable':false});
    expect(row.catalogAgePolicy,isNull);
  });
}
