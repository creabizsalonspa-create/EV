import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'session_vault.dart';

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

/// Native-only same-origin session client; persistence is opt-in and encrypted.
/// No mock stations, bearer shortcuts, certificate overrides or automatic replay.
class EvApi {
  final Uri base;
  final HttpClient _http;
  final Map<String, Cookie> _cookies = {};
  String? _csrf;
  final SessionVault? vault;
  bool _remembered = false;
  int _epoch = 0;
  Future<void> _vaultTail = Future.value();
  bool get canRemember => base.scheme == 'https' && vault != null;
  bool get remembered => _remembered;

  Future<T> _vaultOperation<T>(Future<T> Function() action) {
    final result = _vaultTail.then((_) => action());
    _vaultTail = result.then<void>((_) {}, onError: (_) {});
    return result;
  }

  bool _validCookie(Cookie c) {
    final domain = c.domain?.replaceFirst(RegExp(r'^\.'), '');
    return (domain == null ||
            domain == base.host ||
            base.host.endsWith('.$domain')) &&
        (c.path == null || c.path == '/' || c.path == '/api');
  }

  Future<void> forgetSession() async {
    // Invalidate pending saves before deletion; a late response cannot recreate it.
    _epoch++;
    try {
      if (vault != null)
        await _vaultOperation(() => vault!.delete(base.origin));
      _remembered = false;
    } catch (_) {
      throw const SessionStorageException();
    }
  }

  Future<bool> rememberSession() async {
    if (!canRemember) return false;
    final epoch = _epoch;
    final now = DateTime.now().toUtc();
    // Persist only server-expiring, secure, HTTP-only credentials, never XSRF.
    final cookies = _cookies.values
        .where(
          (c) =>
              c.httpOnly &&
              c.secure &&
              _validCookie(c) &&
              c.expires != null &&
              c.expires!.isAfter(now),
        )
        .toList();
    if (cookies.isEmpty) {
      if (_remembered) await forgetSession();
      return false;
    }
    final expires = cookies
        .map((c) => c.expires!.toUtc())
        .reduce((a, b) => a.isBefore(b) ? a : b);
    final ceiling = now.add(const Duration(hours: 24));
    final value = jsonEncode({
      'version': 1,
      'origin': base.origin,
      'saved_at': now.toIso8601String(),
      'expires': (expires.isBefore(ceiling) ? expires : ceiling)
          .toIso8601String(),
      'cookies': cookies.map((c) => c.toString()).toList(),
    });
    try {
      final saved = await _vaultOperation(() async {
        if (epoch != _epoch) return false;
        await vault!.write(base.origin, value);
        return epoch == _epoch;
      });
      if (saved) _remembered = true;
      return saved;
    } catch (_) {
      _remembered = false;
      throw const SessionStorageException();
    }
  }

  Future<Account?> restoreSession() async {
    if (!canRemember) return null;
    String? raw;
    try {
      raw = await _vaultOperation(() => vault!.read(base.origin));
    } catch (_) {
      throw const SessionStorageException();
    }
    if (raw == null) return null;
    final now = DateTime.now().toUtc();
    try {
      if (raw.length > 65536) throw const FormatException();
      final data = jsonDecode(raw);
      final saved = DateTime.parse(data['saved_at'] as String).toUtc();
      final expiry = DateTime.parse(data['expires'] as String).toUtc();
      if (data['version'] != 1 ||
          data['origin'] != base.origin ||
          !expiry.isAfter(now) ||
          saved.isAfter(now.add(const Duration(minutes: 1))) ||
          expiry.isAfter(saved.add(const Duration(hours: 24))))
        throw const FormatException();
      final values = data['cookies'] as List;
      if (values.isEmpty || values.length > 16) throw const FormatException();
      final cookies = values
          .map((v) => Cookie.fromSetCookieValue(v as String))
          .toList();
      if (cookies.any(
        (c) =>
            !c.httpOnly ||
            !c.secure ||
            !_validCookie(c) ||
            c.expires == null ||
            !c.expires!.isAfter(now),
      ))
        throw const FormatException();
      _cookies.clear();
      for (final cookie in cookies) {
        _cookies[cookie.name] = cookie;
      }
    } catch (_) {
      await forgetSession();
      _cookies.clear();
      _csrf = null;
      return null;
    }
    try {
      final current = Account.fromJson(
        Map<String, dynamic>.from(await request('me') as Map),
      );
      if (!['customer', 'vendor'].contains(current.role)) {
        await signOut();
        return null;
      }
      await csrf();
      await rememberSession();
      return current;
    } on ApiException catch (e) {
      _cookies.clear();
      _csrf = null;
      if ([401, 419].contains(e.status)) return null;
      rethrow;
    }
  }

  EvApi(this.base, {HttpClient? http, this.vault})
    : _http = http ?? HttpClient() {
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
    final epoch = _epoch;
    try {
      _cookies.removeWhere(
        (_, c) => c.expires != null && !c.expires!.isAfter(DateTime.now()),
      );
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
      if (epoch != _epoch) {
        await response.drain<void>();
        throw const ApiException(409, 'Session changed.');
      }
      for (final cookie in response.cookies) {
        if (!_validCookie(cookie)) continue;
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
      if ([401, 419].contains(response.statusCode)) {
        _cookies.clear();
        _csrf = null;
        try {
          await forgetSession();
        } on SessionStorageException {
          _remembered = false;
        }
        throw ApiException(
          response.statusCode,
          'Your session ended. Please sign in again.',
        );
      }
      if (_remembered &&
          response.statusCode >= 200 &&
          response.statusCode < 300)
        await rememberSession();
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
    await forgetSession();
    _cookies.clear();
    _csrf = null;
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
    await forgetSession();
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

  void close() {
    _epoch++;
    _remembered = false;
    _cookies.clear();
    _csrf = null;
    _http.close(force: true);
  }
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
