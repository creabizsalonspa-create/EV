String connectorReportedState(dynamic value, Duration elapsed) {
  if (value is! Map || value['evidence'] != 'SIMULATED_SOURCE' || value['physical_availability_verified'] != false || value['max_age_seconds'] != 300 || elapsed.isNegative) return 'unknown';
  DateTime? instant(String key) {
    final text = value[key];
    if (text is! String || !RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$').hasMatch(text)) return null;
    return DateTime.tryParse(text)?.toUtc();
  }
  final checked = instant('checked_at'), observed = instant('observed_at'), until = instant('valid_until');
  if (checked == null || observed == null || until == null || observed.isAfter(checked) || until.difference(observed) != const Duration(seconds: 300)) return 'unknown';
  if (value['state'] == 'stale' || !until.isAfter(checked.add(elapsed))) return 'stale';
  const statuses = ['Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'];
  return value['state'] == 'recent' && statuses.contains(value['reported_status']) ? value['reported_status'] as String : 'unknown';
}

String connectorFreshnessText(dynamic value, Duration elapsed, bool arabic) {
  final state = connectorReportedState(value, elapsed);
  const labels = {'Available':'متاح بحسب المصدر','Occupied':'مشغول','Preparing':'قيد التحضير','Charging':'جارٍ الشحن','SuspendedEV':'معلق من المركبة','SuspendedEVSE':'معلق من الشاحن','Finishing':'قيد الإنهاء','Reserved':'محجوز','Unavailable':'غير متاح','Faulted':'عطل','stale':'قديم','unknown':'غير معروف'};
  final observed = value is Map && value['observed_at'] is String ? value['observed_at'] : (arabic ? 'غير معروف' : 'unknown');
  return arabic ? 'حالة مصدر المحاكاة: ${labels[state]}. التوفر الفعلي غير مؤكد. وقت الرصد: $observed' : 'Simulated source status: $state. Physical availability is unverified. Observed: $observed';
}
