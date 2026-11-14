import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/account_second_factor.dart';

class FactorApi extends EvApi {
  FactorApi() : super(Uri.parse('https://example.invalid'));
  final writes = <Map<String, dynamic>>[];
  bool enabled = false, pending = false;
  @override
  Future<dynamic> request(String path, {String method = 'GET', Map<String, dynamic>? body, Map<String, String> query = const {}}) async {
    if (method == 'GET') return {'enabled': enabled, 'pending': pending, 'recovery_remaining': enabled ? 8 : 0};
    writes.add({'path': path, ...?body});
    if (path.endsWith('/prepare')) { pending = true; return {'secret': 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'}; }
    enabled = !path.endsWith('/disable'); pending = false;
    return {'enabled': enabled, 'recovery_codes': enabled ? ['ABCDEF-123456-ABCDEF-123456'] : <String>[], 'reauthentication_required': false};
  }
}
void main() {
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
  test('native login sends a factor only on the explicit factor entry path', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final bodies = <Map<String, dynamic>>[];
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path.endsWith('/login')) {
        bodies.add(Map<String, dynamic>.from(jsonDecode(await utf8.decoder.bind(request).join()) as Map));
        request.response.write(jsonEncode({'id': 1, 'name': 'Customer', 'role': 'customer', 'email_verified_at': null}));
      } else { request.response.write(jsonEncode({'token': 'synthetic-csrf'})); }
      await request.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:${server.port}'));
    try {
      await api.signInWithFactor('customer@example.invalid', 'current-password', '012345');
      expect(bodies.single['second_factor_code'], '012345');
      await api.signIn('customer@example.invalid', 'current-password');
      expect(bodies.last.containsKey('second_factor_code'), isFalse);
    } finally { api.close(); await subscription.cancel(); await server.close(force: true); }
  });
}
