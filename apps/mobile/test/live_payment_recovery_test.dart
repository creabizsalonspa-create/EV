import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_PAYMENT_RECOVERY_FIXTURE'];
  test(
    'native real Laravel recovery queue and audit enforce invoice ownership without creating payments',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'], f['password']);
      final root = 'commercial/payment-recovery/${f['intent']}';
      final rows = await api.request('commercial/payment-recovery');
      final intent = (rows['data'] as List).single;
      expect(intent['id'], f['intent']);
      expect(intent['status'], 'uncertain');
      expect(intent['external_provider_verified'], false);
      for (final key in ['identity', 'fingerprint', 'lease', 'observation']) {
        expect(intent.containsKey(key), false);
      }
      final events = await api.request('$root/events');
      expect((events['data'] as List).last['status'], 'uncertain');
      // The running app uses its mock configuration. A test-provider operation
      // cannot silently switch provider or initiate payment on status inspection.
      await expectLater(
        api.request('$root/reconcile', method: 'POST', body: {}),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      expect((await api.request('commercial/payment-recovery'))['total'], 1);
      expect((await api.request('$root/events'))['total'], events['total']);
      await api.signOut();
      await api.signIn(f['other'], f['password']);
      expect((await api.request('commercial/payment-recovery'))['total'], 0);
      for (final suffix in ['events', 'reconcile']) {
        await expectLater(
          api.request(
            '$root/$suffix',
            method: suffix == 'events' ? 'GET' : 'POST',
            body: suffix == 'events' ? null : {},
          ),
          throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
        );
      }
    },
    skip: path == null
        ? 'Run node tests/native-payment-recovery.cjs with local Laravel and mock provider'
        : false,
  );
}
