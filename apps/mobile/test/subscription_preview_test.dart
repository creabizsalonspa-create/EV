import 'dart:async';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/subscription_preview.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'test_support.dart';

Map<String, dynamic> quote() => {
  'subscription_id': 1,
  'current_plan_id': 10,
  'proposed_plan_id': 20,
  'proposed_plan_version': 2,
  'currency': 'AED',
  'minor_units': 2,
  'interval': 'month',
  'current_plan_interval': 'month',
  'effective_at_utc': '2026-12-19T03:00:00+00:00',
  'next_cycle_subtotal_minor': 2999,
  'next_cycle_tax_minor': 150,
  'next_cycle_total_minor': 3149,
  'current_plan_list_total_minor': 2099,
  'cycles_comparable': true,
  'nominal_plan_list_difference_minor': 1050,
  'comparison_is_proration': false,
  'amount_due_now_minor': 0,
  'proration_supported': false,
  'next_cycle_invoice_issued': false,
  'quote_is_binding': false,
  'evidence': 'SIMULATED_NEXT_CYCLE_PREVIEW',
  'real_payment_processed': false,
};

class PreviewApi extends FixtureApi {
  int epoch = 0, reads = 0, writes = 0;
  bool fail = false;
  Map<String, dynamic>? value;
  Completer<dynamic>? pending;
  @override
  bool Function() sessionFence() {
    final captured = epoch;
    return () => captured == epoch;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (method != 'GET') writes++;
    expect(path, 'commercial/subscriptions/1/change-preview');
    expect(method, 'GET');
    expect(body, isNull);
    expect(query, {'plan_id': '20'});
    reads++;
    if (pending != null) return pending!.future;
    if (fail) throw const ApiException(503, 'PRIVATE PREVIEW ERROR');
    return value ?? quote();
  }
}

Widget panel(PreviewApi api, String lang, {VoidCallback? expired}) =>
    MaterialApp(
      locale: Locale(lang),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Scaffold(
        body: SingleChildScrollView(
          child: SubscriptionPreviewPanel(
            api: api,
            subscriptionId: 1,
            planId: 20,
            onExpired: expired ?? () {},
          ),
        ),
      ),
    );
