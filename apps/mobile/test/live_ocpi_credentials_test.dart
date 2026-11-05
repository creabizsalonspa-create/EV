import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_OCPI_CREDENTIALS_FIXTURE'];
  test(
    'native actual Laravel credential metadata and authenticated peer rotation/revocation',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final base = Uri.parse(f['base']);
      expect(base.host, '127.0.0.1');
      expect(base.scheme, 'http');
      final api = EvApi(base);
      final http = HttpClient();
      addTearDown(api.close);
      addTearDown(() => http.close(force: true));
      Future<Map<String, dynamic>> protocol(
        String method,
        String token, {
        Map<String, dynamic>? body,
        int expected = 200,
        String resource = '2.2.1/credentials',
      }) async {
        final request = await http.openUrl(
          method,
          Uri.parse('${f['base']}/api/roaming-protocol/$resource'),
        );
        request.headers.set(
          HttpHeaders.authorizationHeader,
          'Token ${base64Encode(utf8.encode(token))}',
        );
        request.headers.contentType = ContentType.json;
        if (body != null) request.write(jsonEncode(body));
        final reply = await request.close();
        expect(reply.statusCode, expected);
        return jsonDecode(await utf8.decoder.bind(reply).join())
            as Map<String, dynamic>;
      }

      await api.signIn(f['admin'], f['password']);
      final endpoint = 'ocpi/providers/${f['provider_id']}/credentials';
      final before = await api.request(endpoint);
      if (f['incoming'] == true) {
        expect(before['available_actions'], ['prepare_incoming']);
        final receipt = await api.request(
          endpoint,
          method: 'POST',
          body: {
            'action': 'prepare_incoming',
            'reason': 'Preapproved synthetic native incoming enrollment',
            'idempotency_key': operationKey(),
          },
        );
        expect(receipt['state'], 'awaiting_peer');
        final pending = await api.request(endpoint);
        expect(pending['state'], 'awaiting_registration');
        expect(pending['bootstrap_expires_at'], isNotNull);
        expect(jsonEncode(pending).contains(f['local_token']), false);
        await protocol('GET', f['local_token'], resource: 'versions');
        await protocol('GET', f['local_token'], expected: 401);
        await protocol('DELETE', f['local_token'], expected: 401);
        await api.signOut();
        await api.signIn(f['owner'], f['password']);
        expect((await api.request(endpoint))['available_actions'], isEmpty);
        await expectLater(
          api.request(
            endpoint,
            method: 'POST',
            body: {
              'action': 'prepare_incoming',
              'reason': 'Vendor must not grant bootstrap access',
              'idempotency_key': operationKey(),
            },
          ),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 403)),
        );
        return;
      }
      expect(before['state'], 'active');
      expect(jsonEncode(before).contains(f['local_token']), false);
      final rotated = await protocol(
        'PUT',
        f['local_token'],
        body: {
          'token': 'SYNTHETIC-NATIVE-C',
          'url': 'https://partner.example/ocpi/versions',
          'roles': [
            {
              'role': 'CPO',
              'country_code': 'NL',
              'party_id': 'ABC',
              'business_details': {'name': 'Synthetic native peer'},
            },
          ],
        },
      );
      final token = rotated['data']['token'] as String;
      expect(token.length, 64);
      expect(token != f['local_token'], true);
      await protocol('GET', f['local_token'], expected: 401);
      final state = await api.request(endpoint);
      expect(state['operations']['data'][0]['action'], 'peer_rotate');
      expect(jsonEncode(state).contains(token), false);
      expect(jsonEncode(state).contains('SYNTHETIC-NATIVE-C'), false);
      await protocol('DELETE', token);
      await protocol('GET', token, expected: 401);
      final revoked = await api.request(endpoint);
      expect(revoked['state'], 'revoked');
      expect(revoked['available_actions'], isEmpty);
      await api.signOut();
      await api.signIn(f['owner'], f['password']);
      final owner = await api.request(endpoint);
      expect(owner['available_actions'], isEmpty);
      await expectLater(
        api.request(
          endpoint,
          method: 'POST',
          body: {
            'action': 'register',
            'reason': 'Must not mutate as vendor',
            'idempotency_key': operationKey(),
          },
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 403)),
      );
    },
    skip: path == null
        ? 'Requires explicit isolated local OCPI credentials fixture.'
        : false,
  );
}
