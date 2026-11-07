import 'dart:async';
import 'package:flutter/material.dart';
import 'package:creabiz_ev/api.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/support.dart';
import 'package:creabiz_ev/strings.dart';
import 'test_support.dart';

class SupportFixture extends FixtureApi {
  String status = 'open';
  bool read = false;
  int? lastBulkThroughId;
  bool unreadFilterRequested = false;
  String? selectedCategory;
  final publicMessages = ['Original public message'];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'support/1/messages') {
      publicMessages.add(body!['body'] as String);
      return {'id': 3};
    }
    if (path == 'support/1')
      return {
        'ticket': {
          'id': 1,
          'subject': 'Synthetic support thread',
          'status': status,
        },
        'messages': {
          'data': [
            ...publicMessages.map((m) => {'body': m, 'internal': false}),
            {'body': 'Hidden internal fixture', 'internal': true},
          ],
          'current_page': 1,
          'last_page': 1,
          'total': publicMessages.length,
        },
      };
    if (path == 'notifications') selectedCategory = query['category'];
    if (path == 'notifications' && query['unread_only'] == '1') {
      unreadFilterRequested = true;
      if (read) {
        return {
          'data': <Map<String, dynamic>>[],
          'current_page': 1,
          'last_page': 1,
          'total': 0,
        };
      }
    }
    if (path == 'notifications/summary') {
      return {
        'unread_count': read ? 0 : 1,
        'latest_id': 2,
        'unread_by_category': {
          'support': read ? 0 : 1,
          'vehicle': 0,
          'charging': 0,
          'fleet': 0,
          'other': 0,
        },
        'in_app_only': true,
      };
    }
    if (path == 'notifications/read-through') {
      lastBulkThroughId = body?['through_id'] as int?;
      read = true;
      return {
        'through_id': lastBulkThroughId,
        'marked_read': 1,
        'remaining_unread': 0,
        'in_app_only': true,
      };
    }
    if (path == 'notifications/2/read') {
      read = true;
      return {};
    }
    if (path == 'notifications/2/unread') {
      read = false;
      return {};
    }
    final items = path == 'notifications'
        ? [
            {
              'id': 2,
              'kind': 'support.reply',
              'support_ticket_id': 1,
              'read_at': read ? '2026-10-27' : null,
            },
          ]
        : [
            {'id': 1, 'subject': 'Synthetic support thread', 'status': status},
          ];
    return {
      'data': items,
      'current_page': 1,
      'last_page': 1,
      'total': items.length,
    };
  }
}

class BatteryFixture extends FixtureApi {
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async => path == 'notifications/summary'
      ? {'unread_count': 1, 'latest_id': 9, 'in_app_only': true}
      : {
    'data': path == 'notifications'
        ? [
            {
              'id': 9,
              'kind': 'vehicle.battery.simulated',
              'support_ticket_id': null,
              'read_at': null,
              'metadata': {
                'source': 'simulator',
                'vehicle_id': 3,
                'battery_percent': 15,
              },
            },
          ]
        : [],
    'current_page': 1,
    'last_page': 1,
    'total': 1,
  };
}

class FaultFixture extends FixtureApi {
  final List<String> reviewReads = [];
  int writes = 0;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') writes++;
    if (path == 'vendor-ops/companies/5/chargers/7/source-diagnostics') {
      reviewReads.add(path);
      return {
        'protocol': '1.6', 'events': <Map<String, dynamic>>[],
        'automatic_monitor': {'state': 'caught_up'},
        'source_registered': true, 'source_online': false,
      };
    }
    if (path == 'vendor-ops/companies/5/chargers/7/fault-alerts') {
      reviewReads.add(path);
      return {
        'data': [
          {
            'id': 9, 'evse_number': 1, 'connector_number': 1,
            'status': 'Faulted', 'error_code': 'GroundFailure',
            'occurred_at': '2026-11-07T10:00:00Z',
            'recovery_observed_at': null, 'acknowledged_at': null,
          }
        ],
        'current_page': 1, 'last_page': 1, 'can_manage_faults': false,
      };
    }
    if (path == 'notifications/summary') {
      return {'unread_count': 3, 'latest_id': 24, 'in_app_only': true};
    }
    final items = path == 'notifications'
        ? [
            {
              'id': 22,
              'kind': 'charging.fault.simulated',
              'support_ticket_id': null,
              'read_at': null,
              'metadata': {
                'source': 'simulator',
                'station_id': 17,
                'company_id': 5,
                'charger_id': 7,
                'alert_id': 9,
                'physical_verification': false,
              },
            },
            {
              'id': 23,
              'kind': 'charging.fault.recovered.simulated',
              'support_ticket_id': null,
              'read_at': null,
              'metadata': {
                'source': 'simulator',
                'station_id': 17,
                'company_id': 5,
                'charger_id': 7,
                'alert_id': 9,
                'physical_verification': false,
              },
            },
            {
              'id': 24,
              'kind': 'charging.monitor.requires_review.simulated',
              'support_ticket_id': null,
              'read_at': null,
              'metadata': {
                'source': 'simulator',
                'station_id': 17,
                'company_id': 5,
                'charger_id': 7,
                'physical_verification': false,
              },
            },
          ]
        : <Map<String, dynamic>>[];
    return {
      'data': items,
      'current_page': 1,
      'last_page': 1,
      'total': items.length,
    };
  }
}

