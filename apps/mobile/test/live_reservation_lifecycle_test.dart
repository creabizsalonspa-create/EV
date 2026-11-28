import 'local_fixture_origin.dart';
import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_ARRIVAL_FIXTURE'];
  test(
    'actual Laravel check-in is explicit, idempotent and scoped; unused expiry creates no session',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(localFixtureOrigin());
      addTearDown(api.close);
      await api.signIn(f['customer'], f['password']);
      Future<void> assertNoSessionOrInvoice() async {
        for (final endpoint in ['simulations', 'finance/invoices']) {
          final page = await api.request(endpoint);
          expect(page['total'], 0, reason: endpoint);
          expect(page['data'], isEmpty, reason: endpoint);
        }
      }
      await assertNoSessionOrInvoice();
      final before = await api.request('reservations');
      final current = (before['data'] as List).firstWhere(
        (r) => r['id'] == f['current'],
      );
      expect(current['can_check_in'], true);
      expect(current['expiry_outcome'], isNull);
      final body = {'self_reported_arrival': true};
      final first = await api.request(
        'reservations/${f['current']}/check-in',
        method: 'POST',
        body: body,
      );
      expect(first['evidence'], 'SELF_REPORTED');
      expect(first['physical_presence_verified'], false);
      final retry = await api.request(
        'reservations/${f['current']}/check-in',
        method: 'POST',
        body: body,
      );
      expect(retry['replayed'], true);
      expect(
        retry['reservation']['checked_in_at'],
        first['reservation']['checked_in_at'],
      );
      final rows = await api.request('reservations');
      for (final (key, outcome) in [
        ('unused', 'unused_without_reported_arrival'),
        ('arrived_unused', 'unused_after_reported_arrival'),
      ]) {
        final row = (rows['data'] as List).singleWhere((r) => r['id'] == f[key]);
        expect(row['status'], 'expired_unused');
        expect(row['expiry_outcome'], outcome);
        expect(row['physical_presence_verified'], false);
        if (key == 'unused') {
          expect(row['reservation_terms_snapshot'], isNull);
          expect(row['checked_in_at'], isNull);
        } else {
          expect(row['reservation_terms_snapshot']['no_show_fee_minor'], 0);
          expect(row['checked_in_at'], isNotNull);
        }
      }
      await expectLater(
        api.request(
          'reservations/${f['unused']}/check-in',
          method: 'POST',
          body: body,
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      // Expiry and arrival are distinct from admission: neither creates
      // money or a session. A direct start on the expired booking also fails.
      await expectLater(
        api.request(
          'reservations/${f['unused']}/simulate-start',
          method: 'POST',
          body: {},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await assertNoSessionOrInvoice();
      await api.signOut();
      await api.signIn(f['owner'], f['password']);
      final vendorRows = (await api.request('reservations'))['data'] as List;
      for (final (key, outcome) in [
        ('unused', 'unused_without_reported_arrival'),
        ('arrived_unused', 'unused_after_reported_arrival'),
      ]) {
        final row = vendorRows.singleWhere((r) => r['id'] == f[key]);
        expect(row['expiry_outcome'], outcome);
        expect(row['physical_presence_verified'], false);
      }
      await expectLater(
        api.request(
          'reservations/${f['current']}/check-in',
          method: 'POST',
          body: body,
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Requires isolated actual Laravel fixture; run tests/native-reservation-lifecycle.cjs'
        : false,
  );
}
