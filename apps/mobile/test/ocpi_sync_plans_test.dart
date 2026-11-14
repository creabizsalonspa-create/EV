import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/ocpi_sync_plans.dart';
import 'test_support.dart';

class PlanApi extends FixtureApi {
  bool active = true, enabled = false, loseReply = false;
  final writes = <Map<String, dynamic>>[];
  @override
  bool Function() sessionFence() =>
      () => active;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method == 'PUT') {
      expect(path, 'ocpi/providers/7/sync-plans/locations');
      writes.add(Map<String, dynamic>.from(body!));
      enabled = body['enabled'] == true;
      if (loseReply) throw const ApiException(503, 'PRIVATE PARTNER ERROR');
    }
    return {
      'configured': true,
      'read_only_polling': true,
      'remote_control': false,
      'billing_authority': false,
      'intervals': [15, 60],
      'plans': [
        {
          'module': 'locations',
          'enabled': enabled,
          'consent_current': enabled,
          'revision': '9007199254740993',
          'interval_minutes': 60,
          'running': false,
          'negotiated': true,
        },
      ],
      'history': {
        'data': [
          {
            'module': 'locations',
            'state': 'completed',
            'started_at': 'PRIVATE ACCOUNT RECEIPT',
            'counts': '{"created":1}',
          },
        ],
        'current_page': 1,
        'last_page': 1,
      },
    };
  }
}

Widget screen(PlanApi api, {bool ar = false}) => MaterialApp(
  locale: Locale(ar ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: OcpiSyncPlansView(api: api, providerId: 7, onExpired: () {}),
);
void main() {
  for (final ar in [false, true]) {
    testWidgets(
      'partner polling requires explicit module consent and exact revision ($ar)',
      (tester) async {
        final api = PlanApi();
        await tester.pumpWidget(screen(api, ar: ar));
        await tester.pumpAndSettle();
        expect(
          tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
          isNull,
        );
        await tester.ensureVisible(find.byType(CheckboxListTile));
        await tester.tap(find.byType(CheckboxListTile));
        await tester.pump();
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await tester.pumpAndSettle();
        expect(api.writes, [
          {
            'enabled': true,
            'confirmed': true,
            'interval_minutes': 60,
            'expected_revision': '9007199254740993',
          },
        ]);
      },
    );
  }
  testWidgets('unknown response requires explicit read and never resends', (
    tester,
  ) async {
    final api = PlanApi()..loseReply = true;
    await tester.pumpWidget(screen(api));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byType(CheckboxListTile));
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pump();
    await tester.ensureVisible(find.byType(FilledButton));
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();
    expect(api.writes.length, 1);
    expect(find.byType(FilledButton), findsNothing);
    expect(find.textContaining('PRIVATE PARTNER ERROR'), findsNothing);
    await tester.ensureVisible(find.text('Refresh'));
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.writes.length, 1);
    expect(find.text('Current consent'), findsOneWidget);
  });
  testWidgets(
    'background clears private receipts and retained actions cannot cross accounts',
    (tester) async {
      final api = PlanApi();
      await tester.pumpWidget(screen(api));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byType(CheckboxListTile));
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      final action = tester
          .widget<FilledButton>(find.byType(FilledButton))
          .onPressed!;
      api.active = false;
      action();
      await tester.pumpAndSettle();
      expect(api.writes, isEmpty);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.textContaining('PRIVATE ACCOUNT RECEIPT'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
    },
  );
}
