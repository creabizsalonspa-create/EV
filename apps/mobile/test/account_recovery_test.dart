import 'dart:async';
import 'package:creabiz_ev/account_recovery.dart';
import 'package:creabiz_ev/api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

final token = List.filled(64, 'a').join();
String resetLink([String host = 'https://ev.test']) =>
    '$host/reset-password/$token?email=rider%2Btag%40example.com';

class RecoveryApi extends EvApi {
  RecoveryApi() : super(Uri.parse('https://ev.test'));
  final calls = <String>[];
  Map<String, dynamic>? received;
  Object? failure;
  Completer<dynamic>? pending;
  int authEpoch = 0;
  @override
  bool Function() sessionFence() {
    final captured = authEpoch;
    return () => captured == authEpoch;
  }
  @override
  Future<void> csrf() async {
    calls.add('csrf');
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    received = body == null ? null : Map.of(body);
    if (failure != null) throw failure!;
    return pending == null
        ? <String, dynamic>{'message': 'confirmed'}
        : await pending!.future;
  }
}

Widget screen(RecoveryApi api, {bool arabic = false}) => MaterialApp(
  locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: AccountRecoveryView(api: api),
);
Future<void> fill(WidgetTester t) async {
  await t.enterText(find.byKey(const Key('recovery-link')), resetLink());
  await t.enterText(
    find.byKey(const Key('recovery-password')),
    'correct-new-password',
  );
  await t.enterText(
    find.byKey(const Key('recovery-confirmation')),
    'correct-new-password',
  );
  await t.ensureVisible(find.byKey(const Key('recovery-reset')));
}