class OperationalNoticeFixture extends FixtureApi {
  final List<String> calls = [];
  int writes = 0;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add(path);
    if (method != 'GET') writes++;
    if (path == 'notifications/summary') {
      return {'unread_count': 1, 'latest_id': 55, 'in_app_only': true};
    }
    if (path == 'notifications') {
      return {
        'data': [
          {
            'id': 55,
            'kind': 'charging.operational_case.opened',
            'read_at': null,
            'support_ticket_id': null,
            'metadata': {
              'station_id': 2,
              'command_id': 7,
              'case_id': 8,
              'physical_verification': false,
            },
          },
        ],
        'current_page': 1, 'last_page': 1, 'total': 1,
      };
    }
    if (path == 'charging-operations/dashboard') {
      return {
        'active_session_count': 1,
        'missing_recent_evidence_count': 1,
        'unresolved_case_count': 1,
        'command_counts': {'outcome_unknown': 1},
      };
    }
    if (path == 'charging-operations') {
      return {
        'data': <Map<String, dynamic>>[],
        'current_page': 1, 'last_page': 1, 'total': 0,
      };
    }
    if (path == 'charging-operations/7/case') {
      return {
        'case': {'id': 8, 'state': 'open'},
        'command': {'id': 7, 'status': 'outcome_unknown'},
        'source_observed': false,
        'physical_charging_verified': false,
        'events': {'data': <Map<String, dynamic>>[],
                   'current_page': 1, 'last_page': 1, 'total': 0},
      };
    }
    throw StateError('Unexpected operational notice route: $path');
  }
}

class FleetFixture extends FixtureApi {
  int acceptRequests = 0;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path.startsWith('fleet-invitations/') && method == 'POST') {
      acceptRequests++;
      return {'status': 'active'};
    }
    if (path == 'fleet-invitations') {
      return {
        'data': [
          {'id': 8, 'fleet_id': 31, 'status': 'invited'},
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    }
    if (path == 'notifications/summary') {
      return {'unread_count': 3, 'latest_id': 43, 'in_app_only': true};
    }
    final items = path == 'notifications'
        ? [
            for (final row in [
              ['fleet.invitation', 41],
              ['fleet.invitation.accepted', 42],
              ['fleet.membership.revoked', 43],
            ])
              {
                'id': row[1],
                'kind': row[0],
                'support_ticket_id': null,
                'read_at': null,
                'metadata': {'fleet_id': 31},
              },
          ]
        : <Map<String, dynamic>>[];
    return {
      'data': items,
      'current_page': 1,
      'last_page': 1,
      'total': items.length,
    };
  }
}

class PendingSupport extends FixtureApi {
  final response = Completer<dynamic>();
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) => response.future;
}

