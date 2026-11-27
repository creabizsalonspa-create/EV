const reportedConnectorStatuses = ['Available','Occupied','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted'];
String connectorStatusLabel(String state,bool arabic) {
  const labels={'Available':'متاح بحسب المصدر','Occupied':'مشغول','Preparing':'قيد التحضير','Charging':'جارٍ الشحن','SuspendedEV':'معلق من المركبة','SuspendedEVSE':'معلق من الشاحن','Finishing':'قيد الإنهاء','Reserved':'محجوز','Unavailable':'غير متاح','Faulted':'عطل','stale':'قديم','unknown':'غير معروف'};
  return arabic?(labels[state]??labels['unknown']!):state;
}

DateTime? strictUtcConnectorTime(dynamic raw) {
  if (raw is! String) return null;
  final m=RegExp(r'^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?Z$').firstMatch(raw);
  if (m == null || m.end != raw.length) return null;
  final p=[for(var n=1;n<=6;n++) int.parse(m.group(n)!)];
  // DateTime.tryParse may normalize impossible dates. Never show such a
  // different time as a verified connector source observation.
  final d=DateTime.utc(p[0],p[1],p[2],p[3],p[4],p[5]);
  if(d.year!=p[0] || d.month!=p[1] || d.day!=p[2] ||
      d.hour!=p[3] || d.minute!=p[4] || d.second!=p[5])return null;
  return DateTime.tryParse(raw)?.toUtc();
}

({String state, String? observedAt}) _observation(Object? value, Duration elapsed) {
  const unknown = (state: 'unknown', observedAt: null);
  if (value is! Map || value['evidence'] != 'SIMULATED_SOURCE' ||
      value['physical_availability_verified'] != false ||
      value['max_age_seconds'] is! int ||
      value['max_age_seconds'] != 300 || elapsed.isNegative) return unknown;
  final serverState = value['state'];
  final status = value['reported_status'];
  if (serverState == 'stale' ? status != null :
      (serverState != 'recent' || !reportedConnectorStatuses.contains(status))) return unknown;
  final checked = strictUtcConnectorTime(value['checked_at']),
      observed = strictUtcConnectorTime(value['observed_at']),
      until = strictUtcConnectorTime(value['valid_until']);
  if (checked == null || observed == null || until == null ||
      observed.isAfter(checked) || until.difference(observed) != const Duration(seconds: 300) ||
      (serverState == 'stale' && until.isAfter(checked)) ||
      (serverState == 'recent' && !until.isAfter(checked))) return unknown;
  final expired = until.difference(checked) <= elapsed;
  return (state: expired ? 'stale' : status as String,
      observedAt: value['observed_at'] as String);
}

String connectorReportedState(Object? value, Duration elapsed) =>
    _observation(value, elapsed).state;

String connectorFreshnessText(Object? value, Duration elapsed, bool arabic) {
  final observation = _observation(value, elapsed);
  final state = connectorStatusLabel(observation.state, arabic);
  final observed = observation.observedAt ?? connectorStatusLabel('unknown', arabic);
  return arabic
      ? 'حالة مصدر المحاكاة: $state. التوفر الفعلي غير مؤكد. وقت الرصد: $observed'
      : 'Simulated source status: $state. Physical availability is unverified. Observed: $observed';
}
