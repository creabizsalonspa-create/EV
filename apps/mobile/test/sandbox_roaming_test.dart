import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/sandbox_roaming.dart';
import 'test_support.dart';

class RoamingApi extends FixtureApi {
  bool lost = false,
      unsafe = false,
      denied = false,
      revoked = false,
      active = false;
  int attempts = 0;
  Map<String, dynamic>? first;
  Map<String, dynamic> order() => {
    'id': 1,
    'location_name': 'Private source site',
    'evse_uid': 'E1',
    'connector_id': '1',
    'state': active ? 'source_active' : 'start_accepted',
    'token_state': 'registered',
    'commands': <dynamic>[],
    'evidence': 'SANDBOX_PARTNER_UNVERIFIED',
    'billing_eligible': unsafe,
    'live_commands_enabled': false,
    'local_invoice_created': false,
    'physical_charging_verified': false,
    'can_stop': false,
    'start_consent_current': !revoked,
    'can_revoke': true,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (denied) throw const ApiException(401, 'PRIVATE account error');
    if (method != 'GET') {
      attempts++;
      first ??= Map<String, dynamic>.from(body!);
      expect(body, first);
      if (lost) {
        lost = false;
        throw const ApiException(503, 'PRIVATE transport');
      }
      return order();
    }
    if (path.endsWith('sandbox-offers')) {
      return {
        'evidence': 'SANDBOX_PARTNER_UNVERIFIED',
        'billing_eligible': unsafe,
        'live_commands_enabled': false,
        'provider_name': 'Approved test CPO',
        'location_name': 'Test location',
        'offers': [
          {
            'evse_uid': 'E1',
            'connector_id': '1',
            'tariff_id': 'T1',
            'offer_hash': 'a' * 64,
            'terms_version': 'sandbox-roaming-v1',
            'tariff': {'currency': 'EUR', 'price': '0.25'},
          },
        ],
      };
    }
    return {
      'data': [order()],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

Widget app(RoamingApi api, {bool operator = false, VoidCallback? expired}) =>
    MaterialApp(
      home: SandboxRoamingView(
        api: api,
        locationId: operator ? null : 2,
        providerId: operator ? 3 : null,
        onExpired: expired ?? () {},
      ),
    );
void main() {
  testWidgets(
    'credential revocation does not label an already active source session as unstarted',
    (tester) async {
      final api = RoamingApi()
        ..revoked = true
        ..active = true;
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Review sandbox history'));
      await tester.tap(find.text('Review sandbox history'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Account or partner authorization changed.'),
        findsNothing,
      );
      expect(find.textContaining('source_active'), findsOneWidget);
      expect(api.attempts, 0);
      await tester.pumpWidget(const SizedBox());
    },
  );

  testWidgets(
    'revoked start authorization requires explicit token recovery without another command',
    (tester) async {
      final api = RoamingApi()..revoked = true;
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Review sandbox history'));
      await tester.tap(find.text('Review sandbox history'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Account or partner authorization changed.'),
        findsOneWidget,
      );
      expect(find.text('Request sandbox stop'), findsNothing);
      expect(api.attempts, 0);
      await tester.pumpWidget(const SizedBox());
    },
  );

  testWidgets(
    'consent and lost response keep original request; reads do not redispatch',
    (tester) async {
      final api = RoamingApi()..lost = true;
      await tester.pumpWidget(app(api));
      await tester.pumpAndSettle();
      final start = find.widgetWithText(FilledButton, 'Start sandbox roaming');
      expect(tester.widget<FilledButton>(start).onPressed, isNull);
      await tester.ensureVisible(find.byType(CheckboxListTile));
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pumpAndSettle();
      await tester.ensureVisible(start);
      await tester.tap(start);
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      expect(api.first!['consent'], true);
      final key = api.first!['request_key'];
      expect(find.textContaining('PRIVATE'), findsNothing);
      await tester.ensureVisible(find.text('Review sandbox history'));
      await tester.tap(find.text('Review sandbox history'));
      await tester.pumpAndSettle();
      expect(api.attempts, 1);
      expect(find.textContaining('Private source site'), findsOneWidget);
      expect(find.text('Request sandbox stop'), findsNothing);
      await tester.ensureVisible(find.text('Retry same sandbox request'));
      await tester.tap(find.text('Retry same sandbox request'));
      await tester.pumpAndSettle();
      expect(api.attempts, 2);
      expect(api.first!['request_key'], key);
      expect(find.text('Retry same sandbox request'), findsNothing);
    },
  );
  testWidgets(
    'unexpected billing capability and expired identity hide private controls',
    (tester) async {
      final api = RoamingApi()..unsafe = true;
      var expired = 0;
      await tester.pumpWidget(app(api, expired: () => expired++));
      await tester.pumpAndSettle();
      expect(find.text('Start sandbox roaming'), findsNothing);
      api.unsafe = false;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Start sandbox roaming'), findsOneWidget);
      api.denied = true;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('Start sandbox roaming'), findsNothing);
      expect(find.textContaining('PRIVATE'), findsNothing);
      expect(api.attempts, 0);
    },
  );
  testWidgets(
    'operator cannot issue commands; background clears private rows',
    (tester) async {
      final api = RoamingApi();
      await tester.pumpWidget(app(api, operator: true));
      await tester.pumpAndSettle();
      expect(find.textContaining('Private source site'), findsOneWidget);
      expect(find.text('Revoke sandbox authority'), findsNothing);
      expect(find.text('Request sandbox stop'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.textContaining('Private source site'), findsNothing);
      expect(api.attempts, 0);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.textContaining('Private source site'), findsOneWidget);
      expect(api.attempts, 0);
    },
  );
}
