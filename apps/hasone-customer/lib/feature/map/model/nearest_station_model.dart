class NearStationModel {
  String? status;
  String? message;
  List<Data>? data;

  NearStationModel({this.status, this.message, this.data});

  NearStationModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
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
    data['message'] = this.message;
    if (this.data != null) {
      data['data'] = this.data!.map((v) => v.toJson()).toList();
    }
    return data;
  }
}

class Data {
  String? sId;
  String? name;
  String? address;
  List<String>? images;
  String? status;
  String? availableIn;
  double? distanceKm;
  int? durationMins;
  double? rating;
  int? reviewCount;
  String? pricePerHour;
  double? pricePerHourValue;
  double? taxPercent;
  double? latitude;
  double? longitude;
  String? about;

  Data(
      {this.sId,
        this.name,
        this.address,
        this.images,
        this.status,
        this.availableIn,
        this.distanceKm,
        this.durationMins,
        this.rating,
        this.reviewCount,
        this.pricePerHour,
        this.pricePerHourValue,
        this.taxPercent,
        this.latitude,
        this.longitude,
        this.about});

  Data.fromJson(Map<String, dynamic> json) {
    sId = json['_id'];
    name = json['name'];
    address = json['address'];
    images = json['images'] != null ? json['images'].cast<String>() : null;
    status = json['status'];
    availableIn = json['availableIn'];
    distanceKm = (json['distanceKm'] as num?)?.toDouble();
    durationMins = (json['durationMins'] as num?)?.toInt();
    rating = (json['rating'] as num?)?.toDouble();
    reviewCount = (json['reviewCount'] as num?)?.toInt();
    pricePerHour = json['pricePerHour']?.toString();
    pricePerHourValue = (json['pricePerHourValue'] as num?)?.toDouble();
    taxPercent = (json['taxPercent'] as num?)?.toDouble();
    latitude = (json['latitude'] as num?)?.toDouble();
    longitude = (json['longitude'] as num?)?.toDouble();
    about = json['about'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['_id'] = this.sId;
    data['name'] = this.name;
    data['address'] = this.address;
    data['images'] = this.images;
    data['status'] = this.status;
    data['availableIn'] = this.availableIn;
    data['distanceKm'] = this.distanceKm;
    data['durationMins'] = this.durationMins;
    data['rating'] = this.rating;
    data['reviewCount'] = this.reviewCount;
    data['pricePerHour'] = this.pricePerHour;
    data['pricePerHourValue'] = this.pricePerHourValue;
    data['taxPercent'] = this.taxPercent;
    data['latitude'] = this.latitude;
    data['longitude'] = this.longitude;
    data['about'] = this.about;
    return data;
  }
}
