import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_RESERVATION_FIXTURE'];
  test(
    'actual Laravel native reservation consent replay cancellation and tenant privacy',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final base = Uri.parse(f['base']);
      expect(base.host, '127.0.0.1');
      expect(base.scheme, 'http');
      final api = EvApi(base);
      addTearDown(api.close);
      await api.signIn(f['driver'], f['password']);
      final root = 'roaming/locations/${f['location_id']}';
      final offers = await api.request('$root/sandbox-offers');
      expect(offers['reservation_supported'], true);
      final offer = offers['offers'].first as Map;
      final body = {
        'request_key': operationKey(),
        'evse_uid': offer['evse_uid'],
        'connector_id': offer['connector_id'],
        'tariff_id': offer['tariff_id'],
        'offer_hash': offer['offer_hash'],
        'terms_version': offers['reservation_terms_version'],
        'consent': true,
        'duration_minutes': 5,
      };
      final created = await api.request(
        '$root/sandbox-reservations',
        method: 'POST',
        body: body,
      );
      expect(created['state'], 'reservation_accepted');
      expect(created['intent_kind'], 'reservation');
      expect(created['can_stop'], false);
      expect(created['reservation_billing_supported'], false);
      expect(created['live_commands_enabled'], false);
      expect(created['physical_charging_verified'], false);
      final id = created['id'];
      final replay = await api.request(
        '$root/sandbox-reservations',
        method: 'POST',
        body: body,
      );
      expect(replay['id'], id);
      expect(replay['commands'].length, 1);
      final cancel = {'request_key': operationKey(), 'consent': true};
      final cancelled = await api.request(
        'roaming/sandbox-orders/$id/cancel-reservation',
        method: 'POST',
        body: cancel,
      );
      expect(cancelled['commands'].length, 3);
      expect(cancelled['physical_stop_implied'], false);
      expect(cancelled['reservation_closed'], false);
      expect(cancelled['token_state'], 'partner_revoked');
      expect(
        cancelled['commands'].any((c) => c['action'] == 'STOP_SESSION'),
        false,
      );
      final again = await api.request(
        'roaming/sandbox-orders/$id/cancel-reservation',
        method: 'POST',
        body: cancel,
      );
      expect(again['commands'].length, 3);
      final progress = await api.request(
        'roaming/sandbox-orders/$id/reconcile',
        method: 'POST',
        body: {},
      );
      expect(progress['reservation_closed'], false);
      expect(progress['local_invoice_created'], false);
      await api.signOut();
      await api.signIn(f['other'], f['password']);
      await expectLater(
        api.request('roaming/sandbox-orders/$id'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
    },
    skip: path == null
        ? 'Requires private synthetic reservation fixture; no live partner authority.'
        : false,
  );
}
