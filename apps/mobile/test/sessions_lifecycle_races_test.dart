import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/sessions.dart';

import 'test_support.dart';

class ScopedSessionApi extends FixtureApi {
  int epoch = 0;
  int requests = 0;
  int posts = 0;
  int sessionId = 7;
  String state = 'charging';
  Completer<dynamic>? pending;

  @override
  bool Function() sessionFence() {
    final identity = epoch;
    return () => identity == epoch;
  }

  Map<String, dynamic> get page => {
    'data': [
      {
        'id': sessionId,
        'reservation_id': sessionId,
        'status': state,
        'energy_wh': sessionId * 100,
        'total_minor': null,
        'currency': 'AED',
        'csms_linked': false,
      },
    ],
    'current_page': 1,
    'last_page': 1,
    'total': 1,
  };

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') {
      posts++;
      throw StateError('No mutation is authorized by test fixtures');
    }
    expect(path, 'simulations');
    requests++;
    final outstanding = pending;
    pending = null;
    return outstanding == null ? page : outstanding.future;
  }
}

Widget sessionsApp(
  ScopedSessionApi api, {
  String role = 'customer',
  bool ar = false,
  VoidCallback? expired,
}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: Scaffold(
    body: SessionsView(
      api: api,
      role: role,
      onExpired: expired ?? () {},
    ),
  ),
);

void main() {
  testWidgets('initial inactive mount does not fetch private charging history', (
    tester,
  ) async {
    final api = ScopedSessionApi();
    addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    ));
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pumpWidget(sessionsApp(api));
    await tester.pumpAndSettle();
    expect(api.requests, 0);
    expect(find.textContaining('Reservation #7'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.requests, 0);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.requests, 1);
    expect(find.text('Stop simulation'), findsOneWidget);
  });

  testWidgets('reused session screen rebinds same API after credential epoch rotation', (
    tester,
  ) async {
    final api = ScopedSessionApi();
    await tester.pumpWidget(sessionsApp(api));
    await tester.pumpAndSettle();
    expect(find.text('Stop simulation'), findsOneWidget);
    final oldStop = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Stop simulation'),
    ).onPressed!;
    final oldRead = api.requests;
    api.epoch++;
    api.sessionId = 22;
    api.state = 'stopped';
    await tester.pumpWidget(sessionsApp(api));
    oldStop();
    await tester.pumpAndSettle();
    expect(api.posts, 0);
    expect(find.text('Stop simulation'), findsNothing);
    expect(find.text('Simulated invoice'), findsOneWidget);
    expect(api.requests, oldRead + 1);
    expect(tester.takeException(), isNull);
  });

  testWidgets('replaced API and role discard old session data and old stop callbacks', (
    tester,
  ) async {
    final old = ScopedSessionApi();
    final next = ScopedSessionApi()..sessionId = 11;
    await tester.pumpWidget(sessionsApp(old));
    await tester.pumpAndSettle();
    final oldStop = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Stop simulation'),
    ).onPressed!;
    await tester.pumpWidget(sessionsApp(next, role: 'vendor'));
    oldStop();
    await tester.pumpAndSettle();
    expect(old.posts, 0);
    expect(next.posts, 0);
    expect(old.requests, 1);
    expect(next.requests, 1);
    expect(find.text('Stop simulation'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('late former customer denial cannot expire successor session', (
    tester,
  ) async {
    final old = ScopedSessionApi(), next = ScopedSessionApi();
    next.sessionId = 28;
    final oldRead = Completer<dynamic>();
    old.pending = oldRead;
    int expired = 0;
    await tester.pumpWidget(sessionsApp(old, expired: () => expired++));
    await tester.pump();
    await tester.pumpWidget(sessionsApp(next, expired: () => expired++));
    await tester.pumpAndSettle();
    oldRead.completeError(const ApiException(401, 'Past identity'));
    await tester.pumpAndSettle();
    expect(expired, 0);
    expect(find.text('Stop simulation'), findsOneWidget);
    expect(next.requests, 1);
    expect(old.posts, 0);
    expect(next.posts, 0);
    expect(tester.takeException(), isNull);
  });

  for (final ar in [false, true]) {
    testWidgets('session foreground privacy and retained row callback denial ($ar)', (
      tester,
    ) async {
      final api = ScopedSessionApi();
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
        AppLifecycleState.resumed,
      ));
      await tester.pumpWidget(sessionsApp(api, ar: ar));
      await tester.pumpAndSettle();
      final oldStop = tester.widget<OutlinedButton>(
        find.widgetWithText(OutlinedButton, ar ? 'إيقاف المحاكاة' : 'Stop simulation'),
      ).onPressed!;
      final previous = api.requests;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.byType(SessionsView), findsOneWidget);
      expect(find.byType(OutlinedButton), findsNothing);
      oldStop();
      expect(api.posts, 0);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.requests, previous,
          reason: 'Resume does not imply consent to refresh charging history');
      await tester.tap(find.text(ar ? 'تحديث' : 'Refresh'));
      await tester.pumpAndSettle();
      expect(api.requests, previous + 1);
      expect(tester.takeException(), isNull);
    });
  }
}
