import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/main.dart';

void main() {
  testWidgets('missing service configuration never displays mock inventory', (tester) async {
    await tester.pumpWidget(const EvApp());
    expect(find.text('The service is unavailable. Please contact support.'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
    final button = tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Sign in'));
    expect(button.onPressed, isNull);
  });
}
