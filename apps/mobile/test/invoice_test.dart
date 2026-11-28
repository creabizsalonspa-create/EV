import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/invoice.dart';
import 'test_support.dart';

class InvoiceFixture extends FixtureApi {
  bool fail = false, invalidEvidence = false;
  String? corruptField;
  bool transactionClocks = false;
  int precision = 2;
  String currencyCode = 'AED';
  int requests = 0;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'finance/sessions/7/invoice');
    expect(method, 'POST');
    requests++;
    if (fail) throw const ApiException(401, 'expired');
    return {
      'invoice': {
        'id': corruptField == 'id' ? '7' : 7,
        'user_id': 123,
        'charging_session_id': corruptField == 'session' ? 8 : 7,
        'minor_units': corruptField == 'minor_units' ? 5 : precision,
        'number': 'SIM-FIXTURE',
        'evidence': 'SIMULATED',
        'subtotal_minor': 313,
        'tax_minor': 16,
        'total_minor': corruptField == 'total_minor' ? -1 :
            corruptField == 'arithmetic' ? 330 : 329,
        'currency': corruptField == 'currency' ? 'DANGEROUS' : currencyCode,
      },
      'paid_minor': corruptField == 'overpaid' ? 330 : 329,
      'refunded_minor': corruptField == 'overrefund' ? 330 : 329,
      'amount_due_minor': corruptField == 'due' ? 1 : 0,
      'unpaid_credited_minor': 0,
      'state': corruptField == 'state' ? 'paid' : 'refunded',
      'ledger_balanced': true,
      'payments': corruptField == 'payment'
          ? [{'amount_minor': 'not-an-amount', 'created_at': '2026-11-16'}]
          : transactionClocks ? [{'amount_minor': 329, 'created_at': '2026-11-25T19:32:00+04:00'}] : [],
      'refunds': corruptField == 'refund'
          ? [{'amount_minor': 329, 'created_at': null}]
          : transactionClocks ? [{'amount_minor': 329, 'created_at': '2026-02-30T15:32:00Z', 'reason': 'Synthetic refund'}] : [],
      'unpaid_credit_notes': corruptField == 'credit_note'
          ? [{'id': 'not-an-id', 'credit_note_number': 'PRIVATE-NOTE'}]
          : [],
      'payment_provider': 'SIMULATOR_ONLY',
      'real_payment_processed': invalidEvidence,
    };
  }
}

class DeferredInvoiceApi extends InvoiceFixture {
  final pending = Completer<dynamic>();
  int actorEpoch = 0;
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
    expect(path, 'finance/sessions/7/invoice');
    expect(method, 'POST');
    requests++;
    return pending.future;
  }
}

class RotatingInvoiceApi extends InvoiceFixture {
  final events = StreamController<int>.broadcast(sync: true);
  int actorEpoch = 0;
  @override
  Stream<int> get sessionEvents => events.stream;
  @override
  bool Function() sessionFence() {
    final captured = actorEpoch;
    return () => captured == actorEpoch;
  }

  void rotate() {
    actorEpoch++;
    events.add(actorEpoch);
  }

  @override
  void close() {
    unawaited(events.close());
    super.close();
  }
}

