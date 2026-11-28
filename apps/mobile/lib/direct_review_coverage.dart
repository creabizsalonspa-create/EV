import 'package:flutter/material.dart';

/// Absent on earlier servers; absence is unknown, never complete.
Map<String, dynamic>? validateDirectReviewCoverage(Map reply) {
  if (!reply.containsKey('review_coverage')) return null;
  final value = reply['review_coverage'];
  if (value is! Map ||
      value['evidence'] != 'DIRECT_CONNECTOR_REVIEW_COVERAGE_ONLY' ||
      !['requires_review', 'all_identities_reviewed'].contains(value['state']) ||
      ['charging_mapping_created', 'physical_availability_verified',
        'remote_control_authorized'].any((key) => value[key] != false) ||
      !['inventory_count', 'source_target_count', 'current_review_count'].every(
        (key) => value[key] is int && value[key] >= 0 && value[key] <= 101) ||
      reply['connectors'] is! List ||
      value['source_target_count'] != reply['connectors'].length ||
      value['current_review_count'] > value['inventory_count'] ||
      value['current_review_count'] > value['source_target_count'] ||
      (value['state'] == 'all_identities_reviewed' &&
        (reply['source_online'] != true || reply['source_topology_complete'] != true ||
          value['inventory_count'] < 1 || value['inventory_count'] > 100 ||
          value['inventory_count'] != value['source_target_count'] ||
          value['inventory_count'] != value['current_review_count'] ||
          (reply['connectors'] as List).any((row) => row is! Map || row['state'] != 'recent')))) {
    throw const FormatException('Invalid direct connector review coverage');
  }
  return Map<String, dynamic>.from(value);
}

class DirectReviewCoverage extends StatelessWidget {
  final Map reply;
  const DirectReviewCoverage({super.key, required this.reply});

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final value = validateDirectReviewCoverage(reply);
    if (value == null) {
      return Text(ar ? 'تغطية مراجعة الموصلات غير معروفة' : 'Connector review coverage unknown');
    }
    final complete = value['state'] == 'all_identities_reviewed';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(complete
          ? (ar ? 'تمت مراجعة جميع هويات الموصلات؛ الشحن غير مصرح به'
                : 'All connector identities reviewed; charging not authorized')
          : (ar ? 'مراجعة هويات الموصلات غير مكتملة' : 'Connector identity review incomplete')),
        Text('${value['current_review_count']}/${value['inventory_count']}'),
      ],
    );
  }
}
