import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/privacy_copy.dart';
import 'test_support.dart';

class CopyApi extends FixtureApi {
  Completer<dynamic>? pending;
  bool live = true;
  final bodies = <Map<String, dynamic>>[];
  @override
  bool Function() sessionFence() =>
      () => live;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add(path);
    bodies.add(Map.of(body ?? {}));
    if (path.endsWith('/revoke')) return {'discarded': true};
    return pending?.future ??
        {
          'schema_version': 1,
          'request_id': 7,
          'owner_id': 1,
          'scope': 'self_service_account_copy',
          'sections': {
            'profile': {'name': 'PRIVATE CUSTOMER COPY'},
          },
        };
  }
}

void main() {
  testWidgets(
    'reauthentication fields clear and explicit discard removes the private copy',
    (tester) async {
      final api = CopyApi();
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyCopyPage(api: api, requestId: 7, onExpired: () {}),
        ),
      );
      await tester.enterText(
        find.widgetWithText(TextField, 'Current password'),
        'password',
      );
      await tester.tap(find.text('View private copy'));
      await tester.pumpAndSettle();
      expect(api.bodies.single['password'], 'password');
      expect(find.widgetWithText(TextField, 'password'), findsNothing);
      expect(find.textContaining('PRIVATE CUSTOMER COPY'), findsOneWidget);
      await tester.tap(find.text('Discard private copy'));
      await tester.pumpAndSettle();
      expect(api.calls.last, 'account/privacy-requests/7/access-copy/revoke');
      expect(find.textContaining('PRIVATE CUSTOMER COPY'), findsNothing);
      await tester.pumpWidget(const SizedBox());
    },
  );
  testWidgets(
    'backgrounding rejects a late private export and requires reopening',
    (tester) async {
      final api = CopyApi()..pending = Completer<dynamic>();
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyCopyPage(api: api, requestId: 7, onExpired: () {}),
        ),
      );
      await tester.tap(find.text('View private copy'));
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      api.pending!.complete({
        'schema_version': 1,
        'request_id': 7,
        'scope': 'self_service_account_copy',
        'sections': {'secret': 'PRIVATE LATE COPY'},
      });
      await tester.pumpAndSettle();
      expect(find.textContaining('PRIVATE LATE COPY'), findsNothing);
      expect(find.text('View private copy'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpWidget(const SizedBox());
    },
  );
  testWidgets('revoked account clears an already visible private copy', (
    tester,
  ) async {
    final api = CopyApi();
    await tester.pumpWidget(
      MaterialApp(
        home: PrivacyCopyPage(api: api, requestId: 7, onExpired: () {}),
      ),
    );
    await tester.tap(find.text('View private copy'));
    await tester.pumpAndSettle();
    expect(find.textContaining('PRIVATE CUSTOMER COPY'), findsOneWidget);
    api.live = false;
    await tester.pump(const Duration(seconds: 1));
    expect(find.textContaining('PRIVATE CUSTOMER COPY'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
