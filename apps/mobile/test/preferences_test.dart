import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:creabiz_ev/strings.dart';

class MemoryStore implements PreferenceStore {
  String? value;
  bool fail = false;
  @override
  Future<String?> read() async => value;
  @override
  Future<void> write(String value) async {
    if (fail) throw StateError('Storage unavailable');
    this.value = value;
  }
}

void main() {
  test(
    'device storage persists only validated appearance fields across controllers',
    () async {
      SharedPreferences.setMockInitialValues({});
      final first = AppPreferences(DevicePreferenceStore());
      await first.load();
      expect(first.theme, ThemeMode.system);
      await first.update(theme: ThemeMode.dark, locale: const Locale('ar'));
      final restored = AppPreferences(DevicePreferenceStore());
      await restored.load();
      expect(restored.theme, ThemeMode.dark);
      expect(restored.locale.languageCode, 'ar');
      final stored = (await SharedPreferences.getInstance()).getString(
        DevicePreferenceStore.key,
      )!;
      expect((jsonDecode(stored) as Map).keys.toSet(), {'theme', 'locale'});
      await restored.update(
        theme: ThemeMode.system,
        locale: const Locale('en'),
      );
      final again = AppPreferences(DevicePreferenceStore());
      await again.load();
      expect(again.theme, ThemeMode.system);
      expect(again.locale.languageCode, 'en');
    },
  );
  test(
    'corrupt or unsupported stored values use safe defaults; failed writes are visible',
    () async {
      final store = MemoryStore()..value = '{broken';
      final preferences = AppPreferences(store);
      await preferences.load();
      expect(preferences.ready, isTrue);
      expect(preferences.theme, ThemeMode.system);
      store.fail = true;
      await preferences.update(theme: ThemeMode.dark);
      expect(preferences.storageError, isTrue);
      expect(preferences.theme, ThemeMode.system);
      expect(preferences.saving, isFalse);
      store.fail = false;
      await preferences.update(locale: const Locale('ar'));
      expect(preferences.storageError, isFalse);
      expect(preferences.locale.languageCode, 'ar');
      final unsupported = AppPreferences(
        MemoryStore()..value = '{"theme":"unknown","locale":"xx"}',
      );
      await unsupported.load();
      expect(unsupported.theme, ThemeMode.system);
      expect(unsupported.locale.languageCode, 'en');
    },
  );
  test(
    'English and Arabic resource keys and interpolation contracts stay aligned',
    () {
      expect(EvStrings.ar.keys.toSet(), EvStrings.en.keys.toSet());
      final pattern = RegExp(r'\{[^}]+\}');
      for (final key in EvStrings.en.keys) {
        expect(
          pattern.allMatches(EvStrings.ar[key]!).map((m) => m[0]).toSet(),
          pattern.allMatches(EvStrings.en[key]!).map((m) => m[0]).toSet(),
          reason: key,
        );
      }
    },
  );
}
