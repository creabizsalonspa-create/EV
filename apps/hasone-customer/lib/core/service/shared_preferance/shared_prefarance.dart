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
  // Keep credentials device-bound on iOS and encrypted through Android's
  // Keystore-backed preferences. Never fall back to plaintext preferences.
  static const FlutterSecureStorage _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(
      accessibility: KeychainAccessibility.unlocked_this_device,
    ),
  );

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
  // Non-secret fail-closed marker survives app restarts if vault deletion or
  // token replacement fails. It contains no bearer value or user identity.
  static String get _kBlocked => 'credential_blocked:${ApiConfig.baseUrl}';

  final TokenVault _vault;
  SharedPrefs({TokenVault? vault}) : _vault = vault ?? const DeviceTokenVault();

  // All instances (Auth, Profile, Booking and NetworkService) share one
  // generation and serialized storage queue. An old read/write cannot
  // reinstate a token after logout or erase a later login's credential.
  static int _generation = 0;
  static bool _blocked = false;
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
    _blocked = true;
    Session.clear();
    return _ordered(() async {
      if (generation != _generation) return;
      final prefs = await SharedPreferences.getInstance();
      // A failed replacement may have written the old/new token to Keychain.
      // Persist a non-secret block BEFORE touching the secure credential.
      if (!await prefs.setBool(_kBlocked, true)) {
        throw StateError('Unable to protect the on-device credential');
      }
      await prefs.remove(_kUser);
      if (generation != _generation) return;
      await _vault.write(key: _kAccess, value: token);
      if (generation != _generation) return;
      if (!await prefs.remove(_kBlocked)) {
        throw StateError('Unable to finalize the on-device credential');
      }
      if (generation == _generation) {
        _blocked = false;
        Session.setToken(token);
      }
    });
  }

  Future<String?> getToken() async {
    final generation = _generation;
    if (_blocked) return null;
    final saved = await _ordered(() async {
      final prefs = await SharedPreferences.getInstance();
      if (prefs.containsKey(_kBlocked)) return null;
      return _vault.read(key: _kAccess);
    });
    if (generation != _generation || _blocked) return null;
    Session.setToken(saved);
    return saved;
  }

  String? get tokenSync => Session.accessToken;

  Future<void> saveUser(Map<String, dynamic> user) {
    final generation = _generation;
    return _ordered(() async {
      if (generation != _generation || _blocked) return;
      final prefs = await SharedPreferences.getInstance();
      if (generation != _generation || _blocked ||
          prefs.containsKey(_kBlocked)) return;
      await prefs.setString(_kUser, jsonEncode(user));
    });
  }

  Future<Map<String, dynamic>?> getUser() async {
    final generation = _generation;
    if (_blocked) return null;
    final saved = await _ordered(() async {
      final prefs = await SharedPreferences.getInstance();
      if (prefs.containsKey(_kBlocked)) return null;
      return prefs.getString(_kUser);
    });
    if (generation != _generation || _blocked || saved == null) return null;
    try {
      // Profiles are display hints, never the authoritative account. Corrupt
      // or unexpectedly large cached JSON must not crash app startup.
      if (saved.length > 32768) throw const FormatException('Invalid profile');
      final decoded = jsonDecode(saved);
      if (decoded is! Map<String, dynamic>) throw const FormatException();
      return decoded;
    } catch (_) {
      await _ordered(() async {
        if (generation != _generation || _blocked) return;
        final prefs = await SharedPreferences.getInstance();
        // Another user may have replaced the cache while JSON was parsed.
        if (generation == _generation && !_blocked &&
            prefs.getString(_kUser) == saved) {
          await prefs.remove(_kUser);
        }
      });
      return null;
    }
  }

  /// Only apply an old logout if its original identity remains active.
  /// The check and generation invalidation are synchronous; newer logins
  /// cannot be erased by a late network response from a prior account.
  Future<bool> clearIfCurrent({
    required int expectedGeneration,
    required String? expectedToken,
  }) async {
    if (_generation != expectedGeneration ||
        Session.accessToken != expectedToken) return false;
    final deletion = clear();
    final clearingGeneration = _generation;
    await deletion;
    return _generation == clearingGeneration && Session.accessToken == null;
  }

  Future<void> clear() {
    // Invalidate all pending reads/writes immediately, before waiting for the
    // platform vault. Never report a new session as logged out later.
    final generation = ++_generation;
    _blocked = true;
    Session.clear();
    return _ordered(() async {
      final prefs = await SharedPreferences.getInstance();
      // Persist the block BEFORE deletion so a failed Keychain/Keystore
      // delete cannot silently reauthenticate after the next app launch.
      if (!await prefs.setBool(_kBlocked, true)) {
        throw StateError('Unable to block the on-device credential');
      }
      await prefs.remove(_kUser);
      await _vault.delete(key: _kAccess);
      if (generation == _generation) Session.clear();
    });
  }
}
