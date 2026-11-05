import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:creabiz_ev/main.dart';

void main() {
  const demo = bool.fromEnvironment('EV_LOCAL_DEMO');
  const origin = String.fromEnvironment('EV_API_BASE_URL');
  testWidgets(
    'explicit local demo build has a configured service and a visible notice before authentication',
    (tester) async {
      SharedPreferences.setMockInitialValues({});
      expect(origin, 'http://127.0.0.1:8000');
      await tester.pumpWidget(const EvApp());
      await tester.pumpAndSettle();
      expect(find.byType(LocalDemoNotice), findsOneWidget);
      expect(
        find.textContaining('Use synthetic accounts only.'),
        findsOneWidget,
      );
      expect(
        find.text('The service is unavailable. Please contact support.'),
        findsNothing,
      );
      final login = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Sign in'),
      );
      expect(login.onPressed, isNotNull);
      expect(tester.takeException(), isNull);
    },
    skip: !demo,
  );
}
