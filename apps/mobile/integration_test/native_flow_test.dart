// Device-only, synthetic HTTP fixtures. Never imported by lib or shipped builds.
// Real Laravel protocol acceptance is separately tested in test/live_api_test.dart.
import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/financial_operation_store.dart';
import 'package:creabiz_ev/subscriptions.dart';
import 'package:creabiz_ev/payment_cases.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:creabiz_ev/session_vault.dart';
import '../test/fixtures/tls.dart';

List<int> syntheticKycPdf() {
  const stream = 'BT /F1 18 Tf 20 100 Td (Synthetic KYC fixture) Tj ET';
  final objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Length ${stream.length} >>\nstream\n$stream\nendstream',
  ];
  final text = StringBuffer('%PDF-1.4\n'), offsets = <int>[];
  for (var i = 0; i < objects.length; i++) {
    offsets.add(text.length);
    text.write('${i + 1} 0 obj\n${objects[i]}\nendobj\n');
  }
  final start = text.length;
  text.write('xref\n0 6\n0000000000 65535 f \n');
  for (final offset in offsets)
    text.write('${offset.toString().padLeft(10, '0')} 00000 n \n');
  text.write('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n');
  return utf8.encode(text.toString());
}

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
      var subscriptionEnrollments = 0;
      var caseEvidenceUploads = 0;
      var authenticated = 0;
      var loggedIn = false;
      var pricingAmount = 125, pricingVersion = 1;
      bool mandateRevoked = false;
      final pdf = syntheticKycPdf();
      final evidenceDirectory = await Directory.systemTemp.createTemp(
        'creabiz-synthetic-evidence-',
      );
      final evidenceFile = await File(
        '${evidenceDirectory.path}/synthetic.pdf',
      ).writeAsBytes(pdf);
      const pickerChannel = MethodChannel(
        'miguelruivo.flutter.plugins.filepicker',
      );
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(pickerChannel, (call) async {
            if (call.method == 'clear') return true;
            if (call.method == 'custom')
              return [
                {
                  'path': evidenceFile.path,
                  'name': 'synthetic.pdf',
                  'size': pdf.length,
                  'bytes': null,
                },
              ];
            return null;
          });
      var deviceLocationReads = 0;
      const locationChannel = MethodChannel(
        'flutter.baseflow.com/geolocator_apple',
      );
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(locationChannel, (call) async {
            if (call.method == 'isLocationServiceEnabled') return true;
            if (call.method == 'checkPermission' ||
                call.method == 'requestPermission')
              return LocationPermission.whileInUse.index;
            if (call.method == 'getCurrentPosition') {
              deviceLocationReads++;
              return Position(
                latitude: 25.2,
                longitude: 55.3,
                timestamp: DateTime.now(),
                accuracy: 25,
                altitude: 0,
                altitudeAccuracy: 0,
                heading: 0,
                headingAccuracy: 0,
                speed: 0,
                speedAccuracy: 0,
                hasAccuracy: true,
              ).toJson();
            }
            throw StateError('Unexpected synthetic location method.');
          });
      addTearDown(() async {
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(locationChannel, null);
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(pickerChannel, null);
        await evidenceDirectory.delete(recursive: true);
      });
      Map<String, dynamic>? managedCompany;
      bool evidenceUploaded = false;
      final savedStations = <int>{};
      Map<String, dynamic> stationFixture(int id) => {
        'id': id,
        'name': 'Device fixture station $id',
        'connector_type': 'CCS2',
        'evidence': 'SIMULATED',
        'power_kw': 150,
        'latitude': 25.2,
        'longitude': 55.3,
        'reservable': true,
      };

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
        } else if (path == '/api/commercial/cases/75/evidence') {
          final row = {
            'id': 76,
            'case_id': 75,
            'kind': 'service_record',
            'mime_type': 'application/pdf',
            'size_bytes': pdf.length,
            'sha256': sha256.convert(pdf).toString(),
            'created_at': '2026-10-28',
            'evidence_status': 'PRIVATE_REVIEW_ONLY',
            'provider_submitted': false,
          };
          if (request.method == 'POST') {
            expectSync(loggedIn, isTrue);
            expectSync(
              request.headers.contentType!.mimeType,
              'multipart/form-data',
            );
            final body = await utf8.decoder.bind(request).join();
            expectSync(body, contains('filename="evidence.pdf"'));
            expectSync(body, contains('name="consent"'));
            expectSync(body, contains('%PDF-1.4'));
            caseEvidenceUploads++;
            result = row;
          } else {
            result = {
              'data': caseEvidenceUploads == 0 ? [] : [row],
              'current_page': 1,
              'last_page': 1,
              'total': caseEvidenceUploads == 0 ? 0 : 1,
              'can_upload': true,
            };
          }
        } else if (path == '/api/commercial/plans') {
          result = {
            'data': [
              {
                'id': 71,
                'name': 'Synthetic device subscription',
                'audience': role,
                'available': true,
                'currency': 'AED',
                'minor_units': 2,
                'price_minor': 1250,
                'interval': 'month',
                'entitlements': {'priority': true},
              },
            ],
            'current_page': 1,
            'last_page': 1,
            'total': 1,
          };
        } else if (path == '/api/commercial/subscriptions' &&
            request.method == 'POST') {
          final payload = jsonDecode(await utf8.decoder.bind(request).join());
          expectSync(payload['plan_id'], 71);
          expectSync(payload['idempotency_key'], isA<String>());
          subscriptionEnrollments++;
          result = {
            'subscription': {
              'id': 72,
              'user_id': authenticated,
              'plan_id': 71,
              'status': 'pending',
            },
            'plan': {'id': 71},
            'invoices': [],
            'entitlements': [],
            'entitlements_active': false,
            'ledger_balanced': true,
            'real_payment_processed': false,
          };
        } else if (path == '/api/commercial/loyalty/account') {
          result = {
            'user_id': authenticated,
            'balance_points': 70,
            'pending_reconciliation_points': 0,
            'spendable_points': 70,
            'qualified_lifetime_points': 100,
            'redemption_hold': false,
            'unit': 'NON_MONETARY_POINTS',
            'cash_value': false,
            'transferable': false,
            'evidence': 'TEST_PROMOTIONAL_ECONOMICS',
            'tier_by_currency': {
              'AED': {'tier': 'Gold', 'enabled': true},
            },
          };
        } else if (path == '/api/commercial/loyalty/rewards') {
          result = {
            'current_page': 1,
            'last_page': 1,
            'total': 1,
            'data': [
              {
                'id': 7,
                'name': 'Synthetic device benefit',
                'name_ar': 'منفعة جهاز تجريبية',
                'description': 'Manual test benefit only',
                'description_ar': 'منفعة اختبار يدوية فقط',
                'points_cost': 30,
                'available_stock': 1,
                'cash_value': false,
                'fulfillment': 'MANUAL_NON_MONETARY',
              },
            ],
          };
        } else if (path == '/api/financial/analytics') {
          expectSync(loggedIn, isTrue);
          result = {
            'scope': 'own_charging_and_own_billing',
            'real_money_verified': false,
            'period': {
              'from': request.uri.queryParameters['from'],
              'to': request.uri.queryParameters['to'],
              'previous_from': '2026-01-01',
              'previous_to': '2026-01-30',
              'timezone': 'UTC',
            },
            'as_of': DateTime.now().toUtc().toIso8601String(),
            'category_labels': {
              'charging_invoiced': ['Charging invoices issued', 'فواتير الشحن'],
            },
            'state_labels': {
              'issued': ['Issued', 'صادر'],
            },
            'groups': [
              {
                'period': 'current',
                'category': 'charging_invoiced',
                'currency': 'AED',
                'minor_units': 2,
                'state': 'issued',
                'evidence': 'SIMULATED',
                'record_count': 1,
                'amount_minor': 105,
              },
            ],
            'points': {
              'unit': 'NON_MONETARY_POINTS',
              'cash_value': false,
              'groups': [],
            },
            'recurring_run_rates': [],
          };
        } else if (path == '/api/financial/analytics/pdf') {
          expectSync(loggedIn, isTrue);
          expectSync(request.uri.queryParameters['locale'], 'en');
          request.response.headers.contentType = ContentType(
            'application',
            'pdf',
          );
          request.response.add(pdf);
          await request.response.close();
          return;
        } else if (path == '/api/financial/documents') {
          final row = {
            'id': 9,
            'source_id': 7,
            'source_kind': 'charging_invoice',
            'number': 'TEST-DEVICE-DOC',
            'locale': 'en',
            'pdf_sha256': sha256.convert(pdf).toString(),
            'legal_status': 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED',
            'external_provider_verified': false,
          };
          result = request.method == 'POST'
              ? row
              : {
                  'data': [row],
                  'current_page': 1,
                  'last_page': 1,
                  'total': 1,
                };
        } else if (path == '/api/financial/documents/9/download/pdf') {
          expectSync(loggedIn, isTrue);
          request.response.headers.contentType = ContentType(
            'application',
            'pdf',
          );
          request.response.add(pdf);
          await request.response.close();
          return;
        } else if (path == '/api/commercial/payment-methods') {
          result = {
            'methods': {
              'data': [
                {
                  'id': 7,
                  'brand': 'visa',
                  'last_four': '4242',
                  'exp_month': 12,
                  'exp_year': 2032,
                  'status': mandateRevoked ? 'revoked' : 'active',
                  'version': mandateRevoked ? 2 : 1,
                  'detach_status': 'pending',
                  'consent_expires_at': '2032-01-01',
                },
              ],
              'current_page': 1,
              'last_page': 1,
            },
            'default_method_id': 7,
            'version': 1,
            'consent_version': 'recurring-v1',
            'hosted_available': false,
            'collection_available': false,
          };
        } else if (path == '/api/commercial/payment-methods/7' &&
            request.method == 'DELETE') {
          final body =
              jsonDecode(await utf8.decoder.bind(request).join()) as Map;
          expectSync(body['expected_version'], 1);
          expectSync(loggedIn, isTrue);
          mandateRevoked = true;
          result = {};
        } else if (path == '/api/regions') {
          result = [
            {'country': 'AE', 'timezone': 'Asia/Dubai'},
          ];
        } else if (path == '/api/vendor-ops/companies') {
          expectSync(loggedIn, isTrue);
          if (request.method == 'POST') {
            final body =
                jsonDecode(await utf8.decoder.bind(request).join()) as Map;
            expectSync(
              body['idempotency_key'],
              matches(RegExp(r'^[a-f0-9-]{36}$')),
            );
            managedCompany = {
              'id': 1,
              'legal_name': body['legal_name'],
              'country': body['country'],
              'license_expires_on': body['license_expires_on'],
              'status': 'draft',
              'capabilities': {
                'owner': true,
                'company_read': true,
                'private_evidence': true,
                'inventory_read': true,
                'inventory_write': true,
                'inventory_operational': false,
              },
            };
            result = managedCompany;
            request.response.statusCode = 201;
          } else
            result = {
              'data': managedCompany == null ? [] : [managedCompany],
              'current_page': 1,
              'last_page': 1,
              'total': managedCompany == null ? 0 : 1,
            };
        } else if (path == '/api/vendor-ops/companies/1') {
          expectSync(loggedIn, isTrue);
          result = {
            ...?managedCompany,
            'submission_readiness': {
              'editable': true,
              'license_valid': true,
              'trade_license_present': evidenceUploaded,
            },
          };
        } else if (path == '/api/vendor-ops/companies/1/documents') {
          expectSync(loggedIn, isTrue);
          if (request.method == 'POST') {
            expectSync(
              request.headers.contentType?.mimeType,
              'multipart/form-data',
            );
            expectSync(
              request.headers.value('X-CSRF-TOKEN'),
              'synthetic-device-csrf',
            );
            final raw = latin1.decode(
              await request.fold<List<int>>([], (a, b) => a..addAll(b)),
            );
            expectSync(raw, contains('%PDF-1.4'));
            expectSync(raw, contains('name="idempotency_key"'));
            evidenceUploaded = true;
            request.response.statusCode = 201;
            result = {'id': 5};
          } else
            result = {
              'data': evidenceUploaded
                  ? [
                      {
                        'id': 5,
                        'kind': 'trade_license',
                        'mime_type': 'application/pdf',
                        'size_bytes': pdf.length,
                        'created_at': '2026-10-28',
                      },
                    ]
                  : [],
              'current_page': 1,
              'last_page': 1,
              'total': evidenceUploaded ? 1 : 0,
            };
        } else if (path == '/api/vendor-ops/companies/1/documents/5') {
          expectSync(loggedIn && evidenceUploaded, isTrue);
          request.response.headers.contentType = ContentType(
            'application',
            'pdf',
          );
          request.response.add(pdf);
          await request.response.close();
          return;
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
        } else if (path == '/api/discovery/capabilities') {
          result = {
            'provider': 'geoapify',
            'geocoding': true,
            'routing': true,
            'max_charging_stops': 3,
          };
        } else if (path == '/api/discovery/addresses') {
          final body =
              jsonDecode(await utf8.decoder.bind(request).join())
                  as Map<String, dynamic>;
          expectSync(body['consent'], true);
          result = {
            'provider': 'geoapify',
            'evidence': 'GEOCODING_RESULT',
            'places': [
              {
                'id': 'geoapify:device-fixture',
                'label': 'Device address fixture',
                'latitude': 25.2,
                'longitude': 55.3,
              },
            ],
          };
        } else if (path == '/api/discovery/route') {
          final body =
              jsonDecode(await utf8.decoder.bind(request).join())
                  as Map<String, dynamic>;
          expectSync(body['consent'], true);
          expectSync((body['points'] as List).length, 3);
          expectSync((body['points'] as List)[1]['latitude'], 25.2);
          result = {
            'provider': 'geoapify',
            'evidence': 'PROVIDER_ROUTE_ESTIMATE',
            'distance_meters': 1250,
            'duration_seconds': 220,
            'traffic_verified': false,
            'charging_time_included': false,
            'segments': [
              [
                {'latitude': 25.2, 'longitude': 55.3},
                {'latitude': 25.3, 'longitude': 55.4},
              ],
            ],
          };
        } else if (path.startsWith('/api/favorites')) {
          expectSync(loggedIn && role == 'customer', isTrue);
          expectSync(
            request.cookies.any(
              (c) =>
                  c.name == 'session' && c.value == 'synthetic-device-session',
            ),
            isTrue,
          );
          if (path == '/api/favorites/status') {
            final requested = request.uri.queryParametersAll.entries
                .where((e) => e.key.startsWith('station_ids['))
                .expand((e) => e.value)
                .map(int.parse)
                .toSet();
            result = {
              'favorite_station_ids': savedStations
                  .where(requested.contains)
                  .toList(),
            };
          } else if (path == '/api/favorites') {
            result = {
              'data': savedStations
                  .map((id) => {...stationFixture(id), 'is_favorite': true})
                  .toList(),
              'current_page': 1,
              'last_page': 1,
              'total': savedStations.length,
            };
          } else {
            expectSync(
              request.headers.value('X-CSRF-TOKEN'),
              'synthetic-device-csrf',
            );
            final id = int.parse(request.uri.pathSegments.last);
            expectSync(['PUT', 'DELETE'].contains(request.method), isTrue);
            request.method == 'PUT'
                ? savedStations.add(id)
                : savedStations.remove(id);
            result = {
              'station_id': id,
              'is_favorite': savedStations.contains(id),
            };
          }
        } else if (path == '/api/stations') {
          final page = int.parse(request.uri.queryParameters['page'] ?? '1');
          result = {
            'data': [stationFixture(page)],
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
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint('DEVICE: read-only partner pagination verified');
      await tester.scrollUntilVisible(
        find.byKey(const Key('favorite-1')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      expect(find.byKey(const Key('favorite-1')).hitTestable(), findsOneWidget);
      await tester.tap(find.byKey(const Key('favorite-1')));
      await settle();
      expect(savedStations, {1});
      expect(find.text('Remove saved station'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.byKey(const Key('favorites-open')),
        -300,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      expect(
        find.byKey(const Key('favorites-open')).hitTestable(),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('favorites-open')));
      await settle();
      expect(find.text('Device fixture station 1'), findsOneWidget);
      await tester.tap(find.byKey(const Key('favorite-1')));
      await settle();
      expect(savedStations, isEmpty);
      expect(find.text('No saved stations yet.'), findsOneWidget);
      await tester.tap(find.byType(BackButton));
      await settle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('favorite-1')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      expect(find.byKey(const Key('favorite-1')).hitTestable(), findsOneWidget);
      expect(find.text('Save station'), findsOneWidget);
      debugPrint(
        'DEVICE: customer favorites and private catalog status verified',
      );

      await tester.scrollUntilVisible(
        find.byKey(const Key('catalog-map-open')),
        -300,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      expect(
        find.byKey(const Key('catalog-map-open')).hitTestable(),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('catalog-map-open')));
      await settle();
      expect(find.text('Station map'), findsOneWidget);
      expect(find.byKey(const Key('enable-map')), findsOneWidget);
      expect(
        find.textContaining('OpenStreetMap receives your IP'),
        findsOneWidget,
      );
      await tester.scrollUntilVisible(
        find.byKey(const Key('native-location-once')),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      await tester.tap(find.byKey(const Key('native-location-once')));
      await settle();
      expect(deviceLocationReads, 0);
      await tester.tap(find.byKey(const Key('location-consent-accept')));
      await settle();
      expect(deviceLocationReads, 1);
      expect(find.textContaining('approximately 25 m'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.byKey(const Key('charging-route-open')),
        -250,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      expect(
        find.byKey(const Key('charging-route-open')).hitTestable(),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('charging-route-open')));
      await settle();
      for (final entry in {
        'destination-lat': '25.3',
        'destination-lng': '55.4',
      }.entries) {
        await tester.scrollUntilVisible(
          find.byKey(Key(entry.key)),
          250,
          scrollable: find.byType(Scrollable).first,
        );
        await settle();
        await tester.enterText(find.byKey(Key(entry.key)), entry.value);
      }
      FocusManager.instance.primaryFocus?.unfocus();
      await settle();
      await tester.scrollUntilVisible(
        find.text('Add charging stops from results'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      await tester.tap(find.text('Add charging stops from results'));
      await settle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-stop-1')),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      expect(
        find.byKey(const Key('route-stop-1')).hitTestable(),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('route-stop-1')));
      await settle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-provider-consent')),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      expect(
        find.byKey(const Key('route-provider-consent')).hitTestable(),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('route-provider-consent')));
      await settle();
      await tester.scrollUntilVisible(
        find.byKey(const Key('route-preview')),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await settle();
      await tester.tap(find.byKey(const Key('route-preview')));
      await settle();
      expect(find.text('1.3 km · 4 min'), findsOneWidget);
      expect(find.textContaining('Traffic is not verified'), findsOneWidget);
      debugPrint(
        'DEVICE: consented one-shot GPS and sourced charging-stop route verified; synthetic platform/provider',
      );
      await tester.tap(find.byType(BackButton));
      await settle();
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint(
        'DEVICE: customer map privacy consent navigation verified; no external tiles',
      );

      await tester.tap(find.byTooltip('Account'));
      await settle();
      await tester.scrollUntilVisible(
        find.text('Financial insights'),
        200,
        scrollable: find.byType(Scrollable).last,
      );
      await tester.tap(find.text('Financial insights'));
      await settle();
      await tester.scrollUntilVisible(
        find.text('AED 1.05'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('AED 1.05'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.byKey(const Key('analytics-pdf')),
        -150,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byKey(const Key('analytics-pdf')));
      await settle();
      final analyticsPdfDeadline = DateTime.now().add(
        const Duration(seconds: 30),
      );
      while (find
              .byKey(const Key('private-document-ready'))
              .evaluate()
              .isEmpty &&
          DateTime.now().isBefore(analyticsPdfDeadline)) {
        await tester.pump(const Duration(milliseconds: 200));
        await Future<void>.delayed(const Duration(milliseconds: 100));
      }
      expect(find.byKey(const Key('private-document-ready')), findsOneWidget);
      await tester.tap(find.byType(BackButton));
      await settle();
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint(
        'DEVICE: scoped financial analytics and private management PDF verified; synthetic source only',
      );

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
      await tester.scrollUntilVisible(
        find.text('Private financial documents'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Private financial documents'));
      await settle();
      expect(find.text('TEST-DEVICE-DOC'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('View private PDF'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('View private PDF'));
      await settle();
      final financePdfDeadline = DateTime.now().add(
        const Duration(seconds: 30),
      );
      while (find
              .byKey(const Key('private-document-ready'))
              .evaluate()
              .isEmpty &&
          DateTime.now().isBefore(financePdfDeadline)) {
        expect(find.byKey(const Key('private-document-failed')), findsNothing);
        await Future<void>.delayed(const Duration(milliseconds: 100));
        await tester.pump();
      }
      expect(find.byKey(const Key('private-document-ready')), findsOneWidget);
      final financialStore = DeviceFinancialOperationStore();
      final firstOperation = FinancialOperation(
        financialStore,
        'synthetic-native-operation',
      );
      final financialBody = await firstOperation.prepare({
        'amount_minor': 25,
        'reason': 'Synthetic device proof',
      });
      final restoredOperation = FinancialOperation(
        financialStore,
        'synthetic-native-operation',
      );
      await restoredOperation.restore();
      expect(
        await restoredOperation.prepare({
          'amount_minor': 25,
          'reason': 'Synthetic device proof',
        }),
        financialBody,
      );
      await restoredOperation.confirmed();
      expect(await financialStore.read('synthetic-native-operation'), isNull);
      await tester.tap(find.byType(BackButton));
      await settle();
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint(
        'DEVICE: private financial PDF and durable operation storage verified; synthetic document',
      );

      await tester.tap(find.byType(BackButton));
      await settle();
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint('DEVICE: scoped read-only invoice history verified');
      await tester.tap(find.byTooltip('Account'));
      await settle();
      await tester.tap(find.text('Points and rewards'));
      await settle();
      expect(find.text('Posted points: 70'), findsOneWidget);
      expect(find.text('AED: Gold'), findsOneWidget);
      expect(find.textContaining('No cash value'), findsOneWidget);
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await settle();
      await tester.tap(find.text('Reward catalog').last);
      await settle();
      expect(find.text('Synthetic device benefit'), findsOneWidget);
      expect(find.textContaining('Points required: 30'), findsOneWidget);
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint(
        'DEVICE: scoped noncash native loyalty evidence verified; synthetic economics',
      );

      await tester.tap(find.byTooltip('Account'));
      await settle();
      await tester.tap(find.text('Saved payment authorizations'));
      await settle();
      expect(find.text('visa •••• 4242'), findsOneWidget);
      await tester.ensureVisible(find.text('Revoke authorization'));
      await tester.tap(find.text('Revoke authorization'));
      await settle();
      expect(mandateRevoked, isTrue);
      expect(find.text('Remove from provider'), findsOneWidget);
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint(
        'DEVICE: versioned saved mandate revocation verified; no external PSP',
      );

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
      await tester.tap(find.byType(BackButton));
      await settle();
      await tester.tap(find.byType(BackButton));
      await settle();
      debugPrint('DEVICE: exact Arabic vendor pricing verified');
      await tester.tap(find.byTooltip('الحساب'));
      await settle();
      await tester.tap(find.text('مخزون الشركة'));
      await settle();
      await tester.tap(find.text('إنشاء شركة'));
      await settle();
      await tester.enterText(
        find.widgetWithText(TextFormField, 'الاسم القانوني للشركة'),
        'شركة اختبار الجهاز',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'رقم الرخصة التجارية'),
        'SYNTHETIC-DEVICE',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'انتهاء الرخصة (YYYY-MM-DD)'),
        '2030-12-31',
      );
      // Real iOS keyboard transitions must finish before locating the action.
      FocusManager.instance.primaryFocus?.unfocus();
      await settle();
      await tester.scrollUntilVisible(
        find.widgetWithText(FilledButton, 'حفظ'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      expect(
        find.widgetWithText(FilledButton, 'حفظ').hitTestable(),
        findsOneWidget,
      );
      await tester.tap(find.widgetWithText(FilledButton, 'حفظ'));
      await settle();
      expect(find.text('شركة اختبار الجهاز'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('إدارة الشركة'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('إدارة الشركة'));
      await settle();
      await tester.tap(find.text('مستندات التحقق الخاصة'));
      await settle();
      await tester.tap(find.widgetWithText(FilledButton, 'رفع دليل خاص'));
      await settle();
      await tester.tap(find.text('اختيار PDF أو PNG أو JPEG'));
      await settle();
      await tester.tap(find.byType(Checkbox));
      await settle();
      await tester.tap(find.widgetWithText(FilledButton, 'رفع دليل خاص'));
      await settle();
      expect(evidenceUploaded, isTrue);
      await tester.tap(find.text('عرض المستند الخاص'));
      await settle();
      // pumpAndSettle only drains scheduled frames. Native PDFium opens a
      // document asynchronously and may finish after the route transition.
      // Wait for actual successful load, never accept route presence alone.
      final pdfDeadline = DateTime.now().add(const Duration(seconds: 30));
      while (find
              .byKey(const Key('private-document-ready'))
              .evaluate()
              .isEmpty &&
          DateTime.now().isBefore(pdfDeadline)) {
        expect(
          find.byKey(const Key('private-document-failed')),
          findsNothing,
          reason: 'Native PDFium rejected the synthetic private document.',
        );
        await Future<void>.delayed(const Duration(milliseconds: 100));
        await tester.pump();
      }
      expect(find.byKey(const Key('private-document-ready')), findsOneWidget);
      for (var i = 0; i < 4; i++) {
        await tester.tap(find.byType(BackButton));
        await settle();
      }
      debugPrint(
        'DEVICE: native vendor onboarding and private PDF preview verified',
      );

      expect(
        Directionality.of(tester.element(find.byType(Workspace))),
        TextDirection.rtl,
      );
      expect(
        Theme.of(tester.element(find.byType(Workspace))).brightness,
        Brightness.dark,
      );
      Navigator.of(tester.element(find.byType(Workspace))).push(
        MaterialPageRoute<void>(
          builder: (_) => SubscriptionsView(
            api: secondApi,
            onExpired: () => fail('Unexpected synthetic subscription expiry'),
          ),
        ),
      );
      await settle();
      expect(subscriptionEnrollments, 0);
      expect(find.textContaining('١٢٫٥٠ AED'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('التسجيل بهذه الخطة'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('التسجيل بهذه الخطة'));
      await settle();
      expect(subscriptionEnrollments, 0);
      await tester.scrollUntilVisible(
        find.byType(CheckboxListTile),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byType(CheckboxListTile));
      await settle();
      await tester.scrollUntilVisible(
        find.text('تأكيد النية المحفوظة'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('تأكيد النية المحفوظة'));
      await settle();
      expect(subscriptionEnrollments, 1);
      expect(find.text(subscriptionAr['done']!), findsOneWidget);
      debugPrint(
        'DEVICE: explicit native subscription enrollment verified; synthetic test billing',
      );
      Navigator.of(tester.element(find.byType(SubscriptionActionView))).push(
        MaterialPageRoute<void>(
          builder: (_) => CaseEvidenceView(
            api: secondApi,
            onExpired: () => fail('Unexpected case evidence expiry'),
            caseId: 75,
          ),
        ),
      );
      await settle();
      expect(caseEvidenceUploads, 0);
      await tester.scrollUntilVisible(
        find.text(caseAr['choose']!),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text(caseAr['choose']!));
      await settle();
      expect(caseEvidenceUploads, 0);
      await tester.scrollUntilVisible(
        find.byType(CheckboxListTile),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byType(CheckboxListTile));
      await settle();
      await tester.scrollUntilVisible(
        find.text(caseAr['upload']!),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text(caseAr['upload']!));
      await settle();
      expect(caseEvidenceUploads, 1);
      expect(find.text(caseAr['done']!), findsOneWidget);
      debugPrint(
        'DEVICE: private case evidence consent and multipart upload verified; synthetic internal review',
      );

      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
      await settle();
    },
  );
}
