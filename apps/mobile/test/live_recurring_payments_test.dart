import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_RECURRING_FIXTURE'];
  test(
    'native real Laravel saved consent revocation history and tenant isolation',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'], f['password']);
      final methods = await api.request('commercial/payment-methods');
      final row = (methods['methods']['data'] as List).single;
      expect(row['last_four'], '4242');
      expect(row.containsKey('provider_method'), false);
      final subscriptions = await api.request(
        'commercial/recurring-subscriptions',
      );
      expect(
        (subscriptions['data'] as List).single['collection_policy']['enabled'],
        anyOf(true, 1),
      );
      final history = await api.request(
        'commercial/subscriptions/${f['subscription']}',
      );
      expect((history['invoices'] as List).single['total_minor'], 525);
      expect(history['real_payment_processed'], false);
      await api.request(
        'commercial/payment-methods/${f['method']}',
        method: 'DELETE',
        body: {'expected_version': 1},
      );
      final updated = await api.request('commercial/recurring-subscriptions');
      expect(
        (updated['data'] as List).single['collection_policy']['enabled'],
        anyOf(false, 0),
      );
      final events = await api.request('commercial/recurring-events');
      expect((events['data'] as List).any((e) => e['kind'] == 'revoked'), true);
      await api.signOut();
      await api.signIn(f['other'], f['password']);
      expect(
        (await api.request('commercial/payment-methods'))['methods']['total'],
        0,
      );
      await expectLater(
        api.request(
          'commercial/payment-methods/${f['method']}',
          method: 'DELETE',
          body: {'expected_version': 2},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.request('commercial/subscriptions/${f['subscription']}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Run node tests/native-recurring-payments.cjs with local Laravel'
        : false,
  );
}
