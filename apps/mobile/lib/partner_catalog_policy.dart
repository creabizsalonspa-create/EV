import 'connector_freshness.dart' show strictUtcConnectorTime;

/// Source and search clocks must agree with the server's floored age.
/// Ingestion time never substitutes for a missing partner observation.
String? verifiedPartnerSourceTime(Map<String,dynamic> row) {
  final age = row['reported_source_age_minutes'];
  if (row['evidence'] != 'PARTNER_REPORTED_UNVERIFIED' ||
      row['source_timestamp_evidence'] != 'PARTNER_REPORTED_ONLY' ||
      row['source_clock_evidence'] != 'PARTNER_REPORTED_TIME' ||
      age is! int || age < 0) return null;
  final observed = strictUtcConnectorTime(row['source_updated_at']);
  final checked = strictUtcConnectorTime(row['catalog_checked_at']);
  if (observed == null || checked == null || observed.isAfter(checked) ||
      checked.difference(observed).inMinutes != age) return null;
  return row['source_updated_at'] as String;
}

/// The server policy uses both clocks, including the exact inclusive cutoff.
/// Do not present a contradictory within-limit or stale claim.
Map<String,dynamic>? verifiedPartnerCatalogPolicy(Map<String,dynamic> row) {
  final policy = row['catalog_age_policy'];
  if (row['evidence'] != 'PARTNER_REPORTED_UNVERIFIED' || policy is! Map ||
      policy['evidence'] != 'PARTNER_CATALOG_TIMESTAMPS_ONLY' ||
      policy['physical_availability_verified'] != false ||
      policy['remote_control'] != false || policy['billing_authority'] != false) return null;
  final checked = strictUtcConnectorTime(row['catalog_checked_at']);
  final policyChecked = strictUtcConnectorTime(policy['checked_at']);
  final limit = policy['max_age_seconds'];
  if (checked == null || policyChecked != checked || limit is! int ||
      limit < 60 || limit > 604800) return null;
  final source = strictUtcConnectorTime(verifiedPartnerSourceTime(row));
  final received = strictUtcConnectorTime(row['received_at']);
  final receiptAge = row['received_age_minutes'];
  final validReceipt = received != null && !received.isAfter(checked) &&
      row['received_timestamp_evidence'] == 'LOCAL_INGESTION_TIME' &&
      receiptAge is int && receiptAge >= 0 &&
      checked.difference(received).inMinutes == receiptAge;
  final expected = source == null || received == null || !validReceipt
      ? 'unknown'
      : (checked.difference(source) >= Duration(seconds: limit) ||
          checked.difference(received) >= Duration(seconds: limit))
          ? 'stale' : 'within_policy';
  if (policy['state'] != expected) return null;
  return Map<String,dynamic>.unmodifiable(Map<String,dynamic>.from(policy));
}

String partnerCatalogPolicyText(dynamic value, bool ar) {
  String t(String en, String arabic) => ar ? arabic : en;
  String unavailable() => t(
    'Catalog-age policy unavailable. Physical availability unverified.',
    'سياسة عمر الكتالوج غير متاحة. التوفر الفعلي غير مؤكد.',
  );
  if (value is! Map || value['evidence'] != 'PARTNER_CATALOG_TIMESTAMPS_ONLY' ||
      value['physical_availability_verified'] != false ||
      value['remote_control'] != false || value['billing_authority'] != false ||
      strictUtcConnectorTime(value['checked_at']) == null ||
      value['max_age_seconds'] is! int || value['max_age_seconds'] < 60 ||
      value['max_age_seconds'] > 604800) return unavailable();
  final state = <String,String>{
    'within_policy': t('within limit', 'ضمن الحد'),
    'stale': t('limit exceeded', 'تم تجاوز الحد'),
    'unknown': t('age unknown', 'العمر غير معروف'),
  }[value['state']];
  if (state == null) return unavailable();
  return t(
    'Catalog-age policy at search: $state (${value['max_age_seconds']} seconds). Physical availability unverified.',
    'سياسة عمر الكتالوج عند البحث: $state (${value['max_age_seconds']} ثانية). التوفر الفعلي غير مؤكد.',
  );
}
