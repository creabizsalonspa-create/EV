import 'dart:async';
import 'package:flutter/material.dart';
import 'package:creabiz_ev/api.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/support.dart';
import 'test_support.dart';

class SupportFixture extends FixtureApi {
  String status = 'open';
  bool read = false;
  int? lastBulkThroughId;
  bool unreadFilterRequested = false;
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
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'notifications/summary') {
      return {'unread_count': 1, 'latest_id': 22, 'in_app_only': true};
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
      expect(find.textContaining('Ticket #'), findsNothing);
    },
  );
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
