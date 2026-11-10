class ChargingStationMOdel {
  String? status;
  String? message;
  Data? data;

  ChargingStationMOdel({this.status, this.message, this.data});

  ChargingStationMOdel.fromJson(Map<String, dynamic> json) {
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
  String? stationName;
  String? address;
  String? stationImage;
  String? slotLabel;
  String? connectorType;
  String? chargingDuration;
  String? sessionTime;
  String? status;
  bool? canScan;

  Data(
      {this.sessionId,
        this.bookingId,
        this.stationName,
        this.address,
        this.stationImage,
        this.slotLabel,
        this.connectorType,
        this.chargingDuration,
        this.sessionTime,
        this.status,
        this.canScan});

  Data.fromJson(Map<String, dynamic> json) {
    sessionId = json['sessionId'];
    bookingId = json['bookingId'];
    stationName = json['stationName'];
    address = json['address'];
    stationImage = json['stationImage'];
    slotLabel = json['slotLabel'];
    connectorType = json['connectorType'];
    chargingDuration = json['chargingDuration'];
    sessionTime = json['sessionTime'];
    status = json['status'];
    canScan = json['canScan'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['sessionId'] = this.sessionId;
    data['bookingId'] = this.bookingId;
    data['stationName'] = this.stationName;
    data['address'] = this.address;
    data['stationImage'] = this.stationImage;
    data['slotLabel'] = this.slotLabel;
    data['connectorType'] = this.connectorType;
    data['chargingDuration'] = this.chargingDuration;
    data['sessionTime'] = this.sessionTime;
    data['status'] = this.status;
    data['canScan'] = this.canScan;
    return data;
  }
}
