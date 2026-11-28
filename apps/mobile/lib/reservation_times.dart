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

/// Formats the station's existing instant without relying on device timezone.
/// Older rows and inconsistent display metadata fall back to UTC.
String reservationTime(
  BuildContext context,
  Map<String, dynamic> row,
  String field,
) {
  final raw = row[field];
  if (raw is! String ||
      !_wireUtc.hasMatch(raw) ||
      !_validCalendarClock(raw)) return '—';
  final instant = DateTime.tryParse(
    raw.endsWith('Z') || raw.endsWith('+00:00') ? raw : '${raw}Z',
  );
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

/// Server event timestamps carry an explicit offset, or use Laravel's UTC SQL
/// serialization. A timezone-less ISO wall clock is not an authoritative instant.
/// Normalize before display; never interpret source data in the device timezone.
DateTime? serverEventInstant(dynamic raw) {
  if (raw is! String || !_validCalendarClock(raw) || raw.startsWith('0000-')) return null;
  final sql = RegExp(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$').firstMatch(raw);
  final iso = RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$').firstMatch(raw);
  String wire;
  if (sql != null && sql.end == raw.length) {
    wire = raw.replaceFirst(' ', 'T') + 'Z';
  } else if (iso != null && iso.end == raw.length) {
    final offset = iso.group(1)!;
    if (offset != 'Z') {
      final hours = int.parse(offset.substring(1, 3));
      final minutes = int.parse(offset.substring(4, 6));
      if (hours > 14 || minutes > 59 || (hours == 14 && minutes != 0)) {
        return null;
      }
    }
    wire = raw;
  } else {
    return null;
  }
  return DateTime.tryParse(wire)?.toUtc();
}

String serverUtcTime(BuildContext context, dynamic raw) {
  final instant = serverEventInstant(raw);
  if (instant == null) return '—';
  return reservationTime(context, {'event': instant.toIso8601String()}, 'event');
}

/// Localized event clock with explicit source UTC, never raw malformed data.
String serverEventTime(BuildContext context, dynamic raw) {
  final value = serverUtcTime(context, raw);
  if (value != '—') return value;
  return Localizations.localeOf(context).languageCode == 'ar'
      ? 'وقت الحدث غير متاح' : 'Event time unavailable';
}
