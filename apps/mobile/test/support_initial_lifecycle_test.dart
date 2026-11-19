import 'package:creabiz_ev/support.dart';
import 'package:creabiz_ev/strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';

class InitialSupportApi extends FixtureApi {
  int privateReads = 0;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    privateReads++;
    if (path == 'support/1') {
      return {
        'ticket': {
          'id': 1,
          'subject': 'PRIVATE INITIAL TICKET',
          'status': 'open',
        },
        'messages': {
          'data': [
            {'body': 'PRIVATE INITIAL MESSAGE', 'internal': false},
          ],
          'current_page': 1,
          'last_page': 1,
          'total': 1,
        },
      };
    }
    if (path == 'support') {
      return {
        'data': [
          {'id': 1, 'subject': 'PRIVATE INITIAL TICKET', 'status': 'open'},
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      };
    }
    throw StateError('Unexpected private request');
  }
}

void main() {
  for (final language in ['en', 'ar']) {
    for (final thread in [false, true]) {
      testWidgets(
        'first inactive mount gates ${thread ? 'thread' : 'history'} reads $language',
        (tester) async {
          final api = InitialSupportApi();
          addTearDown(api.close);
          addTearDown(
            () => tester.binding.handleAppLifecycleStateChanged(
              AppLifecycleState.resumed,
            ),
          );
          tester.binding.handleAppLifecycleStateChanged(
            AppLifecycleState.inactive,
          );
          final locale = Locale(language);
          await tester.pumpWidget(
            MaterialApp(
              locale: locale,
              supportedLocales: const [Locale('en'), Locale('ar')],
              localizationsDelegates: GlobalMaterialLocalizations.delegates,
              home: thread
                  ? TicketPage(api: api, id: 1, onSessionExpired: () {})
                  : Scaffold(
                      body: SupportView(api: api, onSessionExpired: () {}),
                    ),
            ),
          );
          await tester.pumpAndSettle();
          expect(
            api.privateReads,
            0,
            reason:
                'An already-inactive mount is not consent for private network reads.',
          );
          expect(find.text('PRIVATE INITIAL TICKET'), findsNothing);
          expect(find.text('PRIVATE INITIAL MESSAGE'), findsNothing);
          tester.binding.handleAppLifecycleStateChanged(
            AppLifecycleState.resumed,
          );
          await tester.pumpAndSettle();
          expect(
            api.privateReads,
            0,
            reason:
                'Resume exposes explicit refresh, not an automatic private read.',
          );
          if (thread) {
            await tester.tap(find.text(EvStrings(locale).t('refresh')));
          } else {
            await tester
                .state<SupportViewState>(find.byType(SupportView))
                .refresh();
          }
          await tester.pumpAndSettle();
          expect(api.privateReads, 1);
          expect(find.text('PRIVATE INITIAL TICKET'), findsOneWidget);
          expect(tester.takeException(), isNull);
        },
      );
    }
  }
}
