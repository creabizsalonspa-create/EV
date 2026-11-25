import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/charger_admission.dart' as admission;
import 'package:creabiz_ev/charging_operations.dart' as charging;
import 'package:creabiz_ev/financial_analytics.dart' as analytics;
import 'package:creabiz_ev/hosted_checkout.dart' as hosted;
import 'package:creabiz_ev/loyalty.dart' as loyalty;
import 'package:creabiz_ev/loyalty_actions.dart' as loyaltyActions;
import 'package:creabiz_ev/payment_cases.dart' as paymentCases;
import 'package:creabiz_ev/recurring_payments.dart' as recurring;
import 'package:creabiz_ev/refund_requests.dart' as refund;
import 'package:creabiz_ev/station_information.dart' as station;
import 'package:creabiz_ev/subscriptions.dart' as subscriptions;
import 'package:creabiz_ev/topology_strings.dart' as topology;
import 'package:creabiz_ev/vendor_finance.dart' as vendorFinance;
import 'package:creabiz_ev/vendor_operations_strings.dart' as vendorOps;

/// Full compiled-dictionary checks for explicitly localized customer/vendor
/// finance, station and charging screens. This test supplements EvStrings'
/// existing global dictionary audit, rather than counting a translated map as
/// acceptance of every page, hardware flow or physical device.
void main() {
  final dictionaries = <({
    String name,
    Map<String, String> en,
    Map<String, String> ar,
  })>[
    (name: 'charging admission', en: admission.admissionEn, ar: admission.admissionAr),
    (name: 'charging operations', en: charging.opsEn, ar: charging.opsAr),
    (name: 'financial analytics', en: analytics.analyticsEn, ar: analytics.analyticsAr),
    (name: 'hosted checkout', en: hosted.hostedEn, ar: hosted.hostedAr),
    (name: 'loyalty', en: loyalty.loyaltyEn, ar: loyalty.loyaltyAr),
    (name: 'loyalty actions', en: loyaltyActions.loyaltyActionEn, ar: loyaltyActions.loyaltyActionAr),
    (name: 'payment cases', en: paymentCases.caseEn, ar: paymentCases.caseAr),
    (name: 'recurring payments', en: recurring.recurringEn, ar: recurring.recurringAr),
    (name: 'refund requests', en: refund.refundEn, ar: refund.refundAr),
    (name: 'subscriptions', en: subscriptions.subscriptionEn, ar: subscriptions.subscriptionAr),
    (name: 'vendor finance', en: vendorFinance.vendorFinanceEn, ar: vendorFinance.vendorFinanceAr),
    (name: 'vendor operations', en: vendorOps.vendorOpsEn, ar: vendorOps.vendorOpsAr),
    (name: 'charger topology', en: topology.topologyEn, ar: topology.topologyAr),
  ];

  test('all thirteen native workflow dictionaries have exact EN/AR key parity', () {
    // Dynamic source keys are not included: this is a strict test of the
    // compiled, displayed const maps rather than guessed source text.
    var total = 0;
    for (final dictionary in dictionaries) {
      expect(dictionary.en, isNotEmpty, reason: dictionary.name);
      expect(dictionary.ar.keys.toSet(), dictionary.en.keys.toSet(),
          reason: 'Different English/Arabic keys in ${dictionary.name}');
      total += dictionary.en.length;
      for (final key in dictionary.en.keys) {
        expect(dictionary.en[key]!.trim(), isNotEmpty,
            reason: '${dictionary.name} EN $key');
        expect(dictionary.ar[key]!.trim(), isNotEmpty,
            reason: '${dictionary.name} AR $key');
      }
    }
    // Prevent silent deletion of most translated workflows while a small pair
    // of maps still satisfies a vacuous equality test.
    expect(total, greaterThanOrEqualTo(500));
  });

  test('named interpolation placeholders match on both languages', () {
    final variable = RegExp(r'\{([A-Za-z_][A-Za-z_0-9]*)\}');
    Set<String> variables(String value) => variable
        .allMatches(value)
        .map((match) => match.group(1)!)
        .toSet();

    for (final dictionary in dictionaries) {
      for (final key in dictionary.en.keys) {
        expect(variables(dictionary.ar[key]!), variables(dictionary.en[key]!),
            reason: 'Untranslated or incorrect placeholder in ${dictionary.name}.$key');
      }
    }
  });

  test('Arabic screens retain genuine Arabic copy, not an English key alias', () {
    final arabic = RegExp(r'[\u0600-\u06FF]');
    for (final dictionary in dictionaries) {
      final translated = dictionary.ar.values.where(arabic.hasMatch).length;
      expect(translated * 2, greaterThanOrEqualTo(dictionary.ar.length),
          reason: '${dictionary.name} largely lost its Arabic copy');
    }
  });

  test('station-week labels remain seven matched localized calendar days', () {
    expect(station.stationInfoDaysEn.length, 7);
    expect(station.stationInfoDaysAr.length, 7);
    expect(station.stationInfoDaysEn.toSet().length, 7);
    expect(station.stationInfoDaysAr.toSet().length, 7);
    expect(station.stationInfoDaysAr.every((value) => value.trim().isNotEmpty), isTrue);
  });
}
