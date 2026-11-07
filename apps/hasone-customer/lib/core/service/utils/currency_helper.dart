import 'package:get/get.dart';
import '../app_settings/app_settings_service.dart';

/// Returns the currency symbol from the model response if available,
/// otherwise falls back to AppSettingsService global currency.
/// If the symbol is empty, derives it from currencyCode.
String getCurrencySymbol([String? fromModel]) {
  if (fromModel != null && fromModel.isNotEmpty) return fromModel;
  if (Get.isRegistered<AppSettingsService>()) {
    final symbol = AppSettingsService.to.currencySymbol.value;
    if (symbol.isNotEmpty) return symbol;
    // If symbol is empty, derive from currencyCode
    return _symbolFromCode(AppSettingsService.to.currencyCode.value);
  }
  return '\$';
}

/// Map common currency codes to their symbols.
String _symbolFromCode(String code) {
  switch (code.toUpperCase()) {
    case 'USD':
      return '\$';
    case 'EUR':
      return '€';
    case 'GBP':
      return '£';
    case 'BDT':
      return '৳';
    case 'INR':
      return '₹';
    case 'JPY':
      return '¥';
    case 'CNY':
      return '¥';
    case 'KRW':
      return '₩';
    case 'TRY':
      return '₺';
    case 'RUB':
      return '₽';
    case 'THB':
      return '฿';
    case 'AED':
      return 'د.إ';
    case 'SAR':
      return '﷼';
    case 'MYR':
      return 'RM';
    case 'SGD':
      return 'S\$';
    case 'AUD':
      return 'A\$';
    case 'CAD':
      return 'C\$';
    case 'PKR':
      return '₨';
    case 'LKR':
      return '₨';
    case 'NGN':
      return '₦';
    case 'ZAR':
      return 'R';
    case 'BRL':
      return 'R\$';
    default:
      return code; // Fallback: show the code itself (e.g. "CHF")
  }
}