void main() {

  for (final ar in [false, true]) {
    testWidgets('first inactive recovery mount masks reset link without requests ($ar)',
        (tester) async {
      final api = RecoveryApi();
      addTearDown(api.close);
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpWidget(screen(api, arabic: ar));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('recovery-link')), findsNothing);
      expect(find.byKey(const Key('recovery-password')), findsNothing);
      expect(api.calls, isEmpty);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('recovery-link')), findsOneWidget);
      expect(find.byKey(const Key('recovery-password')), findsOneWidget);
      expect(api.calls, isEmpty, reason: 'No password reset action on OS resume');
      expect(tester.takeException(), isNull);
    });

    testWidgets('reused recovery API epoch discards another accounts token ($ar)',
        (tester) async {
      final api = RecoveryApi();
      addTearDown(api.close);
      await tester.pumpWidget(screen(api, arabic: ar));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('recovery-link')), resetLink());
      await tester.enterText(find.byKey(const Key('recovery-password')),
          'OldAccountSecretPassword');
      final link = tester.widget<TextField>(
          find.byKey(const Key('recovery-link'))).controller!;
      final password = tester.widget<TextField>(
          find.byKey(const Key('recovery-password'))).controller!;
      api.authEpoch++;
      await tester.pumpWidget(screen(api, arabic: ar));
      await tester.pumpAndSettle();
      expect(link.text, isEmpty);
      expect(password.text, isEmpty);
      expect(find.byKey(const Key('recovery-done')), findsNothing);
      expect(api.calls, isEmpty);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('new recovery email prop never inherits prior reset input', (tester) async {
    final api = RecoveryApi();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: AccountRecoveryView(api: api, initialEmail:'first@example.test'),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('recovery-link')), resetLink());
    await tester.enterText(find.byKey(const Key('recovery-password')), 'OldPrivatePassword');
    await tester.pumpWidget(MaterialApp(
      home: AccountRecoveryView(api: api, initialEmail:'second@example.test'),
    ));
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(
        find.byKey(const Key('recovery-email'))).controller!.text,
        'second@example.test');
    expect(tester.widget<TextField>(
        find.byKey(const Key('recovery-link'))).controller!.text,isEmpty);
    expect(tester.widget<TextField>(
        find.byKey(const Key('recovery-password'))).controller!.text,isEmpty);
    expect(api.calls, isEmpty);
  });

  test('strict same-origin reset links preserve email and token', () {
    final parsed = RecoveryLink.parse(
      resetLink(),
      Uri.parse('https://ev.test'),
    )!;
    expect(parsed.email, 'rider+tag@example.com');
    expect(parsed.token, token);
    for (final bad in [
      resetLink('https://other.test'),
      resetLink('http://ev.test'),
      resetLink('https://ev.test:444'),
      resetLink('https://user@ev.test'),
      '${resetLink()}#secret',
      '${resetLink()}#',
      resetLink('https://@ev.test'),
      '${resetLink()}&email=other%40example.com',
      '${resetLink()}&redirect=https://other.test',
      '/reset-password/$token?email=a%40b.test',
      resetLink().replaceFirst(token, 'short'),
      ' ${resetLink()}',
      'file:///reset-password/$token',
    ]) {
      expect(
        RecoveryLink.parse(bad, Uri.parse('https://ev.test')),
        isNull,
        reason: bad,
      );
    }
  });
  testWidgets('request uses generic account-private acknowledgement', (
    t,
  ) async {
    final api = RecoveryApi();
    await t.pumpWidget(screen(api));
    await t.enterText(
      find.byKey(const Key('recovery-email')),
      'rider@example.com',
    );
    await t.tap(find.byKey(const Key('recovery-send')));
    await t.pumpAndSettle();
    expect(api.calls, ['csrf', 'POST forgot-password']);
    expect(api.received, {'email': 'rider@example.com'});
    expect(find.textContaining('If eligible'), findsOneWidget);
  });
  testWidgets(
    'complete reset sends exact existing contract and clears secrets',
    (t) async {
      final api = RecoveryApi();
      await t.pumpWidget(screen(api));
      await fill(t);
      await t.tap(find.byKey(const Key('recovery-reset')));
      await t.pumpAndSettle();
      expect(api.calls, ['csrf', 'POST reset-password']);
      expect(api.received, {
        'email': 'rider+tag@example.com',
        'token': token,
        'password': 'correct-new-password',
        'password_confirmation': 'correct-new-password',
      });
      expect(find.byKey(const Key('recovery-done')), findsOneWidget);
      expect(find.byType(TextField), findsNothing);
    },
  );
  testWidgets('mismatch and foreign links do not issue requests', (t) async {
    final api = RecoveryApi();
    await t.pumpWidget(screen(api));
    await fill(t);
    await t.enterText(
      find.byKey(const Key('recovery-link')),
      resetLink('https://evil.test'),
    );
    await t.ensureVisible(find.byKey(const Key('recovery-reset')));
    await t.tap(find.byKey(const Key('recovery-reset')));
    await t.pumpAndSettle();
    expect(api.calls, isEmpty);
  });
  testWidgets('rate limits and invalid links never expose server secrets', (
    t,
  ) async {
    final api = RecoveryApi()
      ..failure = const ApiException(429, 'sensitive token');
    await t.pumpWidget(screen(api));
    await fill(t);
    await t.tap(find.byKey(const Key('recovery-reset')));
    await t.pumpAndSettle();
    expect(find.textContaining('Too many requests'), findsOneWidget);
    expect(find.textContaining('sensitive token'), findsNothing);
    final field = t.widget<TextField>(find.byKey(const Key('recovery-link')));
    expect(field.controller!.text, isEmpty);
  });
  testWidgets('background clears secrets and ignores delayed completion', (
    t,
  ) async {
    final api = RecoveryApi()..pending = Completer<dynamic>();
    await t.pumpWidget(screen(api));
    await fill(t);
    final field = t.widget<TextField>(
      find.byKey(const Key('recovery-password')),
    );
    await t.tap(find.byKey(const Key('recovery-reset')));
    await t.pump();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    expect(field.controller!.text, isEmpty);
    api.pending!.complete({});
    await t.pumpAndSettle();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pumpAndSettle();
    expect(find.byKey(const Key('recovery-done')), findsNothing);
  });
  testWidgets('Arabic direction with email field LTR and accessible actions', (
    t,
  ) async {
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.resetPhysicalSize);
    addTearDown(t.view.resetDevicePixelRatio);
    await t.pumpWidget(screen(RecoveryApi(), arabic: true));
    expect(
      Directionality.of(t.element(find.byKey(const Key('recovery-email')))),
      TextDirection.rtl,
    );
    expect(
      t
          .widget<TextField>(find.byKey(const Key('recovery-email')))
          .textDirection,
      TextDirection.ltr,
    );
    expect(find.text('استعادة الحساب'), findsOneWidget);
  });
}
