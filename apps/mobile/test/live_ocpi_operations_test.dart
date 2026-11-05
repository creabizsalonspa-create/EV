import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_OCPI_FIXTURE'];
  test(
    'native actual Laravel partner ownership, record pagination, privacy and Admin revocation',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn('roaming-owner-${f['run']}@example.test', f['password']);
      final list = await api.request('ocpi/providers');
      final provider = (list['data'] as List).single as Map;
      expect(provider['name'], 'Synthetic owner ${f['run']}');
      expect(provider['commands_enabled'], false);
      expect(provider['billing_eligible'], false);
      expect(provider.containsKey('endpoints'), false);
      expect(provider.containsKey('connection_key'), false);
      final id = provider['id'];
      final records = await api.request('ocpi/providers/$id/records/locations');
      expect(records['total'], 26);
      expect((records['data'] as List).length, 25);
      expect((records['data'] as List).first.containsKey('payload'), false);
      final next = await api.request(
        'ocpi/providers/$id/records/locations',
        query: {'page': '2'},
      );
      expect((next['data'] as List).length, 1);
      expect(
        (next['data'] as List).first['id'],
        isNot((records['data'] as List).first['id']),
      );
      final cdrs = await api.request('ocpi/providers/$id/records/cdrs');
      final reports = (cdrs['data'] as List)
          .map((row) => row['reconciliation'] as Map)
          .toList();
      expect(
        reports.map((row) => row['status']),
        containsAll(['source_consistent', 'requires_review']),
      );
      expect(
        reports.every(
          (row) =>
              row['billing_eligible'] == false &&
              row['local_invoice_created'] == false,
        ),
        true,
      );
      expect(jsonEncode(cdrs).contains('PRIVATE-CDR-DRIVER-TOKEN'), false);
      await api.signOut();
      await api.signIn('roaming-other-${f['run']}@example.test', f['password']);
      await expectLater(
        api.request('ocpi/providers/$id/records/locations'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await api.signIn('roaming-admin-${f['run']}@example.test', f['password']);
      final revoked = await api.request(
        'ocpi/providers/$id/authorization',
        method: 'PATCH',
        body: {'authorized': false},
      );
      expect(revoked['authorized'], false);
      expect(revoked['readable_modules'], isEmpty);
      expect(revoked['commands_enabled'], false);
      expect(revoked['billing_eligible'], false);
      final preserved = await api.request(
        'ocpi/providers/$id/records/locations',
      );
      expect(preserved['total'], 26);
      await expectLater(
        api.request(
          'ocpi/providers/$id/sync/locations',
          method: 'POST',
          body: {},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
    },
    skip: path == null
        ? 'Requires explicit local synthetic OCPI fixture.'
        : false,
  );
}
