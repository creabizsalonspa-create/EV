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
      final serverContext = fixtureServerContext();
      final server = await HttpServer.bindSecure(
        InternetAddress.loopbackIPv4,
        0,
        serverContext,
      );
      var role = 'customer';
      var authenticated = 0;
      var loggedIn = false;
      var pricingAmount = 125, pricingVersion = 1;
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
        } else if (path == '/api/finance/stations') {
          result = {
            'data': [
              {
                'id': 8,
                'name': 'Device pricing station',
                'currency': 'AED',
                'minor_units': 2,
                'evidence': 'SIMULATED',
              },
            ],
            'current_page': 1,
            'last_page': 1,
            'total': 1,
          };
        } else if (path == '/api/stations/8/tariffs') {
          if (request.method == 'POST') {
            expectSync(role, 'vendor');
            expectSync(
              request.headers.value('X-CSRF-TOKEN'),
              'synthetic-device-csrf',
            );
            final body = jsonDecode(await utf8.decoder.bind(request).join());
            expectSync(body['commission_bps'], 0);
            expectSync(body['expected_minor_units'], 2);
            expectSync(body['expected_currency'], 'AED');
            expectSync(
              body['idempotency_key'],
              matches(RegExp(r'^[a-f0-9]{32}$')),
            );
            pricingAmount = body['rate_minor_per_kwh'] as int;
            result = {'id': ++pricingVersion};
          } else {
            result = {
              'data': [
                {
                  'id': pricingVersion,
                  'rate_minor_per_kwh': pricingAmount,
                  'tax_bps': 500,
                  'commission_bps': 1250,
                  'minor_units': 2,
                  'currency': 'AED',
                  'effective_from': '2026-10-27T00:00:00Z',
                },
              ],
              'current_page': 1,
              'last_page': 1,
              'total': 1,
              'pricing_context': {
                'currency': 'AED',
                'minor_units': 2,
                'evidence': 'SIMULATED',
              },
            };
          }
        } else if (path == '/api/finance/invoices' ||
            path == '/api/finance/invoices/7') {
          expectSync(request.method, 'GET');
          final invoice = {
            'id': 7,
            'number': 'SIM-DEVICE-INVOICE',
            'evidence': 'SIMULATED',
            'created_at': '2026-10-27T00:00:00Z',
            'currency': 'AED',
            'subtotal_minor': 100,
            'tax_minor': 5,
            'total_minor': 105,
          };
          result = path.endsWith('/7')
              ? {
                  'invoice': invoice,
                  'payment_provider': 'SIMULATOR_ONLY',
                  'real_payment_processed': false,
                  'paid_minor': 0,
                  'refunded_minor': 0,
                  'amount_due_minor': 105,
                  'ledger_balanced': true,
                  'payments': [],
                  'refunds': [],
                }
              : {
                  'data': [invoice],
                  'current_page': 1,
                  'last_page': 1,
                  'total': 1,
                };
        } else if (path == '/api/roaming/locations') {
          final page = int.parse(request.uri.queryParameters['page'] ?? '1');
          result = {
            'data': [
              {
                'location': {
                  'id': '$page',
                  'name': 'Device partner fixture $page',
                  'address': 'Synthetic address',
                  'city': 'Test city',
                  'country': 'ARE',
                  'latitude': '25.2',
                  'longitude': '55.3',
                  'last_updated': '2026-10-27T00:00:00Z',
                  'evses': [],
                },
                'received_at': '2026-10-27T00:01:00Z',
                'evidence': 'PARTNER_REPORTED_UNVERIFIED',
                'availability': 'unknown',
                'remote_control': false,
                'reservable': false,
              },
            ],
            'current_page': page,
            'last_page': 2,
            'total': 26,
          };
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
      debugPrint("DEVICE: clearing isolated Keychain entry");
      await vault.delete(origin.origin).timeout(const Duration(seconds: 20));
      HttpClient trusted() => HttpClient(context: fixtureClientContext());
      final firstApi = EvApi(origin, http: trusted(), vault: vault);
      final secondApi = EvApi(origin, http: trusted(), vault: vault);
      Future<void> settle() async {
        await tester.pumpAndSettle(
          const Duration(milliseconds: 100),
          EnginePhase.sendSemanticsUpdate,
          const Duration(seconds: 90),
        );
      }

      addTearDown(() async {
        await vault.delete(origin.origin).timeout(const Duration(seconds: 20));
        firstApi.close();
        secondApi.close();
        await subscription.cancel();
        await server.close(force: true);
      });
      debugPrint("DEVICE: validating fixture TLS before UI");
      await firstApi.csrf().timeout(const Duration(seconds: 30));
      debugPrint("DEVICE: fixture TLS ready");
      await (await SharedPreferences.getInstance()).remove(
        DevicePreferenceStore.key,
      );
      final preferences = AppPreferences(DevicePreferenceStore());
      await preferences.load();
      await tester.pumpWidget(EvApp(preferences: preferences, api: firstApi));
      await settle();
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
      await settle();
      debugPrint("DEVICE: customer authenticated");
      expect(authenticated, 1);
      expect(firstApi.remembered, isTrue);
      expect(await vault.read(origin.origin), isNotNull);
      await tester.ensureVisible(find.byKey(const Key('roaming-open')));
      await tester.tap(find.byKey(const Key('roaming-open')));
      await settle();
      expect(find.text('Device partner fixture 1'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Next'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Next'));
      await settle();
      expect(find.text('Device partner fixture 2'), findsOneWidget);
      expect(find.text('Live availability is unknown.'), findsOneWidget);
      await tester.pageBack();
      await settle();
      debugPrint('DEVICE: read-only partner pagination verified');
      await tester.tap(find.byTooltip('Account'));
      await settle();
      await tester.tap(find.text('Invoice history'));
      await settle();
      expect(find.text('SIM-DEVICE-INVOICE'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('View invoice'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('View invoice'));
      await settle();
      expect(find.text('SIM-DEVICE-INVOICE'), findsOneWidget);
      expect(find.text('Amount due: 105 minor units AED'), findsOneWidget);
      await tester.pageBack();
      await settle();
      await tester.pageBack();
      await settle();
      debugPrint('DEVICE: scoped read-only invoice history verified');

      await tester.scrollUntilVisible(
        find.byKey(const Key('catalog-next')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byKey(const Key('catalog-next')));
      await settle();
      expect(find.text('Device fixture station 2'), findsOneWidget);
      await tester.tap(find.byKey(const Key('settings')));
      await settle();
      await tester.tap(find.byType(DropdownButtonFormField<ThemeMode>));
      await settle();
      await tester.tap(find.text('Dark').last);
      await settle();
      await tester.tap(find.byType(DropdownButtonFormField<Locale>));
      await settle();
      await tester.tap(find.text('العربية').last);
      await settle();
      await tester.tap(find.text('إغلاق'));
      await settle();
      await tester.pumpWidget(const SizedBox());
      await settle();
      await (await SharedPreferences.getInstance()).reload();
      final restored = AppPreferences(DevicePreferenceStore());
      await restored.load();
      expect(restored.theme, ThemeMode.dark);
      expect(restored.locale.languageCode, 'ar');
      await tester.pumpWidget(EvApp(preferences: restored, api: secondApi));
      await settle();
      expect(find.byKey(const Key('auth-email')), findsNothing);
      debugPrint("DEVICE: Keychain session restored");
      expect(secondApi.remembered, isTrue);
      await tester.tap(find.byTooltip('الحساب'));
      await settle();
      await tester.tap(find.text('تسجيل الخروج'));
      await settle();
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
      await settle();
      debugPrint("DEVICE: vendor authenticated after secure logout");
      expect(authenticated, 2);
      expect(secondApi.remembered, isFalse);
      expect(find.byTooltip('إنشاء'), findsOneWidget);
      await tester.tap(find.byTooltip('الحساب'));
      await settle();
      await tester.tap(find.text('التسعير التجريبي'));
      await settle();
      await tester.tap(find.text('Device pricing station'));
      await settle();
      await tester.enterText(find.byType(TextFormField).at(0), '٢٫٥٠');
      await tester.enterText(find.byType(TextFormField).at(1), '٥');
      await tester.ensureVisible(find.text('نشر إصدار التسعير'));
      await tester.tap(find.text('نشر إصدار التسعير'));
      await settle();
      expect(pricingAmount, 250);
      expect(find.text('2.50 AED / kWh'), findsOneWidget);
      await tester.pageBack();
      await settle();
      await tester.pageBack();
      await settle();
      debugPrint('DEVICE: exact Arabic vendor pricing verified');

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
      await settle();
    },
  );
}
