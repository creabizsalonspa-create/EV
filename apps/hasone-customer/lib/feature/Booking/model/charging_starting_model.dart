class ChargingStartingModel {
  String? status;
  String? message;
  Data? data;

  ChargingStartingModel({this.status, this.message, this.data});

  ChargingStartingModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? new Data.fromJson(json['data']) : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['status'] = this.status;
    data['message'] = this.message;
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
    final battery = json['batteryPercent'];
    batteryPercent =
        hasSourceProof &&
            battery is num &&
            battery.isFinite &&
            battery >= 0 &&
            battery <= 100
        ? battery.toInt()
        : null;
    // No source-backed ETA contract exists. Never invent a countdown.
    timeRemaining = null;
    // This development client has no commissioned control adapter.
    canStop = false;
    canExtend = false;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['sessionId'] = this.sessionId;
    data['bookingId'] = this.bookingId;
    data['chargingStatus'] = this.chargingStatus;
    data['batteryPercent'] = this.batteryPercent;
    data['timeRemaining'] = this.timeRemaining;
    data['kwhUsed'] = this.kwhUsed;
    data['canStop'] = this.canStop;
    data['canExtend'] = this.canExtend;
    return data;
  }
}
