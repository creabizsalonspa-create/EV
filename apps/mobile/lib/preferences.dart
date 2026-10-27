import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

abstract interface class PreferenceStore {
  Future<String?> read();
  Future<void> write(String value);
}

/// Only non-secret appearance settings belong here, never session credentials.
class DevicePreferenceStore implements PreferenceStore {
  static const key = 'creabiz.preferences.v1';
  @override
  Future<String?> read() async =>
      (await SharedPreferences.getInstance()).getString(key);
  @override
  Future<void> write(String value) async {
    if (!await (await SharedPreferences.getInstance()).setString(key, value)) {
      throw StateError('Preference write failed');
    }
  }
}

class AppPreferences extends ChangeNotifier {
  final PreferenceStore store;
  ThemeMode theme = ThemeMode.system;
  Locale locale = const Locale('en');
  bool _disposed = false;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  bool ready = false;
  bool saving = false;
  bool storageError = false;
  AppPreferences(this.store);

  Future<void> load() async {
    try {
      final raw = await store.read();
      if (raw != null) {
        try {
          final data = jsonDecode(raw);
          if (data is Map) {
            theme =
                ThemeMode.values
                    .where((v) => v.name == data['theme'])
                    .firstOrNull ??
                ThemeMode.system;
            locale = Locale(data['locale'] == 'ar' ? 'ar' : 'en');
          }
        } on FormatException {
          /* Corrupt preferences fall back without blocking sign in. */
        }
      }
    } catch (_) {
      storageError = true;
    }
    ready = true;
    _notify();
  }

  Future<void> update({ThemeMode? theme, Locale? locale}) async {
    if (saving) return;
    final nextTheme = theme ?? this.theme;
    final nextLocale = locale ?? this.locale;
    if (!['en', 'ar'].contains(nextLocale.languageCode))
      throw ArgumentError('Unsupported locale');
    saving = true;
    _notify();
    try {
      await store.write(
        jsonEncode({
          'theme': nextTheme.name,
          'locale': nextLocale.languageCode,
        }),
      );
      this.theme = nextTheme;
      this.locale = nextLocale;
      storageError = false;
    } catch (_) {
      storageError = true;
    }
    saving = false;
    _notify();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
