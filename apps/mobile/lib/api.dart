import 'dart:async';
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
  final bool emailVerified;
  Account.fromJson(Map<String, dynamic> json)
    : id = json['id'] as int,
      name = json['name'] as String,
      role = json['role'] as String,
      emailVerified = json['email_verified_at'] != null;
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
    Map<String, String> query = const {},
  }) async {
    if (!RegExp(r'^[a-z0-9/-]+$').hasMatch(path)) {
      throw ArgumentError('Invalid API path');
    }
    try {
      final req = await _http.openUrl(
        method,
        base
            .resolve('/api/$path')
            .replace(queryParameters: query.isEmpty ? null : query),
      );
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
    } on TimeoutException {
      throw const ApiException(
        0,
        'Connection timed out. Refresh before trying an action again.',
      );
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

  Future<ApiPage<Station>> catalog({
    CatalogFilter filter = const CatalogFilter(),
    int page = 1,
  }) async {
    final data = await request(
      'stations',
      query: {...filter.query, 'page': '$page'},
    );
    return ApiPage.fromJson(data, Station.fromJson);
  }

  Future<List<Station>> stations() async {
    final data = await request('stations');
    return (data['data'] as List)
        .map((j) => Station.fromJson(Map<String, dynamic>.from(j as Map)))
        .toList();
  }

  void close() => _http.close(force: true);
}

class CatalogFilter {
  final String search;
  final String connector;
  final String country;
  final int? minimumPower;
  const CatalogFilter({
    this.search = '',
    this.connector = '',
    this.country = '',
    this.minimumPower,
  });
  Map<String, String> get query => {
    if (search.trim().isNotEmpty) 'q': search.trim(),
    if (connector.isNotEmpty) 'connector_type': connector,
    if (country.trim().isNotEmpty) 'country': country.trim().toUpperCase(),
    if (minimumPower != null) 'min_power_kw': '$minimumPower',
  };
}

class ApiPage<T> {
  final List<T> items;
  final int current;
  final int last;
  final int total;
  const ApiPage({
    required this.items,
    required this.current,
    required this.last,
    required this.total,
  });
  factory ApiPage.fromJson(
    dynamic data,
    T Function(Map<String, dynamic>) parse,
  ) {
    if (data is! Map ||
        data['data'] is! List ||
        data['current_page'] is! int ||
        data['last_page'] is! int ||
        data['total'] is! int) {
      throw const ApiException(502, 'Invalid pagination response.');
    }
    return ApiPage(
      items: (data['data'] as List)
          .map((row) => parse(Map<String, dynamic>.from(row as Map)))
          .toList(),
      current: data['current_page'] as int,
      last: data['last_page'] as int,
      total: data['total'] as int,
    );
  }
}
