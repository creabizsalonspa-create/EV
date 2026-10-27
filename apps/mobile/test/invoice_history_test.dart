import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/invoice_history.dart';
import 'test_support.dart';

class HistoryApi extends FixtureApi {
  final queries = <Map<String, String>>[];
  bool failSecond = true, expired = false;
  final methods = <String>[];
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
      'created_at': '2026-10-27T00:00:00Z',
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
        'data': [invoice],
        'current_page': int.parse(query['page']!),
        'last_page': 2,
        'total': 26,
      };
    }
    expect(path, 'finance/invoices/7');
    return {
      'invoice': invoice,
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
