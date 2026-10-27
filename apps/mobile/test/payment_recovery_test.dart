import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/payment_recovery.dart';
import 'test_support.dart';

class RecoveryApi extends FixtureApi {
  bool lost = false, deny = false;
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
      'data': [
        {
          'id': 1,
          'number': 'TEST-SUB-1',
          'stage': 3,
          'attention': 'recovery_required',
          'total_minor': 525,
          'currency': 'AED',
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    };
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
      expect(find.textContaining('525 AED'), findsOneWidget);
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
    expect(find.textContaining('999 AED'), findsOneWidget);
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
      expect(find.textContaining('999 AED'), findsNothing);
      await t.tap(find.text('Refresh status'));
      await t.pumpAndSettle();
      expect(api.posts, 1);
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
      expect(find.textContaining('999 AED'), findsNothing);
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
