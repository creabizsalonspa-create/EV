import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final file = Platform.environment['EV_NATIVE_CERTIFICATE_FIXTURE'];
  test(
    'actual native both-protocol certificate lifecycle preserves scoped admission and recovery',
    () async {
      final f =
          jsonDecode(await File(file!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final base = Uri.parse(f['base']);
      expect(base.scheme, 'http');
      expect(base.host, '127.0.0.1');
      final owner = EvApi(base), other = EvApi(base);
      try {
        await owner.signIn(f['owner'], f['password']);
        await other.signIn(f['other'], f['password']);
        final actor = (await owner.request('me'))['id'];
        for (final charger in f['chargers']) {
          final admission =
              'vendor-ops/companies/${f['company_id']}/chargers/${charger['id']}/admission';
          final profile = await owner.request(
            admission,
            method: 'POST',
            body: {'identity': charger['identity']},
          );
          final root = '$admission/certificates';
          final intent = {
            'idempotency_key': operationKey(),
            'expected_actor_id': actor,
            'expected_profile_id': profile['id'],
            'expected_version': 0,
            'public_certificate': charger['pem'],
            'reason': 'Explicit isolated native certificate enrollment',
            'consent': true,
          };
          final enrolled = await owner.request(
            '$root/enroll',
            method: 'POST',
            body: intent,
          );
          expect(enrolled['action'], 'enroll');
          expect(enrolled['profile_id'], profile['id']);
          expect(enrolled['result_version'], 1);
          expect(enrolled['device_commands_sent'], false);
          final replay = await owner.request(
            '$root/enroll',
            method: 'POST',
            body: intent,
          );
          expect(replay['certificate_id'], enrolled['certificate_id']);
          final resolved = await owner.request(
            '$root/resolve',
            method: 'POST',
            body: {
              'idempotency_key': intent['idempotency_key'],
              'expected_actor_id': actor,
              'expected_profile_id': profile['id'],
              'expected_action': 'enroll',
              'cancel_unissued': true,
            },
          );
          expect(resolved['state'], 'completed');
          await expectLater(
            other.request(root),
            throwsA(
              isA<ApiException>().having(
                (error) => error.status,
                'status',
                404,
              ),
            ),
          );
          final credential = await owner.request(
            '$admission/credential',
            method: 'POST',
            body: {'expected_version': 1, 'days': 90},
          );
          expect(
            DateTime.parse(
              credential['profile']['expires_at'],
            ).isAfter(DateTime.parse(enrolled['certificate']['not_after'])),
            false,
          );
          final revoke = {
            'idempotency_key': operationKey(),
            'expected_actor_id': actor,
            'expected_profile_id': profile['id'],
            'expected_version': 2,
            'certificate_id': enrolled['certificate_id'],
            'reason': 'Explicit isolated native certificate revocation',
            'consent': true,
          };
          final revoked = await owner.request(
            '$root/revoke',
            method: 'POST',
            body: revoke,
          );
          expect(revoked['result_version'], 3);
          expect(revoked['gateway_recheck_may_disconnect'], true);
          await expectLater(
            owner.request(
              '$admission/credential',
              method: 'POST',
              body: {'expected_version': 3, 'days': 1},
            ),
            throwsA(
              isA<ApiException>().having(
                (error) => error.status,
                'status',
                409,
              ),
            ),
          );
          final history = await owner.request(root);
          expect(history['data'].length, 1);
          expect(history['data'][0]['state'], 'revoked');
          expect(jsonEncode(history).contains('BEGIN CERTIFICATE'), false);
        }
      } finally {
        owner.close();
        other.close();
      }
    },
    skip: file == null
        ? 'Requires the owned isolated certificate fixture.'
        : false,
  );
}
