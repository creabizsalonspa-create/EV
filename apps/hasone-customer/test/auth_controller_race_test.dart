import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/core/service/session/session.dart';
import 'package:evcharg/core/service/shared_preferance/shared_prefarance.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';

class _MemoryVault implements TokenVault {
  String? value;

  @override
  Future<String?> read({required String key}) async => value;

  @override
  Future<void> write({required String key, required String value}) async {
    this.value = value;
  }

  @override
  Future<void> delete({required String key}) async {
    value = null;
  }
}

/// No live HTTP, credentials, payment or charger access. The response is
/// released only after another controller has explicitly logged out.
class _HeldAuthenticationNetwork extends NetworkClient {
  _HeldAuthenticationNetwork()
      : super(onUnAuthorize: () {}, commonHeaders: () => {});

  final requested = Completer<String>();
  final response = Completer<NetworkResponse>();

  @override
  Future<NetworkResponse> postRequest(
    String url, {
    Map<String, dynamic>? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    String? bearerToken,
    Map<String, String>? headers,
  }) {
    if (!requested.isCompleted) requested.complete(url);
    return response.future;
  }
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    Session.clear();
  });

  test('late successful login after explicit logout never republishes token',
      () async {
    final vault = _MemoryVault();
    final network = _HeldAuthenticationNetwork();
    final auth = AuthController(
      client: network,
      prefs: SharedPrefs(vault: vault),
    );

    final pending = auth.login('old@example.test', 'synthetic-pass');
    expect(await network.requested.future, '/api/v1/Login');

    // A different screen explicitly logs the account out while the earlier
    // login request is still in flight.
    await SharedPrefs(vault: vault).clear();
    network.response.complete(NetworkResponse(
      isSuccess: true,
      statusCode: 200,
      responseData: {
        'token': 'rejected-out-of-order-token',
        'name': 'Old Account',
        'email': 'old@example.test',
        'userId': '1',
      },
    ));

    expect(await pending, isFalse);
    expect(vault.value, isNull);
    expect(Session.accessToken, isNull);
    expect(auth.user.value, isNull);
  });

  test('late OTP approval after logout cannot restore obsolete bearer',
      () async {
    final vault = _MemoryVault();
    final network = _HeldAuthenticationNetwork();
    final auth = AuthController(
      client: network,
      prefs: SharedPrefs(vault: vault),
    );

    final pending = auth.verifyOtp('old@example.test', '123456');
    expect(await network.requested.future, '/api/v1/VerifyOTP');

    await SharedPrefs(vault: vault).clear();
    network.response.complete(NetworkResponse(
      isSuccess: true,
      statusCode: 200,
      responseData: {'token': 'rejected-otp-token'},
    ));

    expect(await pending, isFalse);
    expect(vault.value, isNull);
    expect(Session.accessToken, isNull);
  });
}
