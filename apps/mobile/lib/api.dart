import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'dart:math';
import 'session_vault.dart';

/// Non-secret operation identity; retained only for the current explicit form.
String operationKey() {
  final bytes = List<int>.generate(16, (_) => Random.secure().nextInt(256));
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  final h = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

class PrivateDocument {
  final Uint8List bytes;
  final String mime;
  const PrivateDocument(this.bytes, this.mime);
}

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
  final String? reservationPolicy;
  final int? mappingId, evseNumber, connectorNumber;
  final double? distanceKm;
  Station.fromJson(Map<String, dynamic> json)
    : id = json['id'] as int,
      name = json['name'] as String,
      connector = json['connector_type'] as String,
      source = json['evidence'] as String,
      powerKw = json['power_kw'] as int,
      reservable = json['reservable'] == true,
      reservationPolicy = json['reservation_policy'] as String?,
      distanceKm = (json['distance_km'] as num?)?.toDouble(),
      mappingId = null,
      evseNumber = null,
      connectorNumber = null;
  Station.forConnector(Station parent, Map<String, dynamic> option)
    : distanceKm = parent.distanceKm,
      id = parent.id,
      name = parent.name,
      connector = option['connector_type'] as String,
      source = parent.source,
      powerKw = option['max_power_kw'] as int,
      reservable = option['reservable'] == true,
      reservationPolicy = 'canonical',
      mappingId = option['id'] as int,
      evseNumber = option['evse_number'] as int,
      connectorNumber = option['connector_number'] as int;
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
      // A platform write may have completed before reporting an error. Remove
      // any snapshot; if removal also fails, retain the conservative state so
      // logout cannot claim the device credential was deleted.
      try {
        await forgetSession();
      } on SessionStorageException {
        _remembered = true;
      }
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
  }) => _request(path, method: method, body: body, query: query);

  Future<dynamic> uploadDocument(
    String path, {
    required String kind,
    required Uint8List bytes,
    required String mime,
    required String key,
  }) {
    if (!['trade_license', 'identity', 'agreement'].contains(kind) ||
        !['application/pdf', 'image/png', 'image/jpeg'].contains(mime) ||
        bytes.isEmpty ||
        bytes.length > 5 * 1024 * 1024)
      throw const ApiException(422, 'Select a PDF, PNG or JPEG up to5 MB.');
    if (!RegExp(r'^[a-zA-Z0-9-]{1,100}$').hasMatch(key))
      throw ArgumentError('Invalid operation identity');
    final boundary = 'ev' + operationKey().replaceAll('-', '');
    final extension = {
      'application/pdf': 'pdf',
      'image/png': 'png',
      'image/jpeg': 'jpg',
    }[mime];
    final body = BytesBuilder(copy: false)
      ..add(
        utf8.encode(
          '--$boundary\r\nContent-Disposition: form-data; name="kind"\r\n\r\n$kind\r\n--$boundary\r\nContent-Disposition: form-data; name="idempotency_key"\r\n\r\n$key\r\n--$boundary\r\nContent-Disposition: form-data; name="document"; filename="document.$extension"\r\nContent-Type: $mime\r\n\r\n',
        ),
      )
      ..add(bytes)
      ..add(utf8.encode('\r\n--$boundary--\r\n'));
    return _request(
      path,
      method: 'POST',
      uploadBytes: body.takeBytes(),
      uploadType: 'multipart/form-data; boundary=$boundary',
    );
  }

  Future<PrivateDocument> downloadDocument(String path) async =>
      await _request(path, binary: true) as PrivateDocument;

  Future<dynamic> _request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
    Uint8List? uploadBytes,
    String? uploadType,
    bool binary = false,
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
      req.headers.contentType = uploadType == null
          ? ContentType.json
          : ContentType.parse(uploadType);
      req.cookies.addAll(
        _cookies.values.where(
          (cookie) => !cookie.secure || base.scheme == 'https',
        ),
      );
      if (_csrf != null) req.headers.set('X-CSRF-TOKEN', _csrf!);
      if (uploadBytes != null) {
        req.contentLength = uploadBytes.length;
        req.add(uploadBytes);
      } else if (body != null)
        req.write(jsonEncode(body));
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
      final chunks = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 25))) {
        if (chunks.length + chunk.length >
            (binary ? 5 * 1024 * 1024 : 8 * 1024 * 1024))
          throw const ApiException(502, 'Response exceeds the supported size.');
        chunks.add(chunk);
      }
      final bytes = chunks.takeBytes();
      // Logout/forget may run while a response body is streaming. Recheck
      // before returning private data or scheduling a rotated-cookie save.
      if (epoch != _epoch) {
        throw const ApiException(409, 'Session changed.');
      }
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
      if (epoch != _epoch) throw const ApiException(409, 'Session changed.');
      if (binary && response.statusCode >= 200 && response.statusCode < 300) {
        final mime = response.headers.contentType?.mimeType;
        if (!['application/pdf', 'image/png', 'image/jpeg'].contains(mime))
          throw const ApiException(502, 'Unsupported private document type.');
        return PrivateDocument(bytes, mime!);
      }
      final text = utf8.decode(bytes, allowMalformed: true);
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
  final double? latitude, longitude, radiusKm;
  const CatalogFilter({
    this.search = '',
    this.connector = '',
    this.country = '',
    this.minimumPower,
    this.latitude,
    this.longitude,
    this.radiusKm,
  });
  Map<String, String> get query => {
    if (search.trim().isNotEmpty) 'q': search.trim(),
    if (connector.isNotEmpty) 'connector_type': connector,
    if (country.trim().isNotEmpty) 'country': country.trim().toUpperCase(),
    if (minimumPower != null) 'min_power_kw': '$minimumPower',
    if (latitude != null) 'latitude': '$latitude',
    if (longitude != null) 'longitude': '$longitude',
    if (radiusKm != null) 'radius_km': '$radiusKm',
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
