class GetPaymentMethodModel {
  String? status;
  String? message;
  PaymentMethodData? data;

  GetPaymentMethodModel({this.status, this.message, this.data});

  GetPaymentMethodModel.fromJson(Map<String, dynamic> json) {
    status = json['status'];
    message = json['message'];
    data = json['data'] != null ? PaymentMethodData.fromJson(json['data']) : null;
  }
}

class PaymentMethodData {
  // ── Currency info ──
  String? currencyCode;
  String? currencySymbol;
  String? currencyIcon;

  // ── Stripe key from API ──
  String? stripePublishableKey;

  // ── Booking-specific fields ──
  String? bookingId;
  String? date;
  String? time;
  String? chargingDuration;
  double? amountEstimation;
  double? tax;
  double? totalAmount;
  bool? isPaid;
  String? paymentStatus;
  String? paymentGateway;

  // ── Gateways & saved cards ──
  List<AvailableGateway>? availableGateways;
  List<SavedCard>? cards;

  PaymentMethodData({
    this.currencyCode,
    this.currencySymbol,
    this.currencyIcon,
    this.stripePublishableKey,
    this.bookingId,
    this.date,
    this.time,
    this.chargingDuration,
    this.amountEstimation,
    this.tax,
    this.totalAmount,
    this.isPaid,
    this.paymentStatus,
    this.paymentGateway,
    this.availableGateways,
    this.cards,
  });

  PaymentMethodData.fromJson(Map<String, dynamic> json) {
    currencyCode = json['currencyCode'];
    currencySymbol = json['currencySymbol'];
    currencyIcon = json['currencyIcon'];
    stripePublishableKey = json['stripePublishableKey'];
    bookingId = json['bookingId'];
    date = json['date'];
    time = json['time'];
    chargingDuration = json['chargingDuration'];
    amountEstimation = (json['amountEstimation'] as num?)?.toDouble();
    tax = (json['tax'] as num?)?.toDouble();
    totalAmount = (json['totalAmount'] as num?)?.toDouble();
    isPaid = json['isPaid'];
    paymentStatus = json['paymentStatus'];
    paymentGateway = json['paymentGateway'];

    if (json['availableGateways'] != null) {
      availableGateways = <AvailableGateway>[];
      json['availableGateways'].forEach((v) {
        availableGateways!.add(AvailableGateway.fromJson(v));
      });
    }

    if (json['cards'] != null) {
      cards = <SavedCard>[];
      json['cards'].forEach((v) {
        cards!.add(SavedCard.fromJson(v));
      });
    }
  }
}

class AvailableGateway {
  String? id;
  String? name;
  String? description;
  double? minAmount;

  AvailableGateway({this.id, this.name, this.description, this.minAmount});

  AvailableGateway.fromJson(Map<String, dynamic> json) {
    id = json['id'];
    name = json['name'];
    description = json['description'];
    minAmount = (json['minAmount'] as num?)?.toDouble();
  }
}

class SavedCard {
  String? cardId;
  String? cardType;
  String? cardHolder;
  String? last4;
  String? expiryDate;
  bool? isDefault;

  SavedCard({
    this.cardId,
    this.cardType,
    this.cardHolder,
    this.last4,
    this.expiryDate,
    this.isDefault,
  });

  SavedCard.fromJson(Map<String, dynamic> json) {
    cardId = json['cardId'] ?? json['_id'];
    cardType = json['cardType'];
    cardHolder = json['cardHolder'];
    last4 = json['last4'];
    expiryDate = json['expiryDate'];
    isDefault = json['isDefault'];
  }
}
