import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/support.dart';
import 'package:creabiz_ev/strings.dart';

void main() {
  for (final ar in [false, true]) {
    test('Private account-security titles are localized without echoing metadata $ar', () {
      final strings = EvStrings(Locale(ar ? 'ar' : 'en'));
      final titles = <String>{};
      for (final event in ['enabled', 'disabled', 'recovery_rotated', 'recovery_used']) {
        final title = inboxNotificationTitle({'kind': 'account.security.$event', 'metadata': {'secret': '<img src=x>', 'code': 'PRIVATE-RECOVERY-CODE'}}, strings);
        expect(title, contains(ar ? 'الدعم' : 'contact support'));
        expect(title, isNot(contains('PRIVATE-RECOVERY-CODE')));
        expect(title, isNot(contains('<img')));
        titles.add(title);
      }
      expect(titles.length, 4);
    });
  }
}
