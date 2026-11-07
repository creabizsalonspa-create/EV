import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../session/session.dart';
import '../api_service/api_config.dart';

/// Injectable for deterministic Keychain/Keystore tests. Real sessions always
/// use secure platform storage; ordinary preferences must never hold tokens.
abstract interface class TokenVault {
  Future<void> write({required String key, required String value});
  Future<String?> read({required String key});
  Future<void> delete({required String key});
}

class DeviceTokenVault implements TokenVault {
  const DeviceTokenVault();
  static const FlutterSecureStorage _storage = FlutterSecureStorage();

  @override
  Future<void> write({required String key, required String value}) =>
      _storage.write(key: key, value: value);
  @override
  Future<String?> read({required String key}) => _storage.read(key: key);
  @override
  Future<void> delete({required String key}) => _storage.delete(key: key);
}

class SharedPrefs {
  // Never migrate an unscoped token from the obsolete Render backend.
  static String get _kAccess => 'access_token:${ApiConfig.baseUrl}';
  static String get _kUser => 'user:${ApiConfig.baseUrl}';

  final TokenVault _vault;
  SharedPrefs({TokenVault? vault}) : _vault = vault ?? const DeviceTokenVault();

  // All instances (Auth, Profile, Booking and NetworkService) share one
  // generation and serialized storage queue. An old read/write cannot
  // reinstate a token after logout or erase a later login's credential.
  static int _generation = 0;
  static Future<void> _tail = Future<void>.value();
  static int get credentialGeneration => _generation;

  static Future<T> _ordered<T>(Future<T> Function() action) {
    final result = _tail.then((_) => action());
    _tail = result.then<void>((_) {}, onError: (Object _, StackTrace __) {});
    return result;
  }

  Future<void> saveToken(String token) {
    if (token.trim().isEmpty) {
      throw ArgumentError.value(token, 'token', 'Empty session token');
    }
    final generation = ++_generation;
    return _ordered(() async {
      if (generation != _generation) return;
      await _vault.write(key: _kAccess, value: token);
      if (generation == _generation) Session.setToken(token);
    });
  }

  Future<String?> getToken() async {
    final generation = _generation;
    final saved = await _ordered(() => _vault.read(key: _kAccess));
    if (generation != _generation) return null;
    Session.setToken(saved);
    return saved;
  }

  String? get tokenSync => Session.accessToken;

  Future<void> saveUser(Map<String, dynamic> user) {
    final generation = _generation;
    return _ordered(() async {
      if (generation != _generation) return;
      final prefs = await SharedPreferences.getInstance();
      if (generation != _generation) return;
      await prefs.setString(_kUser, jsonEncode(user));
    });
  }

  Future<Map<String, dynamic>?> getUser() async {
    final generation = _generation;
    final saved = await _ordered(() async {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_kUser);
    });
    if (generation != _generation || saved == null) return null;
    return jsonDecode(saved) as Map<String, dynamic>;
  }

  Future<void> clear() {
    // Invalidate all pending reads/writes immediately, before waiting for the
    // platform vault. Never report a new session as logged out later.
    final generation = ++_generation;
    Session.clear();
    return _ordered(() async {
      // Delete the rejected token before removing the cached profile. If
      // deletion fails, throw: the credential may still exist on the device.
      await _vault.delete(key: _kAccess);
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_kUser);
      if (generation == _generation) Session.clear();
    });
  }
}
