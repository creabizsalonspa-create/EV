import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:creabiz_ev/api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/invoice_reconciliation.dart';
import 'package:creabiz_ev/vendor_finance.dart';
import 'vendor_finance_test.dart' show ScopedVendorApi, vendorApp;

Map<String, dynamic> row(int id) => {
  'invoice_id': id, 'invoice_number': 'TEST-INVOICE-$id',
  'currency': 'KWD', 'minor_units': 3, 'journal_count': 2,
  'invoiced_minor': 1250, 'paid_minor': 1000, 'refunded_minor': 100,
  'unpaid_credited_minor': 250, 'net_collected_minor': 900, 'amount_due_minor': 0,
  'status': 'reconciled', 'issues': <String>[],
  'evidence': 'SIMULATED_CANONICAL_LEDGER', 'external_psp_or_bank_verified': false,
};
Map<String, dynamic> auditPage({int page = 1, int total = 1, int? vendorId = 3,
  String? from, String? to}) => {
  'scope': {'vendor_id': vendorId, 'currency': 'KWD', 'from': from, 'to': to},
  'data': [for (var i = total - (page - 1) * 20; i > 0 && i > total - page * 20; i--) row(i)],
  'current_page': page, 'last_page': total == 0 ? 1 : ((total - 1) ~/ 20) + 1,
  'per_page': 20, 'total': total,
  'evidence': 'SIMULATED_INTERNAL_RECONCILIATION', 'external_psp_or_bank_verified': false,
};
PrivateDocument csvFor({String number = 'TEST-INVOICE-1', int net = 900}) =>
    PrivateDocument(Uint8List.fromList(utf8.encode(
      '${InvoiceAuditCsv.headers.join(',')}\n1,$number,KWD,3,1250,1000,100,250,$net,0,2,reconciled,,SIMULATED_CANONICAL_LEDGER,false\n'
    )), 'text/csv');

class AuditApi extends ScopedVendorApi {
  bool manager = true;
  int auditReads = 0, exports = 0;
  bool forgedCsv = false;
  void Function(Map<String, dynamic>)? changeScope;
  Map<String, String>? exportQuery;
  @override
  Future<PrivateDocument> downloadInvoiceAuditPage(Map<String, String> query) async {
    exports++;
    exportQuery = Map.of(query);
    return csvFor(net: forgedCsv ? 901 : 900);
  }
  Completer<dynamic>? held;
  @override
  Future<dynamic> request(String path, {String method = 'GET',
    Map<String, dynamic>? body, Map<String, String> query = const {}}) async {
    expect(method, 'GET');
    expect(body, isNull);
    if (path == 'me' && !manager) return {'id': 3, 'role': 'customer'};
    if (path == 'finance/reconciliation-audit') {
      auditReads++;
      queries.add(Map.of(query));
      if (held != null) return held!.future;
      final response = auditPage(page: int.parse(query['page']!), vendorId: vendor,
        from: query['from'], to: query['to']);
      changeScope?.call(response);
      return response;
    }
    return super.request(path, method: method, body: body, query: query);
  }
}
Widget screen(AuditApi api, {bool ar = false}) => vendorApp(
  InvoiceReconciliationView(api: api, onExpired: () {}, currency: 'KWD'), ar: ar);

