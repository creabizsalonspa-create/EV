import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/payment_recovery.dart';
import 'test_support.dart';
import 'subscriptions_test.dart' show SubscriptionApi;

class RecoveryApi extends FixtureApi {
  bool lost = false, deny = false, invalidPrecision = false;
  int posts = 0;
  final pages = <int>[];
  Completer<dynamic>? pending;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (deny) throw const ApiException(401, 'private secret diagnostic');
    if (method == 'POST') {
      posts++;
      expect(path, 'commercial/payment-recovery/9/reconcile');
      expect(body, isEmpty);
      if (lost) throw const ApiException(0, 'private secret diagnostic');
      return {};
    }
    if (pending != null) return pending!.future;
    final page = int.parse(query['page']!);
    pages.add(page);
    return {
      'data': [
        path.endsWith('events')
            ? {'id': 5, 'status': 'uncertain'}
            : {
                'id': 9,
                'status': 'uncertain',
                'scope': 'subscription',
                'kind': 'capture',
                'provider': 'stripe_test',
                'amount_minor': 999,
                'minor_units': invalidPrecision ? 7 : 2,
                'currency': 'AED',
                'identity': 'private secret diagnostic',
              },
      ],
      'current_page': page,
      'last_page': 2,
      'total': 26,
    };
  }
}

class DunningApi extends RecoveryApi {
  final bool empty;
  DunningApi({this.empty = false});
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'commercial/subscription-notices');
    expect(method, 'GET');
    return {
      'data': empty
          ? []
          : [
              {
                'id': 1,
                'number': 'TEST-SUB-1',
                'stage': 3,
                'attention': 'recovery_required',
                'total_minor': 525,
                'minor_units': invalidPrecision ? 7 : 2,
                'currency': 'AED',
              },
            ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
  }
}