void main() {
  test('source-based operator closure never presents itself as physical repair', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    final notice = {
      'kind': 'charging.operational_case.review_resolved',
      'metadata': {
        'station_id': 2, 'command_id': 7, 'case_id': 8,
        'source_observed': true, 'physical_verification': false,
      },
    };
    expect(inboxNotificationTitle(notice, en),
        'Source-based operator review closed · station #2');
    expect(inboxNotificationTitle(notice, ar), contains('المحطة #2'));
    expect(operationalCaseTarget(notice), 7);
    expect(operationalCaseTarget({
      ...notice, 'metadata': {
        ...notice['metadata'] as Map<String, dynamic>,
        'physical_verification': true,
      },
    }), isNull);
  });

  test('operational notice deep-link requires a trustworthy local command reference', () {
    final valid = {
      'kind': 'charging.operational_case.opened',
      'metadata': {
        'command_id': 7,
        'case_id': 8,
        'physical_verification': false,
      },
    };
    expect(operationalCaseTarget(valid), 7);
    expect(operationalCaseTarget({
      ...valid,
      'metadata': {
        ...valid['metadata'] as Map<String, dynamic>,
        'case_id': 0,
      },
    }), isNull);
    expect(operationalCaseTarget({
      ...valid,
      'metadata': {
        ...valid['metadata'] as Map<String, dynamic>,
        'physical_verification': true,
      },
    }), isNull);
    expect(operationalCaseTarget({'kind': 'support.reply'}), isNull);
  });

  testWidgets('operational notice opens exact authorized case without mutation', (t) async {
    final api = OperationalNoticeFixture();
    addTearDown(api.close);
    await t.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const Key('support-notifications')));
    await t.pumpAndSettle();
    expect(find.byKey(const Key('review-operational-case')), findsOneWidget);
    await t.tap(find.byKey(const Key('review-operational-case')));
    await t.pumpAndSettle();
    expect(find.text('Recovery notification · command #7'), findsOneWidget);
    expect(find.text('Recorded case state: open'), findsOneWidget);
    expect(api.calls, contains('charging-operations/7/case'));
    expect(api.writes, 0);
  });

  test('monitor review and source catch-up are distinct from physical repair', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    expect(inboxNotificationTitle({
      'kind': 'charging.monitor.requires_review.simulated',
      'metadata': {'station_id': 17},
    }, en), 'Simulator charger monitor requires review · station #17');
    expect(inboxNotificationTitle({
      'kind': 'charging.monitor.resumed.simulated',
      'metadata': {'station_id': 17},
    }, ar), contains('المحطة #17'));
  });

  test('pending invitation withdrawal is never presented as active-access revocation', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    expect(inboxNotificationTitle({
      'kind': 'fleet.invitation.withdrawn',
      'metadata': {'fleet_id': 8},
    }, en), 'Fleet invitation withdrawn · fleet #8');
    expect(inboxNotificationTitle({
      'kind': 'fleet.invitation.withdrawn',
      'metadata': {'fleet_id': 8},
    }, ar), contains('تم سحب'));
  });

  test('driver assignment and cost-center owner alerts are localized safely', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    expect(inboxNotificationTitle({
      'kind': 'fleet.vehicle.assigned',
      'metadata': {'fleet_id': 31},
    }, en), 'Vehicle assigned to fleet #31');
    expect(inboxNotificationTitle({
      'kind': 'fleet.vehicle.cost_center_changed',
      'metadata': {'fleet_id': 31},
    }, ar), contains('الأسطول #31'));
    expect(inboxNotificationTitle({
      'kind': 'fleet.vehicle.assigned',
      'metadata': null,
    }, en), 'Vehicle assigned to fleet #?');
  });

  test('localized titles are safe for incomplete simulator/fleet metadata', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    expect(
      inboxNotificationTitle({
        'kind': 'vehicle.battery.simulated',
        'metadata': {'vehicle_id': 3, 'battery_percent': 15},
      }, en),
      'Simulated low battery · #3 · 15%',
    );
    expect(
      inboxNotificationTitle({
        'kind': 'charging.fault.recovered.simulated',
        'metadata': null,
      }, en),
      'Simulator source reported recovery · station #?',
    );
    expect(
      inboxNotificationTitle({
        'kind': 'fleet.invitation',
        'metadata': {'fleet_id': 31},
      }, ar),
      contains('الأسطول #31'),
    );
    expect(
      inboxNotificationTitle({
        'kind': 'fleet.membership.revoked',
        'metadata': {'fleet_id': 'not-a-valid-id'},
      }, en),
      'Fleet access revoked · fleet #?',
    );
  });

  testWidgets('disposed support requests cannot expire a later screen', (
    tester,
  ) async {
    final api = PendingSupport();
    addTearDown(api.close);
    var expired = false;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SupportView(api: api, onSessionExpired: () => expired = true),
        ),
      ),
    );
    await tester.pumpWidget(const SizedBox());
    api.response.completeError(const ApiException(401, 'old request'));
    await tester.pumpAndSettle();
    expect(expired, isFalse);
    expect(tester.takeException(), isNull);
  });
  testWidgets(
    'thread loads public messages, sends replies and disables closed-ticket actions',
    (tester) async {
      final api = SupportFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Original public message'), findsOneWidget);
      expect(find.text('Hidden internal fixture'), findsNothing);
      await tester.enterText(
        find.byKey(const Key('ticket-reply')),
        'Customer public follow-up',
      );
      await tester.ensureVisible(find.text('Send reply'));
      await tester.tap(find.text('Send reply'));
      await tester.pumpAndSettle();
      expect(api.publicMessages.last, 'Customer public follow-up');
      expect(find.text('Customer public follow-up'), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
      api.status = 'closed';
      await tester.pumpWidget(
        MaterialApp(
          home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('This ticket is closed.'), findsOneWidget);
      expect(find.byKey(const Key('ticket-reply')), findsNothing);
    },
  );
  testWidgets(
    'inbox read actions update actual API state and notification opens its thread',
    (tester) async {
      final api = SupportFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SupportView(api: api, onSessionExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('support-notifications')));
      await tester.pumpAndSettle();
      expect(find.text('Unread'), findsOneWidget);
      await tester.tap(find.text('Mark as read'));
      await tester.pumpAndSettle();
      expect(api.read, isTrue);
      expect(find.text('Read'), findsOneWidget);
      expect(find.text('Mark as read'), findsNothing);
      await tester.tap(find.text('Ticket #1'));
      await tester.pumpAndSettle();
      expect(find.text('Original public message'), findsOneWidget);
    },
  );
  testWidgets(
    'bulk read uses exact observed server high-water and updates unread UI',
    (tester) async {
      final api = SupportFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SupportView(api: api, onSessionExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('support-notifications')));
      await tester.pumpAndSettle();
      expect(find.text('1 unread notifications'), findsOneWidget);
      expect(find.byKey(const Key('mark-current-notifications-read')), findsOneWidget);
      await tester.tap(find.byKey(const Key('mark-current-notifications-read')));
      await tester.pumpAndSettle();
      expect(api.lastBulkThroughId, 2);
      expect(api.read, true);
      expect(find.text('0 unread notifications'), findsOneWidget);
      expect(find.byKey(const Key('mark-current-notifications-read')), findsNothing);
    },
  );
  testWidgets('native category dropdown sends allowlisted filter', (tester) async {
    final api = SupportFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-category')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Charging').last);
    await tester.pumpAndSettle();
    expect(api.selectedCategory, 'charging');
  });
  testWidgets('category unread totals render from one scoped response', (tester) async {
    final fixture = SupportFixture();
    addTearDown(fixture.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: fixture, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('notification-category-counts')), findsOneWidget);
    expect(find.textContaining('Support 1'), findsOneWidget);
  });
  testWidgets('unread-only native filter requests scoped API rows', (tester) async {
    final fixture = SupportFixture();
    addTearDown(fixture.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: fixture, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-unread-only')));
    await tester.pumpAndSettle();
    expect(fixture.unreadFilterRequested, isTrue);
    expect(find.text('Unread only'), findsOneWidget);
    expect(find.text('Unread'), findsOneWidget);
    await tester.tap(find.byKey(const Key('mark-current-notifications-read')));
    await tester.pumpAndSettle();
    expect(fixture.read, isTrue);
    expect(find.text('0 unread notifications'), findsOneWidget);
  });
  test('simulator monitor navigation rejects forged or incomplete metadata', () {
    final notice = {
      'kind': 'charging.monitor.requires_review.simulated',
      'metadata': {
        'source': 'simulator',
        'physical_verification': false,
        'company_id': 5,
        'charger_id': 7,
      },
    };
    expect(simulatedMonitorTarget(notice), {
      'company_id': 5,
      'charger_id': 7,
    });
    expect(simulatedMonitorTarget({
      ...notice,
      'metadata': {
        ...notice['metadata'] as Map<String, dynamic>,
        'physical_verification': true,
      },
    }), isNull);
    expect(simulatedMonitorTarget({
      ...notice,
      'metadata': {
        ...notice['metadata'] as Map<String, dynamic>,
        'charger_id': -1,
      },
    }), isNull);
    expect(simulatedMonitorTarget({'kind': 'charging.fault.simulated'}), isNull);
  });

  testWidgets('monitor inbox review reads source diagnostics without source-inspect POST', (
    tester,
  ) async {
    final fixture = FaultFixture();
    addTearDown(fixture.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: fixture, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('review-simulator-monitor')), findsOneWidget);
    await tester.tap(find.byKey(const Key('review-simulator-monitor')));
    await tester.pumpAndSettle();
    expect(find.text('Source diagnostics'), findsOneWidget);
    expect(fixture.reviewReads, [
      'vendor-ops/companies/5/chargers/7/source-diagnostics',
    ]);
    expect(fixture.writes, 0);
  });

  test('only verified simulator source metadata can link operational review', () {
    final complete = {
      'kind': 'charging.fault.simulated',
      'metadata': {
        'source': 'simulator',
        'physical_verification': false,
        'company_id': 5,
        'charger_id': 7,
        'alert_id': 9,
      },
    };
    expect(simulatedFaultTarget(complete), {
      'company_id': 5, 'charger_id': 7, 'alert_id': 9,
    });
    expect(simulatedFaultTarget({
      ...complete, 'metadata': {
        ...complete['metadata'] as Map<String, dynamic>,
        'source': 'physical',
      },
    }), isNull);
    expect(simulatedFaultTarget({
      ...complete, 'metadata': {
        ...complete['metadata'] as Map<String, dynamic>,
        'physical_verification': true,
      },
    }), isNull);
    expect(simulatedFaultTarget({
      ...complete, 'metadata': {
        ...complete['metadata'] as Map<String, dynamic>,
        'charger_id': -1,
      },
    }), isNull);
    expect(simulatedFaultTarget({'kind': 'support.reply'}), isNull);
  });

  testWidgets('fault inbox opens authorized diagnostics without sending any command', (
    tester,
  ) async {
    final fixture = FaultFixture();
    addTearDown(fixture.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: fixture, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('review-simulated-fault')), findsNWidgets(2));
    await tester.tap(find.byKey(const Key('review-simulated-fault')).first);
    await tester.pumpAndSettle();
    expect(find.text('Source diagnostics'), findsOneWidget);
    expect(find.text('#9'), findsOneWidget);
    expect(fixture.reviewReads, [
      'vendor-ops/companies/5/chargers/7/source-diagnostics',
      'vendor-ops/companies/5/chargers/7/fault-alerts',
    ]);
    expect(fixture.writes, 0);
  });

  testWidgets(
    'source-observed simulated fault is visible to owner without invented ticket or physical claim',
    (tester) async {
      final api = FaultFixture();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SupportView(api: api, onSessionExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('support-notifications')));
      await tester.pumpAndSettle();
      expect(find.text('Observed simulated charger fault · station #17'), findsOneWidget);
      expect(find.text('Simulator source reported recovery · station #17'), findsOneWidget);
      expect(find.textContaining('Ticket #'), findsNothing);
    },
  );
  testWidgets('fleet inbox review opens live invitations without accepting automatically', (
    tester,
  ) async {
    final api = FleetFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SupportView(
          api: api,
          accountId: 19,
          onSessionExpired: () {},
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('review-fleet-invitation')), findsOneWidget);
    await tester.tap(find.byKey(const Key('review-fleet-invitation')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('fleet-invitations')), findsOneWidget);
    expect(find.text('Accept invitation'), findsOneWidget);
    expect(api.acceptRequests, 0);
    // The notification review action must never accept on the user's behalf.
  });

  testWidgets('fleet lifecycle notices render without a fake support link', (
    tester,
  ) async {
    final api = FleetFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(find.text('Fleet invitation · fleet #31'), findsOneWidget);
    expect(find.text('Fleet invitation accepted · fleet #31'), findsOneWidget);
    expect(find.text('Fleet access revoked · fleet #31'), findsOneWidget);
    expect(find.textContaining('Ticket #'), findsNothing);
  });
  testWidgets('explicit unread reversal restores the same notification', (tester) async {
    final api = SupportFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Mark as read'));
    await tester.pumpAndSettle();
    expect(api.read, true);
    await tester.tap(find.text('Mark as unread'));
    await tester.pumpAndSettle();
    expect(api.read, false);
    expect(find.text('Unread'), findsOneWidget);
  });

  testWidgets(
    'battery notification labels simulation without a support ticket link',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SupportView(api: BatteryFixture(), onSessionExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('support-notifications')));
      await tester.pumpAndSettle();
      expect(find.text('Simulated low battery · #3 · 15%'), findsOneWidget);
      expect(find.textContaining('Ticket #'), findsNothing);
    },
  );
}
