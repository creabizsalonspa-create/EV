import 'dart:convert';
import 'dart:io';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_driver_preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('customer saves driver preferences via actual HTTP transport', (tester) async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final writes = <Map<String, dynamic>>[];
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path != '/api/vehicles/12/driver-preferences') {
        request.response.statusCode = 404;
        request.response.write('{}');
      } else if (request.method == 'GET') {
        request.response.write(jsonEncode({
          'vehicle_id': 12, 'revision': 0, 'preferred_power_kw': null,
          'charging_goal': 'balanced', 'accessible_site_required': false,
          'avoid_paid_parking': false, 'applied_to_charging': false,
        }));
      } else if (request.method == 'PUT') {
        final input = jsonDecode(await utf8.decoder.bind(request).join()) as Map<String, dynamic>;
        writes.add(input);
        request.response.write(jsonEncode({...input, 'revision': 1, 'vehicle_id': 12}));
      }
      await request.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:' + server.port.toString()));
    try {
      await tester.pumpWidget(MaterialApp(home:
        VehicleDriverPreferencesView(api: api, vehicleId: 12, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('driver-preferences-power')), '150');
      await tester.ensureVisible(find.byKey(const Key('driver-preferences-access')));
      await tester.tap(find.byKey(const Key('driver-preferences-access')));
      await tester.ensureVisible(find.byKey(const Key('driver-preferences-save')));
      await tester.tap(find.byKey(const Key('driver-preferences-save')));
      await tester.pumpAndSettle();
      expect(writes, hasLength(1));
      expect(writes.single['revision'], 0);
      expect(writes.single['preferred_power_kw'], 150);
      expect(writes.single['accessible_site_required'], true);
      expect(find.text('Driver preferences saved.'), findsOneWidget);
    } finally {
      api.close();
      await subscription.cancel();
      await server.close(force: true);
    }
  });
}
