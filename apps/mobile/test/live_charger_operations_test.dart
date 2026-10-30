import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_CHARGER_OPERATIONS_FIXTURE'];
  test(
    'native actual Laravel prepares, rotates and revokes admission and quarantines unknown command',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'], f['password']);
      final base =
          'vendor-ops/companies/${f['company_id']}/chargers/${f['charger_id']}/admission';
      await api.request(
        base,
        method: 'POST',
        body: {'identity': f['identity'], 'certificate_sha256': null},
      );
      final issued = await api.request(
        '$base/credential',
        method: 'POST',
        body: {'expected_version': 0, 'days': 1},
      );
      expect((issued['secret'] as String).length, 40);
      expect(issued['profile'].containsKey('credential_digest'), false);
      final current = await api.request(base);
      expect(current.containsKey('secret'), false);
      expect(current['profile']['credential_version'], 1);
      expect(current['credential_state'], 'expiring');
      await expectLater(
        api.request(
          '$base/credential',
          method: 'POST',
          body: {'expected_version': 0, 'days': 1},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await api.request('$base/credential', method: 'DELETE');
      final revoked = await api.request(base);
      expect(revoked['profile']['enabled'], false);
      expect(revoked['profile']['credential_version'], 2);
      expect(revoked['credential_state'], 'revoked');
      final history = await api.request('$base/events');
      expect(history['total'], 3);
      expect((history['data'] as List).first['action'], 'revoked');
      expect((history['data'] as List).first.containsKey('actor_id'), false);
      expect(jsonEncode(history).contains(issued['secret']), false);

      final ops = 'charging-operations/${f['command_id']}';
      await api.request(
        '$ops/case',
        method: 'POST',
        body: {'note': 'Investigating missing source evidence'},
      );
      await expectLater(
        api.request(
          '$ops/case',
          method: 'PATCH',
          body: {'note': 'There is no source evidence', 'state': 'resolved'},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      final cases = await api.request('charging-operations');
      expect(
        (cases['data'] as List).any(
          (r) => r['id'] == f['command_id'] && r['status'] == 'outcome_unknown',
        ),
        true,
      );
      await api.signOut();
      await api.signIn(f['customer'], f['password']);
      await expectLater(
        api.request('$base/events'),
        throwsA(
          isA<ApiException>().having(
            (e) => e.status,
            'status',
            isIn([403, 404]),
          ),
        ),
      );
      await expectLater(
        api.request('$ops/case'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 403)),
      );
    },
    skip: path == null
        ? 'Run tests/native-charger-operations.cjs against an isolated local Laravel fixture'
        : false,
  );
}