class LinkedNoticeApi extends SubscriptionApi {
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'commercial/subscription-notices') {
      if (method != 'GET')
        throw StateError('Reminder navigation attempted collection');
      return {
        'data': [
          {
            'id': 1,
            'subscription_id': 4,
            'invoice_id': 11,
            'number': 'TEST-NOTICE',
            'stage': 1,
            'attention': 'payment_due',
            'total_minor': 1313,
            'minor_units': 3,
            'currency': 'KWD',
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

Widget app(RecoveryApi api, {VoidCallback? expired, bool ar = false}) =>
    MaterialApp(
      locale: Locale(ar ? 'ar' : 'en'),
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: PaymentRecoveryView(api: api, onExpired: expired ?? () {}),
    );

void main() {
  testWidgets(
    'subscription reminder opens its scoped subscription without collection or mutation',
    (t) async {
      final api = LinkedNoticeApi();
      await t.pumpWidget(
        MaterialApp(
          home: PaymentRecoveryView(
            api: api,
            onExpired: () {},
            subscriptionNotices: true,
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('Private invoice document'), findsOneWidget);
      expect(api.writes, 0);
      await t.tap(find.text('Review subscription'));
      await t.pumpAndSettle();
      expect(find.text('Subscriptions #4'), findsOneWidget);
      expect(find.text('Benefits are not active'), findsOneWidget);
      expect(api.writes, 0);
      expect(api.calls, contains('GET commercial/subscriptions/4'));
    },
  );

  testWidgets(
    'unsupported server precision fails closed without displaying a misleading amount',
    (t) async {
      await t.pumpWidget(app(RecoveryApi()..invalidPrecision = true));
      await t.pumpAndSettle();
      expect(find.textContaining('9.99 AED'), findsNothing);
      expect(find.text('Inspect provider status'), findsNothing);
      expect(t.takeException(), isNull);
    },
  );

  testWidgets(
    'empty subscription notices do not use the payment-recovery empty label',
    (t) async {
      await t.pumpWidget(
        MaterialApp(
          home: PaymentRecoveryView(
            api: DunningApi(empty: true),
            onExpired: () {},
            subscriptionNotices: true,
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('No subscription notices.'), findsOneWidget);
      expect(find.text('No payment recovery operations.'), findsNothing);
    },
  );

  testWidgets(
    'subscription reminders render authoritative recovery hold and offer no collection action',
    (t) async {
      await t.pumpWidget(
        MaterialApp(
          home: PaymentRecoveryView(
            api: DunningApi(),
            onExpired: () {},
            subscriptionNotices: true,
          ),
        ),
      );
      await t.pumpAndSettle();
      expect(find.text('TEST-SUB-1'), findsOneWidget);
      expect(find.textContaining('existing uncertain payment'), findsOneWidget);
      expect(find.text('Inspect provider status'), findsNothing);
      expect(find.byType(FilledButton), findsNothing);
      expect(find.textContaining('5.25 AED'), findsOneWidget);
    },
  );

  test(
    'payment recovery translations have parity',
    () => expect(recoveryEn.keys.toSet(), recoveryAr.keys.toSet()),
  );
  testWidgets('scoped list paginates and history performs no financial write', (
    t,
  ) async {
    final api = RecoveryApi();
    await t.pumpWidget(app(api));
    await t.pumpAndSettle();
    expect(find.textContaining('9.99 AED'), findsOneWidget);
    expect(find.textContaining('private secret diagnostic'), findsNothing);
    await t.tap(find.text('Next'));
    await t.pumpAndSettle();
    expect(api.pages, [1, 2]);
    await t.tap(find.text('Recovery history'));
    await t.pumpAndSettle();
    expect(find.textContaining('#5'), findsOneWidget);
    expect(api.posts, 0);
  });
  testWidgets(
    'status inspection requires confirmation and lost response never replays',
    (t) async {
      final api = RecoveryApi()..lost = true;
      await t.pumpWidget(app(api));
      await t.pumpAndSettle();
      await t.tap(find.text('Inspect provider status'));
      await t.pumpAndSettle();
      expect(api.posts, 0);
      await t.tap(find.text('Cancel'));
      await t.pumpAndSettle();
      expect(api.posts, 0);
      await t.tap(find.text('Inspect provider status'));
      await t.pumpAndSettle();
      await t.tap(find.widgetWithText(FilledButton, 'Inspect provider status'));
      await t.pumpAndSettle();
      await t.pump(const Duration(seconds: 60));
      expect(api.posts, 1);
      expect(find.textContaining('Outcome remains uncertain'), findsOneWidget);
      expect(find.textContaining('private secret diagnostic'), findsNothing);
      expect(find.textContaining('9.99 AED'), findsNothing);
      await t.tap(find.text('Refresh status'));
      await t.pumpAndSettle();
      expect(api.posts, 1);
      expect(find.textContaining('Outcome remains uncertain'), findsNothing);
    },
  );
  testWidgets(
    'expired authentication clears rows and invokes session invalidation',
    (t) async {
      final api = RecoveryApi();
      var expired = 0;
      await t.pumpWidget(app(api, expired: () => expired++));
      await t.pumpAndSettle();
      api.deny = true;
      await t.tap(find.text('Refresh status'));
      await t.pumpAndSettle();
      expect(expired, 1);
      expect(find.textContaining('9.99 AED'), findsNothing);
    },
  );
  testWidgets('Arabic layout is RTL at mobile width without overflow', (
    t,
  ) async {
    await t.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => t.binding.setSurfaceSize(null));
    await t.pumpWidget(app(RecoveryApi(), ar: true));
    await t.pumpAndSettle();
    expect(
      Directionality.of(t.element(find.text(recoveryAr['title']!))),
      TextDirection.rtl,
    );
    expect(find.textContaining(recoveryAr['subscription']!), findsOneWidget);
    expect(t.takeException(), isNull);
  });
  testWidgets('late responses after route disposal are ignored', (t) async {
    final api = RecoveryApi()..pending = Completer();
    await t.pumpWidget(app(api));
    await t.pump();
    await t.pumpWidget(const MaterialApp(home: Text('Signed out')));
    api.pending!.complete({
      'data': [],
      'current_page': 1,
      'last_page': 1,
      'total': 0,
    });
    await t.pumpAndSettle();
    expect(t.takeException(), isNull);
  });
}
