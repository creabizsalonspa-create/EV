class NotificationModel {
  String? status;
  Data? data;

  NotificationModel({this.status, this.data});

  NotificationModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    data = json['data'] != null ? new Data.fromJson(json['data']) : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['status'] = this.status;
    if (this.data != null) {
      data['data'] = this.data!.toJson();
    }
    return data;
  }
}

class Data {
  bool? chargingStatusAlerts;
  bool? lowBatteryAlerts;
  bool? bookingUpdates;
  bool? stationUpdates;
  bool? paymentAndSession;

  Data(
      {this.chargingStatusAlerts,
        this.lowBatteryAlerts,
        this.bookingUpdates,
        this.stationUpdates,
        this.paymentAndSession});

  Data.fromJson(Map<String, dynamic> json) {
    chargingStatusAlerts = json['chargingStatusAlerts'];
    lowBatteryAlerts = json['lowBatteryAlerts'];
    bookingUpdates = json['bookingUpdates'];
    stationUpdates = json['stationUpdates'];
    paymentAndSession = json['paymentAndSession'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['chargingStatusAlerts'] = this.chargingStatusAlerts;
    data['lowBatteryAlerts'] = this.lowBatteryAlerts;
    data['bookingUpdates'] = this.bookingUpdates;
    data['stationUpdates'] = this.stationUpdates;
    data['paymentAndSession'] = this.paymentAndSession;
    return data;
  }
}
