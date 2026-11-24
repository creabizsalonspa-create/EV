const reportedConnectorStatuses = ['Available','Occupied','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted'];
String connectorStatusLabel(String state,bool arabic) {
  const labels={'Available':'متاح بحسب المصدر','Occupied':'مشغول','Preparing':'قيد التحضير','Charging':'جارٍ الشحن','SuspendedEV':'معلق من المركبة','SuspendedEVSE':'معلق من الشاحن','Finishing':'قيد الإنهاء','Reserved':'محجوز','Unavailable':'غير متاح','Faulted':'عطل','stale':'قديم','unknown':'غير معروف'};
  return arabic?(labels[state]??labels['unknown']!):state;
}

DateTime? strictUtcConnectorTime(dynamic raw) {
  if (raw is! String) return null;
  final m=RegExp(r'^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?Z$').firstMatch(raw);
  if (m == null) return null;
  final p=[for(var n=1;n<=6;n++) int.parse(m.group(n)!)];
  // DateTime.tryParse may normalize impossible dates. Never show such a
  // different time as a verified connector source observation.
  final d=DateTime.utc(p[0],p[1],p[2],p[3],p[4],p[5]);
  if(d.year!=p[0] || d.month!=p[1] || d.day!=p[2] ||
      d.hour!=p[3] || d.minute!=p[4] || d.second!=p[5])return null;
  return DateTime.tryParse(raw)?.toUtc();
}

String connectorReportedState(dynamic value, Duration elapsed) {
  if (value is! Map || value['evidence'] != 'SIMULATED_SOURCE' || value['physical_availability_verified'] != false || value['max_age_seconds'] != 300 || elapsed.isNegative) return 'unknown';
  final checked = strictUtcConnectorTime(value['checked_at']),
      observed = strictUtcConnectorTime(value['observed_at']),
      until = strictUtcConnectorTime(value['valid_until']);
  if (checked == null || observed == null || until == null || observed.isAfter(checked) || until.difference(observed) != const Duration(seconds: 300)) return 'unknown';
  if (value['state'] == 'stale' || !until.isAfter(checked.add(elapsed))) return 'stale';
  return value['state'] == 'recent' && reportedConnectorStatuses.contains(value['reported_status']) ? value['reported_status'] as String : 'unknown';
}

String connectorFreshnessText(dynamic value, Duration elapsed, bool arabic) {
  final state = connectorReportedState(value, elapsed);
  const labels = {'Available':'متاح بحسب المصدر','Occupied':'مشغول','Preparing':'قيد التحضير','Charging':'جارٍ الشحن','SuspendedEV':'معلق من المركبة','SuspendedEVSE':'معلق من الشاحن','Finishing':'قيد الإنهاء','Reserved':'محجوز','Unavailable':'غير متاح','Faulted':'عطل','stale':'قديم','unknown':'غير معروف'};
  final observed = value is Map && value['evidence']=='SIMULATED_SOURCE' &&
      value['physical_availability_verified']==false &&
      strictUtcConnectorTime(value['observed_at'])!=null
      ? value['observed_at'] as String : (arabic ? 'غير معروف' : 'unknown');
  return arabic ? 'حالة مصدر المحاكاة: ${labels[state]}. التوفر الفعلي غير مؤكد. وقت الرصد: $observed' : 'Simulated source status: $state. Physical availability is unverified. Observed: $observed';
}
