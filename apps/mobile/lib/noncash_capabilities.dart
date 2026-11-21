/// Server-issued noncash capabilities and immutable receipt provenance only.
/// No eligibility, points, money or fulfillment calculation belongs here.
class NoncashCapabilities {
  final bool enabled, pointsActionsEnabled, referralsEnabled;
  final String mode;
  const NoncashCapabilities._(
    this.enabled,
    this.mode,
    this.pointsActionsEnabled,
    this.referralsEnabled,
  );
  factory NoncashCapabilities.parse(dynamic value) {
    if (value is! Map ||
        value['enabled'] is! bool ||
        value['points_actions_enabled'] is! bool ||
        value['referrals_enabled'] is! bool ||
        value['verified_live_source_required'] is! bool ||
        ![
          'disabled',
          'sandbox',
          'reviewed_manual',
        ].contains(value['activation_mode']) ||
        value['enabled'] != (value['activation_mode'] != 'disabled') ||
        value['money_movement'] != false ||
        value['fulfillment'] != 'MANUAL_NON_MONETARY' ||
        value['delivery_verified'] != false ||
        value['activation_mode'] != 'sandbox' &&
            value['points_actions_enabled'] != false ||
        !value['enabled'] && value['referrals_enabled'])
      throw const FormatException('Unsupported noncash capability.');
    return NoncashCapabilities._(
      value['enabled'],
      value['activation_mode'],
      value['points_actions_enabled'],
      value['referrals_enabled'],
    );
  }
}

class NoncashReceiptEvidence {
  final String mode;
  final bool recorded;
  const NoncashReceiptEvidence._(this.mode, this.recorded);
  factory NoncashReceiptEvidence.parse(Map value) {
    final legacy =
        !value.containsKey('activation_mode') &&
        !value.containsKey('activation_recorded');
    final mode = legacy ? 'sandbox' : value['activation_mode'];
    final recorded = legacy ? false : value['activation_recorded'];
    if (!(mode == 'sandbox' &&
            recorded is bool &&
            value['evidence'] == 'TEST_PROMOTIONAL_ECONOMICS' ||
        mode == 'reviewed_manual' &&
            recorded == true &&
            value['evidence'] == 'REVIEWED_MANUAL_NONCASH'))
      throw const FormatException('Unsupported noncash provenance.');
    return NoncashReceiptEvidence._(mode, recorded);
  }
}
