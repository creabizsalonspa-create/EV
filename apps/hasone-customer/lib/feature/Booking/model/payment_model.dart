class PaymentModel {
  String? status;
  String? message;
  PaymentData? data;

  PaymentModel({this.status, this.message, this.data});

  PaymentModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? PaymentData.fromJson(json['data']) : null;
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

class PaymentData {
  String? cardId;
  String? cardType;
  String? cardHolder;
  String? last4;
  String? expiryDate;
  bool? isDefault;

  PaymentData({
    this.cardId,
    this.cardType,
    this.cardHolder,
    this.last4,
    this.expiryDate,
    this.isDefault,
  });

  PaymentData.fromJson(Map<String, dynamic> json) {
    cardId = json['cardId'];
    cardType = json['cardType'];
    cardHolder = json['cardHolder'];
    last4 = json['last4'];
    expiryDate = json['expiryDate'];
    isDefault = json['isDefault'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['cardId'] = cardId;
    data['cardType'] = cardType;
    data['cardHolder'] = cardHolder;
    data['last4'] = last4;
    data['expiryDate'] = expiryDate;
    data['isDefault'] = isDefault;
    return data;
  }
}

