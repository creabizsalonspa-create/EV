import 'package:creabiz_ev/strings.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

/// Audit the actual compiled translations, not a regex approximation of Dart
/// source. Keep both dialects and their interpolation contracts in lockstep.
void main() {
  test('English and Arabic retain matching complete translation keys', () {
    expect(EvStrings.en.keys.toSet(), EvStrings.ar.keys.toSet());
    expect(EvStrings.en.length, greaterThanOrEqualTo(300));

    final placeholder = RegExp(r'\{([A-Za-z_][A-Za-z_0-9]*)\}');
    final english = EvStrings(const Locale('en'));
    final arabic = EvStrings(const Locale('ar'));

    for (final key in EvStrings.en.keys) {
      final original = EvStrings.en[key]!;
      final localized = EvStrings.ar[key]!;
      expect(original.trim(), isNotEmpty, reason: 'Empty English translation: $key');
      expect(localized.trim(), isNotEmpty, reason: 'Empty Arabic translation: $key');

      Set<String> variables(String text) =>
          placeholder.allMatches(text).map((match) => match.group(1)!).toSet();

      final enVariables = variables(original);
      final arVariables = variables(localized);
      expect(arVariables, enVariables,
          reason: 'EN/AR interpolation contract differs for: $key');

      // The same named values must be substituted in either direction.
      // Sensitive or user-entered data is never embedded in a test string.
      final values = <String, Object>{
        for (final variable in enVariables) variable: 'synthetic',
      };
      final enResult = english.t(key, values);
      final arResult = arabic.t(key, values);
      expect(variables(enResult), isEmpty,
          reason: 'Unresolved English placeholder for $key');
      expect(variables(arResult), isEmpty,
          reason: 'Unresolved Arabic placeholder for $key');
    }
  });

  test('core privacy and session notices remain localized', () {
    for (final key in [
      'privacyScreenHidden',
      'expired',
      'securityExpired',
      'offline',
      'forgot',
      'notifications',
      'support',
      'garage',
      'signOut',
    ]) {
      final english = EvStrings(const Locale('en')).t(key);
      final arabic = EvStrings(const Locale('ar')).t(key);
      expect(english, isNot(key));
      expect(arabic, isNot(key));
      expect(arabic, isNot(english),
          reason: 'Arabic core privacy text has been replaced by English: $key');
    }
  });
}
