import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/financial_analytics.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_ANALYTICS_FIXTURE'];
  test(
    'actual Laravel native scoped source filters private Arabic PDF and unchanged canonical finances',
    () async {
      final f = jsonDecode(await File(path!).readAsString());
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['customer'], f['password']);
      final before = await api.request('finance/invoices/${f['invoice']}');
      final report = FinancialReport(await api.request('financial/analytics'));
      final collected = report.groups.where(
        (r) =>
            r['category'] == 'charging_collected' && r['period'] == 'current',
      );
      expect(
        collected.fold<int>(0, (n, r) => n + (r['amount_minor'] as int)),
        1050,
      );
      final query = <String, String>{
        'from': report.source['period']['from'],
        'to': report.source['period']['to'],
        'currency': 'AED',
        'locale': 'ar',
      };
      final pdf = await api.downloadFinancialAnalytics(query);
      expect(pdf.mime, 'application/pdf');
      expect(String.fromCharCodes(pdf.bytes.take(5)), '%PDF-');
      expect(pdf.bytes.length, greaterThan(1000));
      final filtered = FinancialReport(
        await api.request('financial/analytics', query: {'currency': 'USD'}),
      );
      expect(filtered.groups, isEmpty);
      await expectLater(
        api.request('financial/analytics', query: {'user_id': '1'}),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)),
      );
      final after = await api.request('finance/invoices/${f['invoice']}');
      expect(after['paid_minor'], before['paid_minor']);
      expect(after['refunded_minor'], before['refunded_minor']);
      expect(after['ledger_balanced'], true);
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      final vendor = FinancialReport(await api.request('financial/analytics'));
      expect(
        vendor.groups.any((r) => r['category'] == 'charging_collected'),
        true,
      );
      await api.signOut();
      await api.signIn(f['foreign'], f['password']);
      expect(
        FinancialReport(await api.request('financial/analytics')).groups,
        isEmpty,
      );
    },
    skip: path == null
        ? 'Requires isolated local Laravel analytics fixture.'
        : false,
  );
}
