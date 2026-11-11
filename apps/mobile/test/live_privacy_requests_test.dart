import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final fixture = Platform.environment['EV_NATIVE_PRIVACY_FIXTURE'];
  test(
    'native rights requests use canonical private receipts and reject other accounts',
    () async {
      final f =
          jsonDecode(await File(fixture!).readAsString())
              as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      final customer = f['accounts']['customer'];
      final other = f['accounts']['other'];
      await api.signIn(
        customer['email'] as String,
        customer['password'] as String,
      );
      final body = <String, dynamic>{
        'kind': 'access',
        'details': 'Please review access to my retained personal information.',
        'idempotency_key': 'native-privacy-${f['run']}',
      };
      final receipt = await api.request(
        'account/privacy-requests',
        method: 'POST',
        body: body,
      );
      expect(receipt['automated_erasure_performed'], false);
      final repeated = await api.request(
        'account/privacy-requests',
        method: 'POST',
        body: body,
      );
      expect(repeated['id'], receipt['id']);
      final mine = await api.request('account/privacy-requests');
      expect(mine['data'], hasLength(1));
      expect(mine['data'][0]['details'], body['details']);
      expect(mine['data'][0].containsKey('review_note'), false);
      await api.signOut();
      await api.signIn(other['email'] as String, other['password'] as String);
      expect((await api.request('account/privacy-requests'))['data'], isEmpty);
      await expectLater(
        api.request(
          'account/privacy-requests/${receipt['id']}/withdraw',
          method: 'POST',
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await api.signIn(
        customer['email'] as String,
        customer['password'] as String,
      );
      final withdrawn = await api.request(
        'account/privacy-requests/${receipt['id']}/withdraw',
        method: 'POST',
      );
      expect(withdrawn['status'], 'withdrawn');
      final closedRetry = await api.request(
        'account/privacy-requests',
        method: 'POST',
        body: body,
      );
      expect(closedRetry['id'], receipt['id']);
      expect(closedRetry['status'], 'withdrawn');
      expect(
        (await api.request('account/privacy-requests'))['data'],
        hasLength(1),
      );
    },
    skip: fixture == null
        ? 'Requires explicit private local Laravel privacy fixture; no automated erasure.'
        : false,
  );
}
