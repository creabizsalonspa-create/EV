import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/sessions.dart';
import 'test_support.dart';

class RecoveryApi extends FixtureApi {
  int writes = 0;
  bool recovered = false, loseAck = false;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'POST') {
      expect(path, 'charging-sessions/7/reconcile');
      writes++;
      if (loseAck) throw const ApiException(0, 'private transport diagnostic');
      recovered = true;
      return {'reconciled': 1, 'network_requests': 0};
    }
    expect(path, 'simulations');
    return {
      'data': [
        {
          'id': 7,
          'reservation_id': 9,
          'status': 'charging',
          'energy_wh': 0,
          'total_minor': null,
          'currency': 'AED',
          'csms_linked': true,
          'start_command_status': recovered
              ? 'source_observed'
              : 'outcome_unknown',
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
    'native reconciliation requires explicit consent and renders source outcome without acceptance claim',
    (t) async {
      final api = RecoveryApi();
      await t.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SessionsView(api: api, role: 'customer', onExpired: () {}),
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(api.writes, 0);
      await t.tap(find.text('Reconcile received evidence'));
      await t.pumpAndSettle();
      expect(find.textContaining('sends no charger command'), findsOneWidget);
      await t.tap(find.text('Confirm'));
      await t.pumpAndSettle();
      expect(api.writes, 1);
      expect(find.textContaining('Source outcome observed'), findsOneWidget);
      expect(
        find.textContaining('physical charging are not verified'),
        findsOneWidget,
      );
      expect(find.text('Reconcile received evidence'), findsNothing);
    },
  );
  testWidgets('reconciliation acknowledgement loss does not repeat writes', (
    t,
  ) async {
    final api = RecoveryApi()..loseAck = true;
    await t.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SessionsView(api: api, role: 'customer', onExpired: () {}),
        ),
      ),
    );
    await t.pumpAndSettle();
    await t.tap(find.text('Reconcile received evidence'));
    await t.pumpAndSettle();
    await t.tap(find.text('Confirm'));
    await t.pumpAndSettle();
    await t.pump(const Duration(seconds: 30));
    expect(api.writes, 1);
    expect(find.textContaining('private transport'), findsNothing);
  });
  testWidgets(
    'Arabic source observation is RTL at phone width without implying acceptance',
    (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.resetPhysicalSize);
      addTearDown(t.view.resetDevicePixelRatio);
      await t.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(
            body: SessionsView(
              api: RecoveryApi()..recovered = true,
              role: 'customer',
              onExpired: () {},
            ),
          ),
        ),
      );
      await t.pumpAndSettle();
      final text = find.text('نقل طلب البدء: رُصدت النتيجة في المصدر');
      expect(text, findsOneWidget);
      expect(Directionality.of(t.element(text)), TextDirection.rtl);
      expect(find.textContaining('لم يُتحقق من قبول الأمر'), findsOneWidget);
      expect(t.takeException(), isNull);
    },
  );
}
