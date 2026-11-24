import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/notification_email.dart';
import 'test_support.dart';

class NoticeApi extends FixtureApi {
  bool enabled = false,
      active = true,
      loseReply = false,
      configured = true,
      malformedHistory = false,
      malformedReceipt = false,
      emptyHistory = false;
  int lastPage = 1;
  int writes = 0, reads = 0, authEpoch = 0;
  Map<String, dynamic>? lastBody;
  @override
  bool Function() sessionFence() {
    final boundEpoch = authEpoch;
    return () => active && authEpoch == boundEpoch;
  }
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'GET') reads++;
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
          'data': malformedHistory
              ? 'untrusted private rows'
              : malformedReceipt
                  ? <Map<String, dynamic>>[
                      {
                        'state': 'outcome_unknown',
                        'created_at': '2026-11-14',
                        'evidence': List<String>.filled(2001, 'X').join(),
                      },
                    ]
                  : emptyHistory
                      ? <Map<String, dynamic>>[]
                      : [
                  {
                    'state': 'outcome_unknown',
                    'created_at': '2026-11-14',
                    'evidence': 'SIMULATED_EMAIL_HANDOFF',
                  },
                ],
          'current_page': int.parse(query['page'] ?? '1'),
          'last_page': lastPage,
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
  localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
  home: NotificationEmailView(api: api, onExpired: () {}),
);
void main() {

  for (final ar in [false, true]) {
    testWidgets('oversized private notice evidence rejects the whole history ($ar)',
        (tester) async {
      final api = NoticeApi()..malformedReceipt = true;
      addTearDown(api.close);
      await tester.pumpWidget(screen(api, ar: ar));
      await tester.pumpAndSettle();
      expect(find.textContaining(List<String>.filled(2001, 'X').join()), findsNothing);
      expect(find.textContaining(ar ? 'النتيجة غير متاحة.' : 'Outcome unavailable.'),
          findsOneWidget);
      api.malformedReceipt = false;
      await tester.tap(find.text(ar ? 'تحديث' : 'Refresh'));
      await tester.pumpAndSettle();
      expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsOneWidget);
      expect(api.writes, 0);
    });
  }
  testWidgets('new account notice refresh resets a retained page-two cursor',
      (tester) async {
    final api = NoticeApi()..lastPage = 2;
    addTearDown(api.close);
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    expect(api.reads, 4);
    api.authEpoch++;
    api.lastPage = 1;
    api.emptyHistory = true;
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsNothing);
    expect(api.reads, 4, reason: 'Reused page must not fetch new-account receipts');
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.reads, 6);
    expect(find.text('No email notice handoffs on this page.'), findsOneWidget);
    expect(find.textContaining('Outcome unavailable.'), findsNothing);
    expect(api.writes, 0);
  });

  for (final ar in [false, true]) {
    testWidgets('initially inactive email notice page makes no private reads ($ar)',
        (tester) async {
      final api = NoticeApi();
      addTearDown(api.close);
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpWidget(screen(api, ar: ar));
      await tester.pumpAndSettle();
      expect(api.reads, 0);
      expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.reads, 0, reason: 'Resume does not implicitly read private receipts');
      final refresh = find.text(ar ? 'تحديث' : 'Refresh');
      expect(refresh, findsOneWidget);
      await tester.tap(refresh);
      await tester.pumpAndSettle();
      expect(api.reads, 2);
      expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsOneWidget);
      expect(api.writes, 0);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('same API instance new account discards previous notice data', (
    tester,
  ) async {
    final api = NoticeApi();
    addTearDown(api.close);
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsOneWidget);
    expect(api.reads, 2);
    // A login transition can change the bound session while retaining EvApi.
    api.authEpoch++;
    api.emptyHistory = true;
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsNothing);
    expect(api.reads, 2, reason: 'No automatic cross-account private read');
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.reads, 4);
    expect(find.text('No email notice handoffs on this page.'), findsOneWidget);
    expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsNothing);
    expect(api.writes, 0);
    expect(tester.takeException(), isNull);
  });
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
  for (final ar in [false, true]) {
    testWidgets('malformed email history fails closed and refresh recovers ($ar)', (
      tester,
    ) async {
      final api = NoticeApi();
      await tester.pumpWidget(screen(api, ar: ar));
      await tester.pumpAndSettle();
      expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsOneWidget);
      api.malformedHistory = true;
      await tester.tap(find.text(ar ? 'تحديث' : 'Refresh'));
      await tester.pumpAndSettle();
      expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsNothing);
      expect(find.textContaining(ar ? 'النتيجة غير متاحة.' : 'Outcome unavailable.'), findsOneWidget);
      expect(find.byType(FilledButton), findsNothing);
      api.malformedHistory = false;
      await tester.tap(find.text(ar ? 'تحديث' : 'Refresh'));
      await tester.pumpAndSettle();
      expect(find.textContaining('SIMULATED_EMAIL_HANDOFF'), findsOneWidget);
      expect(find.textContaining(ar ? 'النتيجة غير متاحة.' : 'Outcome unavailable.'), findsNothing);
      expect(api.writes, 0);
    });
  }
  for (final ar in [false, true]) {
    testWidgets('empty email history has a localized non-error state ($ar)', (
      tester,
    ) async {
      final api = NoticeApi()..emptyHistory = true;
      await tester.pumpWidget(screen(api, ar: ar));
      await tester.pumpAndSettle();
      expect(
        find.text(
          ar
              ? 'لا توجد عمليات نقل إشعارات بالبريد في هذه الصفحة.'
              : 'No email notice handoffs on this page.',
        ),
        findsOneWidget,
      );
      expect(find.textContaining('Outcome unavailable.'), findsNothing);
      expect(api.writes, 0);
    });
  }
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