void main() {
  test(
    'server monetary values are immutable and unknown private fields are discarded',
    () {
      final raw = quote()..['private_note'] = 'PRIVATE EXTRA';
      final parsed = SubscriptionChangePreview.parse(
        raw,
        subscriptionId: 1,
        planId: 20,
      );
      raw['next_cycle_total_minor'] = 999;
      expect(parsed.values['next_cycle_total_minor'], 3149);
      expect(parsed.values.containsKey('private_note'), isFalse);
      expect(() => parsed.values['currency'] = 'USD', throwsUnsupportedError);
    },
  );
  for (final bad in [
    {'subscription_id': 2},
    {'subscription_id': 1.0},
    {'proposed_plan_id': 20.0},
    {'proposed_plan_id': 21},
    {'proposed_plan_version': 0},
    {'minor_units': 4},
    {'minor_units': 2.0},
    {'currency': 'aed'},
    {'next_cycle_total_minor': 3149.0},
    {'next_cycle_tax_minor': -1},
    {'next_cycle_total_minor': 9007199254740992},
    {'nominal_plan_list_difference_minor': -9223372036854775808},
    {'amount_due_now_minor': 1},
    {'real_payment_processed': true},
    {'quote_is_binding': true},
    {'comparison_is_proration': true},
    {'proration_supported': true},
    {'next_cycle_invoice_issued': true},
    {'evidence': 'LIVE_CHARGE'},
    {'effective_at_utc': '2026-02-31T03:00:00Z'},
    {'effective_at_utc': '2026-12-19T03:00:00'},
    {'effective_at_utc': '2026-12-19T03:00:00+04:00'},
    {'cycles_comparable': false},
    {'interval': 'week'},
  ]) {
    test(
      'rejects unverified preview fields ${bad.keys.first} ${bad.values.first}',
      () {
        expect(
          () => SubscriptionChangePreview.parse(
            {...quote(), ...bad},
            subscriptionId: 1,
            planId: 20,
          ),
          throwsFormatException,
        );
      },
    );
  }
  test('mixed cadence is disclosed without a made-up price difference', () {
    final raw = quote()
      ..['interval'] = 'year'
      ..['cycles_comparable'] = false
      ..['nominal_plan_list_difference_minor'] = null;
    expect(
      SubscriptionChangePreview.parse(
        raw,
        subscriptionId: 1,
        planId: 20,
      ).values['cycles_comparable'],
      isFalse,
    );
    raw['nominal_plan_list_difference_minor'] = 0;
    expect(
      () => SubscriptionChangePreview.parse(raw, subscriptionId: 1, planId: 20),
      throwsFormatException,
    );
  });
  for (final lang in ['en', 'ar']) {
    testWidgets('explicit native server quote is private GET-only $lang', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(390, 844));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final api = PreviewApi();
      addTearDown(api.close);
      await tester.pumpWidget(panel(api, lang));
      await tester.pumpAndSettle();
      expect(api.reads, 0);
      await tester.tap(find.byKey(const Key('subscription-preview-read')));
      await tester.pumpAndSettle();
      expect(api.reads, 1);
      expect(api.writes, 0);
      expect(
        find.textContaining(lang == 'ar' ? '٣١٫٤٩ AED' : '31.49 AED'),
        findsOneWidget,
      );
      expect(
        find.textContaining(
          lang == 'ar' ? 'المبلغ المستحق الآن' : 'Amount due now',
        ),
        findsOneWidget,
      );
      expect(
        Directionality.of(
          tester.element(find.byType(SubscriptionPreviewPanel)),
        ),
        lang == 'ar' ? TextDirection.rtl : TextDirection.ltr,
      );
      expect(tester.takeException(), isNull);
    });
    testWidgets(
      'background erases quote and fences captured read callbacks $lang',
      (tester) async {
        final api = PreviewApi();
        addTearDown(api.close);
        await tester.pumpWidget(panel(api, lang));
        await tester.pumpAndSettle();
        final callback = tester
            .widget<OutlinedButton>(
              find.byKey(const Key('subscription-preview-read')),
            )
            .onPressed!;
        callback();
        await tester.pumpAndSettle();
        expect(api.reads, 1);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.inactive,
        );
        await tester.pump();
        expect(find.textContaining('31.49'), findsNothing);
        expect(
          find.byKey(const Key('subscription-preview-read')),
          findsNothing,
        );
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        await tester.pumpAndSettle();
        callback();
        await tester.pumpAndSettle();
        expect(api.reads, 1);
        expect(
          find.textContaining(lang == 'ar' ? '٣١٫٤٩ AED' : '31.49 AED'),
          findsNothing,
        );
        await tester.tap(find.byKey(const Key('subscription-preview-read')));
        await tester.pumpAndSettle();
        expect(api.reads, 2);
        expect(api.writes, 0);
      },
    );
    testWidgets(
      'late prior-account success or401 cannot render or expire the new actor $lang',
      (tester) async {
        for (final revoked in [false, true]) {
          final api = PreviewApi()..pending = Completer<dynamic>();
          addTearDown(api.close);
          var expired = 0;
          await tester.pumpWidget(panel(api, lang, expired: () => expired++));
          await tester.pumpAndSettle();
          await tester.tap(find.byKey(const Key('subscription-preview-read')));
          await tester.pump();
          expect(api.reads, 1);
          api.epoch++;
          if (revoked) {
            api.pending!.completeError(
              const ApiException(401, 'PRIVATE OLD401'),
            );
          } else {
            api.pending!.complete(quote());
          }
          await tester.pumpAndSettle();
          expect(expired, 0);
          expect(find.textContaining('31.49'), findsNothing);
          expect(
            find.byKey(const Key('subscription-preview-read')),
            findsNothing,
          );
          expect(find.textContaining('PRIVATE'), findsNothing);
          await tester.pumpWidget(const SizedBox());
        }
      },
    );
  }
  testWidgets(
    'failed preview read clears prior terms and requires explicit retry',
    (tester) async {
      final api = PreviewApi();
      addTearDown(api.close);
      await tester.pumpWidget(panel(api, 'en'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('subscription-preview-read')));
      await tester.pumpAndSettle();
      api.fail = true;
      await tester.tap(find.byKey(const Key('subscription-preview-read')));
      await tester.pumpAndSettle();
      expect(find.textContaining('31.49'), findsNothing);
      expect(find.textContaining('PRIVATE PREVIEW ERROR'), findsNothing);
      expect(api.reads, 2);
      api.fail = false;
      await tester.tap(find.byKey(const Key('subscription-preview-read')));
      await tester.pumpAndSettle();
      expect(api.reads, 3);
      expect(api.writes, 0);
    },
  );
  for (final entry in [('JPY', 0, '3149 JPY'), ('KWD', 3, '3.149 KWD')]) {
    testWidgets('preview preserves server monetary precision ${entry.$1}', (
      tester,
    ) async {
      final api = PreviewApi()
        ..value = {...quote(), 'currency': entry.$1, 'minor_units': entry.$2};
      addTearDown(api.close);
      await tester.pumpWidget(panel(api, 'en'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('subscription-preview-read')));
      await tester.pumpAndSettle();
      expect(find.textContaining(entry.$3), findsOneWidget);
      expect(api.writes, 0);
    });
  }
  for (final lang in ['en', 'ar']) {
    testWidgets(
      'initially inactive subscription preview waits for an explicit foreground read $lang',
      (tester) async {
        final api = PreviewApi();
        addTearDown(api.close);
        addTearDown(
          () => tester.binding.handleAppLifecycleStateChanged(
            AppLifecycleState.resumed,
          ),
        );
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.inactive,
        );
        await tester.pumpWidget(panel(api, lang));
        await tester.pumpAndSettle();
        expect(
          find.byKey(const Key('subscription-preview-read')),
          findsNothing,
        );
        expect(api.reads, 0);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        await tester.pumpAndSettle();
        expect(api.reads, 0);
        await tester.tap(find.byKey(const Key('subscription-preview-read')));
        await tester.pumpAndSettle();
        expect(api.reads, 1);
        expect(api.writes, 0);
      },
    );
  }
  testWidgets(
    'a changed plan discards a held quote without fetching or scheduling it',
    (tester) async {
      final api = PreviewApi()..pending = Completer<dynamic>();
      addTearDown(api.close);
      await tester.pumpWidget(panel(api, 'en'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('subscription-preview-read')));
      await tester.pump();
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SubscriptionPreviewPanel(
              api: api,
              subscriptionId: 1,
              planId: 21,
              onExpired: () {},
            ),
          ),
        ),
      );
      api.pending!.complete(quote());
      await tester.pumpAndSettle();
      expect(find.textContaining('31.49'), findsNothing);
      expect(api.reads, 1);
      expect(api.writes, 0);
      expect(
        tester
            .widget<OutlinedButton>(
              find.byKey(const Key('subscription-preview-read')),
            )
            .onPressed,
        isNotNull,
      );
    },
  );
}
