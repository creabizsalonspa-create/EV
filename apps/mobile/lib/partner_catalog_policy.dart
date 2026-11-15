String partnerCatalogPolicyText(dynamic value, bool ar) {
  String t(String en, String arabic) => ar ? arabic : en;
  String unavailable() => t(
    'Catalog-age policy unavailable. Physical availability unverified.',
    'سياسة عمر الكتالوج غير متاحة. التوفر الفعلي غير مؤكد.',
  );
  if (value is! Map || value['evidence'] != 'PARTNER_CATALOG_TIMESTAMPS_ONLY' ||
      value['physical_availability_verified'] != false ||
      value['remote_control'] != false || value['billing_authority'] != false ||
      value['checked_at'] is! String ||
      !RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$').hasMatch(value['checked_at']) ||
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
