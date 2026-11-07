class BookingDetailsModel {
  String? status;
  String? message;
  Data? data;

  BookingDetailsModel({this.status, this.message, this.data});

  BookingDetailsModel.fromJson(Map<String, dynamic> json) {
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
      {this.bookingId,
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
    bookingId = json['bookingId'];
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
    data['bookingId'] = this.bookingId;
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
