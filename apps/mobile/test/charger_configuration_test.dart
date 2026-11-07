import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_configuration.dart';
import 'test_support.dart';

class SettingsApi extends FixtureApi {
  int attempts = 0, dispatches = 0;
  bool lose = false, observed = true, expired = false, unsafe = false;
  String? firstKey;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (expired) throw const ApiException(401, 'PRIVATE SERVER DETAIL');
    final row = {
      'id': 1,
      'protocol': '1.6',
      'status': observed ? 'source_observed' : 'outcome_unknown',
      'evidence': 'SIMULATED_SOURCE',
      'settings_changed': false,
      'physical_charging_verified': false,
      'snapshot': observed
          ? [
              {'key': 'HeartbeatInterval', 'status': 'observed', 'value': '30'},
              {
                'key': 'AuthorizationKey',
                'status': 'observed',
                'value': 'PRIVATE SOURCE CREDENTIAL',
              },
            ]
          : null,
    };
    if (method == 'GET')
      return {
        'data': [row],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
        'can_request': true,
        'settings_writes_enabled': unsafe,
      };
    expect(path, 'vendor-ops/companies/2/chargers/3/configuration-reads');
    attempts++;
    if (firstKey == null) {
      firstKey = body!['idempotency_key'] as String;
      dispatches++;
    }
    expect(body!['idempotency_key'], firstKey);
    if (lose) {
      lose = false;
      throw const ApiException(503, 'PRIVATE SERVER DETAIL');
    }
    return row;
  }
}

Widget app(SettingsApi api, VoidCallback expired) => MaterialApp(
  home: ChargerConfigurationView(
    api: api,
    companyId: 2,
    chargerId: 3,
    onExpired: expired,
  ),
);
void main() {
  testWidgets(
    'only approved non-secret values render and unsupported capability changes fail closed',
    (tester) async {
      final api = SettingsApi();
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Heartbeat interval (seconds): 30'),
        findsOneWidget,
      );
      expect(find.textContaining('PRIVATE'), findsNothing);
      expect(find.textContaining('AuthorizationKey'), findsNothing);
      api.unsafe = true;
      await tester.tap(find.text('Refresh source history'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Heartbeat interval'), findsNothing);
      expect(find.text('Read device settings'), findsNothing);
      expect(api.attempts, 0);
    },
  );
  testWidgets(
    'lost acknowledgement keeps its original operation identity for explicit resolution',
    (tester) async {
      final api = SettingsApi()..lose = true;
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Read device settings'));
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      expect(api.dispatches, 1);
      expect(find.textContaining('No automatic retry.'), findsOneWidget);
      expect(find.textContaining('PRIVATE'), findsNothing);
      await tester.tap(find.text('Refresh source history'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Resolve read request'));
      await tester.pumpAndSettle();
      expect(api.attempts, 2);
      expect(api.dispatches, 1);
      expect(find.text('Read device settings'), findsOneWidget);
    },
  );
  testWidgets(
    'expired source history clears observations and returns to authentication',
    (tester) async {
      final api = SettingsApi();
      var expired = 0;
      await tester.pumpWidget(app(api, () => expired++));
      await tester.pumpAndSettle();
      api.expired = true;
      await tester.tap(find.text('Refresh source history'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.textContaining('Heartbeat interval'), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
    },
  );
}
