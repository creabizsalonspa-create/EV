import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/privacy_requests.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

/// Widget API contract fixture. HTTP transport and ownership are validated by
/// api_test.dart and PrivacyRequestTest.php respectively.
class _PrivacyApi extends EvApi {
  _PrivacyApi() : super(Uri.parse('https://example.invalid'));
  final rows = <Map<String, dynamic>>[];
  final received = <Map<String, dynamic>>[];
  final withdrawals = <String>[];
  Completer<void>? holdGet;
  Completer<void>? holdPost;
  Completer<void>? holdWithdraw;
  int privateReads = 0;
  bool failRead = false;
  bool malformedRow = false;
  bool duplicateIds = false;
  bool invalidPage = false;
  bool failReadbackOnce = false;
  bool failPostAfterHold = false;

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'account/privacy-requests' && method == 'GET') {
      privateReads++;
      if (failRead) {
        throw const ApiException(503, 'PRIVATE_INTERNAL_FAILURE_CONTEXT');
      }
      if (holdGet != null) await holdGet!.future;
      if (rows.isNotEmpty && failReadbackOnce) {
        failReadbackOnce = false;
        throw const ApiException(503, 'readback unavailable');
      }
      return {
        'data': malformedRow
            ? <Map<String, dynamic>>[
                {'id': 'not-an-integer', 'kind': 'access',
                  'status': 'pending', 'details': 'PRIVATE MALFORMED ITEM'},
              ]
            : duplicateIds && rows.isNotEmpty
                ? [rows.first, rows.first]
                : rows,
        'current_page': invalidPage ? 2 : 1,
        'last_page': invalidPage ? 2 : 1,
        'total': invalidPage ? 26 : duplicateIds ? 2 : rows.length,
      };
    }
    if (path == 'account/privacy-requests' && method == 'POST') {
      if (holdPost != null) await holdPost!.future;
      if (failPostAfterHold) {
        throw const ApiException(401, 'Prior account request was rejected');
      }
      final payload = Map<String, dynamic>.from(body!);
      received.add(payload);
      final prior = rows
          .where((r) => r['idempotency_key'] == payload['idempotency_key'])
          .toList();
      if (prior.isNotEmpty) return prior.first;
      final row = <String, dynamic>{
        'id': rows.length + 1,
        'status': 'pending',
        'resolution': null,
        'decision_note': null,
        'kind': payload['kind'],
        'details': payload['details'],
        'idempotency_key': payload['idempotency_key'],
      };
      rows.add(row);
      return row;
    }
    if (path.endsWith('/withdraw') && method == 'POST') {
      withdrawals.add(path);
      if (holdWithdraw != null) await holdWithdraw!.future;
      throw const ApiException(401, 'Prior account withdrawal was rejected');
    }
    throw ApiException(404, 'Unexpected test endpoint: $method $path');
  }
}

