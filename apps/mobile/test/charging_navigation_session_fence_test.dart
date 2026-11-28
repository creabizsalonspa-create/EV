import 'dart:async';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/main.dart';
import 'package:creabiz_ev/precharge_pricing.dart';
import 'package:creabiz_ev/preferences.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'test_support.dart';

class DeferredChargingStartApi extends FixtureApi {
  final started = Completer<void>();
  final commandResult = Completer<Map<String, dynamic>>();

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (path == 'reservations' && method == 'GET') {
      return Future.value({
        'data': [
          {
            'id': 3,
            'status': 'confirmed',
            'station_id': 1,
            'starts_at': '2026-11-17T12:00:00Z',
            'csms_bound': false,
          }
        ],
        'current_page': 1,
        'last_page': 1,
        'total': 1,
      });
    }
    if (path == 'reservations/3/simulate-start' && method == 'POST') {
      if (!started.isCompleted) started.complete();
      return commandResult.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

class UncertainPricedStartApi extends DeferredChargingStartApi {
  final expiry = DateTime.now().toUtc().add(const Duration(minutes: 5))
      .toIso8601String().replaceFirst(RegExp(r'\.\d+Z$'), '.000000Z');
  int estimates = 0;
  int consents = 0;
  int starts = 0;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    switch (('$method', path)) {
      case ('GET', 'reservations'):
        final response = await super.request(path, method: method);
        response['data'].first['reservation_terms_snapshot'] = {
          'price_acceptance_required': true,
        };
        return response;
      case ('POST', 'reservations/3/price-estimate'):
        estimates++;
        expect(body, {'energy_wh': 1500});
        if (starts != 0) {
          return {'existing_session_id': 8, 'start_already_recorded': true};
        }
        return {
          'estimate': {
            'version': 1, 'evidence': 'SIMULATED', 'currency': 'KWD',
            'minor_units': 3, 'energy_wh': 1500, 'tariff_version_id': 17,
            'rate_minor_per_kwh': '251', 'tax_bps': 500,
            'subtotal_minor': '377', 'tax_minor': '19', 'total_minor': '396',
            'session_fee_minor': '0', 'idle_fee_minor': '0',
            'external_fees_included': false, 'automatic_stop': false,
            'final_total_depends_on_meter': true, 'expires_at': expiry,
          },
          'quote_token': 'synthetic-quote',
        };
      case ('POST', 'reservations/3/price-consent'):
        consents++;
        expect(body, {'quote_token': 'synthetic-quote', 'accepted': true});
        return {
          'id': 7,
          'accepted_at': DateTime.now().toUtc().toIso8601String(),
          'valid_until': expiry,
        };
      case ('POST', 'reservations/3/simulate-start'):
        starts++;
        throw const ApiException(503, 'Start outcome unknown');
      default:
        return super.request(path, method: method, body: body, query: query);
    }
  }
}

Future<void> loginFixture(WidgetTester tester) async {
  await tester.enterText(
      find.byKey(const Key('auth-email')), 'synthetic@example.test');
  await tester.enterText(
      find.byKey(const Key('auth-password')), 'synthetic-password');
  await tester.tap(find.byKey(const Key('auth-submit')));
  await tester.pumpAndSettle();
}

void main() {
  for (final arabic in [false, true]) {
    testWidgets('priced start with a lost response never repeats consent or start ($arabic)',
        (tester) async {
      final api = UncertainPricedStartApi();
      addTearDown(api.close);
      final prefs = AppPreferences(TestStore());
      await prefs.load();
      if (arabic) await prefs.update(locale: const Locale('ar'));
      await tester.pumpWidget(EvApp(preferences: prefs, api: api));
      await tester.pumpAndSettle();
      await loginFixture(tester);
      await tester.tap(find.byIcon(Icons.event_outlined));
      await tester.pumpAndSettle();
      final start = find.text(arabic ? 'بدء المحاكاة' : 'Start simulator');
      final accept = find.widgetWithText(FilledButton, arabic ? 'قبول السعر' : 'Accept price');

      Future<void> review() async {
        await tester.ensureVisible(start);
        await tester.tap(start);
        // The workspace intentionally remains busy while awaiting the modal.
        // Its indeterminate progress indicator never settles until dismissal.
        // Advance the route animation and fixture futures without waiting for
        // all background animations to stop.
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 300));
        final dialog = find.byType(PrechargePricingDialog);
        expect(dialog, findsOneWidget);
        await tester.enterText(find.descendant(of: dialog, matching: find.byType(TextField)), '1500');
        await tester.tap(find.text(arabic ? 'عرض التقدير' : 'Get estimate'));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 300));
      }

      await review();
      expect(find.textContaining('KWD 0.396'), findsOneWidget);
      await tester.tap(accept);
      await tester.pumpAndSettle();
      expect([api.estimates, api.consents, api.starts], [1, 1, 1]);
      await review();
      expect(find.textContaining(arabic ? 'تم تسجيل جلسة بالفعل' : 'A session is already recorded'), findsOneWidget);
      expect(tester.widget<FilledButton>(accept).onPressed, isNull);
      expect([api.estimates, api.consents, api.starts], [2, 1, 1]);
      // Dismiss the review before settling the underlying workspace.
      await tester.tap(find.text(arabic ? 'إلغاء' : 'Cancel'));
      await tester.pumpAndSettle();
      expect(find.byType(PrechargePricingDialog), findsNothing);
      expect([api.estimates, api.consents, api.starts], [2, 1, 1]);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('delayed simulated start cannot switch a new login to sessions',
      (tester) async {
    final api = DeferredChargingStartApi();
    addTearDown(api.close);
    final prefs = AppPreferences(TestStore());
    await prefs.load();
    await tester.pumpWidget(EvApp(preferences: prefs, api: api));
    await tester.pumpAndSettle();
    await loginFixture(tester);
    final dynamic workspace = tester.state(find.byType(Workspace));

    await tester.tap(find.byIcon(Icons.event_outlined));
    await tester.pumpAndSettle();
    expect(workspace.tab, 1);
    await tester.ensureVisible(find.text('Start simulator'));
    await tester.tap(find.text('Start simulator'));
    await tester.pump();
    await api.started.future;
    expect(workspace.busy, isTrue);

    workspace.expiredSession();
    await tester.pumpAndSettle();
    await loginFixture(tester);
    expect(workspace.account?.role, 'customer');
    expect(workspace.tab, 0);

    api.commandResult.complete({'status': 'started'});
    await tester.pumpAndSettle();
    expect(workspace.tab, 0);
    expect(workspace.message, isNull);
    expect(tester.takeException(), isNull);
  });
}
