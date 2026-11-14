import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/notification_email.dart';
import 'test_support.dart';

class NoticeApi extends FixtureApi {
  bool enabled = false, active = true, loseReply = false, configured = true;
  int writes = 0;
  Map<String, dynamic>? lastBody;
  @override
  bool Function() sessionFence() =>
      () => active;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'PUT') {
      writes++;
      lastBody = body;
      enabled = body!['enabled'] == true;
      if (loseReply) throw const ApiException(503, 'PRIVATE SMTP FAILURE');
    }
    if (path.endsWith('/history'))
      return {
        'delivery_confirmed': false,
        'automatic_retry': false,
        'receipts': {
          'data': [
            {
              'state': 'outcome_unknown',
              'created_at': '2026-11-14',
              'evidence': 'SIMULATED_EMAIL_HANDOFF',
            },
          ],
          'current_page': 1,
          'last_page': 1,
        },
      };
    return {
      'enabled': enabled,
      'revision': '9007199254740993',
      'configured': configured,
      'consent_current': enabled,
      'verified_email_required': false,
    };
  }
}

Widget screen(NoticeApi api, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: NotificationEmailView(api: api, onExpired: () {}),
);
void main() {
  for (final ar in [false, true]) {
    testWidgets('explicit email consent preserves exact revision ($ar)', (
      tester,
    ) async {
      final api = NoticeApi();
      await tester.pumpWidget(screen(api, ar: ar));
      await tester.pumpAndSettle();
      final enable = find.byType(FilledButton);
      expect(tester.widget<FilledButton>(enable).onPressed, isNull);
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      await tester.ensureVisible(enable);
      await tester.tap(enable);
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.lastBody, {
        'enabled': true,
        'confirmed': true,
        'expected_revision': '9007199254740993',
      });
      expect(find.textContaining('PRIVATE'), findsNothing);
    });
  }
  testWidgets(
    'lost write response requires explicit refresh and never resends',
    (tester) async {
      final api = NoticeApi()..loseReply = true;
      await tester.pumpWidget(screen(api));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      await tester.ensureVisible(find.byType(FilledButton));
      await tester.tap(find.byType(FilledButton));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.byType(FilledButton), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
      await tester.ensureVisible(find.text('Refresh'));
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.text('Enabled with current consent'), findsOneWidget);
    },
  );
  testWidgets('background hides private history and requires refresh', (
    tester,
  ) async {
    final api = NoticeApi();
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpAndSettle();
    expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.writes, 0);
  });
  testWidgets(
    'retained action cannot write after account changes; paused delivery allows opt out',
    (tester) async {
      final api = NoticeApi()
        ..enabled = true
        ..configured = false;
      await tester.pumpWidget(screen(api));
      await tester.pumpAndSettle();
      final button = find.widgetWithText(
        OutlinedButton,
        'Disable email notices',
      );
      await tester.ensureVisible(button);
      final action = tester.widget<OutlinedButton>(button).onPressed!;
      api.active = false;
      action();
      await tester.pumpAndSettle();
      expect(api.writes, 0);
      api.active = true;
      await tester.tap(button);
      await tester.pumpAndSettle();
      expect(api.lastBody!['enabled'], false);
    },
  );
}
