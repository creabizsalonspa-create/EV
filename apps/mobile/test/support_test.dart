import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/support.dart';
import 'test_support.dart';

class SupportFixture extends FixtureApi {
  String status = 'open';
  bool read = false;
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

void main() {
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
}
