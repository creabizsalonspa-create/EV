class BookNavigationModel {
  String? status;
  String? message;
  Data? data;

  BookNavigationModel({this.status, this.message, this.data});

  BookNavigationModel.fromJson(Map<String, dynamic> json) {
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
  String? directionText;
  String? distanceBanner;
  String? stationName;
  String? stationImage;
  double? latitude;
  double? longitude;
  int? durationMins;
  double? distanceKm;
  String? arrivalTime;
  bool? hasArrived;

  Data(
      {this.bookingId,
        this.directionText,
        this.distanceBanner,
        this.stationName,
        this.stationImage,
        this.latitude,
        this.longitude,
        this.durationMins,
        this.distanceKm,
        this.arrivalTime,
        this.hasArrived});

  Data.fromJson(Map<String, dynamic> json) {
    bookingId = json['bookingId'];
    directionText = json['directionText'];
    distanceBanner = json['distanceBanner'];
    stationName = json['stationName'];
    stationImage = json['stationImage'];
    latitude = (json['latitude'] as num?)?.toDouble();
    longitude = (json['longitude'] as num?)?.toDouble();
    durationMins = (json['durationMins'] as num?)?.toInt();
    distanceKm = (json['distanceKm'] as num?)?.toDouble();
    arrivalTime = json['arrivalTime'];
    hasArrived = json['hasArrived'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['bookingId'] = this.bookingId;
    data['directionText'] = this.directionText;
    data['distanceBanner'] = this.distanceBanner;
    data['stationName'] = this.stationName;
    data['stationImage'] = this.stationImage;
    data['latitude'] = this.latitude;
    data['longitude'] = this.longitude;
    data['durationMins'] = this.durationMins;
    data['distanceKm'] = this.distanceKm;
    data['arrivalTime'] = this.arrivalTime;
    data['hasArrived'] = this.hasArrived;
    return data;
  }
}
