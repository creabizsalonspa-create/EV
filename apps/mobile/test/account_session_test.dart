import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/main.dart';

void main() {
  testWidgets('session revocation password is obscured and never trimmed', (tester) async {
    Map<String, dynamic>? result;
    await tester.pumpWidget(MaterialApp(home: Builder(builder: (context) =>
      Scaffold(body: TextButton(onPressed: () async {
        result = await showDialog<Map<String, dynamic>>(context: context,
          builder: (_) => const EntryDialog(title: 'Sign out everywhere', fields: {'password': ''}));
      }, child: const Text('Open'))))));
    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();
    final input = tester.widget<EditableText>(find.byType(EditableText));
    expect(input.maxLines, 1);
    expect(input.obscureText, isTrue);
    expect(input.autocorrect, isFalse);
    expect(input.enableSuggestions, isFalse);
    await tester.enterText(find.byType(TextFormField), ' password with spaces ');
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    expect(result?['password'], ' password with spaces ');
  });
}
