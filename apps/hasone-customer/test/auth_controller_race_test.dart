import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
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

class _FailingUserCache extends SharedPrefs {
  _FailingUserCache(TokenVault vault) : super(vault: vault);

  @override
  Future<void> saveUser(Map<String, dynamic> user) async {
    throw StateError('synthetic profile persistence failure');
  }
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    Session.clear();
  });

  testWidgets('failed profile cache save cannot leave a live login token',
      (tester) async {
    await tester.pumpWidget(const GetMaterialApp(home: Scaffold()));
    final vault = _MemoryVault();
    final network = _HeldAuthenticationNetwork();
    final auth = AuthController(
      client: network,
      prefs: _FailingUserCache(vault),
    );

    final pending = auth.login('synthetic@example.test', 'synthetic-only');
    expect(await network.requested.future, '/api/v1/Login');
    network.response.complete(NetworkResponse(
      isSuccess: true,
      statusCode: 200,
      responseData: {
        'token': 'synthetic-token-must-not-survive',
        'name': 'Partial account',
        'email': 'synthetic@example.test',
        'userId': '17',
      },
    ));

    expect(await pending, isFalse);
    expect(Session.accessToken, isNull,
        reason: 'Unsuccessful login must not expose authenticated state');
    expect(vault.value, isNull,
        reason: 'Secure vault must not retain a partial login');
    expect(auth.user.value, isNull);
    // GetX's failure snackbar schedules a 3-second overlay timer and a
    // closing ticker. Drain both before disposing this widget test.
    await tester.pump(const Duration(seconds: 4));
    await tester.pumpAndSettle();
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

  test('two concurrent controllers ignore out-of-order old login',
      () async {
    final vault = _MemoryVault();
    final firstNetwork = _HeldAuthenticationNetwork();
    final secondNetwork = _HeldAuthenticationNetwork();
    final first = AuthController(
      client: firstNetwork,
      prefs: SharedPrefs(vault: vault),
    );
    final second = AuthController(
      client: secondNetwork,
      prefs: SharedPrefs(vault: vault),
    );

    final oldLogin = first.login('first@example.test', 'synthetic-pass');
    expect(await firstNetwork.requested.future, '/api/v1/Login');
    final newLogin = second.login('second@example.test', 'synthetic-pass');
    expect(await secondNetwork.requested.future, '/api/v1/Login');

    firstNetwork.response.complete(NetworkResponse(
      isSuccess: true,
      statusCode: 200,
      responseData: {'token': 'stale-first-account'},
    ));
    expect(await oldLogin, isFalse);
    expect(first.user.value, isNull);
    expect(vault.value, isNull);

    // Finish the second in-flight request only after an explicit logout,
    // so neither response should publish any account.
    await SharedPrefs(vault: vault).clear();
    secondNetwork.response.complete(NetworkResponse(
      isSuccess: true,
      statusCode: 200,
      responseData: {'token': 'stale-second-account'},
    ));
    expect(await newLogin, isFalse);
    expect(vault.value, isNull);
    expect(Session.accessToken, isNull);
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
