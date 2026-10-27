import 'dart:convert';
import 'dart:io';

class ApiException implements Exception {
  final int status;
  final String message;
  const ApiException(this.status, this.message);
  @override
  String toString() => message;
}

class Account {
  final int id;
  final String name;
  final String role;
  Account.fromJson(Map<String, dynamic> json)
    : id = json['id'] as int,
      name = json['name'] as String,
      role = json['role'] as String;
}

class Station {
  final int id;
  final String name;
  final String connector;
  final String source;
  final int powerKw;
  final bool reservable;
  Station.fromJson(Map<String, dynamic> json)
    : id = json['id'] as int,
      name = json['name'] as String,
      connector = json['connector_type'] as String,
      source = json['evidence'] as String,
      powerKw = json['power_kw'] as int,
      reservable = json['reservable'] == true;
}

/// Native-only same-origin session client. Credentials remain in memory.
/// No mock stations, bearer shortcuts, certificate overrides or automatic replay.
class EvApi {
  final Uri base;
  final HttpClient _http;
  final Map<String, Cookie> _cookies = {};
  String? _csrf;

  EvApi(this.base, {HttpClient? http}) : _http = http ?? HttpClient() {
    const release = bool.fromEnvironment('dart.vm.product');
    final loopback = ['localhost', '127.0.0.1', '::1'].contains(base.host);
    if (base.host.isEmpty ||
        base.userInfo.isNotEmpty ||
        base.hasQuery ||
        base.hasFragment ||
        (base.scheme != 'https' && (release || !loopback)) ||
        (base.path.isNotEmpty && base.path != '/')) {
      throw ArgumentError(
        'Use an HTTPS service origin; debug HTTP is loopback-only.',
      );
    }
    _http.connectionTimeout = const Duration(seconds: 15);
  }

  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
  }) async {
    if (!RegExp(r'^[a-z0-9/-]+$').hasMatch(path)) {
      throw ArgumentError('Invalid API path');
    }
    try {
      final req = await _http.openUrl(method, base.resolve('/api/$path'));
      req.followRedirects = false;
      req.headers.set(HttpHeaders.acceptHeader, 'application/json');
      req.headers.contentType = ContentType.json;
      req.cookies.addAll(
        _cookies.values.where(
          (cookie) => !cookie.secure || base.scheme == 'https',
        ),
      );
      if (_csrf != null) req.headers.set('X-CSRF-TOKEN', _csrf!);
      if (body != null) req.write(jsonEncode(body));
      final response = await req.close().timeout(const Duration(seconds: 25));
      for (final cookie in response.cookies) {
        if ((cookie.maxAge != null && cookie.maxAge! <= 0) ||
            (cookie.expires?.isBefore(DateTime.now()) ?? false)) {
          _cookies.remove(cookie.name);
        } else {
          _cookies[cookie.name] = cookie;
        }
      }
      final text = await response
          .transform(utf8.decoder)
          .join()
          .timeout(const Duration(seconds: 25));
      if (response.statusCode == 401) {
        _cookies.clear();
        _csrf = null;
        throw const ApiException(
          401,
          'Your session ended. Please sign in again.',
        );
      }
      if (response.statusCode == 204) return null;
      dynamic data;
      try {
        data = jsonDecode(text);
      } on FormatException {
        throw ApiException(
          response.statusCode,
          'The service returned an unreadable response.',
        );
      }
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw ApiException(
          response.statusCode,
          data is Map
              ? (data['message'] as String? ?? 'Request failed.')
              : 'Request failed.',
        );
      }
      return data;
    } on SocketException {
      throw const ApiException(0, 'Unable to connect. Check your connection.');
    }
  }

  Future<void> csrf() async {
    final data = await request('csrf');
    _csrf = data['token'] as String;
  }

  Future<Account> signIn(String email, String password, {String? name}) async {
    await csrf();
    final data = await request(
      name == null ? 'login' : 'register',
      method: 'POST',
      body: {
        'email': email,
        'password': password,
        if (name != null) 'name': name,
      },
    );
    await csrf();
    return Account.fromJson(Map<String, dynamic>.from(data as Map));
  }

  Future<void> signOut() async {
    try {
      await request('logout', method: 'POST');
    } finally {
      _cookies.clear();
      _csrf = null;
    }
  }

  Future<List<Station>> stations() async {
    final data = await request('stations');
    return (data['data'] as List)
        .map((j) => Station.fromJson(Map<String, dynamic>.from(j as Map)))
        .toList();
  }

  void close() => _http.close(force: true);
}
