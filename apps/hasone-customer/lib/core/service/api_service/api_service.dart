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
    // Don't handle if no token was set (prevents startup race condition)
    if (Session.accessToken == null || Session.accessToken!.isEmpty) {
      return;
    }

    // Clear local storage and session
    final sharedPrefs = SharedPrefs();
    Session.clear();
    await sharedPrefs.clear();

    // Navigate to login screen
    Get.offAll(() => const LoginScreen());

    Get.snackbar(
      'Session Expired',
      'Please login again',
      snackPosition: SnackPosition.BOTTOM,
    );
  }
}
