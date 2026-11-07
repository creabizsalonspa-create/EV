import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_driver_preferences.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Widget contract fixture; API transport and authorization are tested separately.
class _PreferencesApi extends EvApi {
  _PreferencesApi() : super(Uri.parse('https://example.invalid'));
  final writes = <Map<String, dynamic>>[];
  final requests = <String>[];
  Completer<void>? holdVehicle12;
  int revision = 0;

  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    requests.add('$method $path');
    final id = path == 'vehicles/12/driver-preferences' ? 12
        : path == 'vehicles/13/driver-preferences' ? 13 : null;
    if (id == null) throw ApiException(404, 'Unexpected test endpoint: $path');
    if (method == 'GET') {
      if (id == 12 && holdVehicle12 != null) await holdVehicle12!.future;
      return {
        'vehicle_id': id, 'revision': id == 12 ? revision : 4,
        'preferred_power_kw': id == 12 ? null : 80,
        'charging_goal': id == 12 ? 'balanced' : 'fastest',
        'accessible_site_required': false, 'avoid_paid_parking': false,
        'applied_to_charging': false,
      };
    }
    if (method == 'PUT') {
      writes.add(Map<String, dynamic>.from(body!));
      final expected = id == 12 ? revision : 4;
      if (body['revision'] != expected) throw const ApiException(409, 'stale');
      if (id == 12) revision++;
      return {...body, 'revision': expected + 1, 'vehicle_id': id,
        'applied_to_charging': false};
    }
    throw ApiException(405, 'Unexpected method: $method');
  }
}

void main() {
  testWidgets('customer saves driver preferences with versioned payload',
      (tester) async {
    final api = _PreferencesApi();
    try {
      await tester.pumpWidget(MaterialApp(
        home: VehicleDriverPreferencesView(
          api: api, vehicleId: 12, onExpired: () {},
        ),
      ));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('driver-preferences-power')), '150');
      await tester.ensureVisible(find.byKey(const Key('driver-preferences-access')));
      await tester.tap(find.byKey(const Key('driver-preferences-access')));
      await tester.ensureVisible(find.byKey(const Key('driver-preferences-save')));
      await tester.tap(find.byKey(const Key('driver-preferences-save')));
      await tester.pumpAndSettle();
      expect(api.writes, hasLength(1));
      expect(api.writes.single['revision'], 0);
      expect(api.writes.single['preferred_power_kw'], 150);
      expect(api.writes.single['accessible_site_required'], true);
      expect(find.text('Driver preferences saved.'), findsOneWidget);
    } finally {
      api.close();
    }
  });
  testWidgets('reused native editor reloads a new vehicle and ignores late old GET',
      (tester) async {
    final api = _PreferencesApi()..holdVehicle12 = Completer<void>();
    try {
      await tester.pumpWidget(MaterialApp(
        home: VehicleDriverPreferencesView(api: api, vehicleId: 12, onExpired: () {}),
      ));
      await tester.pump();
      expect(api.requests, contains('GET vehicles/12/driver-preferences'));
      // Same widget type and key; a parent can switch the vehicle ID in place.
      await tester.pumpWidget(MaterialApp(
        home: VehicleDriverPreferencesView(api: api, vehicleId: 13, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(api.requests, contains('GET vehicles/13/driver-preferences'));
      expect(tester.widget<TextFormField>(
        find.byKey(const Key('driver-preferences-power'))).controller!.text, '80');
      api.holdVehicle12!.complete();
      await tester.pumpAndSettle();
      expect(tester.widget<TextFormField>(
        find.byKey(const Key('driver-preferences-power'))).controller!.text, '80');
      await tester.ensureVisible(find.byKey(const Key('driver-preferences-save')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('driver-preferences-save')));
      await tester.pumpAndSettle();
      expect(api.requests.last, 'PUT vehicles/13/driver-preferences');
      expect(api.writes.last['revision'], 4);
      expect(api.writes.last['preferred_power_kw'], 80);
    } finally {
      if (!(api.holdVehicle12?.isCompleted ?? true)) api.holdVehicle12!.complete();
      api.close();
    }
  });

}
