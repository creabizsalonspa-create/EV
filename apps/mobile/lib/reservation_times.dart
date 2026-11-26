import 'package:flutter/material.dart';

final _wireLocal = RegExp(
  r'^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})([+-]\d{2}:\d{2})$',
);
final _zone = RegExp(r'^(?:UTC|[A-Za-z][A-Za-z0-9_+-]*(?:/[A-Za-z0-9_+-]+)+)$');
final _wireUtc = RegExp(
  r'^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)?$',
);

final _calendarClock = RegExp(
  r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})',
);

/// Dart's DateTime.parse may normalize an impossible date (February 30)
/// to a different day. Reject it before any station-local presentation.
bool _validCalendarClock(String raw) {
  final match = _calendarClock.firstMatch(raw);
  if (match == null) return false;
  final fields = [
    for (var n = 1; n <= 6; n++) int.parse(match.group(n)!),
  ];
  final calendar = DateTime.utc(
    fields[0], fields[1], fields[2],
    fields[3], fields[4], fields[5],
  );
  return calendar.year == fields[0] &&
      calendar.month == fields[1] &&
      calendar.day == fields[2] &&
      calendar.hour == fields[3] &&
      calendar.minute == fields[4] &&
      calendar.second == fields[5];
}

bool _validStationClock(String raw) {
  final match = _wireLocal.firstMatch(raw);
  if (match == null || !_validCalendarClock(raw)) return false;
  final offset = match.group(2)!;
  final hours = int.parse(offset.substring(1, 3));
  final minutes = int.parse(offset.substring(4, 6));
  return hours <= 14 && minutes <= 59 &&
      (hours != 14 || minutes == 0);
}

/// Parse the API's UTC timestamps without calendar rollover or device timezone.
DateTime? parseServerUtc(Object? raw) {
  if (raw is! String || !_wireUtc.hasMatch(raw) ||
      !_validCalendarClock(raw) || raw.startsWith('0000')) return null;
  return DateTime.tryParse(
    raw.endsWith('Z') || raw.endsWith('+00:00') ? raw : '${raw}Z',
  )?.toUtc();
}

/// Financial history accepts explicit UTC or the SQL UTC wire format.
/// An offsetless ISO T timestamp is not established financial evidence.
DateTime? invoiceUtcInstant(Object? raw) {
  if (raw is! String || (raw.contains('T') &&
      !raw.endsWith('Z') && !raw.endsWith('+00:00'))) return null;
  return parseServerUtc(raw);
}

String invoiceUtcTime(BuildContext context, Object? raw) {
  final instant = invoiceUtcInstant(raw);
  if (instant == null) {
    return Localizations.localeOf(context).languageCode == 'ar'
        ? 'وقت المعاملة غير متاح' : 'Transaction time unavailable';
  }
  return reservationTime(context, {'time': instant.toIso8601String()}, 'time');
}

/// Formats the station's existing instant without relying on device timezone.
/// Older rows and inconsistent display metadata fall back to UTC.
String reservationTime(
  BuildContext context,
  Map<String, dynamic> row,
  String field,
) {
  final instant = parseServerUtc(row[field]);
  if (instant == null) return '—';

  var display = instant;
  var zoneLabel = 'UTC';
  final zone = row['station_timezone'];
  final times = row['station_times'];
  final local = times is Map ? times[field] : null;
  if (zone is String && _zone.hasMatch(zone) &&
      local is String && _validStationClock(local)) {
    final match = _wireLocal.firstMatch(local);
    final sourceInstant = DateTime.tryParse(local);
    if (sourceInstant != null &&
        sourceInstant.difference(instant).abs() < const Duration(seconds: 1)) {
      // Interpret only the station wall-clock components after independently
      // checking that the complete offset timestamp describes the same instant.
      display = DateTime.parse('${match![1]}Z');
      zoneLabel = '$zone (UTC${match[2]})';
    }
  }

  final localizations = MaterialLocalizations.of(context);
  final date = localizations.formatCompactDate(display);
  final time = localizations.formatTimeOfDay(
    TimeOfDay.fromDateTime(display),
    alwaysUse24HourFormat: MediaQuery.alwaysUse24HourFormatOf(context),
  );
  return '$date · $time · \u2068$zoneLabel\u2069';
}
