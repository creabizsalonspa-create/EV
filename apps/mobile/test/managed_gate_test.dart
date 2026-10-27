import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/catalog.dart';
import 'package:creabiz_ev/sessions.dart';
import 'test_support.dart';

class UndispatchedApi extends FixtureApi {
  bool cancelled = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'DELETE') {
      expect(path, 'reservations/9');
      cancelled = true;
      return {'status': 'cancelled'};
    }
    expect(path, 'simulations');
    expect(method, 'GET');
    return {
      'data': [
        {
          'id': 7,
          'reservation_id': 9,
          'status': cancelled ? 'cancelled' : 'pending_start',
          'energy_wh': 0,
          'total_minor': 0,
          'currency': 'AED',
          'csms_linked': true,
          'start_admission_blocked': !cancelled,
          'can_cancel_before_dispatch': !cancelled,
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

void main() {
  testWidgets(
    'customer can confirm cancellation of a provably undispatched start and refresh its state',
    (tester) async {
      final api = UndispatchedApi();
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SessionsView(api: api, role: 'customer', onExpired: () {}),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Cancel undispatched start'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Cancel undispatched start'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.cancelled, isTrue);
      expect(find.text('Cancel undispatched start'), findsNothing);
      expect(find.textContaining('Cancelled'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'managed blocked simulator shows Arabic policy state without a reserve action',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(
            body: CatalogView(
              customer: true,
              onReserve: (_) async {
                fail('Blocked inventory must not offer reservation.');
              },
              loader: (_, page) async => ApiPage(
                items: [
                  Station.fromJson({
                    'id': 1,
                    'name': 'Synthetic managed site',
                    'connector_type': 'CCS2',
                    'power_kw': 50,
                    'evidence': 'SIMULATED',
                    'reservable': false,
                    'reservation_policy': 'managed_blocked',
                  }),
                ],
                current: 1,
                last: 1,
                total: 1,
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text(
          'الحجوزات وبدء الجلسات الجديدة غير متاحة مؤقتاً لهذه المحطة التجريبية المُدارة.',
        ),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('حجز موعد تجريبي'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'Arabic Material route back navigation does not rely on an English tooltip',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) =>
                        Scaffold(appBar: AppBar(title: const Text('Detail'))),
                  ),
                ),
                child: const Text('Open'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();
      expect(find.byType(BackButton), findsOneWidget);
      await tester.tap(find.byType(BackButton));
      await tester.pumpAndSettle();
      expect(find.text('Open'), findsOneWidget);
    },
  );
}
