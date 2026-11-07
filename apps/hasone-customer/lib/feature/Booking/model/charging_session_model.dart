class ChargingSessionModel {
  String? status;
  String? message;
  Data? data;

  ChargingSessionModel({this.status, this.message, this.data});

  ChargingSessionModel.fromJson(Map<String, dynamic> json) {
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
  String? popupTitle;
  int? batteryPercent;
  double? kwhUsedSoFar;
  String? confirmMessage;
  String? chargingDuration;
  int? timeRemainingMs;
  String? startedAt;

  Data(
      {this.sessionId,
        this.bookingId,
        this.popupTitle,
        this.batteryPercent,
        this.kwhUsedSoFar,
        this.confirmMessage,
        this.chargingDuration,
        this.timeRemainingMs,
        this.startedAt});

  Data.fromJson(Map<String, dynamic> json) {
    sessionId = json['sessionId'];
    bookingId = json['bookingId'];
    popupTitle = json['popupTitle'];
    batteryPercent = (json['batteryPercent'] is num) ? (json['batteryPercent'] as num).toInt() : null;
    kwhUsedSoFar = (json['kwhUsedSoFar'] is num) ? (json['kwhUsedSoFar'] as num).toDouble() : null;
    confirmMessage = json['confirmMessage'];
    chargingDuration = json['chargingDuration'];
    timeRemainingMs = (json['timeRemainingMs'] is num) ? (json['timeRemainingMs'] as num).toInt() : null;
    startedAt = json['startedAt'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['sessionId'] = this.sessionId;
    data['bookingId'] = this.bookingId;
    data['popupTitle'] = this.popupTitle;
    data['batteryPercent'] = this.batteryPercent;
    data['kwhUsedSoFar'] = this.kwhUsedSoFar;
    data['confirmMessage'] = this.confirmMessage;
    data['chargingDuration'] = this.chargingDuration;
    data['timeRemainingMs'] = this.timeRemainingMs;
    data['startedAt'] = this.startedAt;
    return data;
  }
}
