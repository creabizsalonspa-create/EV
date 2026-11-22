import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/licensed_discovery.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';
import 'licensed_discovery_test.dart' show capabilities;
import 'workspace_test.dart' show signIn;

class NavigationDiscoveryApi extends FixtureApi {
  NavigationDiscoveryApi({super.role});
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'discovery/open-charge-map/capabilities') {
      calls.add('$method $path');
      return capabilities(enabled: false);
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

void main() {
  for (final role in ['customer', 'vendor']) {
    testWidgets(
      '$role reaches licensed discovery through authenticated app navigation',
      (t) async {
        final api = NavigationDiscoveryApi(role: role);
        addTearDown(api.close);
        final prefs = AppPreferences(TestStore());
        await prefs.load();
        await t.pumpWidget(EvApp(preferences: prefs, api: api));
        await t.pumpAndSettle();
        expect(find.text('Licensed charging discovery'), findsNothing);
        await signIn(t);
        await t.tap(find.byType(PopupMenuButton<String>));
        await t.pumpAndSettle();
        await t.tap(find.text('Licensed charging discovery'));
        await t.pumpAndSettle();
        expect(find.byType(LicensedDiscoveryScreen), findsOneWidget);
        expect(
          api.calls.where(
            (v) => v == 'GET discovery/open-charge-map/capabilities',
          ),
          hasLength(1),
        );
        expect(
          api.calls.where((v) => v.startsWith('POST discovery/')),
          isEmpty,
        );
      },
    );
  }
}
