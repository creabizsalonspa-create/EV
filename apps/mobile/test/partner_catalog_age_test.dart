import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/unified_discovery.dart';

void main() {
  for (final ar in [false, true]) {
    test('Catalog age is report evidence only $ar', () {
      Map<String,dynamic> row(dynamic age, [String evidence = 'PARTNER_REPORTED_TIME']) => {
        'reported_source_age_minutes': age,
        'source_clock_evidence': evidence,
        'source_timestamp_evidence': 'PARTNER_REPORTED_ONLY',
        'evidence': 'PARTNER_REPORTED_UNVERIFIED',
        'catalog_checked_at': '2026-11-27T12:00:00.000000Z',
        'source_updated_at': age is int && age >= 0
          ? DateTime.utc(2026,11,27,12).subtract(Duration(minutes:age)).toIso8601String()
          : null,
      };
      expect(partnerCatalogAgeLabel(row(1500), ar), contains(ar ? 'أقدم من 24' : 'over 24 hours old'));
      expect(partnerCatalogAgeLabel(row(0), ar), contains(ar ? 'عند البحث' : 'at search'));
      for (final invalid in [row(null), row(-1), row('0'), row(0, 'PARTNER_CLOCK_AHEAD'), <String,dynamic>{}]) {
        expect(partnerCatalogAgeLabel(invalid, ar), contains(ar ? 'غير معروف أو' : 'unknown or'));
      }
    });
  }
}
