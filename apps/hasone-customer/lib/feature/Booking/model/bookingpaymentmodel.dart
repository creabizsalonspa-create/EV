class BookingPaymentMOdel {
  String? status;
  String? message;
  Data? data;

  BookingPaymentMOdel({this.status, this.message, this.data});

  BookingPaymentMOdel.fromJson(Map<String, dynamic> json) {
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
  String? paymentMethod;
  String? gatewayUrl;
  String? transactionId;
  String? clientSecret;
  String? paymentIntentId;
  String? publishableKey;
  String? sessionKey;
  String? title;
  String? subtitle;
  String? date;
  String? location;
  String? time;
  String? stationName;
  double? totalAmount;
  String? currencyCode;
  String? currencySymbol;
  String? currencyIcon;

  Data(
      {this.bookingId,
        this.paymentMethod,
        this.gatewayUrl,
        this.transactionId,
        this.clientSecret,
        this.paymentIntentId,
        this.publishableKey,
        this.sessionKey,
        this.title,
        this.subtitle,
        this.date,
        this.location,
        this.time,
        this.stationName,
        this.totalAmount,
        this.currencyCode,
        this.currencySymbol,
        this.currencyIcon});

  Data.fromJson(Map<String, dynamic> json) {
    bookingId = json['bookingId'];
    paymentMethod = json['paymentMethod'];
    gatewayUrl = json['gatewayUrl'] ?? json['GatewayPageURL'];
    transactionId = json['transactionId'];
    clientSecret = json['clientSecret'];
    paymentIntentId = json['paymentIntentId'];
    publishableKey = json['publishableKey'];
    sessionKey = json['sessionKey'];
    title = json['title'];
    subtitle = json['subtitle'];
    date = json['date'];
    location = json['location'];
    time = json['time'];
    stationName = json['stationName'];
    totalAmount = (json['totalAmount'] as num?)?.toDouble();
    currencyCode = json['currencyCode'];
    currencySymbol = json['currencySymbol'];
    currencyIcon = json['currencyIcon'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = new Map<String, dynamic>();
    data['bookingId'] = this.bookingId;
    data['paymentMethod'] = this.paymentMethod;
    data['gatewayUrl'] = this.gatewayUrl;
    data['transactionId'] = this.transactionId;
    data['clientSecret'] = this.clientSecret;
    data['paymentIntentId'] = this.paymentIntentId;
    data['publishableKey'] = this.publishableKey;
    data['sessionKey'] = this.sessionKey;
    data['title'] = this.title;
    data['subtitle'] = this.subtitle;
    data['date'] = this.date;
    data['location'] = this.location;
    data['time'] = this.time;
    data['stationName'] = this.stationName;
    data['totalAmount'] = this.totalAmount;
    data['currencyCode'] = this.currencyCode;
    data['currencySymbol'] = this.currencySymbol;
    data['currencyIcon'] = this.currencyIcon;
    return data;
  }
}
