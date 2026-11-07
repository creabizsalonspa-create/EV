import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:evcharg/core/service/api_service/api_config.dart';
import 'package:evcharg/core/service/network/network_client.dart';

class RecordingAdapter implements HttpClientAdapter {
  int calls = 0;
  RequestOptions? request;
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? stream,
    Future<void>? cancelFuture,
  ) async {
    calls++;
    request = options;
    return ResponseBody.fromString(
      '{}',
      200,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test('Hasone is the HTTPS default origin', () {
    expect(ApiConfig.baseUrl, 'https://api.hasone.io');
  });
  late Dio dio;
  late RecordingAdapter adapter;
  late NetworkClient client;
  setUp(() {
    adapter = RecordingAdapter();
    dio = Dio(BaseOptions(baseUrl: ApiConfig.baseUrl));
    dio.httpClientAdapter = adapter;
    client = NetworkClient(
      dio: dio,
      commonHeaders: () => {'Authorization': 'Bearer test-session'},
      onUnAuthorize: () {},
    );
  });
  test('foreign destination never reaches transport', () async {
    final result = await client.getRequest(
      'https://foreign.invalid/api/v1/Profile',
    );
    expect(result.isSuccess, false);
    expect(adapter.calls, 0);
  });
  test('API reads retain auth and cannot follow redirects', () async {
    final result = await client.getRequest('/api/v1/Profile');
    expect(result.isSuccess, true);
    expect(adapter.request!.headers['Authorization'], 'Bearer test-session');
    expect(adapter.request!.followRedirects, false);
  });
  test(
    'unvalidated hardware and payment writes never reach transport',
    () async {
      for (final path in [
        '/api/v1/ChargingSession/abc/start',
        '/api/v1/ChargingSession/abc/stop',
        '/api/v1/ChargingSession/abc/extend',
        '/api/v1/PaymentCards',
        '/api/v1/Bookings/abc/payment',
        '/api/v1/payment/stripe/create-intent',
      ]) {
        expect((await client.postRequest(path)).isSuccess, false);
      }
      expect(adapter.calls, 0);
    },
  );
  test('old per-request token cannot cross into a new session', () async {
    final result = await client.getRequest(
      '/api/v1/Profile',
      bearerToken: 'old-session',
    );
    expect(result.isSuccess, false);
    expect(adapter.calls, 0);
  });
  test(
    'case-variant stale authorization is rejected before transport',
    () async {
      final result = await client.getRequest(
        '/api/v1/Profile',
        headers: {'authorization': 'Bearer old-session'},
      );
      expect(result.isSuccess, false);
      expect(adapter.calls, 0);
    },
  );
}
