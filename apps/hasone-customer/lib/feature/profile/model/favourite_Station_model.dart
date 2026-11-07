class FavouriteStationModel {
  String? status;
  List<Data>? data;

  FavouriteStationModel({this.status, this.data});

  FavouriteStationModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    if (json['data'] != null) {
      data = <Data>[];
      json['data'].forEach((v) {
        data!.add(new Data.fromJson(v));
      });
    }
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['status'] = this.status;
    if (this.data != null) {
      data['data'] = this.data!.map((v) => v.toJson()).toList();
    }
    return data;
  }
}

class Data {
  String? sId;
  String? userId;
  String? stationId;
  String? stationName;
  String? address;
  String? image;
  String? pricePerHour;
  String? status;
  double? latitude;
  double? longitude;

  Data(
      {this.sId,
        this.userId,
        this.stationId,
        this.stationName,
        this.address,
        this.image,
        this.pricePerHour,
        this.status,
        this.latitude,
        this.longitude});

  Data.fromJson(Map<String, dynamic> json) {
    sId = json['_id'];
    userId = json['userId'];
    stationId = json['stationId'];
    stationName = json['stationName'];
    address = json['address'];
    image = json['image'];
    pricePerHour = json['pricePerHour'];
    status = json['status'];
    latitude = json['latitude']?.toDouble();
    longitude = json['longitude']?.toDouble();
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['_id'] = this.sId;
    data['userId'] = this.userId;
    data['stationId'] = this.stationId;
    data['stationName'] = this.stationName;
    data['address'] = this.address;
    data['image'] = this.image;
    data['pricePerHour'] = this.pricePerHour;
    data['status'] = this.status;
    data['latitude'] = this.latitude;
    data['longitude'] = this.longitude;
    return data;
  }
}
