import 'dart:async';

import 'package:flutter_stripe/flutter_stripe.dart';
import 'package:get/get.dart';
import 'package:flutter/material.dart';

import '../api_service/api_service.dart';

class PaymentService {
  /// Fallback Stripe publishable key (used if API doesn't provide one).
  /// Will be overridden at runtime by the key from GET /api/v1/payment-methods.
  static String _stripePublishableKey = '';

  /// Call once in main() before runApp().
  /// Optionally pass a default key; real key comes from API at runtime.
  static void initStripe([String? defaultKey]) {
    if (defaultKey != null && defaultKey.isNotEmpty) {
      _stripePublishableKey = defaultKey;
      Stripe.publishableKey = defaultKey;
    }
  }

  /// Update Stripe publishable key at runtime (from API response).
  /// Called when GET /api/v1/payment-methods or POST /api/v1/payment/stripe/create-intent
  /// returns a publishableKey.
  static Future<void> updateStripeKey(String key) async {
    if (key.isNotEmpty) {
      _stripePublishableKey = key;
      Stripe.publishableKey = key;
      // Apply settings so the new key takes effect immediately
      await Stripe.instance.applySettings();
      debugPrint('✅ Stripe key updated from API: ${key.substring(0, key.length > 20 ? 20 : key.length)}...');
    }
  }

  /// Confirm Stripe payment using the clientSecret from the backend.
  /// [publishableKey] — if provided, updates the key before confirming (prevents key mismatch error).
  /// Returns the paymentIntentId on success, null on failure.
  static Future<String?> confirmStripePayment(
    String clientSecret, {
    String? publishableKey,
  }) async {
    try {
      // ALWAYS update the key from API response — never rely on previously cached key
      if (publishableKey != null && publishableKey.isNotEmpty) {
        _stripePublishableKey = publishableKey;
        Stripe.publishableKey = publishableKey;
        await Stripe.instance.applySettings();
        debugPrint('✅ Stripe publishableKey set from API: ${publishableKey.substring(0, 20)}...');
      }

      // Ensure publishable key is set
      if (_stripePublishableKey.isEmpty || _stripePublishableKey == 'pk_placeholder') {
        Get.snackbar('Payment Error', 'Stripe is not configured. Please try again.',
            snackPosition: SnackPosition.BOTTOM,
            backgroundColor: Colors.red.shade100,
            colorText: Colors.red.shade900,
            margin: const EdgeInsets.all(16));
        return null;
      }

      // Initialize the payment sheet
      await Stripe.instance.initPaymentSheet(
        paymentSheetParameters: SetupPaymentSheetParameters(
          paymentIntentClientSecret: clientSecret,
          merchantDisplayName: 'EV Charging',
          style: ThemeMode.light,
        ),
      );

      // Present the payment sheet to the user
      await Stripe.instance.presentPaymentSheet();

      // If we reach here, payment was successful
      // Extract the payment intent ID from the client secret
      // Format: pi_xxxxx_secret_yyyyy
      final parts = clientSecret.split('_secret_');
      final paymentIntentId = parts.isNotEmpty ? parts.first : clientSecret;

      return paymentIntentId;
    } on StripeException catch (e) {
      final msg = e.error.localizedMessage ?? 'Stripe payment failed';
      Get.snackbar('Payment Error', msg,
          snackPosition: SnackPosition.BOTTOM,
          backgroundColor: Colors.red.shade100,
          colorText: Colors.red.shade900,
          margin: const EdgeInsets.all(16));
      return null;
    } catch (e) {
      Get.snackbar('Payment Error', 'An unexpected error occurred: $e',
          snackPosition: SnackPosition.BOTTOM,
          backgroundColor: Colors.red.shade100,
          colorText: Colors.red.shade900,
          margin: const EdgeInsets.all(16));
      return null;
    }
  }

  /// Poll payment status for SSLCommerz after WebView redirect.
  /// [type] = 'booking' or 'session'
  /// [id] = bookingId or sessionId
  /// Returns true when payment is confirmed, false on timeout/failure.
  static Future<bool> pollPaymentStatus({
    required String type,
    required String id,
    int maxAttempts = 15,
    Duration interval = const Duration(seconds: 3),
  }) async {
    final net = NetworkService().client;
    final endpoint = '/api/v1/payment/status/$type/$id';

    for (int i = 0; i < maxAttempts; i++) {
      await Future.delayed(interval);

      try {
        final res = await net.getRequest(endpoint);
        if (res.isSuccess && res.statusCode == 200) {
          final data = res.responseData ?? {};
          final status =
              data['data']?['paymentStatus'] ?? data['data']?['status'] ?? '';
          if (status.toString().toLowerCase() == 'paid' ||
              status.toString().toLowerCase() == 'completed' ||
              status.toString().toLowerCase() == 'success') {
            return true;
          }
        }
      } catch (_) {
        // Continue polling
      }
    }
    return false;
  }
}
