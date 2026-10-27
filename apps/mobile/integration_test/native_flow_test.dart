// Device-only, synthetic HTTP fixtures. Never imported by lib or shipped builds.
// Real Laravel protocol acceptance is separately tested in test/live_api_test.dart.
import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:creabiz_ev/session_vault.dart';
import '../test/fixtures/tls.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();
  testWidgets(
    'device trusted-TLS sign-in, Keychain restore/logout, pagination and persistent RTL/themes',
    (tester) async {
      final serverContext = SecurityContext()
        ..useCertificateChainBytes(utf8.encode(fixtureCertificate))
        ..usePrivateKeyBytes(utf8.encode(fixturePrivateKey));
      final server = await HttpServer.bindSecure(
        InternetAddress.loopbackIPv4,
        0,
        serverContext,
      );
      var role = 'customer';
      var authenticated = 0;
      var loggedIn = false;
      final subscription = server.listen((request) async {
        request.response.headers.contentType = ContentType.json;
        final path = request.uri.path;
        dynamic result;
        if (path == '/api/csrf') {
          request.response.cookies.add(
            Cookie('session', 'synthetic-device-session')
              ..secure = true
              ..httpOnly = true
              ..path = '/'
              ..expires = DateTime.now().toUtc().add(
                const Duration(minutes: 30),
              ),
          );
          result = {'token': 'synthetic-device-csrf'};
        } else if (path == '/api/login') {
          expectSync(
            request.headers.value('X-CSRF-TOKEN'),
            'synthetic-device-csrf',
          );
          expectSync(
            request.cookies.any(
              (c) =>
                  c.name == 'session' && c.value == 'synthetic-device-session',
            ),
            isTrue,
          );
          final payload = jsonDecode(await utf8.decoder.bind(request).join());
          role = payload['email'].toString().startsWith('vendor')
              ? 'vendor'
              : 'customer';
          authenticated++;
          loggedIn = true;
          result = {
            'id': authenticated,
            'name': 'Synthetic device fixture',
            'role': role,
          };
        } else if (path == '/api/me') {
          if (!loggedIn ||
              !request.cookies.any(
                (c) =>
                    c.name == 'session' &&
                    c.value == 'synthetic-device-session',
              )) {
            request.response.statusCode = 401;
            result = {'message': 'Unauthenticated'};
          } else {
            result = {
              'id': authenticated,
              'name': 'Synthetic device fixture',
              'role': role,
            };
          }
        } else if (path == '/api/logout') {
          loggedIn = false;
          request.response.statusCode = 204;
        } else if (path == '/api/stations') {
          final page = int.parse(request.uri.queryParameters['page'] ?? '1');
          result = {
            'data': [
              {
                'id': page,
                'name': 'Device fixture station $page',
                'connector_type': 'CCS2',
                'evidence': 'SIMULATED',
                'power_kw': 150,
                'reservable': true,
              },
            ],
            'current_page': page,
            'last_page': 2,
            'total': 26,
          };
        } else {
          result = {'data': [], 'current_page': 1, 'last_page': 1, 'total': 0};
        }
        if (request.response.statusCode != 204)
          request.response.write(jsonEncode(result));
        await request.response.close();
      });
      final origin = Uri.parse('https://127.0.0.1:${server.port}');
      final vault = DeviceSessionVault();
      await vault.delete(origin.origin);
      HttpClient trusted() => HttpClient(
        context: SecurityContext()
          ..setTrustedCertificatesBytes(utf8.encode(fixtureCertificate)),
      );
      final firstApi = EvApi(origin, http: trusted(), vault: vault);
      final secondApi = EvApi(origin, http: trusted(), vault: vault);
      addTearDown(() async {
        await vault.delete(origin.origin);
        firstApi.close();
        secondApi.close();
        await subscription.cancel();
        await server.close(force: true);
      });
      await (await SharedPreferences.getInstance()).remove(
        DevicePreferenceStore.key,
      );
      final preferences = AppPreferences(DevicePreferenceStore());
      await preferences.load();
      await tester.pumpWidget(EvApp(preferences: preferences, api: firstApi));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('auth-email')),
        'customer@example.test',
      );
      await tester.enterText(
        find.byKey(const Key('auth-password')),
        'synthetic-device-password',
      );
      await tester.ensureVisible(find.byKey(const Key('keep-signed-in')));
      await tester.tap(find.byKey(const Key('keep-signed-in')));
      await tester.ensureVisible(find.byKey(const Key('auth-submit')));
      await tester.tap(find.byKey(const Key('auth-submit')));
      await tester.pumpAndSettle();
      expect(authenticated, 1);
      expect(firstApi.remembered, isTrue);
      expect(await vault.read(origin.origin), isNotNull);
      await tester.scrollUntilVisible(
        find.byKey(const Key('catalog-next')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byKey(const Key('catalog-next')));
      await tester.pumpAndSettle();
      expect(find.text('Device fixture station 2'), findsOneWidget);
      await tester.tap(find.byKey(const Key('settings')));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<ThemeMode>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Dark').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<Locale>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('العربية').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('إغلاق'));
      await tester.pumpAndSettle();
      await tester.pumpWidget(const SizedBox());
      await tester.pumpAndSettle();
      await (await SharedPreferences.getInstance()).reload();
      final restored = AppPreferences(DevicePreferenceStore());
      await restored.load();
      expect(restored.theme, ThemeMode.dark);
      expect(restored.locale.languageCode, 'ar');
      await tester.pumpWidget(EvApp(preferences: restored, api: secondApi));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('auth-email')), findsNothing);
      expect(secondApi.remembered, isTrue);
      await tester.tap(find.byTooltip('الحساب'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('تسجيل الخروج'));
      await tester.pumpAndSettle();
      expect(await vault.read(origin.origin), isNull);
      await tester.enterText(
        find.byKey(const Key('auth-email')),
        'vendor@example.test',
      );
      await tester.enterText(
        find.byKey(const Key('auth-password')),
        'synthetic-device-password',
      );
      await tester.tap(find.byKey(const Key('auth-submit')));
      await tester.pumpAndSettle();
      expect(authenticated, 2);
      expect(secondApi.remembered, isFalse);
      expect(find.byTooltip('إنشاء'), findsOneWidget);
      expect(
        Directionality.of(tester.element(find.byType(Workspace))),
        TextDirection.rtl,
      );
      expect(
        Theme.of(tester.element(find.byType(Workspace))).brightness,
        Brightness.dark,
      );
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
      await tester.pumpAndSettle();
    },
  );
}
