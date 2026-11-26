import 'package:creabiz_ev/strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('central English and Arabic resources retain matching keys and tokens', () {
    final en = EvStrings.en;
    final ar = EvStrings.ar;
    expect(en.length, greaterThanOrEqualTo(350));
    expect(ar.keys.toSet(), en.keys.toSet());

    List<String> placeholders(String value) =>
        (RegExp(r'\{([A-Za-z0-9_]+)\}')
            .allMatches(value)
            .map((m) => m[1]!)
            .toList()..sort());

    for (final key in en.keys) {
      expect(en[key]!.trim(), isNotEmpty, reason: key);
      expect(ar[key]!.trim(), isNotEmpty, reason: key);
      expect(placeholders(ar[key]!), placeholders(en[key]!), reason: key);
      // Language self-names intentionally remain identical in both menus.
      if (key != 'english') {
        expect(ar[key], matches(RegExp(r'[ء-ي]')), reason: key);
      }
      if (!const {'english', 'arabic'}.contains(key)) {
        expect(ar[key], isNot(en[key]), reason: key);
      }
    }
  });

  for (final locale in [const Locale('en'), const Locale('ar')]) {
    test('literal interpolation and order independence ($locale)', () {
      final strings = EvStrings(locale);
      final template = (locale.languageCode == 'ar' ? EvStrings.ar : EvStrings.en)['reservation']!;
      const supplied = r'{status} $& $$ {name} <literal>';
      final expected = template.replaceAllMapped(
        RegExp(r'\{(id|status)\}'),
        (m) => m[1] == 'id' ? supplied : 'ready',
      );
      expect(strings.t('reservation', {'id': supplied, 'status': 'ready'}), expected);
      expect(strings.t('reservation', {'status': 'ready', 'id': supplied}), expected);
      expect(strings.t('reservation', {'id': 7}),
        template.replaceAll('{id}', '7'));
      expect(strings.t('unknown {status}', {'status': supplied}),
        'unknown $supplied');
    });
  }
}
