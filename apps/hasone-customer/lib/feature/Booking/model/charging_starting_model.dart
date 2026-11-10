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

  Data(
      {this.sessionId,
        this.bookingId,
        this.chargingStatus,
        this.batteryPercent,
        this.timeRemaining,
        this.kwhUsed,
        this.canStop,
        this.canExtend});

  Data.fromJson(Map<String, dynamic> json) {
    sessionId = json['sessionId'];
    bookingId = json['bookingId'];
    chargingStatus = json['chargingStatus'];
    batteryPercent = (json['batteryPercent'] is num) ? (json['batteryPercent'] as num).toInt() : null;
    timeRemaining = json['timeRemaining'];
    kwhUsed = (json['kwhUsed'] is num) ? (json['kwhUsed'] as num).toDouble() : null;
    canStop = json['canStop'];
    canExtend = json['canExtend'];
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
