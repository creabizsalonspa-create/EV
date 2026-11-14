import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/phone_verification.dart';

class PhoneApi extends EvApi {
  PhoneApi() : super(Uri.parse('https://example.invalid'));
  bool available = true, lost = false;
  final writes = <Map<String, dynamic>>[];
  Map<String, dynamic>? challenge;
  @override
  Future<dynamic> request(String path, {String method = 'GET', Map<String, dynamic>? body, Map<String, String> query = const {}}) async {
    if (method == 'GET') return {'available': available, 'verified': false, 'phone_mask': null, 'challenge': challenge};
    writes.add({'path': path, ...?body});
    if (path.endsWith('/challenges')) {
      if (lost) throw const ApiException(0, 'Synthetic lost response');
      challenge = {'id': 'owned-challenge', 'status': 'pending'};
      return challenge;
    }
    return {'verified': false, 'status': 'pending', 'attempts_remaining': 4};
  }
}
Widget screen(PhoneApi api, {bool arabic = false}) => MaterialApp(locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')], localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: PhoneVerificationView(api: api));
void main() {
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