void main() {
  test('exact immutable ledger diagnostic rejects forged authority and arithmetic', () {
    final raw = auditPage();
    final parsed = InvoiceAuditPage.parse(raw, 'KWD', 1, vendorId: 3);
    raw['data'][0]['paid_minor'] = 5;
    expect(parsed.rows.single.money('net_collected_minor'), 'KWD 0.900');
    expect(() => parsed.rows.single.amounts.clear(), throwsUnsupportedError);
    for (final change in <void Function(Map<String, dynamic>)>[
      (r) => r['external_psp_or_bank_verified'] = true,
      (r) => r['current_page'] = 2,
      (r) => r['data'][0]['currency'] = 'AED',
      (r) => r['data'][0]['net_collected_minor'] = 901,
      (r) => r['data'][0]['amount_due_minor'] = 1,
      (r) => r['data'][0]['invoice_id'] = 9007199254740992,
      (r) => r['data'][0]['paid_minor'] = 1000.0,
      (r) => r['data'][0]['issues'] = ['journal_unbalanced'],
    ]) {
      final invalid = auditPage();
      change(invalid);
      expect(() => InvoiceAuditPage.parse(invalid, 'KWD', 1, vendorId: 3), throwsFormatException);
    }
    final anomaly = auditPage();
    anomaly['data'][0]['status'] = 'attention_required';
    anomaly['data'][0]['issues'] = ['journal_unbalanced'];
    expect(InvoiceAuditPage.parse(anomaly, 'KWD', 1, vendorId: 3).rows.single.issues, ['journal_unbalanced']);
  });
  test('audit envelope requires exact vendor, currency and date scope', () {
    final raw = auditPage(from: '2026-11-01', to: '2026-11-27');
    InvoiceAuditPage parse(Map<String, dynamic> value) => InvoiceAuditPage.parse(
      value, 'KWD', 1, vendorId: 3, from: '2026-11-01', to: '2026-11-27');
    expect(parse(raw).total, 1);
    for (final change in <void Function(Map<String, dynamic>)>[
      (r) => r.remove('scope'),
      (r) => r['scope']['vendor_id'] = 4,
      (r) => r['scope']['vendor_id'] = 3.0,
      (r) => r['scope']['vendor_id'] = null,
      (r) => r['scope']['currency'] = 'AED',
      (r) => r['scope']['from'] = '2026-11-02',
      (r) => r['scope']['to'] = null,
      (r) => r['scope'].remove('to'),
    ]) {
      final invalid = auditPage(from: '2026-11-01', to: '2026-11-27');
      change(invalid);
      expect(() => parse(invalid), throwsFormatException);
    }
    expect(InvoiceAuditPage.parse(auditPage(vendorId: null), 'KWD', 1).total, 1);
    expect(() => InvoiceAuditPage.parse(auditPage(), 'KWD', 1), throwsFormatException);
  });
  testWidgets('foreign audit scope clears rows and blocks CSV even with valid amounts', (t) async {
    final api = AuditApi();
    addTearDown(api.close);
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    expect(find.text('Internally reconciled'), findsOneWidget);
    api.changeScope = (r) => r['scope']['vendor_id'] = 4;
    await t.tap(find.text('Refresh audit'));
    await t.pumpAndSettle();
    expect(find.text('Private audit unavailable. Check dates and access.'), findsOneWidget);
    expect(find.textContaining('TEST-INVOICE'), findsNothing);
    expect(find.text('View verified current-page CSV'), findsNothing);
    expect(api.exports, 0);
  });
  test('private CSV verifies exact page amounts and literal invoice cells', () {
    final source = InvoiceAuditPage.parse(auditPage(), 'KWD', 1, vendorId: 3);
    expect(InvoiceAuditCsv.verify(csvFor(), source), contains('TEST-INVOICE-1'));
    expect(() => InvoiceAuditCsv.verify(csvFor(net: 901), source), throwsFormatException);
    expect(() => InvoiceAuditCsv.verify(csvFor(number: 'FOREIGN'), source), throwsFormatException);
    final raw = auditPage();
    raw['data'][0]['invoice_number'] = '\u200b=1';
    final unsafe = InvoiceAuditPage.parse(raw, 'KWD', 1, vendorId: 3);
    expect(InvoiceAuditCsv.verify(csvFor(number: "'\u200b=1"), unsafe), contains("'\u200b=1"));
    expect(() => InvoiceAuditCsv.verify(csvFor(number: '\u200b=1'), unsafe), throwsFormatException);
  });
  testWidgets('current private CSV is verified, uses exact query and clears on background', (t) async {
    final api = AuditApi();
    addTearDown(api.close);
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    final export = find.text('View verified current-page CSV');
    await t.ensureVisible(export);
    await t.tap(export);
    await t.pumpAndSettle();
    expect(api.exports, 1);
    expect(api.exportQuery, api.queries.last);
    expect(find.byKey(const Key('invoice-audit-csv-preview')), findsOneWidget);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pumpAndSettle();
    expect(find.byKey(const Key('invoice-audit-csv-preview')), findsNothing);
    expect(api.exports, 1);
  });
  testWidgets('forged audit CSV fails closed and clears previously verified page', (t) async {
    final api = AuditApi()..forgedCsv = true;
    addTearDown(api.close);
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    await t.tap(find.text('View verified current-page CSV'));
    await t.pumpAndSettle();
    expect(find.byKey(const Key('invoice-audit-csv-preview')), findsNothing);
    expect(find.text('Internally reconciled'), findsNothing);
    expect(api.exports, 1);
  });
  testWidgets('vendor finance links to canonical audit without any write', (t) async {
    final api = AuditApi();
    addTearDown(api.close);
    await t.pumpWidget(vendorApp(VendorFinanceView(api: api, onExpired: () {})));
    await t.pumpAndSettle();
    final button = find.byKey(const ValueKey('vendor-reconciliation-KWD'));
    await t.ensureVisible(button);
    await t.tap(button);
    await t.pumpAndSettle();
    expect(api.auditReads, 1);
    expect(find.text('Internally reconciled'), findsOneWidget);
    expect(api.queries.last, {'currency': 'KWD', 'page': '1', 'per_page': '20'});
    expect(api.posts, 0);
  });
  testWidgets('customer cannot access native finance audit', (t) async {
    final api = AuditApi()..manager = false;
    addTearDown(api.close);
    await t.pumpWidget(screen(api));
    await t.pumpAndSettle();
    expect(api.auditReads, 0);
    expect(find.text('Private audit unavailable. Check dates and access.'), findsOneWidget);
  });
  testWidgets('Arabic private audit is cleared on background, not automatically refreshed', (t) async {
    final api = AuditApi();
    addTearDown(api.close);
    await t.pumpWidget(screen(api, ar: true));
    await t.pumpAndSettle();
    expect(find.text('متطابقة داخلياً'), findsOneWidget);
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await t.pumpAndSettle();
    expect(find.text('متطابقة داخلياً'), findsNothing);
    expect(api.auditReads, 1);
  });
  testWidgets('late audit reply after signout never renders invoice', (t) async {
    final api = AuditApi()..held = Completer<dynamic>();
    addTearDown(api.close);
    await t.pumpWidget(screen(api));
    await t.pump();
    expect(api.auditReads, 1);
    api.stamp++;
    await api.forgetSession();
    api.held!.complete(auditPage());
    await t.pumpAndSettle();
    expect(find.textContaining('TEST-INVOICE'), findsNothing);
    expect(find.text('Refresh audit'), findsNothing);
  });
}
