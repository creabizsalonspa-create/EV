import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
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
  Map<String, dynamic> preferences = {
    'default_category': 'all',
    'default_unread_only': false,
    'remind_unresolved_simulator_faults': true,
    'fault_reminder_delay_minutes': 15,
    'in_app_only': true,
    'external_delivery_available': false,
    'critical_events_always_visible': true,
  };
  int preferenceWrites = 0;
  final publicMessages = ['Original public message'];
  String? lastReplyKey;
  String? lastTicketKey;
  final List<Map<String, dynamic>> createdTickets = [];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'notifications/preferences') {
      if (method == 'PUT') {
        preferenceWrites++;
        preferences = {...preferences, ...?body};
      }
      return preferences;
    }
    if (path == 'support' && method == 'POST') {
      lastTicketKey = body?['idempotency_key'] as String?;
      final created = {
        'id': createdTickets.length + 2,
        'subject': body?['subject'],
        'status': 'open',
      };
      createdTickets.add(created);
      return created;
    }
    if (path == 'support/1/messages') {
      lastReplyKey = body?['idempotency_key'] as String?;
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

class LostAckSupportFixture extends SupportFixture {
  int attempts = 0;
  String? firstRequestKey;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'support/1/messages' && method == 'POST') {
      attempts++;
      final key = body?['idempotency_key'] as String?;
      if (attempts == 1) {
        firstRequestKey = key;
        throw const ApiException(503, 'response lost');
      }
      expect(key, firstRequestKey);
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class UnconfirmedReplyFixture extends SupportFixture {
  int attempts = 0;
  String? originalKey;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'support/1/messages' && method == 'POST') {
      attempts++;
      final key = body?['idempotency_key'] as String?;
      if (attempts == 1) {
        originalKey = key;
        return {'accepted': true}; // Not an actual confirmed Laravel reply.
      }
      expect(key, originalKey);
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class LostAckCreateTicketFixture extends SupportFixture {
  int attempts = 0;
  String? firstKey;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'support' && method == 'POST') {
      attempts++;
      final key = body?['idempotency_key'] as String?;
      if (attempts == 1) {
        firstKey = key;
        throw const ApiException(503, 'ticket response lost');
      }
      expect(key, firstKey);
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class BatteryFixture extends FixtureApi {
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async => path == 'notifications/preferences'
      ? {
        'default_category': 'all',
        'default_unread_only': false,
        'remind_unresolved_simulator_faults': true,
        'in_app_only': true,
        'external_delivery_available': false,
        'critical_events_always_visible': true,
      }
      : path == 'notifications/summary'
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
    if (path == 'notifications/preferences') return {
        'default_category': 'all',
        'default_unread_only': false,
        'remind_unresolved_simulator_faults': true,
        'in_app_only': true,
        'external_delivery_available': false,
        'critical_events_always_visible': true,
      };
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
    if (path == 'notifications/preferences') return {
        'default_category': 'all',
        'default_unread_only': false,
        'remind_unresolved_simulator_faults': true,
        'in_app_only': true,
        'external_delivery_available': false,
        'critical_events_always_visible': true,
      };
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

class OperationalInboxFixture extends FixtureApi {
  int caseReads = 0;
  int otherReads = 0;
  int writes = 0;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'notifications/preferences') return {
        'default_category': 'all',
        'default_unread_only': false,
        'remind_unresolved_simulator_faults': true,
        'in_app_only': true,
        'external_delivery_available': false,
        'critical_events_always_visible': true,
      };
    if (method != 'GET') {
      writes++;
      throw const ApiException(405, 'Unexpected write from notification review');
    }
    if (path == 'notifications') {
      return {
        'data': [
          {
            'id': 25,
            'kind': 'charging.operational_case.opened',
            'read_at': null,
            'support_ticket_id': null,
            'metadata': {
              'station_id': 3,
              'command_id': 7,
              'case_id': 8,
              'physical_verification': false,
            },
          },
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    }
    if (path == 'notifications/summary') {
      return {
        'unread_count': 1,
        'latest_id': 25,
        'unread_by_category': {
          'support': 0, 'vehicle': 0, 'charging': 1,
          'fleet': 0, 'other': 0,
        },
        'in_app_only': true,
      };
    }
    if (path == 'charging-operations/7/case') {
      caseReads++;
      return {
        'command': {'id': 7, 'status': 'outcome_unknown'},
        'case': {'id': 8, 'state': 'awaiting_source'},
        'source_observed': false,
        'physical_charging_verified': false,
        'events': {
          'data': <Map<String, dynamic>>[],
          'current_page': 1, 'last_page': 1, 'total': 0,
        },
      };
    }
    otherReads++;
    if (path == 'support') {
      return {
        'data': <Map<String, dynamic>>[],
        'current_page': 1, 'last_page': 1, 'total': 0,
      };
    }
    throw ApiException(404, 'Unexpected request: $path');
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
    if (path == 'notifications/preferences') return {
        'default_category': 'all',
        'default_unread_only': false,
        'remind_unresolved_simulator_faults': true,
        'in_app_only': true,
        'external_delivery_available': false,
        'critical_events_always_visible': true,
      };
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

/// Simulates a revocation during an unresolved private Customer inbox GET.
class ActorScopedSupportFixture extends SupportFixture {
  int actorEpoch = 0, supportReads = 0;
  final Completer<dynamic>? firstSupportResponse;
  ActorScopedSupportFixture({bool deferFirst = false})
      : firstSupportResponse = deferFirst ? Completer<dynamic>() : null;

  @override
  bool Function() sessionFence() {
    final captured = actorEpoch;
    return () => actorEpoch == captured;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'support' && method == 'GET') {
      supportReads++;
      if (supportReads == 1 && firstSupportResponse != null) {
        return firstSupportResponse!.future;
      }
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class ActorScopedTicketFixture extends SupportFixture {
  int actorEpoch = 0, detailReads = 0;
  final Completer<dynamic>? firstDetail;
  ActorScopedTicketFixture({bool deferFirst = false})
      : firstDetail = deferFirst ? Completer<dynamic>() : null;

  @override
  bool Function() sessionFence() {
    final captured = actorEpoch;
    return () => actorEpoch == captured;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'support/1' && method == 'GET') {
      detailReads++;
      if (detailReads == 1 && firstDetail != null) return firstDetail!.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class NumericVisibilityTicketFixture extends SupportFixture {
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    final result = await super.request(path, method: method, body: body, query: query);
    if (path == 'support/1' && method == 'GET') {
      final record = Map<String, dynamic>.from(result as Map);
      final messages = Map<String, dynamic>.from(record['messages'] as Map);
      final rows = (messages['data'] as List).whereType<Map>().map((row) {
        final item = Map<String, dynamic>.from(row);
        return {...item, 'internal': item['internal'] == true ? 1 : 0};
      }).toList();
      return {
        ...record,
        'messages': {...messages, 'data': rows},
      };
    }
    return result;
  }
}

class BatteryNavigationFixture extends ActorScopedSupportFixture {
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'notifications' && method == 'GET') {
      return {
        'data': [
          {
            'id': 3,
            'kind': 'vehicle.battery.simulated',
            'metadata': {
              'source': 'simulator',
              'vehicle_id': 11,
              'battery_percent': 5,
            },
            'read_at': null,
          },
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class MalformedInboxFixture extends SupportFixture {
  bool malformedSupport = true, malformedNotifications = true;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    final result = await super.request(
      path, method: method, body: body, query: query,
    );
    if (method == 'GET' && path == 'support' && malformedSupport) {
      return {
        ...Map<String, dynamic>.from(result as Map),
        'data': [
          {'id': '1', 'subject': 'UNVERIFIED-TICKET', 'status': 'open'},
        ],
      };
    }
    if (method == 'GET' && path == 'notifications' &&
        malformedNotifications) {
      return {
        ...Map<String, dynamic>.from(result as Map),
        'data': [
          {
            'id': 2, 'kind': 'support.reply',
            'support_ticket_id': 'other-tenant',
            'read_at': null,
          },
        ],
      };
    }
    return result;
  }
}


class MalformedSupportPageFixture extends SupportFixture {
  bool wrongPage = false, duplicates = false;
  bool wrongThreadPage = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    final result = await super.request(path,
        method: method, body: body, query: query);
    if (method == 'GET' && path == 'support' && (wrongPage || duplicates)) {
      final data = Map<String,dynamic>.from(result as Map);
      if (wrongPage) return {...data, 'current_page': 2, 'last_page': 2};
      final original = List<Map<String,dynamic>>.from(data['data'] as List);
      return {...data, 'data': [...original, ...original]};
    }
    if (method == 'GET' && path == 'support/1' && wrongThreadPage) {
      final data = Map<String,dynamic>.from(result as Map);
      final messages = Map<String,dynamic>.from(data['messages'] as Map);
      return {...data, 'messages': {...messages,
        'current_page': 2, 'last_page': 2}};
    }
    return result;
  }
}

void main() {

  for (final language in ['en', 'ar']) {
    for (final mode in ['wrong-page', 'duplicates']) {
      testWidgets('malformed support history cannot authorize ticket actions ($language, $mode)', (tester) async {
        final api = MalformedSupportPageFixture()
          ..wrongPage = mode == 'wrong-page'
          ..duplicates = mode == 'duplicates';
        addTearDown(api.close);
        await tester.pumpWidget(MaterialApp(
          locale: Locale(language),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
        ));
        await tester.pumpAndSettle();
        expect(find.text('Synthetic support thread'), findsNothing);
        final open = find.byKey(const Key('support-new-ticket'));
        expect(open, findsOneWidget);
        expect(tester.widget<OutlinedButton>(open).onPressed, isNull);
        api.wrongPage = api.duplicates = false;
        await tester.tap(find.byKey(const Key('support-manual-refresh')));
        await tester.pumpAndSettle();
        expect(find.text('Synthetic support thread'), findsOneWidget);
        expect(tester.widget<OutlinedButton>(open).onPressed, isNotNull);
        expect(tester.takeException(), isNull);
      });
    }
    testWidgets('malformed private ticket thread page cannot expose reply controls ($language)', (tester) async {
      final api = MalformedSupportPageFixture()..wrongThreadPage = true;
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('Original public message'), findsNothing);
      expect(find.byKey(const Key('ticket-reply')), findsNothing);
      api.wrongThreadPage = false;
      await tester.tap(find.text(EvStrings(Locale(language)).t('retry')));
      await tester.pumpAndSettle();
      expect(find.text('Original public message'), findsOneWidget);
      expect(find.byKey(const Key('ticket-reply')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }


  for (final language in ['en', 'ar']) {
    testWidgets('reused support API session changes rebind with no old-account read ($language)', (tester) async {
      final api = ActorScopedSupportFixture();
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
      ));
      await tester.pumpAndSettle();
      expect(find.text('Synthetic support thread'), findsOneWidget);
      expect(api.supportReads, 1);
      final staleOpen = tester.widget<TextButton>(
        find.widgetWithText(TextButton,
          EvStrings(Locale(language)).t('ticket', {'id': 1}))).onPressed;
      api.actorEpoch++;
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
      ));
      await tester.pumpAndSettle();
      expect(api.supportReads, 1, reason: 'Session change must not silently fetch inbox');
      expect(find.text('Synthetic support thread'), findsNothing);
      expect(find.byKey(const Key('support-manual-refresh')), findsOneWidget);
      expect(find.textContaining('PRIVATE OLD ACCOUNT'), findsNothing);
      // An old retained callback cannot navigate into the replacement actor.
      staleOpen?.call();
      await tester.pump();
      expect(api.supportReads, 1);
      await tester.tap(find.byKey(const Key('support-manual-refresh')));
      await tester.pumpAndSettle();
      expect(api.supportReads, 2);
      expect(find.text('Synthetic support thread'), findsOneWidget);
      expect(find.byKey(const Key('support-manual-refresh')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('reused ticket API session rebinds without replaying private thread ($language)', (tester) async {
      final api = ActorScopedTicketFixture();
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('Original public message'), findsOneWidget);
      expect(api.detailReads, 1);
      await tester.enterText(find.byKey(const Key('ticket-reply')), 'PRIVATE OLD ACCOUNT DRAFT');
      api.actorEpoch++;
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(api.detailReads, 1, reason: 'Reusing EvApi must not read on account switch');
      expect(find.text('Original public message'), findsNothing);
      expect(find.text('PRIVATE OLD ACCOUNT DRAFT'), findsNothing);
      expect(find.byKey(const Key('ticket-reply')), findsNothing);
      await tester.tap(find.text(EvStrings(Locale(language)).t('refresh')));
      await tester.pumpAndSettle();
      expect(api.detailReads, 2);
      expect(find.text('Original public message'), findsOneWidget);
      expect(find.byKey(const Key('ticket-reply')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('retained notification discovery tap is fenced to the rendered account and read', (
    tester,
  ) async {
    final api = BatteryNavigationFixture();
    addTearDown(api.close);
    final visited = <int>[];
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SupportView(
          api: api,
          onSessionExpired: () {},
          onBatteryDiscovery: (id) async => visited.add(id),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    final discovery = find.widgetWithText(
      TextButton, 'Find compatible chargers',
    );
    expect(discovery, findsOneWidget);
    final retained = tester.widget<TextButton>(discovery).onPressed!;
    retained();
    expect(visited, [11]);

    // The prior page's callback must not act even for the same account after
    // an explicit re-read changes the page generation.
    final state = tester.state<SupportViewState>(find.byType(SupportView));
    await state.refresh();
    await tester.pumpAndSettle();
    retained();
    expect(visited, [11]);

    // A previously retained callback must never route the old vehicle for a
    // newly authenticated actor, even if it is manually called after rotation.
    final newRender = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Find compatible chargers'),
    ).onPressed!;
    api.actorEpoch++;
    newRender();
    await tester.pump();
    expect(visited, [11]);
    expect(find.text('Find compatible chargers'), findsNothing);
  });

  testWidgets('malformed support IDs and ticket targets fail closed until reread', (
    tester,
  ) async {
    final api = MalformedInboxFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    expect(find.text('UNVERIFIED-TICKET'), findsNothing);
    expect(find.text('Synthetic support thread'), findsNothing);
    expect(find.byKey(const Key('support-new-ticket')), findsOneWidget);
    expect(
      tester.widget<OutlinedButton>(
        find.byKey(const Key('support-new-ticket')),
      ).onPressed,
      isNull,
    );
    api.malformedSupport = false;
    final state = tester.state<SupportViewState>(find.byType(SupportView));
    await state.refresh();
    await tester.pumpAndSettle();
    expect(find.text('Synthetic support thread'), findsOneWidget);
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(find.text('Ticket #other-tenant'), findsNothing);
    expect(find.text('Ticket #1'), findsNothing);
    // A successful preference GET is not enough to authorize edits when the
    // associated private inbox GET failed its ownership/shape checks.
    final preferencesButton = find.byKey(
      const Key('notification-preferences-open'),
    );
    expect(preferencesButton, findsOneWidget);
    expect(tester.widget<TextButton>(preferencesButton).onPressed, isNull);
    final beforeWrites = api.preferenceWrites;
    await state.editNotificationPreferences();
    await state.markNotification(2, read: true);
    await state.markCurrentRead();
    expect(api.preferenceWrites, beforeWrites);
    expect(api.read, isFalse);
    api.malformedNotifications = false;
    await state.refresh();
    await tester.pumpAndSettle();
    expect(find.text('Ticket #1'), findsOneWidget);
    expect(api.read, isFalse);
  });

  test('battery discovery notification navigation rejects untrusted metadata', () {
    final valid = {
      'kind': 'vehicle.battery.simulated',
      'metadata': {
        'source': 'simulator', 'vehicle_id': 3, 'battery_percent': 15,
      },
    };
    expect(batteryDiscoveryNotificationTarget(valid), 3);
    for (final metadata in [
      null,
      {},
      {'source': 'external', 'vehicle_id': 3, 'battery_percent': 15},
      {'source': 'simulator', 'vehicle_id': '3', 'battery_percent': 15},
      {'source': 'simulator', 'vehicle_id': 0, 'battery_percent': 15},
      {'source': 'simulator', 'vehicle_id': 3, 'battery_percent': 101},
      {'source': 'simulator', 'vehicle_id': 3, 'battery_percent': -1},
      {'source': 'simulator', 'vehicle_id': 3, 'battery_percent': '15'},
    ]) {
      expect(
        batteryDiscoveryNotificationTarget({
          'kind': 'vehicle.battery.simulated', 'metadata': metadata,
        }),
        isNull,
      );
    }
    expect(batteryDiscoveryNotificationTarget({
      ...valid, 'kind': 'fleet.invitation',
    }), isNull);
  });

  testWidgets('numeric Laravel internal flags never reveal private messages', (
    tester,
  ) async {
    final api = NumericVisibilityTicketFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Original public message'), findsOneWidget);
    expect(find.text('Hidden internal fixture'), findsNothing);
    expect(find.byKey(const Key('ticket-reply')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('late previous-account ticket detail never crosses the new actor', (
    tester,
  ) async {
    final oldApi = ActorScopedTicketFixture(deferFirst: true);
    final newApi = ActorScopedTicketFixture();
    addTearDown(oldApi.close);
    addTearDown(newApi.close);
    var expired = false;
    await tester.pumpWidget(MaterialApp(
      home: TicketPage(api: oldApi, id: 1, onSessionExpired: () => expired = true),
    ));
    await tester.pump();
    expect(oldApi.detailReads, 1);
    oldApi.actorEpoch++;
    await tester.pumpWidget(MaterialApp(
      home: TicketPage(api: newApi, id: 1, onSessionExpired: () => expired = true),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Original public message'), findsOneWidget);
    oldApi.firstDetail!.complete({
      'ticket': {'id': 1, 'subject': 'PRIVATE-OLD-TICKET', 'status': 'open'},
      'messages': {
        'data': [
          {'body': 'PRIVATE-OLD-MESSAGE', 'internal': false},
        ],
        'current_page': 1, 'last_page': 1, 'total': 1,
      },
    });
    await tester.pumpAndSettle();
    expect(find.text('PRIVATE-OLD-TICKET'), findsNothing);
    expect(find.text('PRIVATE-OLD-MESSAGE'), findsNothing);
    expect(find.text('Original public message'), findsOneWidget);
    expect(expired, isFalse);
  });

  testWidgets('background hides private ticket thread and draft until explicit refresh', (
    tester,
  ) async {
    final api = ActorScopedTicketFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Original public message'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('ticket-reply')), 'PRIVATE DRAFT');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('Original public message'), findsNothing);
    expect(find.text('PRIVATE DRAFT'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(api.detailReads, 1);
    expect(find.byKey(const Key('ticket-reply')), findsNothing);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.detailReads, 2);
    expect(find.text('Original public message'), findsOneWidget);
    expect(
      tester.widget<TextFormField>(find.byKey(const Key('ticket-reply'))).controller!.text,
      isEmpty,
    );
  });

  testWidgets('private support GET cannot outlive a switched account', (
    tester,
  ) async {
    final previous = ActorScopedSupportFixture(deferFirst: true);
    final current = ActorScopedSupportFixture();
    addTearDown(previous.close);
    addTearDown(current.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(
        api: previous, accountId: 10, onSessionExpired: () {},
      )),
    ));
    await tester.pump();
    expect(previous.supportReads, 1);
    previous.actorEpoch++;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(
        api: current, accountId: 20, onSessionExpired: () {},
      )),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Synthetic support thread'), findsOneWidget);

    previous.firstSupportResponse!.complete({
      'data': [
        {'id': 71, 'subject': 'PRIVATE-OLD-TICKET', 'status': 'open'},
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    });
    await tester.pumpAndSettle();
    expect(find.text('PRIVATE-OLD-TICKET'), findsNothing);
    expect(find.text('Synthetic support thread'), findsOneWidget);
    expect(previous.supportReads, 1);
    expect(current.supportReads, 1);
  });

  testWidgets('old 401 response cannot expire a replacement inbox account', (
    tester,
  ) async {
    final previous = ActorScopedSupportFixture(deferFirst: true);
    final replacement = ActorScopedSupportFixture();
    addTearDown(previous.close);
    addTearDown(replacement.close);
    var expired = false;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(
        api: previous, accountId: 1, onSessionExpired: () => expired = true,
      )),
    ));
    await tester.pump();
    previous.actorEpoch++;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(
        api: replacement, accountId: 2,
        onSessionExpired: () => expired = true,
      )),
    ));
    await tester.pumpAndSettle();
    previous.firstSupportResponse!.completeError(
      const ApiException(401, 'PRIVATE OLD ACTOR ERROR'),
    );
    await tester.pumpAndSettle();
    expect(expired, isFalse);
    expect(find.text('Synthetic support thread'), findsOneWidget);
    expect(find.textContaining('PRIVATE OLD ACTOR ERROR'), findsNothing);
  });

  testWidgets('inactive app clears inbox and requires explicit foreground refresh', (
    tester,
  ) async {
    final api = ActorScopedSupportFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Synthetic support thread'), findsOneWidget);
    expect(api.supportReads, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('Synthetic support thread'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(api.supportReads, 1);
    expect(find.text('Synthetic support thread'), findsNothing);
    expect(
      tester.widget<OutlinedButton>(
        find.byKey(const Key('support-new-ticket')),
      ).onPressed,
      isNull,
      reason: 'Unknown old ticket POST outcome requires refreshed history.',
    );
    expect(
      find.text('Refresh this account history before taking another action.'),
      findsOneWidget,
    );
    await tester.state<SupportViewState>(find.byType(SupportView)).refresh();
    await tester.pumpAndSettle();
    expect(api.supportReads, 2);
    expect(find.text('Synthetic support thread'), findsOneWidget);
    expect(
      tester.widget<OutlinedButton>(
        find.byKey(const Key('support-new-ticket')),
      ).onPressed,
      isNotNull,
    );
  });

  testWidgets('revoked actor cannot mark notification read or load history', (
    tester,
  ) async {
    final api = ActorScopedSupportFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    final state = tester.state<SupportViewState>(find.byType(SupportView));
    final before = api.supportReads;
    api.actorEpoch++;
    await state.markNotification(2, read: true);
    await state.markCurrentRead();
    await state.refresh();
    await tester.pump();
    expect(api.read, isFalse);
    expect(api.supportReads, before);
    expect(find.text('Synthetic support thread'), findsNothing);
  });

  testWidgets('persistent notification defaults load across native inbox instances', (
    tester,
  ) async {
    final fixture = SupportFixture();
    addTearDown(fixture.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: fixture, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notification-preferences-open')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notification-preference-category')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Charging').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notification-preference-unread')));
    await tester.tap(find.byKey(const Key('notification-preference-repeat')));
    await tester.tap(find.byKey(const Key('notification-preference-delay')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('After 30 minutes').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('notification-preferences-confirm')));
    await tester.pumpAndSettle();

    expect(fixture.preferenceWrites, 1);
    expect(fixture.preferences['default_category'], 'charging');
    expect(fixture.preferences['default_unread_only'], true);
    expect(fixture.preferences['remind_unresolved_simulator_faults'], false);
    expect(fixture.preferences['fault_reminder_delay_minutes'], 30);
    expect(fixture.selectedCategory, 'charging');

    // Simulate a fresh screen instance using the same backend fixture.
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: fixture, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('notification-preferences-open')), findsOneWidget);
    expect(fixture.selectedCategory, 'charging');
    expect(fixture.preferences['external_delivery_available'], false);
  });

  testWidgets('saved Other notification default remains editable and persists',
      (tester) async {
    final fixture = SupportFixture();
    fixture.preferences['default_category'] = 'other';
    addTearDown(fixture.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: fixture, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-notifications')));
    await tester.pumpAndSettle();
    expect(fixture.selectedCategory, 'other');

    await tester.tap(find.byKey(const Key('notification-preferences-open')));
    await tester.pumpAndSettle();
    final category = tester.widget<DropdownButton<String>>(
      find.byKey(const Key('notification-preference-category')),
    );
    expect(category.value, 'other');
    expect(category.items!.any((item) => item.value == 'other'), isTrue);
    await tester.tap(find.byKey(const Key('notification-preferences-confirm')));
    await tester.pumpAndSettle();
    expect(fixture.preferenceWrites, 1);
    expect(fixture.preferences['default_category'], 'other');
    expect(fixture.selectedCategory, 'other');
  });

  test('unknown operational notices do not impersonate support status', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    final other = {'kind': 'operations.dispatch.review'};
    expect(inboxNotificationTitle(other, en), 'Other operational notification');
    expect(inboxNotificationTitle(other, ar), 'إشعار تشغيلي آخر');
    expect(inboxNotificationTitle({'kind': 314}, en), en.t('notificationOtherTitle'));
    expect(inboxNotificationTitle({'kind': 'support.status.closed'}, en),
        en.t('supportStatus'));
  });

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

  testWidgets(
    'owner recovery notification opens only the authorized read-only case',
    (tester) async {
      final fixture = OperationalInboxFixture();
      addTearDown(fixture.close);
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SupportView(api: fixture, onSessionExpired: () {}),
        ),
      ));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('support-notifications')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('review-operational-case')), findsOneWidget);

      await tester.tap(find.byKey(const Key('review-operational-case')));
      await tester.pumpAndSettle();
      expect(find.text('Recovery notification · command #7'), findsOneWidget);
      expect(find.text('Recorded case state: awaiting_source'), findsOneWidget);
      expect(fixture.caseReads, 1);
      expect(fixture.otherReads, 1); // The initial support page only.
      expect(fixture.writes, 0); // No command, inspection or case mutation.
    },
  );

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
  testWidgets('native customer creates a support ticket with a replay key', (tester) async {
    final api = SupportFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-new-ticket')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('support-new-subject')),
        'Charging issue from mobile');
    await tester.enterText(find.byKey(const Key('support-new-body')),
        'Please investigate the failed charging session');
    await tester.tap(find.byKey(const Key('support-create-confirm')));
    await tester.pumpAndSettle();
    expect(api.lastTicketKey, matches(RegExp(r'^[a-f0-9]{32}$')));
    expect(api.createdTickets.length, 1);
    expect(api.createdTickets.single['subject'], 'Charging issue from mobile');
    expect(tester.takeException(), isNull);
  });

  testWidgets('lost ticket-create acknowledgment reuses the pending key', (tester) async {
    final api = LostAckCreateTicketFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SupportView(api: api, onSessionExpired: () {})),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('support-new-ticket')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('support-new-subject')),
        'Retry-safe native support');
    await tester.enterText(find.byKey(const Key('support-new-body')),
        'Network disconnected after submission');
    await tester.tap(find.byKey(const Key('support-create-confirm')));
    await tester.pumpAndSettle();
    expect(api.attempts, 1);
    expect(api.firstKey, matches(RegExp(r'^[a-f0-9]{32}$')));
    await tester.tap(find.byKey(const Key('support-new-ticket')));
    await tester.pumpAndSettle();
    expect(find.text('Retry-safe native support'), findsWidgets);
    await tester.tap(find.byKey(const Key('support-create-confirm')));
    await tester.pumpAndSettle();
    expect(api.attempts, 2);
    expect(api.createdTickets.length, 1);
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
      expect(api.lastReplyKey, matches(RegExp(r'^[a-f0-9]{32}$')));
      expect(find.text('Customer public follow-up'), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
      api.status = 'closed';
      await tester.pumpWidget(
        MaterialApp(
          home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('This ticket is closed.'),
        180,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('This ticket is closed.'), findsOneWidget);
      expect(find.byKey(const Key('ticket-reply')), findsNothing);
    },
  );
  testWidgets('unconfirmed support reply never clears draft or changes idempotency key', (
    tester,
  ) async {
    final api = UnconfirmedReplyFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('ticket-reply')), 'Customer reply exact intent',
    );
    await tester.ensureVisible(find.text('Send reply'));
    await tester.tap(find.text('Send reply'));
    await tester.pumpAndSettle();
    expect(api.attempts, 1);
    expect(api.originalKey, matches(RegExp(r'^[a-f0-9]{32}$')));
    expect(
      tester.widget<TextFormField>(
        find.byKey(const Key('ticket-reply')),
      ).controller!.text,
      'Customer reply exact intent',
    );
    expect(api.publicMessages.contains('Customer reply exact intent'), isFalse);
    await tester.tap(find.text('Send reply'));
    await tester.pumpAndSettle();
    expect(api.attempts, 2);
    expect(api.lastReplyKey, api.originalKey);
    expect(
      api.publicMessages.where((x) => x == 'Customer reply exact intent').length,
      1,
    );
  });

  testWidgets('lost support reply acknowledgement retries the same key', (tester) async {
    final api = LostAckSupportFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: TicketPage(api: api, id: 1, onSessionExpired: () {}),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('ticket-reply')),
        'One reply after lost acknowledgement');
    await tester.ensureVisible(find.text('Send reply'));
    await tester.tap(find.text('Send reply'));
    await tester.pumpAndSettle();
    expect(api.attempts, 1);
    expect(api.firstRequestKey, matches(RegExp(r'^[a-f0-9]{32}$')));
    await tester.tap(find.text('Send reply'));
    await tester.pumpAndSettle();
    expect(api.attempts, 2);
    expect(api.publicMessages.where((m) =>
        m == 'One reply after lost acknowledgement').length, 1);
  });

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
    await tester.tap(find.byKey(const Key('support-category')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Other').last);
    await tester.pumpAndSettle();
    expect(api.selectedCategory, 'other');
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
    final review = find.byKey(const Key('review-simulator-monitor'));
    await tester.scrollUntilVisible(
      review,
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(review, findsOneWidget);
    // The email settings entry can push the lazy notification card below the
    // viewport. Scroll to build it, then retain the exact navigation assertions.
    await tester.ensureVisible(review);
    await tester.pumpAndSettle();
    await tester.tap(review);
    await tester.pumpAndSettle();
    expect(find.text('Source diagnostics'), findsOneWidget);
    expect(fixture.reviewReads, [
      'vendor-ops/companies/5/chargers/7/source-diagnostics',
    ]);
    expect(fixture.writes, 0);
  });

  test('admin escalation closure distinguishes source evidence from operator acknowledgment', () {
    final en = EvStrings(const Locale('en'));
    for (final source in ['source_recovered', 'operator_acknowledged']) {
      final notice = {
        'kind': 'charging.fault.escalation_closed.simulated',
        'metadata': {
          'source': 'simulator', 'physical_verification': false,
          'company_id': 5, 'charger_id': 7,
          'alert_id': 9, 'station_id': 17,
          'resolution_source': source,
        },
      };
      final title = inboxNotificationTitle(notice, en);
      expect(title, contains('station #17'));
      expect(title, contains(source == 'source_recovered'
          ? 'source fault escalation resolved'
          : 'Operator acknowledged'));
      expect(simulatedFaultTarget(notice), {
        'company_id': 5, 'charger_id': 7, 'alert_id': 9,
      });
    }
  });

  test('explicit on-call Admin escalation stays simulator scoped', () {
    final notice = {
      'kind': 'charging.fault.escalated.simulated',
      'metadata': {
        'source': 'simulator',
        'physical_verification': false,
        'company_id': 5,
        'charger_id': 7,
        'station_id': 17,
        'alert_id': 9,
        'admin_escalation_only': true,
      },
    };
    expect(inboxNotificationTitle(notice, EvStrings(const Locale('en'))),
        contains('on-call Admin'));
    expect(inboxNotificationTitle(notice, EvStrings(const Locale('ar'))),
        contains('المشرف المناوب'));
    expect(simulatedFaultTarget(notice), {
      'company_id': 5, 'charger_id': 7, 'alert_id': 9,
    });
    expect(simulatedFaultTarget({
      ...notice,
      'metadata': {...notice['metadata'] as Map<String, dynamic>,
        'physical_verification': true},
    }), isNull);
  });

  test('optional simulator reminder is visibly distinct but uses the same read-only fault review', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    final notice = {
      'kind': 'charging.fault.reminder.simulated',
      'metadata': {
        'source': 'simulator',
        'physical_verification': false,
        'station_id': 17,
        'company_id': 5,
        'charger_id': 7,
        'alert_id': 9,
        'reminder_only': true,
      },
    };
    expect(inboxNotificationTitle(notice, en),
        'Unacknowledged simulated charger fault reminder · station #17');
    expect(inboxNotificationTitle(notice, ar), contains('المحطة #17'));
    expect(simulatedFaultTarget(notice), {
      'company_id': 5, 'charger_id': 7, 'alert_id': 9,
    });
    expect(simulatedFaultTarget({
      ...notice,
      'metadata': {
        ...notice['metadata'] as Map<String, dynamic>,
        'physical_verification': true,
      },
    }), isNull);
  });

  test('repair review notices navigate only to source-scoped read-only diagnostics', () {
    final en = EvStrings(const Locale('en'));
    final ar = EvStrings(const Locale('ar'));
    for (final kind in [
      'charging.repair.opened.simulated',
      'charging.repair.work_started.simulated',
      'charging.repair.reopened.simulated',
      'charging.repair.review_requested.simulated',
      'charging.repair.review_closed.simulated',
    ]) {
      final notice = {
        'kind': kind,
        'metadata': {
          'source': 'simulator',
          'physical_verification': false,
          'company_id': 5,
          'charger_id': 7,
          'alert_id': 9,
          'station_id': 17,
          'repair_order_id': 22,
          'review_only': true,
        },
      };
      expect(inboxNotificationTitle(notice, en), contains('station #17'));
      expect(inboxNotificationTitle(notice, ar), contains('المحطة #17'));
      expect(simulatedFaultTarget(notice), isNull);
      expect(simulatedRepairTarget(notice), {
        'company_id': 5, 'charger_id': 7,
        'alert_id': 9, 'repair_order_id': 22,
      });
      expect(simulatedRepairTarget(notice)?['repair_order_id'], 22);
      expect(simulatedRepairTarget({
        ...notice,
        'metadata': {
          ...notice['metadata'] as Map<String, dynamic>,
          'physical_verification': true,
        },
      }), isNull);
      expect(simulatedRepairTarget({
        ...notice,
        'metadata': {
          ...notice['metadata'] as Map<String, dynamic>,
          'review_only': false,
        },
      }), isNull);
      expect(simulatedRepairTarget({
        ...notice,
        'metadata': {
          ...notice['metadata'] as Map<String, dynamic>,
          'repair_order_id': -1,
        },
      }), isNull);
    }
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
      int? chosen;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SupportView(
              api: BatteryFixture(),
              onSessionExpired: () {},
              onBatteryDiscovery: (id) async {
                chosen = id;
              },
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('support-notifications')));
      await tester.pumpAndSettle();
      expect(find.text('Simulated low battery · #3 · 15%'), findsOneWidget);
      expect(find.textContaining('Ticket #'), findsNothing);
      await tester.tap(find.text('Find compatible chargers'));
      expect(chosen, 3);
    },
  );
}
