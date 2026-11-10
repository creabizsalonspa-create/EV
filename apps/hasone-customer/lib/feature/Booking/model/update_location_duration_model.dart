class UpdateLocationDurationMOdel {
  String? status;
  String? message;
  Data? data;

  UpdateLocationDurationMOdel({this.status, this.message, this.data});

  UpdateLocationDurationMOdel.fromJson(Map<String, dynamic> json) {
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
  String? bookingId;
  double? userLat;
  double? userLng;
  String? lastLocationAt;
  int? distanceM;
  String? distanceBanner;
  int? durationMins;
  String? eta;
  double? stationLat;
  double? stationLng;
  String? stationName;
  String? stationImage;

  Data(
      {this.bookingId,
        this.userLat,
        this.userLng,
        this.lastLocationAt,
        this.distanceM,
        this.distanceBanner,
        this.durationMins,
        this.eta,
        this.stationLat,
        this.stationLng,
        this.stationName,
        this.stationImage});

  Data.fromJson(Map<String, dynamic> json) {
    bookingId = json['bookingId'];
    userLat = (json['userLat'] as num?)?.toDouble();
    userLng = (json['userLng'] as num?)?.toDouble();
    lastLocationAt = json['lastLocationAt'];
    distanceM = (json['distanceM'] as num?)?.toInt();
    distanceBanner = json['distanceBanner'];
    durationMins = (json['durationMins'] as num?)?.toInt();
    eta = json['eta'];
    stationLat = (json['stationLat'] as num?)?.toDouble();
    stationLng = (json['stationLng'] as num?)?.toDouble();
    stationName = json['stationName'];
    stationImage = json['stationImage'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['bookingId'] = this.bookingId;
    data['userLat'] = this.userLat;
    data['userLng'] = this.userLng;
    data['lastLocationAt'] = this.lastLocationAt;
    data['distanceM'] = this.distanceM;
    data['distanceBanner'] = this.distanceBanner;
    data['durationMins'] = this.durationMins;
    data['eta'] = this.eta;
    data['stationLat'] = this.stationLat;
    data['stationLng'] = this.stationLng;
    data['stationName'] = this.stationName;
    data['stationImage'] = this.stationImage;
    return data;
  }
}
