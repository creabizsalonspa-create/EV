class ChargingStartingModel {
  String? status;
  String? message;
  Data? data;

  ChargingStartingModel({this.status, this.message, this.data});

  ChargingStartingModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? Data.fromJson(json['data']) : null;
  }

  Map<String, dynamic> toJson() {
    final data = <String, dynamic>{};
    data['status'] = status;
    data['message'] = message;
    if (this.data != null) {
      data['data'] = this.data!.toJson();
    }
    return data;
  }
}

class Data {
  String? sessionId;
  String? bookingId;
  String? chargingStatus;
  int? batteryPercent;
  String? timeRemaining;
  double? kwhUsed;
  bool? canStop;
  bool? canExtend;
  bool sourceVerified = false;
  bool meterVerified = false;
  String? source;
  String? protocol;
  String? paymentStatus;
  int? energyWh;
  int? provisionalCostFils, finalCostFils, meterReceiptAgeSeconds;
  DateTime? meterReceivedAt;
  String meterFreshness = 'unknown';
  String? sourceEndReason;
  bool sourceEndNeedsReview = false;
  bool get hasSourceProof =>
      sourceVerified &&
      source == 'ocpp' &&
      ['ocpp1.6', 'ocpp2.0.1'].contains(protocol);
  bool get hasVerifiedMeter =>
      hasSourceProof && meterVerified && energyWh != null && kwhUsed != null;

  Data({
    this.sessionId,
    this.bookingId,
    this.chargingStatus,
    this.batteryPercent,
    this.timeRemaining,
    this.kwhUsed,
    this.canStop,
    this.canExtend,
  });

  Data.fromJson(Map<String, dynamic> json) {
    sessionId = json['sessionId'];
    bookingId = json['bookingId'];
    sourceVerified = json['sourceVerified'] == true;
    meterVerified = json['meterVerified'] == true;
    source = json['source'] is String ? json['source'] : null;
    protocol = json['protocol'] is String ? json['protocol'] : null;
    paymentStatus = json['paymentStatus'] is String
        ? json['paymentStatus']
        : null;
    chargingStatus = hasSourceProof && json['chargingStatus'] is String
        ? json['chargingStatus']
        : null;
    final wh = json['energyWh'];
    final kwh = json['kwhUsed'];
    if (hasSourceProof &&
        meterVerified &&
        wh is int &&
        wh >= 0 &&
        kwh is num &&
        kwh.isFinite &&
        kwh >= 0 &&
        (kwh * 1000 - wh).abs() < 0.0001) {
      energyWh = wh;
      kwhUsed = kwh.toDouble();
    }
    if (hasSourceProof && chargingStatus == 'Completed') {
      // An ended transaction is not proof that the intended charge succeeded.
      sourceEndNeedsReview = true;
      const reasons = {
        'EmergencyStop',
        'EVDisconnected',
        'HardReset',
        'Local',
        'Other',
        'PowerLoss',
        'Reboot',
        'Remote',
        'SoftReset',
        'UnlockCommand',
        'DeAuthorized',
        'EnergyLimitReached',
        'GroundFault',
        'ImmediateReset',
        'LocalOutOfCredit',
        'OvercurrentFault',
        'PowerQuality',
        'SOCLimitReached',
        'StoppedByEV',
        'TimeLimitReached',
        'Timeout',
      };
      const review = {
        'EmergencyStop',
        'HardReset',
        'PowerLoss',
        'Reboot',
        'SoftReset',
        'GroundFault',
        'ImmediateReset',
        'OvercurrentFault',
        'PowerQuality',
        'Timeout',
        'Other',
      };
      final end = json['sourceEnd'];
      if (json['readOnly'] == true &&
          end is Map &&
          end['reason'] is String &&
          reasons.contains(end['reason']) &&
          end['reviewRequired'] is bool &&
          end['reviewRequired'] == review.contains(end['reason'])) {
        sourceEndReason = end['reason'];
        sourceEndNeedsReview = review.contains(sourceEndReason);
      }
    }
    final readOnlyCost =
        hasVerifiedMeter &&
        json['readOnly'] == true &&
        json['billingMode'] == 'calculation_only_no_collection' &&
        json['currency'] == 'AED' &&
        paymentStatus == 'not_collected';
    int? checkedAmount(String subtotalKey, String vatKey, String totalKey) {
      final subtotal = json[subtotalKey],
          vat = json[vatKey],
          total = json[totalKey];
      bool minor(Object? value) =>
          value is int && value >= 0 && value <= 1000000000000;
      return minor(subtotal) &&
              minor(vat) &&
              minor(total) &&
              subtotal + vat == total
          ? total as int
          : null;
    }

    if (readOnlyCost &&
        chargingStatus == 'Charging' &&
        json['billingStatus'] == 'provisional_unpaid' &&
        json['provisionalCostVerified'] == true) {
      provisionalCostFils = checkedAmount(
        'provisionalSubtotalFils',
        'provisionalVatFils',
        'provisionalTotalFils',
      );
    }
    if (readOnlyCost &&
        chargingStatus == 'Completed' &&
        json['billingStatus'] == 'calculated_unpaid') {
      finalCostFils = checkedAmount('subtotalFils', 'vatFils', 'totalFils');
    }
    final received = json['meterReceivedAt'],
        age = json['meterReceiptAgeSeconds'];
    if (hasVerifiedMeter &&
        received is String &&
        RegExp(
          r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$',
        ).hasMatch(received) &&
        age is int &&
        age >= 0 &&
        age <= 3153600000) {
      final time = DateTime.tryParse(received);
      final freshness = age <= 120 ? 'recent' : 'stale';
      if (time != null &&
          time.toUtc().toIso8601String().substring(0, 19) ==
              received.substring(0, 19) &&
          !time.isAfter(
            DateTime.now().toUtc().add(const Duration(seconds: 5)),
          ) &&
          json['meterFreshness'] == freshness) {
        meterReceivedAt = time.toUtc();
        meterReceiptAgeSeconds = age;
        meterFreshness = freshness;
      }
    }
    // OCPP transaction/meter proof does not establish the EV battery's SOC.
    // The staged status contract has no independent authenticated SOC source.
    // Ignore even plausible numeric batteryPercent values until such a
    // provenance-verified contract is implemented; do not display an estimate.
    batteryPercent = null;
    // No source-backed ETA contract exists. Never invent a countdown.
    timeRemaining = null;
    // This development client has no commissioned control adapter.
    canStop = false;
    canExtend = false;
  }

  Map<String, dynamic> toJson() {
    final data = <String, dynamic>{};
    data['sessionId'] = sessionId;
    data['bookingId'] = bookingId;
    data['chargingStatus'] = chargingStatus;
    data['batteryPercent'] = batteryPercent;
    data['timeRemaining'] = timeRemaining;
    data['kwhUsed'] = kwhUsed;
    data['canStop'] = canStop;
    data['canExtend'] = canExtend;
    return data;
  }
}