void main() {
  for (final language in ['en', 'ar']) {
    testWidgets('privacy request progress is announced in $language', (tester) async {
      final api = _PrivacyApi()..holdGet = Completer<void>();
      addTearDown(api.close);
      final semantics = tester.ensureSemantics();
      addTearDown(semantics.dispose);
      final ar = language == 'ar';
      final loading = ar ? 'جارٍ تحميل طلبات الخصوصية' : 'Loading privacy requests';
      final updating = ar ? 'جارٍ تحديث طلبات الخصوصية' : 'Updating privacy requests';
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: PrivacyRequestsView(api: api, onExpired: () {}),
      ));
      await tester.pump();
      expect(find.bySemanticsLabel(loading), findsOneWidget);
      api.holdGet!.complete();
      await tester.pumpAndSettle();
      expect(find.bySemanticsLabel(loading), findsNothing);

      api.holdPost = Completer<void>();
      final confirm = find.byKey(const Key('privacy-confirm'));
      await tester.ensureVisible(confirm);
      await tester.tap(confirm);
      final submit = find.byKey(const Key('privacy-submit'));
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pump();
      tester.state<ScrollableState>(find.byType(Scrollable).first)
          .position.jumpTo(0);
      await tester.pump();
      expect(find.bySemanticsLabel(updating), findsOneWidget);
      api.holdPost!.complete();
      await tester.pumpAndSettle();
      expect(find.bySemanticsLabel(updating), findsNothing);
    });
  }


  for (final language in ['en', 'ar']) {
    for (final failureMode in ['duplicate', 'wrong-page']) {
      testWidgets('private pagination verification fails closed ($language, $failureMode)',
          (tester) async {
        final api = _PrivacyApi();
        addTearDown(api.close);
        api.rows.add({
          'id': 44,
          'kind': 'access',
          'status': 'pending',
          'details': 'PRIVATE UNVERIFIED ROW',
        });
        api.duplicateIds = failureMode == 'duplicate';
        api.invalidPage = failureMode == 'wrong-page';
        await tester.pumpWidget(MaterialApp(
          locale: Locale(language),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
          home: PrivacyRequestsView(api: api, onExpired: () {}),
        ));
        await tester.pumpAndSettle();
        expect(find.text('PRIVATE UNVERIFIED ROW'), findsNothing);
        expect(find.text(language == 'ar' ? 'سحب الطلب' : 'Withdraw request'),
            findsNothing);
        expect(find.byKey(const Key('privacy-error')), findsOneWidget);
        api.duplicateIds = api.invalidPage = false;
        final refresh = find.byTooltip(language == 'ar' ? 'تحديث' : 'Refresh');
        await tester.ensureVisible(refresh);
        await tester.tap(refresh);
        await tester.pumpAndSettle();
        expect(find.text('PRIVATE UNVERIFIED ROW'), findsOneWidget);
        expect(find.text(language == 'ar' ? 'سحب الطلب' : 'Withdraw request'),
            findsOneWidget);
        expect(find.byKey(const Key('privacy-error')), findsNothing);
        expect(tester.takeException(), isNull);
      });
    }
  }

  for (final language in ['en', 'ar']) {
    testWidgets('a failed private history refresh removes old actions and safely recovers ($language)',
        (tester) async {
      final api = _PrivacyApi();
      addTearDown(api.close);
      api.rows.add({
        'id': 66, 'kind': 'access', 'status': 'pending',
        'details': 'PRIVATE RETAINED REQUEST REASON',
      });
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: PrivacyRequestsView(api: api, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('PRIVATE RETAINED REQUEST REASON'), findsOneWidget);
      final withdraw = find.text(language == 'ar' ? 'سحب الطلب' : 'Withdraw request');
      expect(withdraw, findsOneWidget);
      api.failRead = true;
      final refresh = find.byTooltip(language == 'ar' ? 'تحديث' : 'Refresh');
      await tester.ensureVisible(refresh);
      await tester.tap(refresh);
      await tester.pumpAndSettle();
      expect(find.text('PRIVATE RETAINED REQUEST REASON'), findsNothing);
      expect(withdraw, findsNothing);
      expect(find.byKey(const Key('privacy-error')), findsOneWidget);
      api.failRead = false;
      await tester.tap(refresh);
      await tester.pumpAndSettle();
      expect(find.text('PRIVATE RETAINED REQUEST REASON'), findsOneWidget);
      expect(withdraw, findsOneWidget);
      expect(tester.takeException(), isNull);
    });
    testWidgets('malformed customer request rows cannot create a withdrawal action ($language)',
        (tester) async {
      final api = _PrivacyApi()..malformedRow = true;
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: PrivacyRequestsView(api: api, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('PRIVATE MALFORMED ITEM'), findsNothing);
      expect(find.text(language == 'ar' ? 'سحب الطلب' : 'Withdraw request'), findsNothing);
      expect(find.byKey(const Key('privacy-error')), findsOneWidget);
      api.malformedRow = false;
      final refresh = find.byTooltip(language == 'ar' ? 'تحديث' : 'Refresh');
      await tester.ensureVisible(refresh);
      await tester.tap(refresh);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('privacy-error')), findsNothing);
      expect(api.privateReads, 2);
      expect(tester.takeException(), isNull);
    });
  }

  for (final language in ['en', 'ar']) {
    testWidgets('private request failures never expose transport diagnostics ($language)',
        (tester) async {
      final api = _PrivacyApi()..failRead = true;
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: PrivacyRequestsView(api: api, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.textContaining('PRIVATE_INTERNAL_FAILURE_CONTEXT'), findsNothing);
      expect(find.textContaining(language == 'ar' ? 'تعذّر التحقق' : 'Unable to verify'),
          findsOneWidget);
      api.failRead = false;
      final refresh = find.byTooltip(language == 'ar' ? 'تحديث' : 'Refresh');
      await tester.ensureVisible(refresh);
      await tester.tap(refresh);
      await tester.pumpAndSettle();
      expect(find.textContaining(language == 'ar' ? 'تعذّر التحقق' : 'Unable to verify'),
          findsNothing);
      expect(find.byKey(const Key('privacy-reason')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  for (final language in ['en', 'ar']) {
    testWidgets('first inactive privacy mount requires foreground refresh ($language)',
        (tester) async {
      final api = _PrivacyApi();
      addTearDown(api.close);
      addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed));
      api.rows.add({
        'id': 19,
        'kind': 'access',
        'status': 'pending',
        'details': 'PRIVATE INITIAL ACCOUNT REQUEST',
      });
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(language),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: PrivacyRequestsView(api: api, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(api.privateReads, 0);
      expect(find.text('PRIVATE INITIAL ACCOUNT REQUEST'), findsNothing);
      expect(find.byKey(const Key('privacy-reason')), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(api.privateReads, 0, reason: 'Resume must not silently read account requests');
      expect(find.byKey(const Key('privacy-reason')), findsOneWidget);
      final refresh = find.byTooltip(language == 'ar' ? 'تحديث' : 'Refresh');
      await tester.ensureVisible(refresh);
      await tester.tap(refresh);
      await tester.pumpAndSettle();
      expect(api.privateReads, 1);
      expect(find.text('PRIVATE INITIAL ACCOUNT REQUEST'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('native privacy request submits and stays usable afterwards', (
    tester,
  ) async {
    final api = _PrivacyApi();
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyRequestsView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('No privacy requests yet.'), findsOneWidget);
      await tester.tap(find.byKey(const Key('privacy-confirm')));
      await tester.ensureVisible(find.byKey(const Key('privacy-submit')));
      await tester.tap(find.byKey(const Key('privacy-submit')));
      await tester.pumpAndSettle();
      expect(api.received, hasLength(1));
      expect(api.received.single['kind'], 'access');
      expect(
        (api.received.single['idempotency_key'] as String).length,
        greaterThanOrEqualTo(12),
      );
      expect(find.text('Request recorded for review.'), findsOneWidget);
      expect(find.text('Submitted'), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(find.byKey(const Key('privacy-submit')))
            .onPressed,
        isNotNull,
      );
    } finally {
      api.close();
    }
  });
  testWidgets(
    'post-commit privacy readback failure retains original request identity',
    (tester) async {
      final api = _PrivacyApi()..failReadbackOnce = true;
      try {
        await tester.pumpWidget(
          MaterialApp(
            home: PrivacyRequestsView(api: api, onExpired: () {}),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const Key('privacy-confirm')));
        await tester.ensureVisible(find.byKey(const Key('privacy-submit')));
        await tester.tap(find.byKey(const Key('privacy-submit')));
        await tester.pumpAndSettle();
        expect(api.rows, hasLength(1));
        expect(api.received, hasLength(1));
        expect(find.byKey(const Key('privacy-error')), findsOneWidget);
        expect(find.text('Request recorded for review.'), findsNothing);
        await tester.tap(find.byKey(const Key('privacy-submit')));
        await tester.pumpAndSettle();
        expect(api.received, hasLength(2));
        expect(
          api.rows,
          hasLength(1),
          reason: 'Retry must not duplicate the request',
        );
        expect(
          api.received[1]['idempotency_key'],
          api.received[0]['idempotency_key'],
        );
        expect(find.text('Request recorded for review.'), findsOneWidget);
      } finally {
        api.close();
      }
    },
  );

  testWidgets('reused privacy view cannot retain previous account reasons', (
    tester,
  ) async {
    final first = _PrivacyApi()..holdGet = Completer<void>();
    final second = _PrivacyApi();
    second.rows.add({
      'id': 44,
      'status': 'pending',
      'kind': 'access',
      'details': 'Second account reason',
      'resolution': null,
      'decision_note': null,
    });
    first.rows.add({
      'id': 12,
      'status': 'pending',
      'kind': 'access',
      'details': 'First account private reason',
      'resolution': null,
      'decision_note': null,
    });
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyRequestsView(api: first, onExpired: () {}),
        ),
      );
      await tester.pump();
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyRequestsView(api: second, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Second account reason'), findsOneWidget);
      expect(find.text('First account private reason'), findsNothing);
      first.holdGet!.complete();
      await tester.pumpAndSettle();
      expect(find.text('Second account reason'), findsOneWidget);
      expect(find.text('First account private reason'), findsNothing);
    } finally {
      if (!(first.holdGet?.isCompleted ?? true)) first.holdGet!.complete();
      first.close();
      second.close();
    }
  });

  testWidgets('late privacy submission denial cannot expire a newer session', (
    tester,
  ) async {
    final api = _PrivacyApi()
      ..holdPost = Completer<void>()
      ..failPostAfterHold = true;
    var expired = 0;
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyRequestsView(api: api, onExpired: () => expired++),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('privacy-confirm')));
      await tester.ensureVisible(find.byKey(const Key('privacy-submit')));
      await tester.tap(find.byKey(const Key('privacy-submit')));
      await tester.pump();
      // An explicit sign-out/new sign-in invalidates the request's session.
      // A delayed 401 from the old operation must not log out the next account.
      await api.forgetSession();
      api.holdPost!.complete();
      await tester.pumpAndSettle();
      expect(expired, 0);
      expect(find.byKey(const Key('privacy-error')), findsNothing);
    } finally {
      if (!(api.holdPost?.isCompleted ?? true)) api.holdPost!.complete();
      api.close();
    }
  });

  testWidgets('late privacy withdrawal denial cannot expire a newer session', (
    tester,
  ) async {
    final api = _PrivacyApi()..holdWithdraw = Completer<void>();
    api.rows.add({
      'id': 33,
      'status': 'pending',
      'kind': 'access',
      'details': 'Old account private request',
      'resolution': null,
      'decision_note': null,
    });
    var expired = 0;
    try {
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyRequestsView(api: api, onExpired: () => expired++),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Withdraw request'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Withdraw request'));
      await tester.pump();
      expect(api.withdrawals, hasLength(1));
      await api.forgetSession();
      api.holdWithdraw!.complete();
      await tester.pumpAndSettle();
      expect(expired, 0);
      expect(find.byKey(const Key('privacy-error')), findsNothing);
    } finally {
      if (!(api.holdWithdraw?.isCompleted ?? true))
        api.holdWithdraw!.complete();
      api.close();
    }
  });
  testWidgets(
    'background clears private reasons and receipts until explicit refresh',
    (tester) async {
      final api = _PrivacyApi();
      api.rows.add({
        'id': 1,
        'kind': 'access',
        'status': 'pending',
        'details': 'Private retained explanation',
      });
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: PrivacyRequestsView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Private retained explanation'), findsOneWidget);
      await tester.enterText(
        find.byType(TextField).first,
        'Sensitive unfinished explanation',
      );
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pumpAndSettle();
      expect(find.text('Private retained explanation'), findsNothing);
      expect(find.text('Sensitive unfinished explanation'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('Private retained explanation'), findsNothing);
      expect(find.text('No privacy requests yet.'), findsNothing);
    },
  );
  testWidgets(
    'Arabic privacy view uses RTL and keeps the manual-review notice visible',
    (tester) async {
      final api = _PrivacyApi();
      addTearDown(api.close);
      await tester.binding.setSurfaceSize(const Size(390, 844));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: PrivacyRequestsView(api: api, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('طلبات الخصوصية والبيانات'), findsOneWidget);
      expect(
        Directionality.of(
          tester.element(find.text('طلبات الخصوصية والبيانات')),
        ),
        TextDirection.rtl,
      );
      expect(find.textContaining('تقديم الطلب لا يحذف الحساب'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
