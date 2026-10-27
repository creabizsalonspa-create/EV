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
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['customer'], f['password']);
      final before = await api.request('reservations');
      final current = (before['data'] as List).firstWhere(
        (r) => r['id'] == f['current'],
      );
      expect(current['can_check_in'], true);
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
      expect(
        (rows['data'] as List).firstWhere(
          (r) => r['id'] == f['unused'],
        )['status'],
        'expired_unused',
      );
      await expectLater(
        api.request(
          'reservations/${f['unused']}/check-in',
          method: 'POST',
          body: body,
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await api.signOut();
      await api.signIn(f['owner'], f['password']);
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
