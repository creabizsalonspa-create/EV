import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/fleet.dart';
import 'package:creabiz_ev/sessions.dart';
import 'test_support.dart';

class FleetFixture extends FixtureApi {
  bool centerConflict = false,
      invited = true,
      revoked = false,
      meterConflict = false;
  bool lostMembership = false, linked = false, sourceStopping = false;
  int energy = 1000;
  bool stopped = false;
  final centers = <Map<String, dynamic>>[];
  final assignments = <Map<String, dynamic>>[];
  final mutations = <String>[];
  Map<String, dynamic>? lastAssignment;
  dynamic page(
    List<dynamic> data, {
    int current = 1,
    int last = 1,
    int? total,
  }) => {
    'data': data,
    'current_page': current,
    'last_page': last,
    'total': total ?? data.length,
  };
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') mutations.add('$method $path');
    if (path == 'regions')
      return [
        {'country': 'AE'},
      ];
    if (path == 'fleets')
      return page([
        {'id': 1, 'name': 'Fixture fleet', 'owner_id': 1},
      ]);
    if (path == 'fleet-invitations')
      return page(
        invited
            ? [
                {'id': 11, 'fleet_id': 1},
              ]
            : [],
      );
    if (path == 'fleet-invitations/11/accept') {
      invited = false;
      return {'status': 'active'};
    }
    if (path == 'fleets/1/invitations')
      return {'message': 'Invitation recorded if eligible.'};
    if (path == 'fleets/1/cost-centers') {
      if (centerConflict) throw const ApiException(409, 'duplicate');
      final center = {'id': 10, ...body!};
      centers.add(center);
      return center;
    }
    if (path == 'fleets/1/vehicles') {
      lastAssignment = body;
      assignments.add(body!);
      return {'id': 1, ...body};
    }
    if (path == 'fleets/1/members/11') {
      revoked = true;
      return null;
    }
    if (path == 'fleets/1' && lostMembership)
      throw const ApiException(404, 'not found');
    if (path == 'fleets/1')
      return {
        'fleet': {'id': 1, 'name': 'Fixture fleet', 'owner_id': 1},
        'members': [
          {
            'id': 11,
            'user_id': 2,
            'role': 'driver',
            'status': revoked ? 'revoked' : 'invited',
          },
        ],
        'cost_centers': centers,
        'vehicles': assignments,
      };
    if (path == 'vehicles') {
      final second = query['page'] == '2';
      return page(
        [
          {
            'id': second ? 26 : 1,
            'name': second ? 'Own EV second page' : 'Own EV first page',
            'connector_type': 'CCS2',
          },
        ],
        current: second ? 2 : 1,
        last: 2,
        total: 26,
      );
    }
    if (path == 'simulations')
      return page([
        {
          'id': 7,
          'reservation_id': 3,
          'status': stopped
              ? 'stopped'
              : sourceStopping
              ? 'stopping'
              : 'charging',
          'csms_linked': linked,
          'energy_wh': energy,
          'total_minor': stopped ? 200 : null,
          'currency': 'AED',
        },
      ]);
    if (path == 'simulations/7/meter') {
      if (meterConflict) throw const ApiException(409, 'concurrent update');
      energy = body!['energy_wh'] as int;
      return {'energy_wh': energy};
    }
    if (path == 'charging-sessions/7/csms-stop') {
      sourceStopping = true;
      return {'status': 'stopping'};
    }
    if (path == 'simulations/7/stop') {
      stopped = true;
      return {'status': 'stopped'};
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

void main() {
  testWidgets(
    'CSMS-linked vendor hides manual meters and queues source stop instead of freezing energy',
    (tester) async {
      final api = FleetFixture()..linked = true;
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SessionsView(api: api, role: 'vendor', onExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('CSMS SIMULATION'), findsOneWidget);
      expect(find.text('Submit simulated meter'), findsNothing);
      await tester.tap(find.text('Request CSMS stop'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.mutations, contains('POST charging-sessions/7/csms-stop'));
      expect(api.mutations, isNot(contains('POST simulations/7/stop')));
      expect(find.textContaining('Waiting for source stop'), findsOneWidget);
    },
  );
  testWidgets(
    'revoked access clears previously loaded fleet details on refresh',
    (tester) async {
      final api = FleetFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: FleetPage(api: api, accountId: 1, id: 1, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Fixture fleet'), findsOneWidget);
      api.lostMembership = true;
      await tester.drag(find.byType(ListView).first, const Offset(0, 500));
      await tester.pumpAndSettle();
      expect(find.text('Fixture fleet'), findsNothing);
      expect(find.text('Add cost center'), findsNothing);
      expect(find.text('Retry'), findsOneWidget);
    },
  );
  testWidgets(
    'owner creates cost center, invites privately, assigns own second-page vehicle and revokes membership',
    (tester) async {
      final api = FleetFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: FleetPage(api: api, accountId: 1, id: 1, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Add cost center'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('code')), 'OPS');
      await tester.enterText(find.byKey(const ValueKey('name')), 'Operations');
      api.centerConflict = true;
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('action-error')), findsOneWidget);
      expect(
        tester
            .widget<TextFormField>(find.byKey(const ValueKey('code')))
            .controller!
            .text,
        'OPS',
      );
      api.centerConflict = false;
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(find.text('Operations'), findsOneWidget);
      await tester.tap(find.text('Invite an existing account'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('email')),
        'driver@example.test',
      );
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(
        find.text(
          'Invitation recorded if the account is eligible. Acceptance is required.',
        ),
        findsOneWidget,
      );
      await tester.ensureVisible(find.text('Assign your vehicle'));
      await tester.tap(find.text('Assign your vehicle'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Own EV second page'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('OPS · Operations').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(api.lastAssignment, {'vehicle_id': 26, 'cost_center_id': 10});
      await tester.ensureVisible(find.text('Revoke membership'));
      await tester.tap(find.text('Revoke membership'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.revoked, isTrue);
      expect(find.text('Revoke membership'), findsNothing);
    },
  );
  testWidgets(
    'driver has no owner controls and explicitly accepts an invitation',
    (tester) async {
      final api = FleetFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: FleetPage(api: api, accountId: 2, id: 1, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Invite an existing account'), findsNothing);
      expect(find.text('Add cost center'), findsNothing);
      expect(find.text('Revoke membership'), findsNothing);
      expect(find.text('Account #2'), findsNothing);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: FleetView(api: api, accountId: 2, onExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('fleet-invitations')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Accept invitation'));
      await tester.pumpAndSettle();
      expect(
        find.text(
          'Join this fleet? Its owner can see vehicles you assign and their cost centers.',
        ),
        findsOneWidget,
      );
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.invited, isFalse);
      expect(find.text('Fixture fleet'), findsOneWidget);
    },
  );
  testWidgets(
    'vendor meter validates monotonic readings, retains failed input, submits and stops; customers cannot edit meters',
    (tester) async {
      final api = FleetFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SessionsView(api: api, role: 'vendor', onExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Submit simulated meter'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('energy_wh')), '500');
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(api.mutations, isEmpty);
      await tester.enterText(find.byKey(const ValueKey('energy_wh')), '2000');
      api.meterConflict = true;
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('action-error')), findsOneWidget);
      expect(
        tester
            .widget<TextFormField>(find.byKey(const ValueKey('energy_wh')))
            .controller!
            .text,
        '2000',
      );
      api.meterConflict = false;
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      expect(api.energy, 2000);
      await tester.tap(find.text('Stop simulation'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.stopped, isTrue);
      expect(find.text('Submit simulated meter'), findsNothing);
      api.stopped = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SessionsView(
              key: const Key('customer'),
              api: api,
              role: 'customer',
              onExpired: () {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Submit simulated meter'), findsNothing);
      expect(find.text('Stop simulation'), findsOneWidget);
    },
  );
}
