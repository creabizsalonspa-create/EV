import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/phone_verification.dart';

class PhoneApi extends EvApi {
  PhoneApi() : super(Uri.parse('https://example.invalid'));
  bool available = true, lost = false, failRead = false,
      malformedChallenge = false, malformedAcknowledgment = false;
  int privateReads = 0, authEpoch = 0;
  @override
  bool Function() sessionFence() {
    final captured = authEpoch;
    return () => authEpoch == captured;
  }
  final writes = <Map<String, dynamic>>[];
  Map<String, dynamic>? challenge;
  @override
  Future<dynamic> request(String path, {String method = 'GET', Map<String, dynamic>? body, Map<String, String> query = const {}}) async {
    if (method == 'GET') {
      privateReads++;
      if (failRead) throw const ApiException(503, 'PRIVATE_PHONE_TRANSPORT_DIAGNOSTIC');
      return {
        'available': available,
        'verified': false,
        'phone_mask': null,
        'challenge': malformedChallenge
            ? {'id': '../another-account', 'status': 'pending'}
            : challenge,
      };
    }
    writes.add({'path': path, ...?body});
    if (path.endsWith('/challenges')) {
      if (lost) throw const ApiException(0, 'Synthetic lost response');
      if (malformedAcknowledgment) {
        return {'id': '../foreign-account', 'status': 'pending'};
      }
      challenge = {'id': 'owned-challenge', 'status': 'pending'};
      return challenge;
    }
    return {'verified': false, 'status': 'pending', 'attempts_remaining': 4};
  }
}
Widget screen(PhoneApi api, {bool arabic = false}) => MaterialApp(locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: PhoneVerificationView(api: api));
void main() {

  for (final ar in [false, true]) {
    testWidgets('unverified refresh removes old phone and OTP actions ($ar)',
        (tester) async {
      final api = PhoneApi()
        ..challenge = {'id': 'owned-challenge', 'status': 'pending'};
      addTearDown(api.close);
      await tester.pumpWidget(screen(api, arabic: ar));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('phone-code')), findsOneWidget);
      await tester.enterText(find.byKey(const Key('phone-number')), '+971501234567');
      await tester.enterText(find.byKey(const Key('phone-password')), 'PRIVATE_PHONE_PASSWORD');
      api.failRead = true;
      await tester.tap(find.text(
          ar ? 'تحديث حالة التحقق' : 'Refresh verification status'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('phone-code')), findsNothing);
      expect(find.byKey(const Key('phone-number')), findsNothing);
      expect(find.byKey(const Key('phone-password')), findsNothing);
      expect(find.textContaining('PRIVATE_PHONE_TRANSPORT_DIAGNOSTIC'), findsNothing);
      api.failRead = false;
      await tester.tap(find.text(
          ar ? 'تحديث حالة التحقق' : 'Refresh verification status'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('phone-code')), findsOneWidget);
      expect(find.byKey(const Key('phone-password')), findsOneWidget);
      expect(tester.widget<TextField>(
          find.byKey(const Key('phone-password'))).controller!.text, isEmpty);
      expect(api.writes, isEmpty);
      expect(tester.takeException(), isNull);
    });
  }

  for (final ar in [false, true]) {
    testWidgets('malformed SMS start receipt cannot authorize foreign challenge ($ar)',
        (tester) async {
      // The consent+password+submit sequence extends below the default
      // 800x600 widget-test viewport; hit-test the actual button, not an
      // offscreen finder that can silently miss a tap.
      tester.view.physicalSize = const Size(900, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = PhoneApi()..malformedAcknowledgment = true;
      addTearDown(api.close);
      await tester.pumpWidget(screen(api, arabic: ar));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('phone-number')), '+971501234567');
      await tester.enterText(find.byKey(const Key('phone-password')), 'current-password');
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pumpAndSettle();
      expect(tester.widget<CheckboxListTile>(
          find.byType(CheckboxListTile)).value, isTrue);
      final send = find.text(ar ? 'طلب رسالة تحقق' : 'Request verification SMS');
      await tester.ensureVisible(send);
      await tester.tap(send);
      await tester.pumpAndSettle();
      expect(api.writes, hasLength(1));
      expect(find.byKey(const Key('phone-code')), findsNothing);
      expect(find.textContaining('../foreign-account'), findsNothing);
      api.malformedAcknowledgment = false;
      await tester.enterText(find.byKey(const Key('phone-password')), 'current-password');
      await tester.ensureVisible(send);
      await tester.tap(send);
      await tester.pumpAndSettle();
      expect(api.writes, hasLength(2));
      expect(api.writes.first['idempotency_key'], api.writes.last['idempotency_key']);
      expect(find.byKey(const Key('phone-code')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  for (final arabic in [false, true]) {
    testWidgets('initially inactive SMS screen never reads on resume ($arabic)',
        (tester) async {
      final api = PhoneApi();
      addTearDown(api.close);
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpWidget(screen(api, arabic: arabic));
      await tester.pumpAndSettle();
      expect(api.privateReads, 0);
      expect(find.byKey(const Key('phone-number')), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.privateReads, 0, reason: 'OS resume is not a new consent to private GET');
      await tester.tap(find.text(
          arabic ? 'تحديث حالة التحقق' : 'Refresh verification status'));
      await tester.pumpAndSettle();
      expect(api.privateReads, 1);
      expect(find.byKey(const Key('phone-number')), findsOneWidget);
      expect(api.writes, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('reused EvApi phone screen discards prior account secrets ($arabic)',
        (tester) async {
      final api = PhoneApi();
      addTearDown(api.close);
      await tester.pumpWidget(screen(api, arabic: arabic));
      await tester.pumpAndSettle();
      expect(api.privateReads, 1);
      await tester.enterText(find.byKey(const Key('phone-number')), '+971501234567');
      await tester.enterText(find.byKey(const Key('phone-password')), 'OLD PRIVATE PASSWORD');
      final priorPassword = tester.widget<TextField>(
          find.byKey(const Key('phone-password'))).controller!;
      await tester.tap(find.byType(CheckboxListTile));
      api.authEpoch++;
      await tester.pumpWidget(screen(api, arabic: arabic));
      await tester.pumpAndSettle();
      expect(api.privateReads, 1, reason: 'New actor must explicitly Refresh');
      expect(find.byKey(const Key('phone-number')), findsNothing);
      expect(priorPassword.text, isEmpty);
      await tester.tap(find.text(
          arabic ? 'تحديث حالة التحقق' : 'Refresh verification status'));
      await tester.pumpAndSettle();
      expect(api.privateReads, 2);
      expect(tester.widget<TextField>(
          find.byKey(const Key('phone-number'))).controller!.text, isEmpty);
      expect(tester.widget<CheckboxListTile>(
          find.byType(CheckboxListTile)).value, isFalse);
      expect(api.writes, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('malformed private SMS challenge blocks all verification actions ($arabic)',
        (tester) async {
      final api = PhoneApi()..malformedChallenge = true;
      addTearDown(api.close);
      await tester.pumpWidget(screen(api, arabic: arabic));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('phone-number')), findsNothing);
      expect(find.byKey(const Key('phone-code')), findsNothing);
      expect(find.textContaining('../another-account'), findsNothing);
      api.malformedChallenge = false;
      await tester.tap(find.text(
          arabic ? 'تحديث حالة التحقق' : 'Refresh verification status'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('phone-number')), findsOneWidget);
      expect(find.byKey(const Key('phone-code')), findsNothing);
      expect(api.writes, isEmpty);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('disabled delivery offers no fake SMS or phone verification action', (tester) async {
    final api = PhoneApi()..available = false;
    addTearDown(api.close);
    await tester.pumpWidget(screen(api)); await tester.pumpAndSettle();
    expect(find.text('SMS verification delivery is unavailable.'), findsOneWidget);
    expect(find.byKey(const Key('phone-number')), findsNothing);
    expect(api.writes, isEmpty);
  });
  testWidgets('explicit Arabic consent sends one request; password and code are cleared and never auto-replayed', (tester) async {
    tester.view.physicalSize = const Size(900, 1600); tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize); addTearDown(tester.view.resetDevicePixelRatio);
    final api = PhoneApi(); addTearDown(api.close);
    await tester.pumpWidget(screen(api, arabic: true)); await tester.pumpAndSettle();
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'طلب رسالة تحقق')).onPressed, isNull);
    await tester.enterText(find.byKey(const Key('phone-number')), '+٩٧١٥٠١٢٣٤٥٦٧');
    await tester.enterText(find.byKey(const Key('phone-password')), ' password with spaces ');
    await tester.tap(find.byType(CheckboxListTile)); await tester.pumpAndSettle();
    await tester.tap(find.text('طلب رسالة تحقق')); await tester.pumpAndSettle();
    expect(api.writes, hasLength(1)); expect(api.writes.single['phone'], '+971501234567');
    expect(api.writes.single['password'], ' password with spaces '); expect(api.writes.single['consent'], true);
    expect(tester.widget<TextField>(find.byKey(const Key('phone-password'))).controller!.text, isEmpty);
    await tester.enterText(find.byKey(const Key('phone-code')), '١٢٣٤٥٦');
    await tester.tap(find.text('تأكيد رقم الهاتف')); await tester.pumpAndSettle();
    expect(api.writes, hasLength(2)); expect(api.writes.last['code'], '123456');
    expect(tester.widget<TextField>(find.byKey(const Key('phone-code'))).controller!.text, isEmpty);
    await tester.tap(find.text('تحديث حالة التحقق')); await tester.pumpAndSettle(); expect(api.writes, hasLength(2));
    expect(Directionality.of(tester.element(find.byType(PhoneVerificationView))), TextDirection.rtl);
  });
  testWidgets('lost request retains original identity for an explicit retry', (tester) async {
    tester.view.physicalSize = const Size(900, 1600); tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize); addTearDown(tester.view.resetDevicePixelRatio);
    final api = PhoneApi()..lost = true; addTearDown(api.close);
    await tester.pumpWidget(screen(api)); await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('phone-number')), '+971501234567');
    await tester.enterText(find.byKey(const Key('phone-password')), 'current-password');
    await tester.tap(find.byType(CheckboxListTile)); await tester.pumpAndSettle();
    await tester.tap(find.text('Request verification SMS')); await tester.pumpAndSettle();
    expect(api.writes, hasLength(1));
    await tester.enterText(find.byKey(const Key('phone-password')), 'current-password'); api.lost = false;
    await tester.tap(find.text('Request verification SMS')); await tester.pumpAndSettle();
    expect(api.writes, hasLength(2)); expect(api.writes.first['idempotency_key'], api.writes.last['idempotency_key']);
  });
}
