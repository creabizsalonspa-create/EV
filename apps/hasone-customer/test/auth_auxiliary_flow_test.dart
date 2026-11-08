import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:evcharg/core/service/network/network_client.dart';
import 'package:evcharg/feature/Auth/UI/controller/auth_controller.dart';

// No live network, emails, identities, payments or physical charger commands.
class _HeldAuxiliaryNetwork extends NetworkClient {
  _HeldAuxiliaryNetwork()
    : super(onUnAuthorize: () {}, commonHeaders: () => {});

  final requests = <String>[];
  final responses = <Completer<NetworkResponse>>[];
  final firstRequested = Completer<void>();
  final secondRequested = Completer<void>();

  @override
  Future<NetworkResponse> postRequest(
    String url, {
    Map<String, dynamic>? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    String? bearerToken,
    Map<String, String>? headers,
  }) {
    requests.add(url);
    final response = Completer<NetworkResponse>();
    responses.add(response);
    if (requests.length == 1 && !firstRequested.isCompleted) {
      firstRequested.complete();
    }
    if (requests.length == 2 && !secondRequested.isCompleted) {
      secondRequested.complete();
    }
    return response.future;
  }
}

NetworkResponse _success([String message = 'Synthetic success']) =>
    NetworkResponse(
      isSuccess: true,
      statusCode: 200,
      responseData: {'message': message},
    );

void main() {
  test(
    'registration transport failure resets loading and retains prior email',
    () async {
      final network = _HeldAuxiliaryNetwork();
      final feedback = <String>[];
      final auth = AuthController(
        client: network,
        authFeedback: (title, _) => feedback.add(title),
      );
      auth.pendingEmail = 'original@example.test';

      final pending = auth.register(
        'Synthetic User',
        'new@example.test',
        '000000',
        'synthetic-only',
      );
      await network.firstRequested.future;
      expect(auth.loading.value, isTrue);
      expect(network.requests.single, '/api/v1/Register');
      network.responses.single.completeError(StateError('synthetic offline'));

      expect(await pending, isFalse);
      expect(auth.loading.value, isFalse);
      expect(auth.pendingEmail, 'original@example.test');
      expect(feedback, ['Registration Failed']);
    },
  );

  test(
    'an obsolete password request cannot replace newer registration OTP',
    () async {
      final network = _HeldAuxiliaryNetwork();
      final feedback = <String>[];
      final auth = AuthController(
        client: network,
        authFeedback: (title, _) => feedback.add(title),
      );

      final old = auth.forgotPassword('old@example.test');
      await network.firstRequested.future;
      final latest = auth.register(
        'Synthetic User',
        'latest@example.test',
        '000000',
        'synthetic-only',
      );
      await network.secondRequested.future;
      network.responses[1].complete(_success());
      expect(await latest, isTrue);
      expect(auth.loading.value, isFalse);
      network.responses[0].complete(_success());
      expect(await old, isFalse);
      expect(auth.pendingEmail, 'latest@example.test');
      expect(feedback, ['OTP Sent']);
    },
  );

  test('obsolete response must not dismiss newer loading indicator', () async {
    final network = _HeldAuxiliaryNetwork();
    final auth = AuthController(client: network, authFeedback: (_, __) {});
    final old = auth.forgotPassword('old@example.test');
    await network.firstRequested.future;
    final latest = auth.verifyForgotOtp('latest@example.test', '123456');
    await network.secondRequested.future;
    network.responses[0].complete(_success());
    expect(await old, isFalse);
    expect(auth.loading.value, isTrue);
    network.responses[1].complete(_success());
    expect(await latest, isTrue);
    expect(auth.loading.value, isFalse);
  });

  test('OTP resend network error clears spinner with safe feedback', () async {
    final network = _HeldAuxiliaryNetwork();
    final feedback = <String>[];
    final auth = AuthController(
      client: network,
      authFeedback: (title, _) => feedback.add(title),
    );
    final pending = auth.resendOtp('synthetic@example.test');
    await network.firstRequested.future;
    expect(auth.resendLoading.value, isTrue);
    network.responses.single.completeError(StateError('synthetic offline'));
    await pending;
    expect(auth.resendLoading.value, isFalse);
    expect(feedback, ['Error']);
  });

  test('older OTP resend cannot clear or notify over newer request', () async {
    final network = _HeldAuxiliaryNetwork();
    final feedback = <String>[];
    final auth = AuthController(
      client: network,
      authFeedback: (title, _) => feedback.add(title),
    );
    final old = auth.resendOtp('old@example.test');
    await network.firstRequested.future;
    final latest = auth.resendOtp('latest@example.test');
    await network.secondRequested.future;
    network.responses[0].complete(_success());
    await old;
    expect(auth.resendLoading.value, isTrue);
    expect(feedback, isEmpty);
    network.responses[1].complete(_success());
    await latest;
    expect(auth.resendLoading.value, isFalse);
    expect(feedback, ['OTP Resent']);
  });

  test('reset-password feedback exception cannot strand spinner', () async {
    final network = _HeldAuxiliaryNetwork();
    final auth = AuthController(
      client: network,
      authFeedback: (_, __) => throw StateError('overlay unmounted'),
    );
    final pending = auth.resetPassword(
      'synthetic@example.test',
      'synthetic-password',
      'synthetic-password',
    );
    await network.firstRequested.future;
    network.responses.single.complete(_success());
    expect(await pending, isTrue);
    expect(auth.loading.value, isFalse);
  });
}
