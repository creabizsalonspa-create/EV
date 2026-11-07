class BookingDetailsModel {
  String? status;
  String? message;
  Data? data;

  BookingDetailsModel({this.status, this.message, this.data});

  BookingDetailsModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? Data.fromJson(json['data']) : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['status'] = status;
    data['message'] = message;
    if (this.data != null) {
      data['data'] = this.data!.toJson();
    }
    return data;
  }
}

class Data {
  String? bookingId;
  String? reservedChargerId;
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

  Data({
    this.bookingId,
    this.reservedChargerId,
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
    this.currencyIcon,
  });

  Data.fromJson(Map<String, dynamic> json) {
    bookingId = json['bookingId'];
    reservedChargerId = json['reservedChargerId'] is String
        ? json['reservedChargerId']
        : null;
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
    final Map<String, dynamic> data = <String, dynamic>{};
    data['bookingId'] = bookingId;
    data['reservedChargerId'] = reservedChargerId;
    data['vehicleName'] = vehicleName;
    data['vehiclePlate'] = vehiclePlate;
    data['vehicleImage'] = vehicleImage;
    data['stationName'] = stationName;
    data['address'] = address;
    data['stationImage'] = stationImage;
    data['connectorType'] = connectorType;
    data['energyKwh'] = energyKwh;
    data['chargingSlot'] = chargingSlot;
    data['chargerType'] = chargerType;
    data['bookingDate'] = bookingDate;
    data['chargingDuration'] = chargingDuration;
    data['chargingSessionTiming'] = chargingSessionTiming;
    data['slotStart'] = slotStart;
    data['slotEnd'] = slotEnd;
    data['amountEstimation'] = amountEstimation;
    data['tax'] = tax;
    data['totalAmount'] = totalAmount;
    data['isPaid'] = isPaid;
    data['status'] = status;
    data['currencyCode'] = currencyCode;
    data['currencySymbol'] = currencySymbol;
    data['currencyIcon'] = currencyIcon;
    return data;
  }
}
