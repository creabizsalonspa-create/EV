import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/invoice_history.dart';
import 'test_support.dart';

class HistoryApi extends FixtureApi {
  final queries = <Map<String, String>>[];
  bool failSecond = true, expired = false, malformedList = false;
  final methods = <String>[];
  String clock = '2026-10-27T00:00:00Z';
  int epoch = 0;
  final changes = StreamController<int>.broadcast(sync: true);
  @override
  Stream<int> get sessionEvents => changes.stream;
  @override
  bool Function() sessionFence() {
    final captured = epoch;
    return () => captured == epoch;
  }
  void revoke() { epoch++; changes.add(epoch); }
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    methods.add(method);
    expect(method, 'GET');
    if (expired) throw const ApiException(401, 'expired');
    final invoice = {
      'id': 7,
      'number': 'SIM-HISTORY-7',
      'evidence': 'SIMULATED',
      'created_at': clock,
      'total_minor': 329,
      'subtotal_minor': 313,
      'tax_minor': 16,
      'currency': 'AED',
    };
    if (path == 'finance/invoices') {
      queries.add({...query});
      if (query['page'] == '2' && failSecond) {
        failSecond = false;
        throw const ApiException(503, 'offline');
      }
      return {
        'data': [malformedList ? {...invoice, 'id': 'not-an-id'} : invoice],
        'current_page': int.parse(query['page']!),
        'last_page': 2,
        'total': 26,
      };
    }
    expect(path, 'finance/invoices/7');
    return {
      'invoice': {
        ...invoice,
        // Canonical finance/invoices/{id} detail includes these identity and
        // currency precision fields; listing projections need not include them.
        'charging_session_id': 7,
        'user_id': 123,
        'minor_units': 2,
      },
      'state': 'partially_refunded',
      'unpaid_credited_minor': 0,
      'paid_minor': 329,
      'refunded_minor': 100,
      'amount_due_minor': 0,
      'ledger_balanced': true,
      'payment_provider': 'SIMULATOR_ONLY',
      'real_payment_processed': false,
      'payments': [
        {
          'amount_minor': 329,
          'created_at': '2026-10-27T00:01:00Z',
          'idempotency_key': 'private-key-never-render',
        },
      ],
      'refunds': [
        {
          'amount_minor': 100,
          'created_at': '2026-10-27T00:02:00Z',
          'reason': 'Literal <img src=x>',
        },
      ],
    };
  }
}

class DelayedHistoryApi extends HistoryApi {
  final pending = Completer<dynamic>();
  int actorEpoch = 0;
  int invoiceReads = 0;
  @override
  bool Function() sessionFence() {
    final captured = actorEpoch;
    return () => captured == actorEpoch;
  }

  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'finance/invoices') {
      invoiceReads++;
      return pending.future;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}

