import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_revenue_cohorts.dart';

void main() {
  final fixturePath = Platform.environment['EV_NATIVE_ANALYTICS_FIXTURE'];
  test('actual Laravel native issuance-cohort access, integrity and isolation',
      () async {
    final f = jsonDecode(await File(fixturePath!).readAsString())
        as Map<String, dynamic>;
    expect(f['fixture_only'], true);
    final base = Uri.parse(f['base'] as String);
    expect(base.scheme, 'http');
    expect(['localhost', '127.0.0.1'], contains(base.host));
    final api = EvApi(base);
    addTearDown(api.close);
    final today = DateTime.now().toUtc();
    final to = today.toIso8601String().substring(0, 10);
    final from = today.subtract(const Duration(days: 1))
        .toIso8601String().substring(0, 10);
    final owner = f['vendor_id'] as int, invoice = f['invoice'] as int;
    final path = 'commercial/vendors/$owner/revenue-cohorts';
    final query = {'currency': 'AED', 'from': from, 'to': to};

    await api.signIn(f['customer'], f['password']);
    final before = await api.request('finance/invoices/$invoice');
    await expectLater(api.request(path, query: query),
      throwsA(isA<ApiException>().having((e) => e.status, 'customer access', 404)));
    await api.signOut();

    await api.signIn(f['vendor'], f['password']);
    final identity = await api.request('me');
    expect(identity['id'], owner);
    final actual = VendorRevenueCohortSnapshot.parse(
      await api.request(path, query: query),
      vendorId: owner, currency: 'AED', from: from, to: to,
    );
    expect(actual.days, hasLength(2));
    expect(actual.totals['invoice_count'], greaterThanOrEqualTo(1));
    expect(actual.totals['paid_minor'], greaterThanOrEqualTo(1050));
    expect(actual.source['real_money_movement'], false);
    expect(actual.source['external_psp_or_bank_verified'], false);
    final document = await api.downloadVendorRevenueCohort(owner,
      currency:'AED',from:from,to:to);
    expect(VendorRevenueCohortCsv.verifiedText(document,actual),contains('date_utc,invoice_count'));
    final empty = VendorRevenueCohortSnapshot.parse(
      await api.request(path, query: {
        ...query,
        'from': today.subtract(const Duration(days: 10))
            .toIso8601String().substring(0, 10),
        'to': today.subtract(const Duration(days: 9))
            .toIso8601String().substring(0, 10),
      }),
      vendorId: owner,
      currency: 'AED',
      from: today.subtract(const Duration(days: 10))
          .toIso8601String().substring(0, 10),
      to: today.subtract(const Duration(days: 9))
          .toIso8601String().substring(0, 10),
    );
    expect(empty.totals['invoice_count'], 0);
    await expectLater(api.request(path, query: {...query, 'from': '2026-02-30'}),
      throwsA(isA<ApiException>().having((e) => e.status, 'invalid date', 422)));
    await api.signOut();

    await api.signIn(f['customer'], f['password']);
    final after = await api.request('finance/invoices/$invoice');
    expect(after['paid_minor'], before['paid_minor']);
    expect(after['refunded_minor'], before['refunded_minor']);
    expect(after['ledger_balanced'], true);
    await api.signOut();
    await api.signIn(f['foreign'], f['password']);
    await expectLater(api.request(path, query: query),
      throwsA(isA<ApiException>().having((e) => e.status, 'foreign vendor', 404)));
    await expectLater(api.downloadVendorRevenueCohort(owner,currency:'AED',from:from,to:to),
      throwsA(isA<ApiException>().having((e) => e.status, 'foreign CSV', 404)));
  }, skip: fixturePath == null
      ? 'Requires owned local Laravel finance fixture.'
      : false);
}
