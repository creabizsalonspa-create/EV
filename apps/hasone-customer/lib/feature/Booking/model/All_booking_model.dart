class AllBookingModel {
  String? status;
  String? message;
  List<Data>? data;

  AllBookingModel({this.status, this.message, this.data});

  AllBookingModel.fromJson(Map<String, dynamic> json) {
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
  String? bookingId;
  String? stationId;
  String? vehicleName;
  String? vehiclePlate;
  String? vehicleImage;
  String? stationName;
  String? address;
  String? stationImage;
  String? connectorType;
  String? energyKwh;
  String? chargingSlot;
  String? chargerType;
  String? bookingDate;
  String? date;
  String? time;
  String? chargingDuration;
  String? chargingSessionTiming;
  String? slotStart;
  String? slotEnd;
  double? amountEstimation;
  double? tax;
  double? totalAmount;
  bool? isPaid;
  String? status;
  String? currencyCode;
  String? currencySymbol;
  String? currencyIcon;

  Data(
      {this.sId,
        this.bookingId,
        this.stationId,
        this.vehicleName,
        this.vehiclePlate,
        this.vehicleImage,
        this.stationName,
        this.address,
        this.stationImage,
        this.connectorType,
        this.energyKwh,
        this.chargingSlot,
        this.chargerType,
        this.bookingDate,
        this.date,
        this.time,
        this.chargingDuration,
        this.chargingSessionTiming,
        this.slotStart,
        this.slotEnd,
        this.amountEstimation,
        this.tax,
        this.totalAmount,
        this.isPaid,
        this.status,
        this.currencyCode,
        this.currencySymbol,
        this.currencyIcon});

  Data.fromJson(Map<String, dynamic> json) {
    sId = json['_id'] ?? json['sId'];
    bookingId = json['bookingId'];
    stationId = json['stationId'];
    vehicleName = json['vehicleName'];
    vehiclePlate = json['vehiclePlate'];
    vehicleImage = json['vehicleImage'];
    stationName = json['stationName'];
    address = json['address'];
    stationImage = json['stationImage'];
    connectorType = json['connectorType'];
    energyKwh = json['energyKwh'];
    chargingSlot = json['chargingSlot'];
    chargerType = json['chargerType'];
    bookingDate = json['bookingDate'];
    date = json['date'] ?? json['bookingDate'];
    time = json['time'] ?? json['chargingSessionTiming'];
    chargingDuration = json['chargingDuration'];
    chargingSessionTiming = json['chargingSessionTiming'];
    slotStart = json['slotStart'];
    slotEnd = json['slotEnd'];
    amountEstimation = (json['amountEstimation'] as num?)?.toDouble();
    tax = (json['tax'] as num?)?.toDouble();
    totalAmount = (json['totalAmount'] as num?)?.toDouble();
    isPaid = json['isPaid'];
    status = json['status'];
    currencyCode = json['currencyCode'];
    currencySymbol = json['currencySymbol'];
    currencyIcon = json['currencyIcon'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['_id'] = this.sId;
    data['bookingId'] = this.bookingId;
    data['stationId'] = this.stationId;
    data['vehicleName'] = this.vehicleName;
    data['vehiclePlate'] = this.vehiclePlate;
    data['vehicleImage'] = this.vehicleImage;
    data['stationName'] = this.stationName;
    data['address'] = this.address;
    data['stationImage'] = this.stationImage;
    data['connectorType'] = this.connectorType;
    data['energyKwh'] = this.energyKwh;
    data['chargingSlot'] = this.chargingSlot;
    data['chargerType'] = this.chargerType;
    data['bookingDate'] = this.bookingDate;
    data['date'] = this.date;
    data['time'] = this.time;
    data['chargingDuration'] = this.chargingDuration;
    data['chargingSessionTiming'] = this.chargingSessionTiming;
    data['slotStart'] = this.slotStart;
    data['slotEnd'] = this.slotEnd;
    data['amountEstimation'] = this.amountEstimation;
    data['tax'] = this.tax;
    data['totalAmount'] = this.totalAmount;
    data['isPaid'] = this.isPaid;
    data['status'] = this.status;
    data['currencyCode'] = this.currencyCode;
    data['currencySymbol'] = this.currencySymbol;
    data['currencyIcon'] = this.currencyIcon;
    return data;
  }
}
