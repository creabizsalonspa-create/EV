import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract interface class SessionVault {
  Future<String?> read(String origin);
  Future<void> write(String origin, String value);
  Future<void> delete(String origin);
}

class SessionStorageException implements Exception {
  const SessionStorageException();
}

/// Keychain (device-only, unlocked) / Android encrypted preferences + Keystore.
/// No fallback to ordinary preferences, files, passwords or stored CSRF tokens.
class DeviceSessionVault implements SessionVault {
  final FlutterSecureStorage storage;
  DeviceSessionVault({FlutterSecureStorage? storage})
    : storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(encryptedSharedPreferences: true),
            iOptions: IOSOptions(
              accessibility: KeychainAccessibility.unlocked_this_device,
            ),
          );
  String key(String origin) =>
      'creabiz.session.v1.${base64Url.encode(utf8.encode(origin))}';
  @override
  Future<String?> read(String origin) => storage.read(key: key(origin));
  @override
  Future<void> write(String origin, String value) =>
      storage.write(key: key(origin), value: value);
  @override
  Future<void> delete(String origin) => storage.delete(key: key(origin));
}
