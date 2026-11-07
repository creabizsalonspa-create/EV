import 'dart:convert';
import 'dart:ui';

import 'package:logger/logger.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

part 'network_responce.dart';

class NetworkClient {
  final Logger _logger = Logger();
  final String _defaultErrorMessage = 'Something went wrong';

  final VoidCallback onUnAuthorize;
  final Map<String, String> Function() commonHeaders;

  /// You can inject a preconfigured Dio if you want (for tests or custom baseUrl).
  final Dio _dio;

  NetworkClient({
    required this.onUnAuthorize,
    required this.commonHeaders,
    Dio? dio,
    String? baseUrl,
    Duration connectTimeout = const Duration(seconds: 15),
    Duration receiveTimeout = const Duration(seconds: 20),
    Duration sendTimeout = const Duration(seconds: 20),
  }) : _dio =
           dio ??
           Dio(
             BaseOptions(
               baseUrl: baseUrl ?? '',
               connectTimeout: connectTimeout,
               receiveTimeout: receiveTimeout,
               sendTimeout: sendTimeout,
               // Don’t throw on non-200; we’ll handle statuses ourselves.
               validateStatus: (code) =>
                   code != null && code >= 100 && code < 600,
               // Let server decide content-type; we’ll still send JSON bodies.
               responseType: ResponseType.json,
               followRedirects: false,
             ),
           ) {
    // Basic interceptors for headers + logging
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          // Reject foreign origins before the transport can send credentials.
          final origin = Uri.parse(_dio.options.baseUrl);
          if (options.uri.origin != origin.origin) {
            handler.reject(
              DioException(
                requestOptions: options,
                type: DioExceptionType.cancel,
                message: 'External API destinations are not permitted',
              ),
            );
            return;
          }
          final path = options.uri.path.toLowerCase();
          final paymentWrite =
              path.contains('/payment') || path.endsWith('/pay');
          final chargerWrite =
              path.contains('/chargingsession/') &&
              (path.endsWith('/start') ||
                  path.endsWith('/stop') ||
                  path.endsWith('/extend'));
          if (options.method != 'GET' &&
              options.method != 'HEAD' &&
              (paymentWrite || chargerWrite)) {
            handler.reject(
              DioException(
                requestOptions: options,
                type: DioExceptionType.cancel,
                message: chargerWrite
                    ? 'Remote controls unavailable: authorized hardware validation required'
                    : 'Payments unavailable: verified merchant test configuration required',
              ),
            );
            return;
          }
          options.extra['sessionAuthorization'] =
              commonHeaders()['Authorization'];
          options.followRedirects = false;
          final headers = commonHeaders();
          for (final entry in headers.entries) {
            options.headers.putIfAbsent(entry.key, () => entry.value);
          }
          final sessionAuth = commonHeaders()['Authorization'];
          if (sessionAuth != null &&
              options.headers.entries.any(
                (entry) =>
                    entry.key.toLowerCase() == 'authorization' &&
                    entry.value != sessionAuth,
              )) {
            handler.reject(
              DioException(
                requestOptions: options,
                type: DioExceptionType.cancel,
                message: 'Session changed; discard stale credentials',
              ),
            );
            return;
          }
          _logRequest(options);
          handler.next(options);
        },
        onResponse: (response, handler) {
          if (response.requestOptions.extra['sessionAuthorization'] !=
              commonHeaders()['Authorization']) {
            handler.reject(
              DioException(
                requestOptions: response.requestOptions,
                type: DioExceptionType.cancel,
                message: 'Session changed; discard stale response',
              ),
            );
            return;
          }
          _logResponse(response);
          handler.next(response);
        },
        onError: (e, handler) {
          _logError(e);
          handler.next(e);
        },
      ),
    );
  }

  // -------------------- PUBLIC METHODS --------------------
  Future<NetworkResponse> getRequest(
    String url, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    String? bearerToken,
    Map<String, String>? headers,
  }) async {
    try {
      // ✅ Merge global + custom headers safely
      final mergedHeaders = <String, String>{};

      // Step 1: start with global headers
      mergedHeaders.addAll(commonHeaders());

      // Step 2: add per-request headers
      if (headers != null) mergedHeaders.addAll(headers);

      // Step 3: override Authorization only if provided
      if (bearerToken != null && bearerToken.isNotEmpty) {
        mergedHeaders['Authorization'] = 'Bearer $bearerToken';
      }

      final Response response = await _dio.get(
        url,
        queryParameters: query,
        cancelToken: cancelToken,
        options: Options(headers: mergedHeaders),
      );

      return _handleResponse(response);
    } on DioException catch (e) {
      return _handleDioError(e);
    } catch (e) {
      return _unknownError(e);
    }
  }

  Future<NetworkResponse> postRequest(
    String url, {
    Map<String, dynamic>? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    String? bearerToken,
    Map<String, String>? headers,
  }) async {
    try {
      final mergedHeaders = <String, String>{}
        ..addAll(commonHeaders())
        ..addAll(headers ?? {});
      if (bearerToken != null && bearerToken.isNotEmpty) {
        mergedHeaders['Authorization'] = 'Bearer $bearerToken';
      }

      final Response response = await _dio.post(
        url,
        data: body,
        queryParameters: query,
        cancelToken: cancelToken,
        options: Options(headers: mergedHeaders),
      );
      return _handleResponse(response);
    } on DioException catch (e) {
      return _handleDioError(e);
    } catch (e) {
      return _unknownError(e);
    }
  }

  Future<NetworkResponse> putRequest(
    String url, {
    dynamic body, // <-- changed from Map<String, dynamic>?
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    try {
      final Response response = await _dio.put(
        url,
        data: body, // Works with Map OR FormData
        queryParameters: query,
        cancelToken: cancelToken,
      );
      return _handleResponse(response);
    } on DioException catch (e) {
      return _handleDioError(e);
    } catch (e) {
      return _unknownError(e);
    }
  }

  /// Upload file via multipart/form-data POST
  Future<NetworkResponse> uploadRequest(
    String url, {
    required FormData formData,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    try {
      final Response response = await _dio.post(
        url,
        data: formData,
        queryParameters: query,
        cancelToken: cancelToken,
      );
      return _handleResponse(response);
    } on DioException catch (e) {
      return _handleDioError(e);
    } catch (e) {
      return _unknownError(e);
    }
  }

  Future<NetworkResponse> patchRequest(
    String url, {
    Map<String, dynamic>? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    try {
      final Response response = await _dio.patch(
        url,
        data: body,
        queryParameters: query,
        cancelToken: cancelToken,
      );
      return _handleResponse(response);
    } on DioException catch (e) {
      return _handleDioError(e);
    } catch (e) {
      return _unknownError(e);
    }
  }

  Future<NetworkResponse> deleteRequest(
    String url, {
    Map<String, dynamic>? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
  }) async {
    try {
      final Response response = await _dio.delete(
        url,
        data: body,
        queryParameters: query,
        cancelToken: cancelToken,
      );
      return _handleResponse(response);
    } on DioException catch (e) {
      return _handleDioError(e);
    } catch (e) {
      return _unknownError(e);
    }
  }

  // -------------------- INTERNAL HANDLERS --------------------

  NetworkResponse _handleResponse(Response response) {
    final status = response.statusCode ?? -1;

    // Normalize data: Dio may already give Map/List; if it's String, try parse
    final dynamic data = _normalizeData(response.data);

    if (status == 200 || status == 201) {
      return NetworkResponse(
        isSuccess: true,
        statusCode: status,
        responseData: data,
      );
    }

    if (status == 401) {
      onUnAuthorize();
      return NetworkResponse(
        isSuccess: false,
        statusCode: status,
        errorMessage: 'Un-authorize',
        responseData: data,
      );
    }

    // Generic failure with best-effort message extraction
    final msg = _extractMessage(data) ?? _defaultErrorMessage;
    return NetworkResponse(
      isSuccess: false,
      statusCode: status,
      errorMessage: msg,
      responseData: data,
    );
  }

  NetworkResponse _handleDioError(DioException e) {
    // If there’s a response, treat like HTTP error
    final res = e.response;
    if (res != null) {
      final status = res.statusCode ?? -1;
      final dynamic data = _normalizeData(res.data);

      if (status == 401) {
        onUnAuthorize();
        return NetworkResponse(
          isSuccess: false,
          statusCode: status,
          errorMessage: 'Un-authorize',
          responseData: data,
        );
      }

      final msg = _extractMessage(data) ?? e.message ?? _defaultErrorMessage;
      return NetworkResponse(
        isSuccess: false,
        statusCode: status,
        errorMessage: msg,
        responseData: data,
      );
    }

    // No response: network / timeout / cancel
    final errMsg = switch (e.type) {
      // DioExceptionType.connectionTimeout => 'Connection timed out',
      DioExceptionType.sendTimeout => 'Send timed out',
      DioExceptionType.receiveTimeout => 'Receive timed out',
      DioExceptionType.cancel => 'Request cancelled',
      DioExceptionType.connectionError => 'Network error',
      DioExceptionType.badCertificate => 'Bad certificate',
      _ => e.message ?? _defaultErrorMessage,
    };

    return NetworkResponse(
      isSuccess: false,
      statusCode: -1,
      errorMessage: errMsg,
    );
  }

  NetworkResponse _unknownError(Object e) {
    return NetworkResponse(
      isSuccess: false,
      statusCode: -1,
      errorMessage: e.toString(),
    );
  }

  // -------------------- LOGGING --------------------

  // Never log bearer tokens, credentials, payment secrets, request parameters
  // or response bodies. Diagnostic logs must remain safe even in debug builds.
  void _logRequest(RequestOptions options) {
    if (!kDebugMode) return;
    _logger.i('[REQUEST] ${options.method} API');
  }

  void _logResponse(Response response) {
    if (!kDebugMode) return;
    final req = response.requestOptions;
    _logger.i('[RESPONSE] ${req.method} API HTTP ${response.statusCode}');
  }

  void _logError(DioException e) {
    if (!kDebugMode) return;
    final req = e.requestOptions;
    _logger.w(
      '[NETWORK ERROR] ${req.method} API '
      'HTTP ${e.response?.statusCode ?? 'none'} / ${e.type}',
    );
  }

  // -------------------- HELPERS --------------------

  dynamic _normalizeData(dynamic data) {
    // Dio commonly returns Map/List already.
    // If it’s a String (e.g., text/plain), try parsing JSON.
    if (data is String) {
      try {
        return jsonDecode(data);
      } catch (_) {
        return data; // keep raw string
      }
    }
    return data;
  }

  String? _extractMessage(dynamic data) {
    if (data is Map) {
      // try common fields
      for (final k in ['msg', 'message', 'error', 'detail']) {
        final v = data[k];
        if (v is String && v.trim().isNotEmpty) return v;
      }
    }
    return null;
  }
}
