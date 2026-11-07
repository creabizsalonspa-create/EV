import 'package:get/get.dart';

import 'api_config.dart';

import '../../../feature/Auth/UI/screen/login_screen.dart';
import '../network/network_client.dart';
import '../session/session.dart';
import '../shared_preferance/shared_prefarance.dart';

/// API origin is configurable per build; API paths include /api/v1.
String get _apiBaseUrl => ApiConfig.baseUrl;

class NetworkService {
  late final NetworkClient client;

  NetworkService() {
    client = NetworkClient(
      baseUrl: _apiBaseUrl,
      onUnAuthorize: _handleUnauthorized,
      commonHeaders: _buildHeaders,
    );
  }

  Map<String, String> _buildHeaders() {
    final h = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };
    final t = Session.accessToken; // <- sync from cache
    if (t != null && t.isNotEmpty) {
      h['Authorization'] = 'Bearer $t';
    }
    return h;
  }

  void _handleUnauthorized() async {
    // NetworkClient already discards responses for a superseded bearer token.
    // A new login can still occur while Keychain/Keystore deletion is pending.
    final rejectedToken = Session.accessToken;
    if (rejectedToken == null || rejectedToken.isEmpty) return;

    final sharedPrefs = SharedPrefs();
    final deletion = sharedPrefs.clear();
    final rejectedGeneration = SharedPrefs.credentialGeneration;
    try {
      await deletion;
    } catch (_) {
      // Secure storage may retain the rejected token. Do not navigate or
      // claim the credential was removed after a failed platform deletion.
      return;
    }
    if (SharedPrefs.credentialGeneration != rejectedGeneration ||
        Session.accessToken != null) return;

    Get.offAll(() => const LoginScreen());
    Get.snackbar(
      'Session Expired',
      'Please login again',
      snackPosition: SnackPosition.BOTTOM,
    );
  }
}
