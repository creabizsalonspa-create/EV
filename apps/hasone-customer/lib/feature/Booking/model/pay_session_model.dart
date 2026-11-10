class PaySessionModel {
  String? status;
  String? message;
  Data? data;

  PaySessionModel({this.status, this.message, this.data});

  PaySessionModel.fromJson(Map<String, dynamic> json) {
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
  String? paymentMethod;
  String? gatewayUrl;
  String? transactionId;
  String? clientSecret;
  String? paymentIntentId;
  String? publishableKey;
  String? sessionKey;
  double? extensionPaid;
  double? totalAmount;
  String? status;
  bool? showReviewPrompt;
  String? currencyCode;
  String? currencySymbol;
  String? currencyIcon;

  Data({
    this.sessionId,
    this.bookingId,
    this.paymentMethod,
    this.gatewayUrl,
    this.transactionId,
    this.clientSecret,
    this.paymentIntentId,
    this.publishableKey,
    this.sessionKey,
    this.extensionPaid,
    this.totalAmount,
    this.status,
    this.showReviewPrompt,
    this.currencyCode,
    this.currencySymbol,
    this.currencyIcon,
  });

  Data.fromJson(Map<String, dynamic> json) {
    sessionId = json['sessionId'];
    bookingId = json['bookingId'];
    paymentMethod = json['paymentMethod'];
    gatewayUrl = json['gatewayUrl'] ?? json['GatewayPageURL'];
    transactionId = json['transactionId'];
    clientSecret = json['clientSecret'];
    paymentIntentId = json['paymentIntentId'];
    publishableKey = json['publishableKey'];
    sessionKey = json['sessionKey'];
    extensionPaid = (json['extensionPaid'] is num) ? (json['extensionPaid'] as num).toDouble() : null;
    totalAmount = (json['totalAmount'] is num) ? (json['totalAmount'] as num).toDouble() : null;
    status = json['status'];
    showReviewPrompt = json['showReviewPrompt'];
    currencyCode = json['currencyCode'];
    currencySymbol = json['currencySymbol'];
    currencyIcon = json['currencyIcon'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = {};
    data['status'] = status;
    data['message'] = null;
    data['sessionId'] = sessionId;
    data['bookingId'] = bookingId;
    data['paymentMethod'] = paymentMethod;
    data['gatewayUrl'] = gatewayUrl;
    data['transactionId'] = transactionId;
    data['clientSecret'] = clientSecret;
    data['paymentIntentId'] = paymentIntentId;
    data['publishableKey'] = publishableKey;
    data['sessionKey'] = sessionKey;
    data['extensionPaid'] = extensionPaid;
    data['totalAmount'] = totalAmount;
    data['status'] = status;
    data['showReviewPrompt'] = showReviewPrompt;
    data['currencyCode'] = currencyCode;
    data['currencySymbol'] = currencySymbol;
    data['currencyIcon'] = currencyIcon;
    return data;
  }
}

