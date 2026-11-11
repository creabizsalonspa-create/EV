import '../../../../core/utils/reservation_date.dart';
import '../../model/reservation_chargers.dart';
import '../../../../core/service/network/network_client.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../../core/service/api_service/api_service.dart';
import '../../model/bookingdetailsMOdel.dart';
import '../../model/bookingpaymentmodel.dart' as payment;
import '../../model/get_payment_method_model.dart' as gpm;
import '../../model/payment_model.dart';

class BookingController extends GetxController {
  final NetworkClient _net;
  BookingController({NetworkClient? client})
    : _net = client ?? NetworkService().client;
  final RxString bookingError = ''.obs;

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
  ///   "date": "2026-01-06",
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
    String? reservedChargerId,
  }) async {
    if (loading.value) {
      bookingError.value =
          'A booking request is already pending. Wait for its result.';
      return null;
    }
    bookingError.value = '';
    bookingData.value = null;
    if (!isReservationDay(date)) {
      bookingError.value =
          'Choose a complete reservation date including the year.';
      return null;
    }
    if (reservedChargerId != null &&
        !validReservationCharger(reservedChargerId)) {
      bookingError.value = 'Choose a valid approved charger.';
      return null;
    }
    loading.value = true;

    final body = {
      'stationId': stationId,
      'date': date,
      'slotStart': slotStart,
      'slotEnd': slotEnd,
      'connectorType': connectorType,
      'energyKwh': energyKwh,
      'chargingSlot': chargingSlot,
      'chargerType': chargerType,
      if (reservedChargerId != null) 'reservedChargerId': reservedChargerId,
    };

    try {
      final res = await _net.postRequest('/api/v1/Bookings', body: body);
      final response = res.responseData;
      if (res.isSuccess &&
          response is Map<String, dynamic> &&
          response['status'] == 'Success') {
        final model = BookingDetailsModel.fromJson(response);
        if (model.data != null &&
            (reservedChargerId == null ||
                model.data!.reservedChargerId == reservedChargerId)) {
          bookingData.value = model.data;
          return model.data;
        }
      }
      final message = response is Map ? response['message'] : null;
      bookingError.value =
          res.statusCode == 400 &&
              message is String &&
              message.isNotEmpty &&
              message.length <= 500
          ? message
          : 'Booking outcome unconfirmed. Refresh bookings before trying again.';
      return null;
    } catch (_) {
      bookingError.value =
          'Booking outcome unconfirmed. Refresh bookings before trying again.';
      return null;
    } finally {
      loading.value = false;
    }
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

    final body = <String, dynamic>{'bookingId': bookingId, 'amount': amount};
    if (savedCardId != null && savedCardId.isNotEmpty) {
      body['savedCardId'] = savedCardId;
    }

    final res = await _net.postRequest(
      '/api/v1/payment/stripe/create-intent',
      body: body,
    );
    paymentLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = payment.BookingPaymentMOdel.fromJson(
        res.responseData ?? {},
      );
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
      final model = payment.BookingPaymentMOdel.fromJson(
        res.responseData ?? {},
      );
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

    final body = <String, dynamic>{'bookingId': bookingId, 'amount': amount};

    final res = await _net.postRequest(
      '/api/v1/payment/sslcommerz/initiate',
      body: body,
    );
    paymentLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = payment.BookingPaymentMOdel.fromJson(
        res.responseData ?? {},
      );
      return model.data;
    }

    _showPaymentError(res);
    return null;
  }

  /// POST /api/v1/payment/stripe/save-card
  Future<gpm.SavedCard?> saveStripeCard() async {
    final res = await _net.postRequest(
      '/api/v1/payment/stripe/save-card',
      body: {},
    );

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

    final body = <String, dynamic>{'paymentMethod': paymentMethod};
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
      final model = payment.BookingPaymentMOdel.fromJson(
        res.responseData ?? {},
      );
      return model.data;
    }

    _showPaymentError(res);
    return null;
  }

  void _showPaymentError(dynamic res) {
    final errorMsg = res.responseData is Map
        ? (res.responseData['message'] ?? 'Payment failed. Please try again.')
        : 'Payment failed. Please try again.';
    Get.snackbar(
      'Payment Error',
      errorMsg.toString(),
      snackPosition: SnackPosition.BOTTOM,
      backgroundColor: const Color(0xFFFFCDD2),
      colorText: const Color(0xFFB71C1C),
      margin: const EdgeInsets.all(16),
    );
  }
}