Widget app(HistoryApi api, VoidCallback expired) => MaterialApp(
  locale: const Locale('ar'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  home: InvoiceHistoryView(api: api, onExpired: expired),
);
void main() {
  testWidgets('invoice list rejects impossible source clocks and recovers with explicit UTC display', (tester) async {
    final api = HistoryApi()..clock = '2026-02-30T08:00:00+04:00';
    addTearDown(api.close);
    await tester.pumpWidget(app(api, () {}));
    await tester.pumpAndSettle();
    expect(find.text('SIM-HISTORY-7'), findsNothing);
    api.clock = '2026-10-27T08:00:00+04:00';
    await tester.ensureVisible(find.text('إعادة المحاولة'));
    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pumpAndSettle();
    expect(find.text('SIM-HISTORY-7'), findsOneWidget);
    expect(find.textContaining('UTC'), findsOneWidget);
    expect(find.text(api.clock), findsNothing);
  });
  testWidgets('revocation immediately removes invoice history and retained detail callback cannot navigate', (tester) async {
    final api = HistoryApi();
    addTearDown(api.close);
    await tester.pumpWidget(app(api, () {}));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('عرض الفاتورة'), 300, scrollable: find.byType(Scrollable).first);
    final open = tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'عرض الفاتورة')).onPressed!;
    final calls = api.methods.length;
    api.revoke();
    await tester.pumpAndSettle();
    open();
    await tester.pumpAndSettle();
    expect(find.text('SIM-HISTORY-7'), findsNothing);
    expect(find.text('عرض الفاتورة'), findsNothing);
    expect(api.methods.length, calls);
    expect(tester.takeException(), isNull);
  });
  testWidgets('late old-account invoice list is discarded and new actor gets fresh data', (
    tester,
  ) async {
    final oldApi = DelayedHistoryApi();
    addTearDown(oldApi.close);
    var expired = false;
    await tester.pumpWidget(app(oldApi, () => expired = true));
    await tester.pump();
    expect(oldApi.invoiceReads, 1);

    // Replace the authenticated API while the previous account's GET is still
    // unresolved. The next user's invoice must win even if the old GET returns.
    oldApi.actorEpoch++;
    final newApi = HistoryApi()..failSecond = false;
    addTearDown(newApi.close);
    await tester.pumpWidget(app(newApi, () {}));
    await tester.pumpAndSettle();
    expect(find.text('SIM-HISTORY-7'), findsOneWidget);

    oldApi.pending.complete({
      'data': [
        {
          'id': 7,
          'number': 'PRIVATE-OLD-ACCOUNT',
          'evidence': 'SIMULATED',
          'created_at': '2026-10-27T00:00:00Z',
          'total_minor': 329,
          'currency': 'AED',
        },
      ],
      'current_page': 1,
      'last_page': 1,
      'total': 1,
    });
    await tester.pumpAndSettle();
    expect(find.text('SIM-HISTORY-7'), findsOneWidget);
    expect(find.text('PRIVATE-OLD-ACCOUNT'), findsNothing);
    expect(oldApi.invoiceReads, 1);
    expect(expired, isFalse);
  });

  testWidgets('malformed private invoice row hides detail controls until explicit retry', (
    tester,
  ) async {
    final api = HistoryApi()
      ..malformedList = true
      ..failSecond = false;
    addTearDown(api.close);
    await tester.pumpWidget(app(api, () {}));
    await tester.pumpAndSettle();
    expect(find.text('SIM-HISTORY-7'), findsNothing);
    expect(find.text('عرض الفاتورة'), findsNothing);
    await tester.ensureVisible(find.text('إعادة المحاولة'));
    api.malformedList = false;
    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pumpAndSettle();
    expect(find.text('SIM-HISTORY-7'), findsOneWidget);
    expect(api.methods.every((method) => method == 'GET'), isTrue);
  });
  testWidgets('background clears private invoice list and filters without automatic reads', (
    tester,
  ) async {
    final api = HistoryApi()..failSecond = false;
    addTearDown(api.close);
    await tester.pumpWidget(app(api, () {}));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).first, 'PRIVATE-PREFIX');
    final requests = api.queries.length;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('SIM-HISTORY-7'), findsNothing);
    expect(
      tester.widget<TextFormField>(find.byType(TextFormField).first).controller!.text,
      isEmpty,
    );
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(api.queries.length, requests);
    expect(find.text('SIM-HISTORY-7'), findsNothing);
    await tester.ensureVisible(find.text('تحديث سجل الفواتير'));
    await tester.tap(find.text('تحديث سجل الفواتير'));
    await tester.pumpAndSettle();
    expect(api.queries.length, requests + 1);
    expect(find.text('SIM-HISTORY-7'), findsOneWidget);
    expect(api.queries.last, {'page': '1'});
  });
  testWidgets(
    'invoice history keeps filters through pagination failure/retry and clears on expiry',
    (tester) async {
      final api = HistoryApi();
      addTearDown(api.close);
      var expired = false;
      await tester.pumpWidget(app(api, () => expired = true));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).at(0), 'SIM-HISTORY');
      await tester.enterText(find.byType(TextFormField).at(1), 'aed');
      await tester.ensureVisible(find.text('تصفية الفواتير'));
      await tester.tap(find.text('تصفية الفواتير'));
      await tester.pumpAndSettle();
      expect(api.queries.last, {
        'q': 'SIM-HISTORY',
        'currency': 'AED',
        'page': '1',
      });
      await tester.scrollUntilVisible(
        find.text('التالي'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('التالي'));
      await tester.pumpAndSettle();
      expect(find.text('SIM-HISTORY-7'), findsNothing);
      await tester.ensureVisible(find.text('إعادة المحاولة'));
      await tester.tap(find.text('إعادة المحاولة'));
      await tester.pumpAndSettle();
      expect(api.queries.last, {
        'q': 'SIM-HISTORY',
        'currency': 'AED',
        'page': '2',
      });
      expect(find.text('الصفحة 2 من 2 · 26 سجل'), findsOneWidget);
      api.expired = true;
      await tester.drag(find.byType(ListView), const Offset(0, 600));
      await tester.pumpAndSettle();
      // Submit the visible filter form to verify authenticated failure cleanup.
      await tester.ensureVisible(find.text('تصفية الفواتير'));
      await tester.tap(find.text('تصفية الفواتير'));
      await tester.pumpAndSettle();
      expect(expired, isTrue);
      expect(find.text('SIM-HISTORY-7'), findsNothing);
    },
  );
  testWidgets(
    'existing invoice detail uses GET and shows only receipt amounts, times and literal refund reason',
    (tester) async {
      final api = HistoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('عرض الفاتورة'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('عرض الفاتورة'));
      await tester.pumpAndSettle();
      expect(find.text('SIM-HISTORY-7'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Literal <img src=x>'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Literal <img src=x>'), findsOneWidget);
      expect(find.text('private-key-never-render'), findsNothing);
      expect(api.methods.every((m) => m == 'GET'), isTrue);
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'invalid calendar and reversed date filters are rejected without a request',
    (tester) async {
      final api = HistoryApi();
      addTearDown(api.close);
      await tester.pumpWidget(app(api, () {}));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).at(2), '2026-02-30');
      await tester.ensureVisible(find.text('تصفية الفواتير'));
      await tester.tap(find.text('تصفية الفواتير'));
      await tester.pumpAndSettle();
      expect(api.queries.length, 1);
      await tester.enterText(find.byType(TextFormField).at(2), '2026-10-27');
      await tester.enterText(find.byType(TextFormField).at(3), '2026-10-26');
      await tester.ensureVisible(find.text('تصفية الفواتير'));
      await tester.tap(find.text('تصفية الفواتير'));
      await tester.pumpAndSettle();
      expect(api.queries.length, 1);
      expect(
        find.text('يجب ألا يسبق تاريخ النهاية تاريخ البداية.'),
        findsOneWidget,
      );
    },
  );
}
