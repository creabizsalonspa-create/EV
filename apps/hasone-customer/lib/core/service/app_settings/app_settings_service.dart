import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import '../api_service/api_service.dart';
import '../payment/payment_service.dart';

/// Global app settings fetched from GET /api/v1/app/settings.
/// Call [fetch] once at app startup (e.g. after login).
class AppSettingsService extends GetxService {
  static AppSettingsService get to => Get.find<AppSettingsService>();

  final _net = NetworkService().client;

  // ── Fetch tracking ──
  DateTime? _lastFetchTime;
  bool _isFetching = false;

  // ── Payment gateway flags ──
  RxBool stripeEnabled = false.obs;
  RxBool sslcommerzEnabled = true.obs;
  RxBool savedCardEnabled = true.obs;
  RxBool sslIsLive = false.obs;

  // ── Currency ──
  RxString currencyCode = 'BDT'.obs;
  RxString currencySymbol = '৳'.obs;
  RxString currencyIcon = 'currency_taka'.obs;

  // ── Min amounts ──
  RxDouble sslMinAmount = 10.0.obs;
  RxDouble stripeMinAmount = 100.0.obs;

  // ── Stripe key ──
  RxString stripePublishableKey = ''.obs;

  // ── Available gateways from settings ──
  RxList<Map<String, dynamic>> availableGateways = <Map<String, dynamic>>[].obs;

  /// Fetch app settings from backend.
  Future<void> fetch() async {
    if (_isFetching) return;
    _isFetching = true;
    try {
      final res = await _net.getRequest('/api/v1/app/settings');
      if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
        final data = (res.responseData ?? {})['data'];
        if (data is Map<String, dynamic>) {
          stripeEnabled.value = data['stripeEnabled'] ?? false;
          sslcommerzEnabled.value = data['sslcommerzEnabled'] ?? true;
          savedCardEnabled.value = data['savedCardEnabled'] ?? true;
          sslIsLive.value = data['sslIsLive'] ?? false;
          currencyCode.value = data['currencyCode'] ?? 'BDT';
          final rawSymbol = data['currencySymbol'] ?? '';
          currencySymbol.value = (rawSymbol is String && rawSymbol.isNotEmpty)
              ? rawSymbol
              : _deriveSymbol(currencyCode.value);
          currencyIcon.value = data['currencyIcon'] ?? 'currency_taka';
          sslMinAmount.value = (data['sslMinAmount'] as num?)?.toDouble() ?? 10.0;
          stripeMinAmount.value = (data['stripeMinAmount'] as num?)?.toDouble() ?? 100.0;

          final key = data['stripePublishableKey'] ?? '';
          stripePublishableKey.value = key;
          if (key.isNotEmpty) {
            await PaymentService.updateStripeKey(key);
          }

          if (data['availableGateways'] is List) {
            availableGateways.value = List<Map<String, dynamic>>.from(
              (data['availableGateways'] as List).map((e) => Map<String, dynamic>.from(e)),
            );
          }

          _lastFetchTime = DateTime.now();
          debugPrint('✅ AppSettings fetched — currency: ${currencySymbol.value} (${currencyCode.value})');
        }
      }
    } catch (_) {
      // Silently fail — use defaults
    } finally {
      _isFetching = false;
    }
  }

  /// Silently refresh settings if stale (older than 2 minutes).
  /// Call this on tab switches / screen entries so currency & gateways stay in sync.
  void refreshIfNeeded() {
    final now = DateTime.now();
    if (_lastFetchTime == null ||
        now.difference(_lastFetchTime!).inMinutes >= 2) {
      fetch(); // fire-and-forget — Rx values auto-update UI via Obx
    }
  }

  /// Check if a gateway is enabled based on settings.
  /// First checks availableGateways array (backend source of truth),
  /// then falls back to individual boolean flags.
  bool isGatewayEnabled(String gatewayId) {
    // If availableGateways is populated, use it as source of truth
    if (availableGateways.isNotEmpty) {
      return availableGateways.any((gw) => gw['id'] == gatewayId);
    }
    // Fallback to individual flags
    switch (gatewayId) {
      case 'stripe':
        return stripeEnabled.value;
      case 'sslcommerz':
        return sslcommerzEnabled.value;
      case 'card':
        return savedCardEnabled.value;
      default:
        return true;
    }
  }

  /// Derive currency symbol from code when backend returns empty symbol.
  static String _deriveSymbol(String code) {
    switch (code.toUpperCase()) {
      case 'USD': return '\$';
      case 'EUR': return '€';
      case 'GBP': return '£';
      case 'BDT': return '৳';
      case 'INR': return '₹';
      case 'JPY': return '¥';
      case 'CNY': return '¥';
      case 'KRW': return '₩';
      case 'TRY': return '₺';
      case 'AED': return 'د.إ';
      case 'SAR': return '﷼';
      case 'BRL': return 'R\$';
      case 'CAD': return 'C\$';
      case 'AUD': return 'A\$';
      case 'PKR': return '₨';
      default: return code;
    }
  }
}

