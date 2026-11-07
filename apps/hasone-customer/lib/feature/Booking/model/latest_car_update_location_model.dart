class LatestCarUpdateLocationMOdel {
  String? status;
  String? message;
  Data? data;

  LatestCarUpdateLocationMOdel({this.status, this.message, this.data});

  LatestCarUpdateLocationMOdel.fromJson(Map<String, dynamic> json) {
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
  bool? hasArrived;
  double? stationLat;
  double? stationLng;
  String? stationName;
  String? stationImage;

  Data(
      {this.bookingId,
        this.userLat,
        this.userLng,
        this.lastLocationAt,
        this.hasArrived,
        this.stationLat,
        this.stationLng,
        this.stationName,
        this.stationImage});

  Data.fromJson(Map<String, dynamic> json) {
    bookingId = json['bookingId'];
    userLat = (json['userLat'] as num?)?.toDouble();
    userLng = (json['userLng'] as num?)?.toDouble();
    lastLocationAt = json['lastLocationAt'];
    hasArrived = json['hasArrived'];
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
    data['hasArrived'] = this.hasArrived;
    data['stationLat'] = this.stationLat;
    data['stationLng'] = this.stationLng;
    data['stationName'] = this.stationName;
    data['stationImage'] = this.stationImage;
    return data;
  }
}
