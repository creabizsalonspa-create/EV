const reportedConnectorStatuses = ['Available','Occupied','Preparing','Charging','SuspendedEV','SuspendedEVSE','Finishing','Reserved','Unavailable','Faulted'];
String connectorStatusLabel(String state,bool arabic) {
  const labels={'Available':'متاح بحسب المصدر','Occupied':'مشغول','Preparing':'قيد التحضير','Charging':'جارٍ الشحن','SuspendedEV':'معلق من المركبة','SuspendedEVSE':'معلق من الشاحن','Finishing':'قيد الإنهاء','Reserved':'محجوز','Unavailable':'غير متاح','Faulted':'عطل','stale':'قديم','unknown':'غير معروف'};
  return arabic?(labels[state]??labels['unknown']!):state;
}

final _canonicalUtc = RegExp(
  r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$',
);

DateTime? _parseUtcInstant(Object? value) {
  if (value is! String || !_canonicalUtc.hasMatch(value)) return null;
  final parsed = DateTime.tryParse(value);
  // Dart accepts overflow dates. Source timestamps must be actual instants.
  if (parsed == null ||
      parsed.toIso8601String().substring(0, 19) != value.substring(0, 19)) {
    return null;
  }
  return parsed;
}

({String state, String? observedAt}) _observation(
  Object? value,
  Duration elapsed,
) {
  const unknown = (state: 'unknown', observedAt: null);
  if (value is! Map ||
      value['evidence'] != 'SIMULATED_SOURCE' ||
      value['physical_availability_verified'] != false ||
      value['max_age_seconds'] != 300 ||
      elapsed.isNegative) {
    return unknown;
  }
  final serverState = value['state'];
  final status = value['reported_status'];
  if (serverState != 'stale' &&
      (serverState != 'recent' ||
          !reportedConnectorStatuses.contains(status))) {
    return unknown;
  }
  final checked = _parseUtcInstant(value['checked_at']);
  final observed = _parseUtcInstant(value['observed_at']);
  final until = _parseUtcInstant(value['valid_until']);
  if (checked == null ||
      observed == null ||
      until == null ||
      observed.isAfter(checked) ||
      until.difference(observed) != const Duration(seconds: 300) ||
      (serverState == 'stale' && until.isAfter(checked))) {
    return unknown;
  }
  final expired = until.difference(checked) <= elapsed;
  return (
    state: expired ? 'stale' : status as String,
    observedAt: value['observed_at'] as String,
  );
}

String connectorReportedState(Object? value, Duration elapsed) =>
    _observation(value, elapsed).state;

String connectorFreshnessText(Object? value, Duration elapsed, bool arabic) {
  final observation = _observation(value, elapsed);
  final state = connectorStatusLabel(observation.state, arabic);
  final observed =
      observation.observedAt ?? connectorStatusLabel('unknown', arabic);
  return arabic
      ? 'حالة مصدر المحاكاة: $state. التوفر الفعلي غير مؤكد. وقت الرصد: $observed'
      : 'Simulated source status: $state. Physical availability is unverified. Observed: $observed';
}
