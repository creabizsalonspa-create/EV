import 'dart:async';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/discovery_client.dart';
import 'package:creabiz_ev/route_planner.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_map/flutter_map.dart' show FlutterMap;
import 'package:flutter_test/flutter_test.dart';

void main() {
  for (final language in ['en', 'ar']) {
    testWidgets(
      'initially inactive route discards private intent and tile consent $language',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(900, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));
        addTearDown(
          () => tester.binding.handleAppLifecycleStateChanged(
            AppLifecycleState.resumed,
          ),
        );
        var capabilities = 0, routes = 0;
        final client = DiscoveryClient.withRequest((path, method, body) async {
          if (path == 'capabilities') {
            capabilities++;
            return {
              'provider': 'geoapify',
              'routing': true,
              'geocoding': false,
              'max_charging_stops': 3,
            };
          }
          expect(path, 'route');
          routes++;
          return {
            'provider': 'geoapify',
            'evidence': 'PROVIDER_ROUTE_ESTIMATE',
            'distance_meters': 1250.0,
            'duration_seconds': 220.0,
            'traffic_verified': false,
            'charging_time_included': false,
            'segments': [
              [
                {'latitude': 25.2, 'longitude': 55.3},
                {'latitude': 25.3, 'longitude': 55.4},
              ],
            ],
          };
        });
        final stop = Station.fromJson({
          'id': 1,
          'name': 'PRIVATE INITIAL STOP',
          'evidence': 'DISCOVERY_ONLY',
          'connector_type': 'CCS2',
          'power_kw': 50,
          'latitude': 25.25,
          'longitude': 55.35,
        });
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.inactive,
        );
        await tester.pumpWidget(
          MaterialApp(
            locale: Locale(language),
            supportedLocales: const [Locale('en'), Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: RoutePlannerView(
              client: client,
              stations: [stop],
              initialStop: stop,
              origin: GeoPoint(25.2, 55.3),
              tilesEnabled: true,
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(
          capabilities,
          0,
          reason: 'First mount must observe the existing inactive state.',
        );
        expect(find.byKey(const Key('origin-lat')), findsNothing);
        expect(find.byType(FlutterMap), findsNothing);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        await tester.pumpAndSettle();
        expect(
          capabilities,
          0,
          reason:
              'Resume must not silently restore routing intent or provider reads.',
        );
        for (final key in [
          'origin-lat',
          'origin-lng',
          'destination-lat',
          'destination-lng',
        ]) {
          expect(
            tester.widget<TextFormField>(find.byKey(Key(key))).controller!.text,
            isEmpty,
          );
        }
        expect(find.textContaining('1. PRIVATE INITIAL STOP'), findsNothing);
        final check = find.byKey(const Key('route-capability-check'));
        await tester.ensureVisible(check);
        await tester.tap(check);
        await tester.pumpAndSettle();
        expect(capabilities, 1);
        for (final entry in {
          'origin-lat': '25.2',
          'origin-lng': '55.3',
          'destination-lat': '25.3',
          'destination-lng': '55.4',
        }.entries) {
          await tester.ensureVisible(find.byKey(Key(entry.key)));
          await tester.enterText(find.byKey(Key(entry.key)), entry.value);
        }
        // Render the newly edited itinerary before re-authorizing its consent.
        FocusManager.instance.primaryFocus?.unfocus();
        await tester.pumpAndSettle();
        final consent = find.byKey(const Key('route-provider-consent'));
        await tester.ensureVisible(consent);
        await tester.tap(consent);
        await tester.pumpAndSettle();
        final preview = find.byKey(const Key('route-preview'));
        await tester.ensureVisible(preview);
        await tester.tap(preview);
        await tester.pumpAndSettle();
        expect(routes, 1);
        expect(
          find.byType(FlutterMap),
          findsNothing,
          reason: 'The old tile opt-in must never return after resume.',
        );
        expect(
          find.text(
            language == 'ar'
                ? 'عرض الخريطة — يرسل المنطقة وعنوان الإنترنت إلى OpenStreetMap'
                : 'Show map — sends viewed area/IP to OpenStreetMap',
          ),
          findsOneWidget,
        );
        expect(tester.takeException(), isNull);
      },
    );
    testWidgets(
      'routing configuration retry is single-flight and survives itinerary edits $language',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(900, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));
        final pending = Completer<dynamic>();
        var reads = 0;
        final projection = {
          'provider': 'geoapify',
          'routing': true,
          'geocoding': false,
          'max_charging_stops': 3,
        };
        final client = DiscoveryClient.withRequest((path, method, body) async {
          expect(path, 'capabilities');
          expect(
            body,
            isNull,
            reason: 'A configuration check never submits route coordinates.',
          );
          reads++;
          if (reads == 1) throw StateError('PRIVATE CAPABILITY ERROR');
          if (reads == 2) return pending.future;
          return projection;
        });
        await tester.pumpWidget(
          MaterialApp(
            locale: Locale(language),
            supportedLocales: const [Locale('en'), Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: RoutePlannerView(client: client, stations: const []),
          ),
        );
        await tester.pumpAndSettle();
        expect(reads, 1);
        expect(find.textContaining('PRIVATE CAPABILITY ERROR'), findsNothing);
        expect(
          find.textContaining(
            language == 'ar' ? 'لم يتم التحقق' : 'configuration is unverified',
          ),
          findsOneWidget,
        );
        final check = find.byKey(const Key('route-capability-check'));
        await tester.ensureVisible(check);
        final captured = tester.widget<OutlinedButton>(check).onPressed!;
        captured();
        captured();
        await tester.pump();
        expect(
          reads,
          2,
          reason:
              'Repeated captured callbacks cannot duplicate a pending configuration read.',
        );
        expect(tester.widget<OutlinedButton>(check).onPressed, isNull);
        await tester.ensureVisible(find.byKey(const Key('origin-lat')));
        await tester.enterText(find.byKey(const Key('origin-lat')), '25.2');
        pending.complete(projection);
        await tester.pumpAndSettle();
        expect(
          find.byKey(const Key('route-provider-consent')),
          findsNothing,
          reason: 'An obsolete read does not authorize a changed itinerary.',
        );
        await tester.ensureVisible(check);
        expect(
          tester.widget<OutlinedButton>(check).onPressed,
          isNotNull,
          reason: 'Discarding a stale reply must not strand the read spinner.',
        );
        await tester.tap(check);
        await tester.pumpAndSettle();
        expect(reads, 3);
        expect(find.byKey(const Key('route-provider-consent')), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
