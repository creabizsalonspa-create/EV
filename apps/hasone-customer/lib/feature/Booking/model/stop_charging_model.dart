class StopChargingModel {
  String? status;
  String? message;
  Data? data;

  StopChargingModel({this.status, this.message, this.data});

  StopChargingModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? Data.fromJson(json['data']) : null;
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

class Data {
  String? sessionId;
  String? bookingId;
  String? popupTitle;
  String? subTitle;
  double? energyDelivered;
  double? costPerKwh;
  double? extendSessionCharge;
  double? totalAmount;
  bool? needsPayment;
  String? status;
  bool? showReviewPrompt;
  String? currencyCode;
  String? currencySymbol;
  String? currencyIcon;

  Data({
    this.sessionId,
    this.bookingId,
    this.popupTitle,
    this.subTitle,
    this.energyDelivered,
    this.costPerKwh,
    this.extendSessionCharge,
    this.totalAmount,
    this.needsPayment,
    this.status,
    this.showReviewPrompt,
    this.currencyCode,
    this.currencySymbol,
    this.currencyIcon,
  });

  Data.fromJson(Map<String, dynamic> json) {
    sessionId = json['sessionId'];
    bookingId = json['bookingId'];
    popupTitle = json['popupTitle'];
    subTitle = json['subTitle'];
    energyDelivered = (json['energyDelivered'] is num) ? (json['energyDelivered'] as num).toDouble() : null;
    costPerKwh = (json['costPerKwh'] is num) ? (json['costPerKwh'] as num).toDouble() : null;
    extendSessionCharge = (json['extendSessionCharge'] is num) ? (json['extendSessionCharge'] as num).toDouble() : null;
    totalAmount = (json['totalAmount'] is num) ? (json['totalAmount'] as num).toDouble() : null;
    needsPayment = json['needsPayment'];
    status = json['status'];
    showReviewPrompt = json['showReviewPrompt'];
    currencyCode = json['currencyCode'];
    currencySymbol = json['currencySymbol'];
    currencyIcon = json['currencyIcon'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = {};
    data['sessionId'] = sessionId;
    data['bookingId'] = bookingId;
    data['popupTitle'] = popupTitle;
    data['subTitle'] = subTitle;
    data['energyDelivered'] = energyDelivered;
    data['costPerKwh'] = costPerKwh;
    data['extendSessionCharge'] = extendSessionCharge;
    data['totalAmount'] = totalAmount;
    data['needsPayment'] = needsPayment;
    data['status'] = status;
    data['showReviewPrompt'] = showReviewPrompt;
    data['currencyCode'] = currencyCode;
    data['currencySymbol'] = currencySymbol;
    data['currencyIcon'] = currencyIcon;
    return data;
  }
}

