import 'package:flutter/material.dart';

final _wireLocal = RegExp(
  r'^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})([+-]\d{2}:\d{2})$',
);
final _zone = RegExp(r'^(?:UTC|[A-Za-z][A-Za-z0-9_+-]*(?:/[A-Za-z0-9_+-]+)+)$');
final _wireUtc = RegExp(
  r'^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)?$',
);

/// Formats the station's existing instant without relying on device timezone.
/// Older rows and inconsistent display metadata fall back to UTC.
String reservationTime(
  BuildContext context,
  Map<String, dynamic> row,
  String field,
) {
  final raw = row[field];
  if (raw is! String || !_wireUtc.hasMatch(raw)) return '—';
  final instant = DateTime.tryParse(
    raw.endsWith('Z') || raw.endsWith('+00:00') ? raw : '${raw}Z',
  );
  if (instant == null) return '—';

  var display = instant;
  var zoneLabel = 'UTC';
  final zone = row['station_timezone'];
  final times = row['station_times'];
  final local = times is Map ? times[field] : null;
  if (zone is String && _zone.hasMatch(zone) && local is String) {
    final match = _wireLocal.firstMatch(local);
    final sourceInstant = match == null ? null : DateTime.tryParse(local);
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