void main() {
  for (final ar in [false, true]) {
    testWidgets('invoice transaction times use authoritative UTC and hide malformed source ($ar)', (tester) async {
      final api = InvoiceFixture()..transactionClocks = true;
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        locale: Locale(ar ? 'ar' : 'en'),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      final utc = find.textContaining('\u2068UTC\u2069');
      await tester.scrollUntilVisible(utc, 160,
        scrollable: find.byType(Scrollable).first);
      expect(utc, findsOneWidget);
      if (!ar) {
        final label = tester.widget<Text>(utc).data!;
        expect(label, anyOf(contains('15:32'), contains('3:32')));
      }
      expect(Directionality.of(tester.element(utc)),
        ar ? TextDirection.rtl : TextDirection.ltr);
      final hidden = find.textContaining(ar ? '329 وحدة صغرى AED · —' : '329 minor units AED · —');
      await tester.scrollUntilVisible(hidden, 100,
        scrollable: find.byType(Scrollable).first);
      expect(hidden, findsOneWidget);
      expect(find.textContaining('2026-02-30'), findsNothing);
      expect(find.textContaining('2026-11-25T19:32'), findsNothing);
      expect(api.requests, 1);
      expect(tester.takeException(), isNull);
    });
  }

  for (final digits in [0, 2, 3]) {
    for (final arabic in [false, true]) {
      testWidgets('invoice currency precision and direction ($digits, $arabic)', (
        tester,
      ) async {
        final api = InvoiceFixture()
          ..precision = digits
          ..currencyCode = digits == 0 ? 'JPY' : digits == 3 ? 'KWD' : 'AED';
        addTearDown(api.close);
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        await tester.pumpWidget(MaterialApp(
          locale: Locale(arabic ? 'ar' : 'en'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
        ));
        await tester.pumpAndSettle();
        final amount = digits == 0 ? '16 JPY' : digits == 3 ? '0.016 KWD' : '0.16 AED';
        final label = find.textContaining(amount);
        await tester.scrollUntilVisible(label, 200,
          scrollable: find.byType(Scrollable).first,
        );
        expect(label, findsOneWidget);
        expect(Directionality.of(tester.element(label)),
          arabic ? TextDirection.rtl : TextDirection.ltr);
        expect(api.requests, 1);
        expect(tester.takeException(), isNull);
      });
    }
  }

  testWidgets('initial inactive invoice never issues a hidden POST', (tester) async {
    final api = InvoiceFixture();
    addTearDown(api.close);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    ));
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
    ));
    await tester.pumpAndSettle();
    expect(api.requests, 0);
    expect(find.byType(OutlinedButton), findsNothing);
    expect(find.text('Refresh'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(api.requests, 0);
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.requests, 1);
    expect(find.text('SIM-FIXTURE'), findsOneWidget);
  });

  testWidgets('retired account immediately hides invoice and rejects cached actions', (
    tester,
  ) async {
    final api = RotatingInvoiceApi();
    addTearDown(api.close);
    Widget screen(int id) => MaterialApp(
      home: InvoicePage(api: api, sessionId: id, onExpired: () {}),
    );
    await tester.pumpWidget(screen(7));
    await tester.pumpAndSettle();
    expect(find.text('SIM-FIXTURE'), findsOneWidget);
    final oldDocument = tester.widget<OutlinedButton>(
      find.byType(OutlinedButton).first,
    ).onPressed!;
    final oldRefresh = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Refresh'),
    ).onPressed!;
    api.rotate();
    oldDocument();
    oldRefresh();
    await tester.pumpAndSettle();
    expect(find.text('SIM-FIXTURE'), findsNothing);
    expect(find.byType(OutlinedButton), findsNothing);
    expect(find.text('Refresh'), findsNothing);
    expect(api.requests, 1);
    // Reusing the same API object cannot give the retired route a new actor.
    await tester.pumpWidget(screen(8));
    await tester.pumpAndSettle();
    oldDocument();
    oldRefresh();
    await tester.pumpAndSettle();
    expect(find.byType(OutlinedButton), findsNothing);
    expect(api.requests, 1);
    expect(tester.takeException(), isNull);
  });

  testWidgets('background and refreshed invoice reject former action callbacks', (
    tester,
  ) async {
    final api = InvoiceFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
    ));
    await tester.pumpAndSettle();
    final oldDocument = tester.widget<OutlinedButton>(
      find.byType(OutlinedButton).first,
    ).onPressed!;
    final oldRefresh = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Refresh'),
    ).onPressed!;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    addTearDown(() => tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    ));
    oldDocument();
    oldRefresh();
    await tester.pumpAndSettle();
    expect(api.requests, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.requests, 2);
    oldDocument();
    oldRefresh();
    await tester.pumpAndSettle();
    expect(api.requests, 2);
    expect(find.text('SIM-FIXTURE'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('late old-account invoice result cannot repopulate financial actions', (
    tester,
  ) async {
    final api = DeferredInvoiceApi();
    addTearDown(api.close);
    var expired = false;
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () => expired = true),
    ));
    await tester.pump();
    api.actorEpoch++;
    api.pending.complete({
      'invoice': {
        'number': 'PRIVATE-OLD-INVOICE',
        'evidence': 'SIMULATED',
        'id': 7,
        'user_id': 123,
        'minor_units': 2,
        'currency': 'AED',
      },
      'payment_provider': 'SIMULATOR_ONLY',
      'real_payment_processed': false,
    });
    await tester.pump();
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () => expired = true),
    ));
    await tester.pump();
    expect(find.text('PRIVATE-OLD-INVOICE'), findsNothing);
    expect(find.byType(OutlinedButton), findsNothing);
    expect(expired, isFalse);
    expect(api.requests, 1);
  });

  testWidgets('background discards invoice and never automatically replays POST', (
    tester,
  ) async {
    final api = InvoiceFixture();
    addTearDown(api.close);
    await tester.pumpWidget(MaterialApp(
      home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
    ));
    await tester.pumpAndSettle();
    expect(find.text('SIM-FIXTURE'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('SIM-FIXTURE'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(api.requests, 1);
    expect(find.text('SIM-FIXTURE'), findsNothing);
    await tester.ensureVisible(find.text('Refresh'));
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(api.requests, 2);
    expect(find.text('SIM-FIXTURE'), findsOneWidget);
  });
  testWidgets(
    'read-only simulated invoice displays actual minor-unit totals and clears private data on expiry',
    (tester) async {
      final api = InvoiceFixture();
      addTearDown(api.close);
      var expired = false;
      await tester.pumpWidget(
        MaterialApp(
          home: InvoicePage(
            api: api,
            sessionId: 7,
            onExpired: () => expired = true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('SIM-FIXTURE'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Tax: 16 minor units AED'), 200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Tax: 16 minor units AED'), findsOneWidget);
      expect(find.textContaining('0.16 AED'), findsOneWidget);
      // The verified detail screen contains several safe, read-only actions;
      // its ListView lazily builds the lower amount rows on small viewports.
      await tester.scrollUntilVisible(
        find.text('Amount due: 0 minor units AED'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Amount due: 0 minor units AED'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Ledger balanced'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Ledger balanced'), findsOneWidget);
      expect(find.byType(TextField), findsNothing);
      api.fail = true;
      await tester.scrollUntilVisible(
        find.text('Refresh'),
        -200,
        scrollable: find.byType(Scrollable).first,
      );
      await Scrollable.ensureVisible(
        tester.element(find.text('Refresh')),
        alignment: 0.5,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(expired, isTrue);
      expect(find.text('SIM-FIXTURE'), findsNothing);
      expect(api.requests, 2);
    },
  );
  for (final field in ['id', 'session', 'minor_units', 'total_minor', 'currency', 'payment', 'refund', 'credit_note', 'arithmetic', 'overpaid', 'overrefund', 'due', 'state']) {
    testWidgets('malformed invoice $field cannot open financial actions', (
      tester,
    ) async {
      final api = InvoiceFixture()..corruptField = field;
      addTearDown(api.close);
      await tester.pumpWidget(MaterialApp(
        home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      expect(find.text('SIM-FIXTURE'), findsNothing);
      expect(find.text('Ledger balanced'), findsNothing);
      expect(find.byType(OutlinedButton), findsNothing);
      expect(api.requests, 1);
    });
  }

  testWidgets(
    'unsupported finance evidence is not presented as a simulation document',
    (tester) async {
      final api = InvoiceFixture()..invalidEvidence = true;
      addTearDown(api.close);
      await tester.pumpWidget(
        MaterialApp(
          home: InvoicePage(api: api, sessionId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('SIM-FIXTURE'), findsNothing);
      expect(find.text('Ledger balanced'), findsNothing);
    },
  );
}
