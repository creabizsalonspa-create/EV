class StationDetailsModel {
  String? status;
  String? message;
  StationData? data;

  StationDetailsModel({this.status, this.message, this.data});

  StationDetailsModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? StationData.fromJson(json['data']) : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = {};
    data['status'] = status;
    data['message'] = message;
    if (this.data != null) {
      data['data'] = this.data!.toJson();
    }
    return data;
  }
}

class StationData {
  String? sId;
  String? name;
  String? address;
  List<String>? images;
  String? status;
  String? availableIn;
  double? distanceKm;
  String? distanceText;
  int? durationMins;
  String? durationText;
  double? rating;
  int? reviewCount;
  String? pricePerHour;
  double? pricePerHourValue;
  double? taxPercent;
  double? latitude;
  double? longitude;
  String? about;
  List<Amenities>? amenities;
  List<String>? availableDates;
  String? selectedDate;
  List<Slots>? slots;
  String? qrToken;
  String? qrCode;
  List<Review>? reviews;
  String? currencyCode;
  String? currencySymbol;
  String? currencyIcon;

  StationData({
    this.sId,
    this.name,
    this.address,
    this.images,
    this.status,
    this.availableIn,
    this.distanceKm,
    this.distanceText,
    this.durationMins,
    this.durationText,
    this.rating,
    this.reviewCount,
    this.pricePerHour,
    this.pricePerHourValue,
    this.taxPercent,
    this.latitude,
    this.longitude,
    this.about,
    this.amenities,
    this.availableDates,
    this.selectedDate,
    this.slots,
    this.qrToken,
    this.qrCode,
    this.reviews,
    this.currencyCode,
    this.currencySymbol,
    this.currencyIcon,
  });

  StationData.fromJson(Map<String, dynamic> json) {
    sId = json['_id'];
    name = json['name'];
    address = json['address'];
    images = (json['images'] as List?)?.cast<String>();
    status = json['status'];
    availableIn = json['availableIn']?.toString();
    distanceKm = (json['distanceKm'] as num?)?.toDouble();
    distanceText = json['distanceText']?.toString();
    durationMins = (json['durationMins'] as num?)?.toInt();
    durationText = json['durationText']?.toString();
    rating = (json['rating'] as num?)?.toDouble();
    reviewCount = (json['reviewCount'] as num?)?.toInt();
    pricePerHour = json['pricePerHour']?.toString();
    pricePerHourValue = (json['pricePerHourValue'] as num?)?.toDouble();
    taxPercent = (json['taxPercent'] as num?)?.toDouble();
    latitude = (json['latitude'] as num?)?.toDouble();
    longitude = (json['longitude'] as num?)?.toDouble();
    about = json['about'];
    if (json['amenities'] != null) {
      amenities = <Amenities>[];
      json['amenities'].forEach((v) {
        amenities!.add(Amenities.fromJson(v));
      });
    }
    availableDates = (json['availableDates'] as List?)?.cast<String>();
    selectedDate = json['selectedDate']?.toString();
    if (json['slots'] != null) {
      slots = <Slots>[];
      json['slots'].forEach((v) {
        slots!.add(Slots.fromJson(v));
      });
    }
    qrToken = json['qrToken']?.toString();
    qrCode = json['qrCode']?.toString();
    if (json['reviews'] != null) {
      reviews = <Review>[];
      json['reviews'].forEach((v) {
        reviews!.add(Review.fromJson(v));
      });
    }
    currencyCode = json['currencyCode'];
    currencySymbol = json['currencySymbol'];
    currencyIcon = json['currencyIcon'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = {};
    data['_id'] = sId;
    data['name'] = name;
    data['address'] = address;
    data['images'] = images;
    data['status'] = status;
    data['availableIn'] = availableIn;
    data['distanceKm'] = distanceKm;
    data['distanceText'] = distanceText;
    data['durationMins'] = durationMins;
    data['durationText'] = durationText;
    data['rating'] = rating;
    data['reviewCount'] = reviewCount;
    data['pricePerHour'] = pricePerHour;
    data['pricePerHourValue'] = pricePerHourValue;
    data['taxPercent'] = taxPercent;
    data['latitude'] = latitude;
    data['longitude'] = longitude;
    data['about'] = about;
    if (amenities != null) {
      data['amenities'] = amenities!.map((v) => v.toJson()).toList();
    }
    data['availableDates'] = availableDates;
    data['selectedDate'] = selectedDate;
    if (slots != null) {
      data['slots'] = slots!.map((v) => v.toJson()).toList();
    }
    data['qrToken'] = qrToken;
    data['qrCode'] = qrCode;
    if (reviews != null) {
      data['reviews'] = reviews!.map((v) => v.toJson()).toList();
    }
    data['currencyCode'] = currencyCode;
    data['currencySymbol'] = currencySymbol;
    data['currencyIcon'] = currencyIcon;
    return data;
  }
}

class Amenities {
  String? label;
  String? icon;

  Amenities({this.label, this.icon});

  Amenities.fromJson(Map<String, dynamic> json) {
    label = json['label'];
    icon = json['icon'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['label'] = this.label;
    data['icon'] = this.icon;
    return data;
  }
}

class Slots {
  String? startTime;
  String? endTime;
  bool? isBooked;

  Slots({this.startTime, this.endTime, this.isBooked});

  Slots.fromJson(Map<String, dynamic> json) {
    startTime = json['startTime'];
    endTime = json['endTime'];
    isBooked = json['isBooked'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['startTime'] = this.startTime;
    data['endTime'] = this.endTime;
    data['isBooked'] = this.isBooked;
    return data;
  }
}

class Review {
  String? reviewId;
  int? rating;
  String? description;
  String? userName;
  String? createdAt;

  Review({this.reviewId, this.rating, this.description, this.userName, this.createdAt});

  Review.fromJson(Map<String, dynamic> json) {
    reviewId = json['reviewId'];
    rating = (json['rating'] as num?)?.toInt();
    description = json['description'];
    userName = json['userName'];
    createdAt = json['createdAt'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = {};
    data['reviewId'] = reviewId;
    data['rating'] = rating;
    data['description'] = description;
    data['userName'] = userName;
    data['createdAt'] = createdAt;
    return data;
  }
}

/// Response model for GET /api/v1/Stations/{id}/slots?date=...
class SlotsResponseModel {
  String? status;
  String? message;
  SlotsData? data;

  SlotsResponseModel({this.status, this.message, this.data});

  SlotsResponseModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? SlotsData.fromJson(json['data']) : null;
  }
}

class SlotsData {
  String? stationId;
  String? stationName;
  String? date;
  int? totalSlots;
  int? bookedCount;
  int? availableCount;
  bool? allBooked;
  List<Slots>? slots;

  SlotsData({
    this.stationId,
    this.stationName,
    this.date,
    this.totalSlots,
    this.bookedCount,
    this.availableCount,
    this.allBooked,
    this.slots,
  });

  SlotsData.fromJson(Map<String, dynamic> json) {
    stationId = json['stationId'];
    stationName = json['stationName'];
    date = json['date'];
    totalSlots = (json['totalSlots'] as num?)?.toInt();
    bookedCount = (json['bookedCount'] as num?)?.toInt();
    availableCount = (json['availableCount'] as num?)?.toInt();
    allBooked = json['allBooked'];
    if (json['slots'] != null) {
      slots = <Slots>[];
      json['slots'].forEach((v) {
        slots!.add(Slots.fromJson(v));
      });
    }
  }
}

