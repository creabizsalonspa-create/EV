import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_driver_preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Widget contract fixture; API transport and authorization are tested separately.
class _PreferencesApi extends EvApi {
  _PreferencesApi() : super(Uri.parse('https://example.invalid'));
  final writes = <Map<String, dynamic>>[];
  int revision = 0;

  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path != 'vehicles/12/driver-preferences') {
      throw ApiException(404, 'Unexpected test endpoint: $path');
    }
    if (method == 'GET') {
      return {
        'vehicle_id': 12, 'revision': revision, 'preferred_power_kw': null,
        'charging_goal': 'balanced', 'accessible_site_required': false,
        'avoid_paid_parking': false, 'applied_to_charging': false,
      };
    }
    if (method == 'PUT') {
      writes.add(Map<String, dynamic>.from(body!));
      if (body['revision'] != revision) throw const ApiException(409, 'stale');
      revision++;
      return {...body, 'revision': revision, 'vehicle_id': 12,
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
}
