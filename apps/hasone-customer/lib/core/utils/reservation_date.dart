/// Serialize the selected calendar day without a locale label or UTC conversion.
String reservationDay(DateTime day) {
  if (day.year < 1 || day.year > 9999) {
    throw ArgumentError('Unsupported reservation year');
  }
  return '${day.year.toString().padLeft(4, '0')}-'
      '${day.month.toString().padLeft(2, '0')}-'
      '${day.day.toString().padLeft(2, '0')}';
}

bool isReservationDay(String value) {
  if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value)) return false;
  final parsed = DateTime.tryParse('${value}T12:00:00Z');
  return parsed != null && parsed.year >= 1 && reservationDay(parsed) == value;
}
