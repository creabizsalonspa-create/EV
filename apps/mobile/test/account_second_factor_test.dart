import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/account_second_factor.dart';

class FactorApi extends EvApi {
  FactorApi() : super(Uri.parse('https://example.invalid'));
  final writes = <Map<String, dynamic>>[];
  bool enabled = false, pending = false, malformedCodes = false;
  int reads = 0, epoch = 0;
  @override
  bool Function() sessionFence() {
    final captured = epoch;
    return () => epoch == captured;
  }
  @override
  Future<dynamic> request(String path, {String method = 'GET', Map<String, dynamic>? body, Map<String, String> query = const {}}) async {
    if (method == 'GET') {
      reads++;
      return {'enabled': enabled, 'pending': pending, 'recovery_remaining': enabled ? 8 : 0};
    }
    writes.add({'path': path, ...?body});
    if (path.endsWith('/prepare')) { pending = true; return {'secret': 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'}; }
    if (malformedCodes) {
      return {
        'enabled': true,
        'reauthentication_required': false,
        'recovery_codes': [List<String>.filled(101, 'X').join()],
      };
    }
    enabled = !path.endsWith('/disable'); pending = false;
    return {'enabled': enabled, 'recovery_codes': enabled ? ['ABCDEF-123456-ABCDEF-123456'] : <String>[], 'reauthentication_required': false};
  }
}
void main() {

  for (final ar in [false, true]) {
    testWidgets('oversized recovery codes fail closed and require verified refresh ($ar)', (tester) async {
      final api = FactorApi()
        ..enabled = true
        ..malformedCodes = true;
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(ar ? 'ar' : 'en'),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: AccountSecondFactorView(api: api),
      ));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('factor-password')), 'CurrentPassword');
      await tester.enterText(find.byKey(const Key('factor-code')), '123456');
      await tester.tap(find.text(ar ? 'استبدال رموز الاسترداد' : 'Replace recovery codes'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('factor-recovery')), findsNothing);
      expect(find.byKey(const Key('factor-password')), findsNothing);
      expect(find.textContaining(List<String>.filled(101, 'X').join()), findsNothing);
      expect(api.writes, hasLength(1));
      api.malformedCodes = false;
      await tester.tap(find.text(ar ? 'تحديث حالة تطبيق التحقق' : 'Refresh authenticator status'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('factor-password')), findsOneWidget);
      expect(api.writes, hasLength(1), reason: 'Refresh must never retry a mutation');
      expect(tester.takeException(), isNull);
    });
  }

  for (final language in ['en', 'ar']) {
    testWidgets('first-inactive authenticator mount never reads on resume ($language)',
        (tester) async {
      final api = FactorApi();
      addTearDown(api.close);
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: AccountSecondFactorView(api: api),
      ));
      await tester.pumpAndSettle();
      expect(api.reads, 0);
      expect(find.byKey(const Key('factor-password')), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.reads, 0, reason: 'Resume is not private MFA read consent');
      await tester.tap(find.text(language == 'ar'
          ? 'تحديث حالة تطبيق التحقق'
          : 'Refresh authenticator status'));
      await tester.pumpAndSettle();
      expect(api.reads, 1);
      expect(find.byKey(const Key('factor-password')), findsOneWidget);
      expect(api.writes, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('same API new session loses MFA recovery secrets and only loads manually ($language)',
        (tester) async {
      final api = FactorApi();
      addTearDown(api.close);
      Widget view() => MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: AccountSecondFactorView(api: api),
      );
      await tester.pumpWidget(view());
      await tester.pumpAndSettle();
      expect(api.reads, 1);
      await tester.enterText(find.byKey(const Key('factor-password')), 'current-password');
      await tester.tap(find.text(language == 'ar'
          ? 'إعداد تطبيق التحقق'
          : 'Prepare authenticator setup'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('factor-secret')), findsOneWidget);
      api.epoch++;
      await tester.pumpWidget(view());
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('factor-secret')), findsNothing);
      expect(find.byKey(const Key('factor-password')), findsNothing);
      expect(api.reads, 2,
        reason: 'Preparation reads once before rotation, never after it');
      await tester.tap(find.text(language == 'ar'
          ? 'تحديث حالة تطبيق التحقق'
          : 'Refresh authenticator status'));
      await tester.pumpAndSettle();
      expect(api.reads, 3);
      expect(find.byKey(const Key('factor-password')), findsOneWidget);
      expect(find.byKey(const Key('factor-secret')), findsNothing);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('authenticator setup is explicit, secrets are one-time and refresh never writes', (tester) async {
    tester.view.physicalSize = const Size(900, 1400); tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize); addTearDown(tester.view.resetDevicePixelRatio);
    final api = FactorApi(); addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(home: AccountSecondFactorView(api: api))); await tester.pumpAndSettle();
    expect(api.writes, isEmpty);
    await tester.enterText(find.byKey(const Key('factor-password')), 'current-password');
    await tester.tap(find.text('Prepare authenticator setup')); await tester.pumpAndSettle();
    expect(find.byKey(const Key('factor-secret')), findsOneWidget);
    expect(tester.widget<TextField>(find.byKey(const Key('factor-password'))).controller!.text, isEmpty);
    await tester.enterText(find.byKey(const Key('factor-password')), 'current-password');
    await tester.enterText(find.byKey(const Key('factor-code')), '012345');
    await tester.tap(find.text('Enable authenticator')); await tester.pumpAndSettle();
    expect(api.writes, hasLength(2)); expect(api.writes.last['code'], '012345');
    expect(find.byKey(const Key('factor-secret')), findsNothing); expect(find.byKey(const Key('factor-recovery')), findsOneWidget);
    await tester.tap(find.text('Refresh authenticator status')); await tester.pumpAndSettle();
    expect(api.writes, hasLength(2)); expect(find.byKey(const Key('factor-recovery')), findsNothing);
  });
}
