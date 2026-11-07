import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../../core/service/api_service/api_service.dart';
import '../../model/bookingdetailsMOdel.dart';
import '../../model/bookingpaymentmodel.dart' as payment;
import '../../model/get_payment_method_model.dart' as gpm;
import '../../model/payment_model.dart';

class BookingController extends GetxController {
  final _net = NetworkService().client;

  /// Booking response data
  Rxn<Data> bookingData = Rxn<Data>();

  RxBool loading = false.obs;
  RxBool paymentLoading = false.obs;

  /// Payment method data from GET
  Rxn<gpm.PaymentMethodData> paymentMethodData = Rxn<gpm.PaymentMethodData>();
  RxBool paymentMethodLoading = false.obs;

  /// POST /api/v1/Bookings
  /// Request body:
  /// {
  ///   "stationId": "...",
  ///   "date": "06 Jan, Tue",
  ///   "slotStart": "10:30 AM",
  ///   "slotEnd": "11:30 AM",
  ///   "connectorType": "Type A",
  ///   "energyKwh": "110 kw/h",
  ///   "chargingSlot": "Slot A",
  ///   "chargerType": "CCS - 150 kW"
  /// }
  Future<Data?> createBooking({
    required String stationId,
    required String date,
    required String slotStart,
    required String slotEnd,
    String connectorType = 'Type A',
    String energyKwh = '110 kw/h',
    String chargingSlot = 'Slot A',
    String chargerType = 'CCS - 150 kW',
  }) async {
    loading.value = true;
    bookingData.value = null;

    final body = {
      'stationId': stationId,
      'date': date,
      'slotStart': slotStart,
      'slotEnd': slotEnd,
      'connectorType': connectorType,
      'energyKwh': energyKwh,
      'chargingSlot': chargingSlot,
      'chargerType': chargerType,
    };

    final res = await _net.postRequest('/api/v1/Bookings', body: body);

    loading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = BookingDetailsModel.fromJson(res.responseData ?? {});
      bookingData.value = model.data;
      return model.data;
    }
    return null;
  }

  /// POST /api/v1/PaymentCards
  /// Adds a payment card and returns the card data (including cardId).
  Future<PaymentData?> addPaymentCard({
    required String cardHolder,
    required String cardNumber,
    required String expiryDate,
    required String cardType,
  }) async {
    final body = {
      'cardHolder': cardHolder,
      'cardNumber': cardNumber,
      'expiryDate': expiryDate,
      'cardType': cardType,
    };

    final res = await _net.postRequest('/api/v1/PaymentCards', body: body);

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = PaymentModel.fromJson(res.responseData ?? {});
      return model.data;
    }
    return null;
  }

  /// GET /api/v1/payment-methods?bookingId={bookingId}
  /// Fetches available payment gateways, saved cards, booking summary, and currency info.
  Future<gpm.PaymentMethodData?> fetchPaymentMethod(String bookingId) async {
    paymentMethodLoading.value = true;
    paymentMethodData.value = null;

    final res = await _net.getRequest(
      '/api/v1/payment-methods',
      query: {'bookingId': bookingId},
    );
    paymentMethodLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = gpm.GetPaymentMethodModel.fromJson(res.responseData ?? {});
      paymentMethodData.value = model.data;
      return model.data;
    }
    return null;
  }

  /// POST /api/v1/payment/stripe/create-intent
  /// Creates a Stripe PaymentIntent and returns clientSecret + publishableKey.
  Future<payment.Data?> initiateStripePayment({
    required String bookingId,
    required double amount,
    String? savedCardId,
  }) async {
    paymentLoading.value = true;

    final body = <String, dynamic>{
      'bookingId': bookingId,
      'amount': amount,
    };
    if (savedCardId != null && savedCardId.isNotEmpty) {
      body['savedCardId'] = savedCardId;
    }

    final res = await _net.postRequest(
      '/api/v1/payment/stripe/create-intent',
      body: body,
    );
    paymentLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = payment.BookingPaymentMOdel.fromJson(res.responseData ?? {});
      return model.data;
    }

    _showPaymentError(res);
    return null;
  }

  /// POST /api/v1/payment/stripe/verify
  /// Verifies Stripe payment after client-side confirmation.
  Future<payment.Data?> verifyStripePayment({
    required String bookingId,
    required String stripePaymentIntentId,
  }) async {
    paymentLoading.value = true;

    final body = <String, dynamic>{
      'bookingId': bookingId,
      'stripePaymentIntentId': stripePaymentIntentId,
    };

    final res = await _net.postRequest(
      '/api/v1/payment/stripe/verify',
      body: body,
    );
    paymentLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = payment.BookingPaymentMOdel.fromJson(res.responseData ?? {});
      return model.data;
    }

    _showPaymentError(res);
    return null;
  }

  /// POST /api/v1/payment/sslcommerz/initiate
  /// Initiates SSLCommerz payment and returns gatewayUrl.
  Future<payment.Data?> initiateSSLCommerzPayment({
    required String bookingId,
    required double amount,
  }) async {
    paymentLoading.value = true;

    final body = <String, dynamic>{
      'bookingId': bookingId,
      'amount': amount,
    };

    final res = await _net.postRequest(
      '/api/v1/payment/sslcommerz/initiate',
      body: body,
    );
    paymentLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = payment.BookingPaymentMOdel.fromJson(res.responseData ?? {});
      return model.data;
    }

    _showPaymentError(res);
    return null;
  }

  /// POST /api/v1/payment/stripe/save-card
  Future<gpm.SavedCard?> saveStripeCard() async {
    final res = await _net.postRequest('/api/v1/payment/stripe/save-card', body: {});

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final data = res.responseData ?? {};
      if (data['data'] != null) {
        return gpm.SavedCard.fromJson(data['data']);
      }
    }
    return null;
  }

  /// POST /api/v1/Bookings/{id}/payment  (legacy / generic confirm for saved card)
  Future<payment.Data?> confirmPayment({
    required String bookingId,
    required String paymentMethod,
    String? cardId,
    String? stripePaymentIntentId,
  }) async {
    paymentLoading.value = true;

    final body = <String, dynamic>{
      'paymentMethod': paymentMethod,
    };
    if (cardId != null && cardId.isNotEmpty) {
      body['cardId'] = cardId;
    }
    if (stripePaymentIntentId != null && stripePaymentIntentId.isNotEmpty) {
      body['stripePaymentIntentId'] = stripePaymentIntentId;
    }

    final res = await _net.postRequest(
      '/api/v1/Bookings/$bookingId/payment',
      body: body,
    );

    paymentLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = payment.BookingPaymentMOdel.fromJson(res.responseData ?? {});
      return model.data;
    }

    _showPaymentError(res);
    return null;
  }

  void _showPaymentError(dynamic res) {
    final errorMsg = res.responseData is Map
        ? (res.responseData['message'] ?? 'Payment failed. Please try again.')
        : 'Payment failed. Please try again.';
    Get.snackbar('Payment Error', errorMsg.toString(),
        snackPosition: SnackPosition.BOTTOM,
        backgroundColor: const Color(0xFFFFCDD2),
        colorText: const Color(0xFFB71C1C),
        margin: const EdgeInsets.all(16));
  }
}

