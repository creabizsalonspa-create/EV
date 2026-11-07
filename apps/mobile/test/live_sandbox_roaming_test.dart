import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_SANDBOX_ROAMING_FIXTURE'];
  test(
    'native actual Laravel sandbox consent, immutable replay, nonbillable history and revocation',
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
      expect(offers['billing_eligible'], false);
      final offer = offers['offers'].first as Map;
      final body = {
        'request_key': operationKey(),
        'evse_uid': offer['evse_uid'],
        'connector_id': offer['connector_id'],
        'tariff_id': offer['tariff_id'],
        'offer_hash': offer['offer_hash'],
        'terms_version': offer['terms_version'],
        'consent': true,
      };
      final created = await api.request(
        '$root/sandbox-orders',
        method: 'POST',
        body: body,
      );
      expect(created['state'], 'start_accepted');
      expect(created['can_stop'], false);
      expect(created['live_commands_enabled'], false);
      expect(created['local_invoice_created'], false);
      final id = created['id'];
      final replay = await api.request(
        '$root/sandbox-orders',
        method: 'POST',
        body: body,
      );
      expect(replay['id'], id);
      expect(replay['commands'].length, 1);
      final progress = await api.request(
        'roaming/sandbox-orders/$id/reconcile',
        method: 'POST',
        body: {},
      );
      expect(progress['source_status'], isNull);
      expect(progress['can_stop'], false);
      final revoked = await api.request(
        'roaming/sandbox-orders/$id/authority',
        method: 'DELETE',
      );
      expect(revoked['can_revoke'], false);
      expect(revoked['physical_stop_implied'], false);
      expect(revoked['token_state'], 'partner_revoked');
      expect(revoked['local_invoice_created'], false);
      await api.signOut();
      await api.signIn(f['other'], f['password']);
      await expectLater(
        api.request('roaming/sandbox-orders/$id'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
    },
    skip: path == null
        ? 'Requires the private local sandbox CPO fixture; no live partner authority.'
        : false,
  );
}
