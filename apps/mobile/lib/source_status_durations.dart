import 'package:flutter/material.dart';

List<Map<String, dynamic>> sourceDurationComponents(dynamic value) {
  if (value is! Map ||
      value['basis'] != 'BOUNDED_SIMULATED_STATUS_OBSERVATIONS' ||
      value['physical_uptime_verified'] != false ||
      value['window_seconds'] != 86400 ||
      value['max_observation_hold_seconds'] != 300 ||
      value['components'] is! List ||
      (value['components'] as List).length > 64 ||
      ![
        'observed',
        'not_observed',
        'requires_review',
      ].contains(value['state'])) {
    throw const FormatException('Unsupported duration evidence.');
  }
  return (value['components'] as List).map((dynamic component) {
    final row = Map<String, dynamic>.from(component as Map);
    final durations = row['observed_status_seconds'];
    if (row['evse_number'] is! int ||
        row['connector_number'] is! int ||
        row['unknown_seconds'] is! int ||
        row['unknown_seconds'] < 0 ||
        durations is! Map ||
        durations.values.any((v) => v is! int || v < 0 || v > 86400) ||
        durations.values.fold<int>(0, (total, v) => total + (v as int)) +
                row['unknown_seconds'] !=
            86400) {
      throw const FormatException('Invalid duration accounting.');
    }
    return row;
  }).toList();
}

class SourceStatusDurationsView extends StatelessWidget {
  final dynamic report;
  const SourceStatusDurationsView({super.key, required this.report});
  @override
  Widget build(BuildContext context) {
    String t(String en, String ar) =>
        Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
    List<Map<String, dynamic>> components;
    try {
      components = sourceDurationComponents(report);
    } catch (_) {
      return Text(
        t(
          'Status duration analysis requires review',
          'يتطلب تحليل مدد الحالات مراجعة',
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          t(
            'Observed status durations over 24 hours',
            'مدد الحالات المرصودة خلال 24 ساعة',
          ),
        ),
        Text(
          t(
            'Each observation covers at most five minutes. Gaps are unknown; this is not physical uptime or live availability.',
            'تغطي كل ملاحظة خمس دقائق كحد أقصى. الفجوات مجهولة؛ ليست هذه مدة تشغيل فعلية أو إتاحة مباشرة.',
          ),
        ),
        Text('${report['window_started_at']} — ${report['window_ended_at']}'),
        if (report['state'] == 'requires_review')
          Text(
            t(
              'Status duration analysis requires review',
              'يتطلب تحليل مدد الحالات مراجعة',
            ),
          ),
        if (report['state'] == 'not_observed')
          Text(
            t(
              'Status duration history not observed',
              'لم يُرصد سجل مدد الحالات',
            ),
          ),
        for (final component in components) ...[
          Text(
            'EVSE ${component['evse_number']} / ${component['connector_number']}',
          ),
          Text(t('Observed status seconds', 'ثواني الحالات المرصودة')),
          for (final entry
              in (component['observed_status_seconds'] as Map).entries)
            Text('${entry.key}: ${entry.value}'),
          Text(
            '${t('Unknown seconds', 'ثوانٍ مجهولة')}: ${component['unknown_seconds']}',
          ),
        ],
      ],
    );
  }
}
